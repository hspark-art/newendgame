<?php /** 온라인 상대 전적·온라인 상대 종족 전적 (같은 상자) — $view: {title, cols[{name, vs, record, rate}], h2h, sum?, mock} */ ?>
<div class="cg-box cg-rwr cg-online">
<?php if ($view['title'] !== ''): ?>
  <div class="cg-title"><span class="cg-fit"><?= h($view['title']) ?></span></div>
<?php endif ?>
<?php if (isset($view['sum'])): ?>
  <div class="cg-sumbar"><span class="cg-fit"><small>온라인 통산</small> <?= h($view['sum']) ?></span></div>
<?php else: ?>
  <div class="cg-sumbar"><span class="cg-fit"><small>온라인 맞대결</small> <?= h($view['cols'][0]['name']) ?> <b><?= h($view['h2h']) ?></b> <?= h($view['cols'][1]['name']) ?></span></div>
<?php endif ?>
  <div class="cg-head">
<?php foreach ($view['cols'] as $c): ?>
    <div class="cg-cell"><span class="cg-fit"><span class="cg-name"><?= h($c['name']) ?></span><?php if (($c['vs'] ?? '') !== ''): ?> <span class="cg-vs">vs <?= h($c['vs']) ?></span><?php endif ?></span></div>
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
