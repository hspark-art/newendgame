/*
 * 실제 브라우저(Chromium) 확인 — 개발용. 실행 중인 PC 모드 서버에 접속한다.
 *   CG_BASE=http://127.0.0.1:3100 OUT_DIR=./shots node tests/browser_check.cjs
 * 필요: playwright (전역 설치 또는 PLAYWRIGHT_MODULE), CHROMIUM_PATH(선택)
 */
'use strict';
const path = require('path');
const fs = require('fs');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const BASE = process.env.CG_BASE || 'http://127.0.0.1:3100';
const OUT = process.env.OUT_DIR || path.join(__dirname, 'shots');
fs.mkdirSync(OUT, { recursive: true });

function log(msg) { console.log(msg); }
function assert(cond, msg) { if (!cond) { throw new Error('FAIL: ' + msg); } log('PASS ' + msg); }
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
  const errors = [];
  const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
  const panel = await ctx.newPage();
  panel.on('pageerror', (e) => errors.push('panel: ' + e.message));
  panel.on('console', (m) => { if (m.type() === 'error') { errors.push('panel console: ' + m.text()); } });

  await panel.goto(BASE + '/');
  await panel.waitForFunction(() => document.getElementById('srcStatus').textContent.includes('정상'), null, { timeout: 10000 });
  assert(true, '패널 로드 + 첫 실행 자동 데이터 새로고침');

  // 페이지 추가 (UI)
  const P = (key) => '#pParams [data-key="' + key + '"]';
  await panel.click('#btnAdd');
  await panel.selectOption(P('a.player'), 'jo-iljang');
  await panel.selectOption(P('b.player'), 'jang-yunchul');
  assert(await panel.inputValue(P('a.vs')) === 'P' && await panel.inputValue(P('b.vs')) === 'Z', '선수 선택 시 상대 종족 자동 선택 (A vs P, B vs Z)');
  await panel.fill('#pLabel', '1세트 전');
  await panel.click('#pOk');
  await panel.waitForSelector('#rdBody tr.cued');
  assert((await panel.textContent('#rdBody tr')).includes('조일장 vs P / 장윤철 vs Z'), '페이지 리스트에 추가·PVW 큐');

  // 두 번째 페이지 (0경기: 장윤철 vs T — 자동 선택된 종족을 직접 바꿈)
  await panel.click('#btnAdd');
  await panel.selectOption(P('a.player'), 'jang-yunchul');
  await panel.selectOption(P('b.player'), 'jo-iljang');
  await panel.selectOption(P('a.vs'), 'T');
  await panel.click('#pOk');
  await panel.waitForFunction(() => document.querySelectorAll('#rdBody tr').length === 2);

  // 송출 화면 (OBS 역할): 투명 배경 캡처
  const out = await ctx.newPage();
  out.on('pageerror', (e) => errors.push('output: ' + e.message));
  await out.goto(BASE + '/output.php?layer=1');
  await out.screenshot({ path: path.join(OUT, 'output-empty.png'), omitBackground: true });

  await panel.waitForTimeout(1200);
  await panel.screenshot({ path: path.join(OUT, 'panel-before-take.png') });

  // TAKE (F1)
  await panel.keyboard.press('F1');
  await out.waitForFunction(() => document.getElementById('cg').classList.contains('is-shown'), null, { timeout: 5000 });
  await out.waitForTimeout(600);
  const txt = await out.textContent('#cg');
  assert(txt.includes('33승 21패') && txt.includes('(61.1%)') && txt.includes('129승 123패') && txt.includes('(51.2%)'), 'F1 TAKE → 송출 화면에 33승 21패 (61.1%) / 129승 123패 (51.2%)');
  await out.screenshot({ path: path.join(OUT, 'output-take.png'), omitBackground: true });

  // PREVIEW 편집: PROGRAM 불변
  await panel.fill('#edBody input.val[data-key="a.wins"]', '34');
  await panel.keyboard.press('Control+s');
  await panel.waitForFunction(() => document.querySelector('#edBody tr[data-key="a.rate"] td.final').textContent.startsWith('61.8%'));
  assert(true, 'SAVE TO PREVIEW → 승률 자동 재계산 61.8% (34승 21패)');
  await out.waitForTimeout(800);
  assert((await out.textContent('#cg')).includes('33승 21패'), 'PREVIEW 저장 후에도 PROGRAM은 33승 21패 유지');
  await panel.waitForSelector('#rdBody tr .tag.pending');
  assert(true, '페이지 리스트에 "송출값과 다름" 표시');

  // UPDATE LIVE (확인창)
  await panel.click('#btnLive');
  await panel.waitForSelector('#dlgConfirm[open]');
  const diff = await panel.textContent('#cfBody');
  assert(diff.includes('A 승') && diff.includes('34'), 'UPDATE LIVE 확인창에 변경 내용 표시');
  await panel.click('#cfOk');
  await out.waitForFunction(() => document.getElementById('cg').textContent.includes('34승 21패'), null, { timeout: 5000 });
  assert((await out.textContent('#cg')).includes('(61.8%)'), 'UPDATE LIVE → 송출 화면 34승 21패 (61.8%) 즉시 반영');

  // OUT / SHOW
  await panel.keyboard.press('F2');
  await out.waitForFunction(() => !document.getElementById('cg').classList.contains('is-shown'), null, { timeout: 5000 });
  assert(true, 'F2 OUT → 송출 화면 숨김');
  await out.waitForTimeout(500);
  await out.screenshot({ path: path.join(OUT, 'output-out.png'), omitBackground: true });
  await panel.keyboard.press('F3');
  await out.waitForFunction(() => document.getElementById('cg').classList.contains('is-shown'), null, { timeout: 5000 });
  assert(true, 'F3 SHOW → 다시 표시');

  // 번호 큐 + TAKE (FADE)
  await panel.selectOption('#fx', 'fade');
  await panel.keyboard.press('2');
  await panel.keyboard.press('Enter');
  await panel.waitForFunction(() => document.getElementById('pvwInfo').textContent.startsWith('002'));
  assert(true, '숫자 2 + Enter → 002 페이지 PVW 큐');
  await panel.keyboard.press('Space');
  await out.waitForFunction(() => document.getElementById('cg').textContent.includes('(자료 없음)'), null, { timeout: 5000 });
  assert(true, 'Space TAKE → 0경기 CG 송출 "(자료 없음)"');

  // 입력칸에 포커스가 있을 때 단축키 무시
  await panel.click('#edBody input.val[data-key="title"]');
  const takeBefore = await panel.evaluate(() => fetch('api/state.php').then((r) => r.json()).then((s) => s.program.take_id));
  await panel.keyboard.press('F1');
  await panel.waitForTimeout(800);
  const takeAfter = await panel.evaluate(() => fetch('api/state.php').then((r) => r.json()).then((s) => s.program.take_id));
  assert(takeBefore === takeAfter, '입력칸 포커스 중 F1 무시');
  await panel.keyboard.press('Escape');

  await panel.waitForTimeout(1200);
  await panel.screenshot({ path: path.join(OUT, 'panel-after.png') });

  assert(errors.length === 0, '브라우저 스크립트 오류 없음' + (errors.length ? ': ' + errors.join(' | ') : ''));

  if (process.env.SKIP_OFFLINE !== '1') {
    // 서버 종료·재시작: 바깥 스크립트가 신호 파일로 서버를 끄고 켠다
    const flag = (name) => fs.existsSync(path.join(OUT, name));
    const waitFlag = async (name) => { for (let i = 0; i < 100 && !flag(name); i++) { await sleep(200); } };
    fs.writeFileSync(path.join(OUT, 'READY_TO_STOP'), '1');
    await waitFlag('STOPPED');
    await out.waitForTimeout(3000);
    const still = await out.evaluate(() => [document.getElementById('cg').classList.contains('is-shown'), document.getElementById('cg').textContent]);
    assert(still[0] && still[1].includes('(자료 없음)'), '서버 종료 후 3초: 송출 화면이 마지막 상태 유지 (빈 화면 아님)');
    assert(await panel.isVisible('#conn'), '패널에 "서버 연결 끊김" 표시');
    fs.writeFileSync(path.join(OUT, 'READY_TO_RESTART'), '1');
    await waitFlag('RESTARTED');
    await panel.waitForSelector('#conn', { state: 'hidden', timeout: 10000 });
    await panel.keyboard.press('F2');
    await out.waitForFunction(() => !document.getElementById('cg').classList.contains('is-shown'), null, { timeout: 8000 });
    assert(true, '서버 재시작 후 자동 재연결 → OUT이 송출 화면에 반영');
  }

  errors.length = 0; // 서버를 일부러 끈 동안의 연결 실패 기록은 제외
  // ---- CG 9종: 대화상자로 추가 → 번호 큐 → TAKE(CUT) → 송출 화면 캡처 (1920 기준 우측 하단 560×250)
  const TYPES = [
    ['race-win-rate', [['a.player', 'jo-iljang'], ['b.player', 'jang-yunchul']], '129승 123패'],
    ['recent-race', [['player', 'kim-minchul'], ['vs', 'T']], '2026-05-06'],
    ['head-to-head', [['a.player', 'jo-iljang'], ['b.player', 'kim-jisung']], '다섯 번째 맞대결'],
    ['win-ranking', [['race', '']], '139W 127L'],
    ['prediction-ranking', [['seats', ['park-sanghyun', 'lim-sungchun', 'lee-seungwon']]], '100.0%'],
    ['online-h2h', [['a.player', 'jo-iljang'], ['b.player', 'jang-yunchul']], '12 : 8'],
    ['double-chance', [['a.player', 'yoo-youngjin'], ['b.player', 'jo-iljang']], '(70.0%)'],
    ['win-streak', [], '진행 중'],
    ['full-set', [['a.player', 'jo-iljang'], ['b.player', 'jang-yunchul']], '40.6%'],
  ];
  await panel.selectOption('#fx', 'cut');
  let no = 100;
  for (const [slug, fields, expect] of TYPES) {
    no += 1;
    await panel.click('#btnAdd');
    await panel.selectOption('#pTemplate', slug);
    if (slug === 'win-streak') {
      assert(await panel.inputValue(P('race')) === 'T', '연승 순위: 종족 기본값 테란이 미리 선택됨');
    }
    for (const [key, val] of fields) {
      if (Array.isArray(val)) {
        for (let i = 0; i < val.length; i++) { await panel.selectOption(P(key) + '[data-slot="' + i + '"]', val[i]); }
      } else {
        await panel.selectOption(P(key), val);
      }
    }
    await panel.fill('#pNo', String(no));
    await panel.click('#pOk');
    await panel.waitForFunction((n) => [...document.querySelectorAll('#rdBody tr')].some((tr) => tr.getAttribute('data-no') === String(n)), no);
    await panel.keyboard.press(String(no)[0]);
    await panel.keyboard.press(String(no)[1]);
    await panel.keyboard.press(String(no)[2]);
    await panel.keyboard.press('Enter');
    await panel.waitForFunction((n) => document.getElementById('pvwInfo').textContent.startsWith(String(n)), no);
    await panel.waitForFunction(() => !document.getElementById('btnTake').disabled, null, { timeout: 5000 });
    await panel.keyboard.press('F1');
    await out.waitForFunction((t) => document.getElementById('cg').classList.contains('is-shown')
      && document.getElementById('cg').textContent.includes(t), expect, { timeout: 5000 });
    await out.waitForTimeout(400);
    // 글자가 칸을 넘치지 않는지 (자동 축소 후)
    const over = await out.evaluate(() => [...document.querySelectorAll('#cg .cg-fit')]
      .filter((el) => {
        const ps = getComputedStyle(el.parentNode);
        const room = el.parentNode.clientWidth - parseFloat(ps.paddingLeft) - parseFloat(ps.paddingRight);
        return el.getBoundingClientRect().width > room + 1;
      }).map((el) => el.textContent));
    await out.screenshot({ path: path.join(OUT, 'type-' + slug + '.png'), omitBackground: true, clip: { x: 1340, y: 810, width: 580, height: 270 } });
    assert(over.length === 0, slug + ' 송출 (' + expect + '), 넘치는 글자 없음' + (over.length ? ': ' + over.join(', ') : ''));
    await panel.keyboard.press('F2');
    await out.waitForFunction(() => !document.getElementById('cg').classList.contains('is-shown'), null, { timeout: 5000 });
  }
  // 목록형 CG 편집: 행 구분 줄, 예측 순위 자리 순서 페이지 수정 대화상자에 미리 채움
  for (const k of ['1', '0', '4', 'Enter']) { await panel.keyboard.press(k); }
  await panel.waitForFunction(() => document.getElementById('pvwInfo').textContent.startsWith('104'));
  await panel.waitForFunction(() => document.querySelectorAll('#edBody tr.grp').length === 5);
  assert(true, '에디터: 목록형 CG(다승 순위)는 1~5행 구분');
  // 긴 이름·닉네임(최대 글자 수)도 칸 안에 들어가게 줄어드는지
  await panel.fill('#edBody input.val[data-key="r1.name"]', '가나다라마');
  await panel.fill('#edBody input.val[data-key="r1.nick"]', 'VeryLongNickname');
  await panel.keyboard.press('Control+s');
  await panel.waitForFunction(() => document.querySelector('#edBody tr[data-key="r1.nick"] td.final').textContent === 'VeryLongNickname');
  await panel.evaluate(() => document.activeElement && document.activeElement.blur());
  await panel.keyboard.press('F1');
  await out.waitForFunction(() => document.getElementById('cg').textContent.includes('VeryLongNickname'), null, { timeout: 5000 });
  await out.waitForTimeout(400);
  const longOver = await out.evaluate(() => [...document.querySelectorAll('#cg .cg-fit')].filter((el) => {
    const ps = getComputedStyle(el.parentNode);
    return el.scrollWidth > el.parentNode.clientWidth - parseFloat(ps.paddingLeft) - parseFloat(ps.paddingRight) + 1;
  }).length);
  await out.screenshot({ path: path.join(OUT, 'type-win-ranking-long.png'), omitBackground: true, clip: { x: 1340, y: 810, width: 580, height: 270 } });
  assert(longOver === 0, '긴 이름(5자)·닉네임(16자)도 이름·닉네임이 함께 줄어 칸 안에 표시');
  await panel.keyboard.press('F2');
  await panel.click('#rdBody tr[data-no="105"] [data-act="edit"]');
  await panel.waitForSelector('#dlgPage[open]');
  const seats = await panel.$$eval('#pParams select[data-slot]', (els) => els.map((e) => e.value));
  assert(seats.slice(0, 3).join(',') === 'park-sanghyun,lim-sungchun,lee-seungwon', '페이지 수정: 저장된 자리 순서가 채워짐');
  await panel.keyboard.press('Escape');
  await panel.waitForTimeout(1200);
  await panel.screenshot({ path: path.join(OUT, 'panel-types.png') });
  assert(errors.length === 0, '9종 확인 중 브라우저 오류 없음' + (errors.length ? ': ' + errors.join(' | ') : ''));

  // ---- 데이터 점검·설정 창 (XLSX_PATH: 합성 시트 xlsx가 있으면 가져오기까지 확인)
  await panel.click('#btnData');
  await panel.waitForSelector('#dlgData[open]');
  await panel.waitForFunction(() => document.getElementById('dcSummary').textContent.includes('MOCK'));
  assert(true, '데이터 점검 창: MOCK 데이터 표시');
  await panel.click('#dlgData .tab[data-tab="players"]');
  await panel.waitForSelector('#piBody tr[data-player="jang-yunchul"]');
  await panel.fill('#piBody tr[data-player="jang-yunchul"] .nick', 'SnowFlake');
  await panel.click('#piBody tr[data-player="jang-yunchul"] [data-act="nick"]');
  await panel.waitForFunction(() => document.querySelector('#piBody tr[data-player="jang-yunchul"] .nick').value === 'SnowFlake');
  await panel.click('#dlgData .tab[data-tab="settings"]');
  await panel.waitForFunction(() => document.getElementById('dsSource').options.length === 2);
  assert(!(await panel.isDisabled('#dsSave')), '데이터 설정: PC(관리자)는 설정 가능');
  if (process.env.XLSX_PATH) {
    await panel.setInputFiles('#dsXlsx', process.env.XLSX_PATH);
    await panel.waitForFunction(() => document.getElementById('dcSummary').textContent.includes('xlsx 파일'), null, { timeout: 10000 });
    const sum = await panel.textContent('#dcSummary');
    assert(sum.includes('세트 40') && sum.includes('끝장전 5') && sum.includes('세트 전적 대조됨'), 'xlsx 가져오기 → 점검: 세트 40 · 끝장전 5 · 대조됨');
    assert((await panel.textContent('#dcAnomaly')).includes('세트 수 4개'), '이상 경기 목록 표시');
    await panel.waitForTimeout(600);
    await panel.screenshot({ path: path.join(OUT, 'panel-data-check.png') });
    await panel.click('#dlgData button[value="close"]');
    await panel.waitForFunction(() => document.getElementById('srcStatus').textContent.includes('Google 시트(파일)'));
    assert(true, '상단 상태: Google 시트(파일) · 정상');
    // MOCK 선수로 만든 페이지는 송출 차단 안내
    await panel.keyboard.press('1');
    await panel.keyboard.press('Enter');
    await panel.waitForFunction(() => document.getElementById('edNotice').textContent.includes('지금 데이터에 없는 선수'));
    assert(true, '데이터 소스를 바꾸면 이전 선수 페이지는 송출 차단 안내');
  } else {
    await panel.click('#dlgData button[value="close"]');
  }
  assert(errors.length === 0, '데이터 창 확인 중 브라우저 오류 없음' + (errors.length ? ': ' + errors.join(' | ') : ''));

  await browser.close();
})().catch((e) => { console.error(e.message || e); process.exit(1); });
