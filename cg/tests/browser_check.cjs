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
  await panel.click('#btnAdd');
  await panel.selectOption('#pAPlayer', 'jo-iljang');
  await panel.selectOption('#pBPlayer', 'jang-yunchul');
  assert(await panel.inputValue('#pAVs') === 'P' && await panel.inputValue('#pBVs') === 'Z', '선수 선택 시 상대 종족 자동 선택 (A vs P, B vs Z)');
  await panel.fill('#pLabel', '1세트 전');
  await panel.click('#pOk');
  await panel.waitForSelector('#rdBody tr.cued');
  assert((await panel.textContent('#rdBody tr')).includes('조일장 vs P / 장윤철 vs Z'), '페이지 리스트에 추가·PVW 큐');

  // 두 번째 페이지 (0경기)
  await panel.click('#btnAdd');
  await panel.selectOption('#pAPlayer', 'jo-iljang');
  await panel.selectOption('#pBPlayer', 'jang-yunchul');
  await panel.selectOption('#pAVs', 'T');
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

  await browser.close();
})().catch((e) => { console.error(e.message || e); process.exit(1); });
