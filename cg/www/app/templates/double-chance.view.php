<?php /** 더블 찬스 승률 — $view: {title, cols[{name, record, rate}], mock} */ ?>
<div class="cg-box cg-rwr cg-double">
  <div class="cg-title"><span class="cg-fit"><?= h($view['title']) ?></span></div>
  <div class="cg-band"></div>
  <div class="cg-head">
<?php foreach ($view['cols'] as $c): ?>
    <div class="cg-cell"><span class="cg-fit"><span class="cg-name"><?= h($c['name']) ?></span></span></div>
<?php endforeach ?>
  </div>
  <div class="cg-body">
<?php foreach ($view['cols'] as $c): ?>
    <div class="cg-cell">
      <div class="cg-record"><span class="cg-fit"><?= h($c['record']) ?></span></div>
      <div class="cg-rate"><?= h($c['rate']) ?></div>
    </div>
<?php endforeach ?>
  </div>
<?php if (!empty($view['mock'])): ?>
  <div class="cg-mock">MOCK</div>
<?php endif ?>
</div>
