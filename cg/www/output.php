<?php
declare(strict_types=1);

/**
 * 송출 화면 (OBS/vMix Browser Source, 1920×1080 투명 배경).
 * 첫 화면을 서버가 바로 그리므로 소스를 새로고침해도 비지 않는다. 이후 0.3초마다 변경만 받아 반영한다.
 * 주소: output.php?layer=1 (웹은 &t=비밀값). 선택: &template=race-win-rate (그 CG일 때만 표시)
 */
require __DIR__ . '/app/bootstrap.php';

app_start('output');
[$ch, $ghost, $layer] = output_request();
output_access($ch, $ghost);
output_layer_check($layer);
$state = output_payload($ch, $layer);
$only = preg_match('/^[a-z0-9-]{1,40}$/D', (string)($_GET['template'] ?? '')) ? (string)$_GET['template'] : '';
$api = 'api/output.php?' . http_build_query(array_filter([
    't' => $_GET['t'] ?? null,
    'layer' => $layer,
    'ch' => $ch === 'preview' ? 'preview' : null,
    'ghost' => $ghost ? '1' : null,
], static fn($v) => $v !== null && $v !== ''));
$shown = $state['visible'] && ($only === '' || $state['template'] === $only);
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="referrer" content="no-referrer">
<title>끝장전 CG 출력</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="<?= h(asset_url('cg-fonts.css')) ?>">
<link rel="stylesheet" href="<?= h(asset_url('cg.css')) ?>">
<link rel="stylesheet" href="<?= h(asset_url('cg-themes.css')) ?>">
<link rel="stylesheet" href="<?= h(asset_url('output.css')) ?>">
</head>
<body class="out out-<?= h($ch) ?><?= $ghost ? ' out-ghost' : '' ?>">
<div id="stage" data-api="<?= h($api) ?>" data-only="<?= h($only) ?>" data-channel="<?= h($ch) ?>"
     data-heartbeat="<?= $ch === 'program' && !$ghost ? '1' : '0' ?>" data-state="<?= h(json_enc($state)) ?>">
  <div class="cg-pos" id="pos">
    <div class="cg no-anim fx-<?= h($state['effect']) ?><?= $shown ? ' is-shown' : '' ?>" id="cg"><?= $state['html'] ?></div>
  </div>
</div>
<script src="<?= h(asset_url('output.js')) ?>"></script>
</body>
</html>
