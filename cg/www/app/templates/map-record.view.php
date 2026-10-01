<?php /** 맵 전적 — $view: {title, cols[{name, race, record, rate, detail}], mock} */ ?>
<div class="cg-box cg-rwr cg-map-record">
<?php if ($view['title'] !== ''): ?>
  <div class="cg-title"><span class="cg-fit"><?= h($view['title']) ?></span></div>
  <div class="cg-band"></div>
<?php endif ?>
  <div class="cg-head">
<?php foreach ($view['cols'] as $c): ?>
    <div class="cg-cell"><span class="cg-fit"><span class="cg-name"><?= h($c['name']) ?></span><?php if ($c['race'] !== ''): ?> <span class="cg-race"><?= h($c['race']) ?></span><?php endif ?></span></div>
<?php endforeach ?>
  </div>
  <div class="cg-body">
<?php foreach ($view['cols'] as $c): ?>
    <div class="cg-cell">
      <div class="cg-record"><span class="cg-fit"><?= h($c['record']) ?></span></div>
      <div class="cg-rate"><?= h($c['rate']) ?></div>
<?php if ($c['detail'] !== ''): ?>
      <div class="cg-detail"><?= h($c['detail']) ?></div>
<?php endif ?>
    </div>
<?php endforeach ?>
  </div>
<?php if (!empty($view['mock'])): ?>
  <div class="cg-mock">MOCK</div>
<?php endif ?>
</div>
