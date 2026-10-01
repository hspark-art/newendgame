/*
 * 실제 브라우저 확인 — 배포 설정(MOCK 없음)으로 처음 실행했을 때. 개발용.
 *   CG_BASE=http://127.0.0.1:3100 XLSX_PATH=합성시트.xlsx OUT_DIR=./shots node tests/browser_check_fresh.cjs
 * 서버는 비어 있는 데이터 폴더와 배포본 config.desktop.php 로 띄운다.
 */
'use strict';
const path = require('path');
const fs = require('fs');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const BASE = process.env.CG_BASE || 'http://127.0.0.1:3100';
const OUT = process.env.OUT_DIR || path.join(__dirname, 'shots');
fs.mkdirSync(OUT, { recursive: true });

function assert(cond, msg) { if (!cond) { throw new Error('FAIL: ' + msg); } console.log('PASS ' + msg); }

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined });
  const errors = [];
  const panel = await (await browser.newContext({ viewport: { width: 1920, height: 1080 } })).newPage();
  panel.on('pageerror', (e) => errors.push(e.message));
  panel.on('console', (m) => { if (m.type() === 'error') { errors.push(m.text()); } });

  await panel.goto(BASE + '/');
  await panel.waitForFunction(() => document.getElementById('srcStatus').textContent.includes('Google 시트 연결 필요'));
  await panel.waitForTimeout(2500);
  assert(true, '첫 실행: "데이터 없음 · Google 시트 연결 필요" 표시');
  assert(!(await panel.isVisible('#mockBadge')), 'MOCK 표시 없음');
  assert((await panel.$$('#toast > div')).length === 0, '시트 연결 전에는 자동 새로고침을 하지 않음 (오류 알림 없음)');
  assert(!(await panel.textContent('#logList')).includes('갱신 실패') && !(await panel.isVisible('#alertBadge')), '새로고침 실패 기록·알림 없음');
  await panel.click('#btnAdd');
  await panel.waitForFunction(() => document.getElementById('toast').textContent.includes('Google 시트를 연결하거나 xlsx'));
  assert(!(await panel.isVisible('#dlgPage')), '선수 목록 비어 있음 (MOCK 선수 없음) → 페이지 추가 대신 연결 안내');
  await panel.screenshot({ path: path.join(OUT, 'fresh-panel.png') });

  await panel.click('#btnData');
  await panel.waitForFunction(() => document.getElementById('dcSummary').textContent.includes('Google 시트를 연결'));
  assert(true, '데이터 점검: 연결 안내');
  await panel.click('#dlgData .tab[data-tab="settings"]');
  await panel.waitForFunction(() => document.getElementById('dsSource').options.length > 0);
  const sources = await panel.$$eval('#dsSource option', (els) => els.map((e) => e.textContent));
  assert(sources.join(',') === 'Google 시트', '데이터 소스는 Google 시트뿐');
  if (process.env.XLSX_PATH) {
    await panel.setInputFiles('#dsXlsx', process.env.XLSX_PATH);
    await panel.waitForFunction(() => document.getElementById('dcSummary').textContent.includes('xlsx 파일'), null, { timeout: 10000 });
    await panel.click('#dlgData button[value="close"]');
    await panel.waitForFunction(() => document.getElementById('srcStatus').textContent.includes('Google 시트(파일)'));
    await panel.click('#btnAdd');
    await panel.waitForSelector('#pParams [data-key="a.player"] option:nth-child(2)', { state: 'attached' });
    const names = await panel.$$eval('#pParams [data-key="a.player"] option', (els) => els.map((e) => e.value));
    assert(names.slice(1).join(',') === '가선수,나선수,다선수,라선수', 'xlsx 가져오기 후 시트 선수 전원이 목록에 나옴');
    await panel.keyboard.press('Escape');
  }
  assert(errors.length === 0, '브라우저 오류 없음' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await browser.close();
})().catch((e) => { console.error(e.message || e); process.exit(1); });
