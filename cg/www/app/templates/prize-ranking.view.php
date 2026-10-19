<?php /** 총상금 순위 — $view: {title, rows[{rank, name, nick, record, rate}], mock}. 다승 순위(win-ranking.view.php)와 같은 줄 모양·크기,
 *  금액이 기록 칸보다 길면 자동 맞춤(cg-fit)으로 줄인다 */ ?>
<div class="cg-box cg-list cg-ranking cg-prize cg-n<?= count($view['rows']) ?>">
<?php if ($view['title'] !== ''): ?>
  <div class="cg-title"><span class="cg-fit"><?= h($view['title']) ?></span></div>
<?php endif ?>
  <div class="cg-rows cg-bars">
<?php foreach ($view['rows'] as $r): ?>
    <div class="cg-row cg-bar<?= $r['top'] ? ' is-hl' : '' ?>">
      <span class="c-rank"><?= h($r['rank']) ?></span>
      <span class="c-name"><span class="cg-fit"><b><?= h($r['name']) ?></b> <small><?= h($r['nick']) ?></small></span></span>
      <span class="c-record"><span class="cg-fit"><?= h($r['record']) ?></span></span>
      <span class="c-rate"><?= h($r['rate']) ?></span>
    </div>
<?php endforeach ?>
  </div>
<?php if (!empty($view['mock'])): ?>
  <div class="cg-mock">MOCK</div>
<?php endif ?>
</div>
