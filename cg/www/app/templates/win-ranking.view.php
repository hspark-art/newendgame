<?php /** 다승 순위 — $view: {title, rows[{rank, name, nick, record, rate}], mock} */ ?>
<div class="cg-box cg-list cg-ranking cg-n<?= count($view['rows']) ?>">
<?php if ($view['title'] !== ''): ?>
  <div class="cg-title"><span class="cg-fit"><?= h($view['title']) ?></span></div>
<?php endif ?>
  <div class="cg-rows cg-bars">
<?php foreach ($view['rows'] as $r): ?>
    <div class="cg-row cg-bar<?= $r['top'] ? ' is-hl' : '' ?>">
      <span class="c-rank"><?= h($r['rank']) ?></span>
      <span class="c-name"><span class="cg-fit"><b><?= h($r['name']) ?></b> <small><?= h($r['nick']) ?></small></span></span>
      <span class="c-record"><?= h($r['record']) ?></span>
      <span class="c-rate"><?= h($r['rate']) ?></span>
    </div>
<?php endforeach ?>
  </div>
<?php if (!empty($view['mock'])): ?>
  <div class="cg-mock">MOCK</div>
<?php endif ?>
</div>
