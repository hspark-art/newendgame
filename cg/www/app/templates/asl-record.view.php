<?php /** ASL 선수 기록 — $view: {title, cols[{name, rate(우승), count(준우승·4강 이상), detail(세트 전적)}], mock}.
 *  풀세트 접전 확률(full-set.view.php)과 같은 두 칸 모양·크기 */ ?>
<div class="cg-box cg-rwr cg-full-set cg-asl">
<?php if ($view['title'] !== ''): ?>
  <div class="cg-title"><span class="cg-fit"><?= h($view['title']) ?></span></div>
  <div class="cg-band"></div>
<?php endif ?>
  <div class="cg-head">
<?php foreach ($view['cols'] as $c): ?>
    <div class="cg-cell"><span class="cg-fit"><span class="cg-name"><?= h($c['name']) ?></span> <span class="cg-vs">ASL</span></span></div>
<?php endforeach ?>
  </div>
  <div class="cg-body">
<?php foreach ($view['cols'] as $c): ?>
    <div class="cg-cell">
      <div class="cg-record"><span class="cg-fit"><?= h($c['rate']) ?></span></div>
      <div class="cg-rate"><span class="cg-fit"><?= h($c['count']) ?></span></div>
      <div class="cg-detail"><span class="cg-fit"><?= h($c['detail']) ?></span></div>
    </div>
<?php endforeach ?>
  </div>
<?php if (!empty($view['mock'])): ?>
  <div class="cg-mock">MOCK</div>
<?php endif ?>
</div>
