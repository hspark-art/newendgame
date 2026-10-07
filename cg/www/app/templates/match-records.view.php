<?php /** 매치 기록 — $view: {title, rows[{name, num, unit, desc, note}], mock}. 1줄이면 크게(이름 · 큰 숫자 · 설명 · 근거 한 줄), 2~3줄이면 줄로 */
$val = static fn(array $r): string => '<span class="cg-fit"><b>' . h($r['num']) . '</b>' . ($r['unit'] !== '' ? '<small>' . h($r['unit']) . '</small>' : '') . '</span>';
?>
<div class="cg-box cg-records">
<?php if ($view['title'] !== ''): ?>
  <div class="cg-title"><span class="cg-fit"><?= h($view['title']) ?></span></div>
  <div class="cg-band"></div>
<?php endif ?>
<?php if (count($view['rows']) === 1): $r = $view['rows'][0]; ?>
  <div class="rec-big">
<?php if ($r['name'] !== ''): ?>
    <div class="rec-name"><span class="cg-fit"><?= h($r['name']) ?></span></div>
<?php endif ?>
    <div class="rec-val"><?= $val($r) ?></div>
<?php if ($r['desc'] !== ''): ?>
    <div class="rec-desc"><span class="cg-fit"><?= h($r['desc']) ?></span></div>
<?php endif ?>
<?php if (($r['note'] ?? '') !== ''): // 0.7.0에서 송출한 화면(스냅샷)에는 note가 없다 ?>
    <div class="rec-note"><span class="cg-fit"><?= h($r['note']) ?></span></div>
<?php endif ?>
  </div>
<?php else: ?>
  <div class="rec-rows">
<?php foreach ($view['rows'] as $r): ?>
    <div class="rec-row">
      <div class="rec-who">
        <div class="rec-name"><span class="cg-fit"><?= h($r['name']) ?></span></div>
<?php if ($r['desc'] !== ''): ?>
        <div class="rec-desc"><span class="cg-fit"><?= h($r['desc']) ?></span></div>
<?php endif ?>
      </div>
      <div class="rec-val"><?= $val($r) ?></div>
    </div>
<?php endforeach ?>
  </div>
<?php endif ?>
<?php if (!empty($view['mock'])): ?>
  <div class="cg-mock">MOCK</div>
<?php endif ?>
</div>
