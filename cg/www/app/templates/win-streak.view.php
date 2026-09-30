<?php /** 연승 순위 — $view: {title, rows[{rank, name, nick, streak, period, ongoing}], mock} */ ?>
<div class="cg-box cg-list cg-ranking cg-streak">
  <div class="cg-title"><span class="cg-fit"><?= h($view['title']) ?></span></div>
  <div class="cg-rows cg-bars">
<?php foreach ($view['rows'] as $r): ?>
    <div class="cg-row cg-bar">
      <span class="c-rank"><?= h($r['rank']) ?></span>
      <span class="c-name"><span class="cg-fit"><b><?= h($r['name']) ?></b> <small><?= h($r['nick']) ?></small></span></span>
      <span class="c-streak<?= $r['ongoing'] ? ' is-win' : '' ?>"><?= h($r['streak']) ?></span>
      <span class="c-period"><span class="cg-fit"><?= h($r['period']) ?></span></span>
    </div>
<?php endforeach ?>
  </div>
<?php if (!empty($view['mock'])): ?>
  <div class="cg-mock">MOCK</div>
<?php endif ?>
</div>
