<?php
declare(strict_types=1);

/**
 * 조작 패널 (토네이도식 페이지 리스트 + PREVIEW/PROGRAM 모니터 + 타이틀 에디터).
 * 화면 내용은 assets/panel.js 가 api/state.php 를 폴링해 그린다.
 */
require __DIR__ . '/app/bootstrap.php';

app_start('panel');
$op = guard_control();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<meta name="app-mode" content="<?= h((string)config('mode')) ?>">
<title>끝장전 CG</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="<?= h(asset_url('panel.css')) ?>">
</head>
<body class="mode-<?= h((string)config('mode')) ?>">
<header class="top">
  <div class="brand">끝장전 CG <span class="ver">v<?= h(APP_VERSION) ?></span>
    <span class="chip"><?= is_web() ? '웹' : 'PC' ?></span></div>
  <div class="top-session">
    <span class="muted">세션</span> <b id="sessName">-</b>
    <button type="button" id="btnNewSession" class="btn sm">새 세션</button>
  </div>
  <div class="top-source">
    <span class="chip mock" id="mockBadge" hidden>MOCK 데이터</span>
    <span class="status" id="srcStatus">-</span>
    <label class="check"><input type="checkbox" id="autoRefresh" checked> 자동 새로고침</label>
    <button type="button" id="btnRefresh" class="btn sm">데이터 새로고침 <kbd>F5</kbd></button>
    <button type="button" id="btnData" class="btn sm">데이터 점검·설정 <span class="tag err" id="dataBadge" hidden></span></button>
    <a class="btn sm" href="design.php" target="_blank" rel="noopener" title="테마·폰트·크기·글자색 (관리자) — 새 탭">CG 디자인</a>
    <button type="button" id="btnAlerts" class="btn sm" title="데이터 오류·새로고침 실패 알림 (관리자)" hidden>알림 <span class="tag err" id="alertBadge" hidden></span></button>
  </div>
  <div class="top-right">
    <span class="outputs" id="outSeen"><i class="dot"></i> 출력 연결 확인 중</span>
    <span class="conn" id="conn" hidden>서버 연결 끊김 — 재시도 중</span>
    <span class="clock" id="clock">--:--:--</span>
<?php if (is_web()): ?>
    <span class="user"><?= h($op['name']) ?></span>
    <?php if ($op['role'] === 'admin'): ?><a class="btn sm" href="admin.php">관리자</a><?php endif ?>
    <a class="btn sm" href="account.php">내 계정</a>
    <form method="post" action="logout.php" class="inline"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><button class="btn sm">로그아웃</button></form>
<?php endif ?>
  </div>
</header>

<main class="grid">
  <section class="panel rundown">
    <div class="panel-head">
      <div class="pagebox" title="숫자를 누르고 Enter: 해당 페이지를 PREVIEW에 큐">PAGE <span id="pageBuf">___</span></div>
      <h2>페이지 리스트</h2>
      <div class="spacer"></div>
      <button type="button" id="btnAdd" class="btn primary">+ 페이지 추가</button>
      <button type="button" id="btnExport" class="btn">내보내기</button>
      <button type="button" id="btnImport" class="btn">가져오기</button>
      <input type="file" id="fileImport" accept=".json,application/json" hidden>
    </div>
    <div class="table-wrap">
      <table class="rd">
        <thead><tr><th class="c-no">No</th><th class="c-type">종류</th><th>내용</th><th class="c-memo">메모</th><th class="c-state">상태</th><th class="c-air">송출</th><th class="c-act"></th></tr></thead>
        <tbody id="rdBody"></tbody>
      </table>
      <p class="empty" id="rdEmpty" hidden>페이지가 없습니다. <b>+ 페이지 추가</b>로 이번 경기에 쓸 CG를 등록하세요.</p>
    </div>
    <p class="hint">숫자 + Enter 큐 · ↑↓ 이전/다음 · Space·F1 TAKE · F2 OUT · F3 SHOW · F4 NEXT · F5 새로고침 · Ctrl+S 저장</p>
  </section>

  <section class="panel switcher">
    <div class="monitors">
      <div class="mon pvw">
        <div class="mon-label"><b>PREVIEW</b> <span id="pvwInfo" class="ellipsis">-</span></div>
        <div class="mon-frame zoom" id="pvwFrame"><iframe title="PREVIEW" id="pvwIframe" width="1920" height="1080" tabindex="-1"></iframe></div>
      </div>
      <div class="mon pgm">
        <div class="mon-label"><b>PROGRAM</b> <span id="pgmBadge" class="air off">비어 있음</span>
          <span id="pgmInfo" class="ellipsis"></span> <span id="pgmElapsed" class="elapsed"></span></div>
        <div class="mon-frame zoom" id="pgmFrame"><iframe title="PROGRAM" id="pgmIframe" width="1920" height="1080" tabindex="-1"></iframe></div>
      </div>
    </div>
    <div class="fxbar">
      <label>효과 <select id="fx"><option value="slide">SLIDE</option><option value="fade">FADE</option><option value="cut">CUT</option></select></label>
      <label>시간 <input type="number" id="fxDur" min="0.1" max="2" step="0.05" value="0.35"> 초</label>
      <label class="check"><input type="checkbox" id="autoNext"> TAKE 후 자동 NEXT</label>
      <div class="spacer"></div>
      <button type="button" id="btnZoom" class="btn sm">전체 화면 보기</button>
    </div>
    <div class="bigbtns">
      <button type="button" id="btnTake" class="big take">TAKE<kbd>F1 · Space</kbd></button>
      <button type="button" id="btnOut" class="big out">OUT<kbd>F2</kbd></button>
      <button type="button" id="btnShow" class="big show">SHOW<kbd>F3</kbd></button>
      <button type="button" id="btnPrev" class="big prev">◀ PREV<kbd>↑</kbd></button>
      <button type="button" id="btnNext" class="big next">NEXT ▶<kbd>F4 · ↓</kbd></button>
    </div>
    <p class="hint" id="takeHint">TAKE: PREVIEW를 송출하고 표시합니다 (선택한 효과). OUT은 내리기만 하며 데이터는 지우지 않습니다.</p>
    <div class="sw-info">
      <div class="block">
        <h3>송출 주소 <small>클릭하면 복사</small></h3>
        <div id="urlList" class="urls"></div>
        <p class="hint" title="특정 CG만 띄우려면 주소 끝에 &amp;template=race-win-rate">OBS/vMix 브라우저 소스 1920 × 1080</p>
      </div>
      <div class="block">
        <h3>위치 · 크기 <small>[적용]은 PREVIEW에, [송출에도 바로 적용]은 송출 중인 화면까지</small></h3>
        <div class="display-form">
          <label>오른쪽 <input type="number" id="dRight" step="1"> px</label>
          <label>아래 <input type="number" id="dBottom" step="1"> px</label>
          <label>크기 <input type="number" id="dScale" min="50" max="200" step="5"> %</label>
          <button type="button" id="btnDisplay" class="btn sm">적용</button>
          <button type="button" id="btnDisplayLive" class="btn sm live" title="TAKE 없이 송출 중인 화면의 위치·크기만 바로 바꿉니다 (수치는 그대로)">송출에도 바로 적용</button>
        </div>
      </div>
    </div>
  </section>

  <section class="panel editor">
    <div class="panel-head">
      <h2>타이틀 에디터</h2>
      <span id="edTarget" class="ellipsis muted">PREVIEW에 큐된 페이지가 없습니다.</span>
    </div>
    <div id="edNotice" class="notice" hidden></div>
    <div class="table-wrap">
      <table class="ed">
        <thead><tr><th>항목</th><th>AUTO</th><th>MANUAL 입력</th><th>FINAL</th><th>LIVE (송출값)</th><th>상태</th><th title="다음 방송 세션에도 이 수정값 유지">KEEP</th><th></th></tr></thead>
        <tbody id="edBody"></tbody>
      </table>
    </div>
    <div class="ed-actions">
      <button type="button" id="btnSave" class="btn save">SAVE TO PREVIEW <kbd>Ctrl+S</kbd></button>
      <button type="button" id="btnDiscard" class="btn">입력 취소</button>
      <button type="button" id="btnResetAll" class="btn">전체 되돌리기 (AUTO)</button>
      <div class="spacer"></div>
      <div class="livebox">
        <span>긴급 송출 수정 — 송출 중인 같은 CG에 바로 반영</span>
        <button type="button" id="btnLive" class="btn live">UPDATE LIVE</button>
      </div>
    </div>
  </section>

  <section class="panel side">
    <div class="block grow">
      <h3>송출 로그</h3>
      <ol id="logList" class="log"></ol>
    </div>
  </section>
</main>

<div id="toast" class="toast" role="status" aria-live="polite"></div>

<dialog id="dlgPage" class="dlg">
  <form method="dialog" id="pageForm">
    <h3 id="pageTitle">페이지 추가</h3>
    <label>종류 <select id="pTemplate"></select></label>
    <div id="pParams" class="prms"></div>
    <p class="hint" id="pHint"></p>
    <label>페이지 번호 <input type="number" id="pNo" min="1" max="999" placeholder="비우면 다음 번호"></label>
    <label>메모 <input type="text" id="pLabel" maxlength="100" placeholder="예: 3세트 전"></label>
    <div class="dlg-btns"><button value="cancel" class="btn">취소</button><button value="ok" id="pOk" class="btn primary">저장</button></div>
  </form>
</dialog>

<dialog id="dlgData" class="dlg wide">
  <form method="dialog">
    <h3>데이터 점검·설정</h3>
    <div class="tabs">
      <button type="button" class="tab on" data-tab="check">점검</button>
      <button type="button" class="tab" data-tab="players">선수 닉네임</button>
      <button type="button" class="tab" data-tab="maps">맵 이름</button>
      <button type="button" class="tab" data-tab="settings">데이터 설정</button>
    </div>
    <section class="tabpane" data-pane="check">
      <div id="dcSummary" class="dc-summary"></div>
      <h4>시트 집계와 다른 항목 <span class="muted" id="dcMisCount"></span></h4>
      <p class="hint">여기 나온 선수·항목을 쓰는 CG는 송출이 막힙니다. 시트를 고치고 새로고침하거나, 확인한 값을 타이틀 에디터에 직접 입력하세요.</p>
      <div class="dc-list"><table class="grid-table"><thead><tr><th>구분</th><th>선수·중계진</th><th>항목</th><th>시트 집계</th><th>프로그램 계산</th></tr></thead>
        <tbody id="dcMismatch"></tbody></table></div>
      <h4>이상 경기·확인 필요 <span class="muted" id="dcAnoCount"></span></h4>
      <p class="hint">9세트가 아닌 경기·동점·경기 중 종족 변경 등은 끝장전 통계(맞대결·연승·풀세트·최근 전적)에서 빼고, 관련 선수의 CG는 확인 전까지 막습니다.</p>
      <ul id="dcAnomaly" class="dc-list plain"></ul>
      <h4>시트 입력 점검 <span class="muted" id="dcLintCount"></span></h4>
      <p class="hint">프로그램은 공백을 지우고 읽었지만, 시트 자체 집계(Players 탭 등)는 다르게 셀 수 있는 칸입니다. 안내된 행을 시트에서 고쳐 주세요.</p>
      <ul id="dcLint" class="dc-list plain"></ul>
      <h4>끝장전 통계에서 제외한 경기 (확정) <span class="muted" id="dcExcCount"></span></h4>
      <p class="hint">세트 수가 9가 아닌 특별 경기 등을 관리자가 제외로 확정한 목록입니다. 세트 전적(종족 승률)에는 그대로 들어갑니다.</p>
      <ul id="dcExcluded" class="dc-list plain"></ul>
    </section>
    <section class="tabpane" data-pane="players" hidden>
      <p class="hint">닉네임(예: soma, Light)은 시트의 <b>'닉네임' 탭</b>(A열 선수명, B열 닉네임)에서 읽습니다. 여기에 입력하면 시트보다 우선하고,
        비우고 저장하면 시트 값을 씁니다. 닉네임은 다승·연승 CG의 이름 옆에 표시됩니다.</p>
      <div class="dc-list"><table class="grid-table"><thead><tr><th>선수</th><th>종족</th><th>시트 닉네임</th><th>프로그램 닉네임 (우선)</th><th></th></tr></thead>
        <tbody id="piBody"></tbody></table></div>
    </section>
    <section class="tabpane" data-pane="maps" hidden>
      <p class="hint">맵 이름은 시트 Results의 영문 표기 그대로 나옵니다. 한글 이름은 시트의 <b>'맵 이름' 탭</b>(A열 영문, B열 한글)에서 읽고,
        여기에 입력하면 시트보다 우선합니다. 비우고 저장하면 시트 값(없으면 영문)을 씁니다. 매치 프리뷰·맵 전적·맵 종족 상성 CG에 쓰입니다.</p>
      <div class="dc-list"><table class="grid-table"><thead><tr><th>맵 (Results 표기)</th><th>세트</th><th>시트 한글</th><th>프로그램 한글 (우선)</th><th></th></tr></thead>
        <tbody id="miBody"></tbody></table></div>
    </section>
    <section class="tabpane" data-pane="settings" hidden>
      <p class="notice" id="dsNotAdmin" hidden>데이터 설정은 관리자만 바꿀 수 있습니다.</p>
      <div id="dsForm">
        <label class="prm"><span>데이터 소스</span><select id="dsSource"></select></label>
        <label class="prm"><span>Google 시트 주소</span><input type="text" id="dsSheet" autocomplete="off" placeholder="https://docs.google.com/spreadsheets/d/…"></label>
        <label class="prm"><span>끝장전 기록 탭</span><input type="text" id="dsTabResults"></label>
        <label class="prm"><span>세트 집계 탭 (검증)</span><input type="text" id="dsTabPlayers"></label>
        <label class="prm"><span>끝장전 목록 탭 (검증)</span><input type="text" id="dsTabMatches"></label>
        <label class="prm"><span>승자 예측 탭</span><input type="text" id="dsTabPredictions"></label>
        <label class="prm"><span>더블 찬스 보정 탭</span><input type="text" id="dsTabAdjust"></label>
        <label class="prm"><span>선수별 통계 탭 (더블 찬스 검증)</span><input type="text" id="dsTabStats"></label>
        <label class="prm"><span>닉네임 탭 (선택)</span><input type="text" id="dsTabNicks"></label>
        <label class="prm"><span>맵 통계 탭 (맵 상성 검증)</span><input type="text" id="dsTabMapstats"></label>
        <label class="prm"><span>선수 맵 전적 탭 (검증)</span><input type="text" id="dsTabMapplayers"></label>
        <label class="prm"><span>맵 이름 탭 (선택)</span><input type="text" id="dsTabMapnames"></label>
        <div class="dlg-btns left"><button type="button" id="dsSave" class="btn primary">설정 저장</button>
          <button type="button" id="dsTest" class="btn">연결 테스트</button></div>
        <h4>서비스 계정 키</h4>
        <p class="hint">시트는 공개하지 말고, 아래 서비스 계정 이메일에 "뷰어" 권한으로만 공유하세요. 키 파일은 이 프로그램의 비밀 폴더에만 저장되고 화면에 다시 표시되지 않습니다.</p>
        <p>등록된 키: <b id="dsKeyEmail">없음</b></p>
        <p class="notice err" id="dsKeyPublic" hidden>키 보관 폴더가 웹 폴더 안에 있습니다. Apache의 .htaccess로만 막혀 있으니, app/config.php의 secrets_dir에 웹 폴더 밖 경로를 지정하세요.</p>
        <div class="dlg-btns left"><label class="btn">키 파일(JSON) 등록<input type="file" id="dsKeyFile" accept=".json,application/json" hidden></label>
          <button type="button" id="dsKeyRemove" class="btn">키 삭제</button></div>
        <h4>파일로 가져오기 (예비)</h4>
        <p class="hint">시트에 연결할 수 없을 때: Google 시트에서 "파일 → 다운로드 → Microsoft Excel(.xlsx)"로 받은 파일을 가져옵니다. 검증은 같은 방식으로 합니다.</p>
        <div class="dlg-btns left"><label class="btn">xlsx 파일 가져오기<input type="file" id="dsXlsx" accept=".xlsx" hidden></label></div>
        <p class="hint" id="dsOpenssl" hidden>이 PHP에 openssl 확장이 없어 시트에 직접 연결할 수 없습니다. xlsx 가져오기를 쓰세요.</p>
        <p class="hint" id="dsZip" hidden>이 PHP에 zip 확장이 없어 xlsx 파일을 읽을 수 없습니다.</p>
      </div>
    </section>
    <div class="dlg-btns"><button value="close" class="btn">닫기</button></div>
  </form>
</dialog>

<dialog id="dlgAlerts" class="dlg wide">
  <form method="dialog">
    <h3>알림 <small class="muted">관리자</small></h3>
    <p class="hint">데이터 오류(시트 집계와 다름·이상 경기·대조 불가·시트 입력 점검)와 새로고침 실패를 모아 보여 줍니다.
      문제가 사라지면 자동으로 '해결됨'으로 옮겨지고, 다시 생기거나 값이 바뀌면 새 알림이 됩니다.</p>
    <div class="tabs">
      <button type="button" class="tab on" data-atab="new">새 알림 <span id="alNewCount"></span></button>
      <button type="button" class="tab" data-atab="acked">확인함 <span id="alAckCount"></span></button>
      <button type="button" class="tab" data-atab="resolved">해결됨 <span id="alResCount"></span></button>
    </div>
    <div class="dlg-btns left">
      <button type="button" id="alAckAll" class="btn sm">새 알림 모두 확인</button>
      <button type="button" id="alOpenData" class="btn sm">데이터 점검 열기</button>
    </div>
    <ul id="alList" class="al-list"></ul>
    <div class="dlg-btns"><button value="close" class="btn">닫기</button></div>
  </form>
</dialog>

<dialog id="dlgConfirm" class="dlg">
  <form method="dialog">
    <h3 id="cfTitle">확인</h3>
    <div id="cfBody"></div>
    <div class="dlg-btns"><button value="cancel" class="btn">취소</button><button value="ok" id="cfOk" class="btn primary">확인</button></div>
  </form>
</dialog>

<dialog id="dlgSession" class="dlg">
  <form method="dialog">
    <h3>새 방송 세션 시작</h3>
    <p>수정값(MANUAL)은 방송 세션마다 따로 관리됩니다. 새 세션은 모든 CG가 AUTO로 시작하며,
      <b>KEEP</b>을 체크한 수정값 <b id="keepCount">0</b>개만 넘어갑니다. PROGRAM(송출 중 화면)은 바뀌지 않습니다.</p>
    <label>세션 이름 <input type="text" id="sessNameInput" maxlength="100"></label>
    <div class="dlg-btns"><button value="cancel" class="btn">취소</button><button value="ok" class="btn primary">새 세션 시작</button></div>
  </form>
</dialog>

<script src="<?= h(asset_url('panel.js')) ?>"></script>
</body>
</html>
