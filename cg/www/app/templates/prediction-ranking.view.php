<?php /** 승자 예측 순위 — $view: {title, rows[{rank, name, record, rate, top}], mock} */ ?>
<div class="cg-box cg-list cg-ranking cg-prediction cg-n<?= count($view['rows']) ?>">
  <div class="cg-title"><span class="cg-fit"><?= h($view['title']) ?></span></div>
  <div class="cg-band"></div>
  <div class="cg-rows cg-bars">
<?php foreach ($view['rows'] as $r): ?>
    <div class="cg-row cg-bar<?= $r['top'] ? ' is-top' : '' ?>">
      <span class="c-rank"><?= h($r['rank']) ?></span>
      <span class="c-name"><span class="cg-fit"><b><?= h($r['name']) ?></b></span></span>
      <span class="c-record"><?= h($r['record']) ?></span>
      <span class="c-rate"><?= h($r['rate']) ?></span>
    </div>
<?php endforeach ?>
  </div>
<?php if (!empty($view['mock'])): ?>
  <div class="cg-mock">MOCK</div>
<?php endif ?>
</div>
