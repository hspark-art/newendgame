<?php
declare(strict_types=1);

/**
 * CG 디자인 (v0.5): 테마·폰트·크기·글자색·프리셋을 고르고 송출 화면에 적용한다. 바꾸기는 관리자만 (운영자는 보기만).
 * 미리보기는 페이지 리스트에 있는 CG를 지금 데이터로 그린다 (종류별 첫 페이지, 값이 비어 그릴 수 없는 CG는 빼고). 동작은 assets/design.js.
 */
require __DIR__ . '/app/bootstrap.php';

app_start('panel');
$op = guard_control();

// 미리보기용 CG: 페이지 리스트의 종류별 첫 페이지
$samples = [];
foreach (rundown_rows() as $row) {
    $inst = instance_get((int)$row['instance_id']);
    if (isset($samples[$inst['template']])) {
        continue;
    }
    $st = instance_state($inst, current_session_id());
    $view = $st['view'];
    $label = sprintf('%03d %s', (int)$row['page_no'], template_get($inst['template'])['name']);
    if ($view === null) {
        // 시트 대조로 송출이 막힌 CG도 모양은 볼 수 있게 계산값으로 그린다 (송출은 여전히 막힘)
        try {
            $view = template_present($inst['template'], $st['final'], $inst['params'], false, $st['hidden']);
            $label .= ' (송출 확인 필요)';
        } catch (Throwable) {
            continue;
        }
    }
    $samples[$inst['template']] = ['label' => $label, 'html' => cg_render($view)];
}
$boot = ['view' => design_view($op), 'samples' => array_values($samples)];
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<title>CG 디자인 · 끝장전 CG</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="<?= h(asset_url('panel.css')) ?>">
<link rel="stylesheet" href="<?= h(asset_url('cg-fonts.css')) ?>">
<link rel="stylesheet" href="<?= h(asset_url('cg.css')) ?>">
<link rel="stylesheet" href="<?= h(asset_url('cg-themes.css')) ?>">
<link rel="stylesheet" href="<?= h(asset_url('design.css')) ?>">
</head>
<body class="design-page">
<header class="top">
  <div class="brand">끝장전 CG <span class="ver">v<?= h(APP_VERSION) ?></span> <span class="chip">CG 디자인</span></div>
  <div class="spacer"></div>
  <a class="btn sm" href="index.php">조작 패널로</a>
</header>

<main class="dz" id="app" data-boot="<?= h(json_enc($boot)) ?>">
  <section class="dz-monitor">
    <div class="dz-frame" id="frame">
      <div class="dz-stage" id="stage"><div class="dz-pos"><div class="cg" id="stageCg"></div></div></div>
    </div>
    <div class="dz-bar">
      <div class="dz-picker" id="picker" role="group" aria-label="미리볼 CG"></div>
      <div class="spacer"></div>
      <label class="check"><input type="checkbox" id="bgLight"> 밝은 배경</label>
    </div>
    <p class="hint" id="noSample" hidden>페이지 리스트에 CG가 없어 미리볼 수 없습니다. 조작 패널에서 페이지를 추가한 뒤 다시 여세요.</p>
    <div class="dz-strip" id="strip"></div>
  </section>

  <aside class="dz-rack">
    <p class="notice" id="notAdmin" hidden>CG 디자인은 관리자만 바꿀 수 있습니다. 지금 적용된 디자인을 보여 줍니다.</p>
    <div class="dz-applied"><span class="muted">송출에 적용됨</span> <b id="applied">-</b></div>

    <div class="dz-sec">
      <h3>테마 <small>모든 CG 공통</small></h3>
      <div class="dz-seg" id="themes" role="group" aria-label="테마"></div>
    </div>
    <div class="dz-sec">
      <h3>폰트 <small>모두 상업용 무료 (OFL)</small></h3>
      <label class="dz-field"><span>제목</span><select id="font-title" data-role="title"></select></label>
      <label class="dz-field"><span>이름·글자</span><select id="font-name" data-role="name"></select></label>
      <label class="dz-field"><span>숫자</span><select id="font-num" data-role="num"></select></label>
      <p class="hint">영문·숫자 전용 폰트는 한글(승·패·개)만 기본 글꼴로 나옵니다.</p>
    </div>
    <div class="dz-sec">
      <h3>크기 <button type="button" class="btn sm" id="sizeReset">모두 100%</button></h3>
      <div id="sizes"></div>
    </div>
    <div class="dz-sec">
      <h3>글자색 <button type="button" class="btn sm" id="colorReset">모두 테마 색</button></h3>
      <div id="colors"></div>
    </div>
    <div class="dz-actions">
      <button type="button" class="btn primary" id="btnApply">송출에 적용</button>
      <button type="button" class="btn" id="btnRevert">적용된 값으로 되돌리기</button>
      <button type="button" class="btn" id="btnDefault">기본 디자인</button>
    </div>
    <p class="hint">[송출에 적용]을 누르면 PREVIEW·PROGRAM(송출 중인 화면)에 바로 반영됩니다. 누르기 전에는 이 화면에서만 바뀝니다.</p>

    <div class="dz-sec">
      <h3>프리셋 <small>서버에 저장 · 모든 운영자 공통</small></h3>
      <ul class="dz-presets" id="presets"></ul>
      <form class="dz-row" id="presetForm">
        <input type="text" id="presetName" maxlength="20" placeholder="예: 결승전용" aria-label="프리셋 이름" autocomplete="off">
        <button type="submit" class="btn">지금 설정을 프리셋으로 저장</button>
      </form>
    </div>
  </aside>
</main>

<div id="toast" class="toast" role="status" aria-live="polite"></div>
<script src="<?= h(asset_url('design.js')) ?>"></script>
</body>
</html>
