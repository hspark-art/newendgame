<?php /** 맞대결 — $view: {title, a, b, summary, rows[{date, sa, sb, a_win, b_win}], mock} */ ?>
<div class="cg-box cg-list cg-head-to-head">
  <div class="cg-title"><span class="cg-fit"><?= h($view['title']) ?></span></div>
  <div class="cg-band"></div>
  <div class="cg-sumbar"><span class="cg-fit"><?= h($view['a']) ?> <b><?= h($view['summary']) ?></b> <?= h($view['b']) ?></span></div>
  <div class="cg-rows">
<?php foreach ($view['rows'] as $r): ?>
    <div class="cg-row">
      <span class="c-date"><?= h((string)$r['date']) ?></span>
      <span class="c-a<?= $r['a_win'] ? ' is-win' : '' ?>"><span class="cg-fit"><?= h($view['a']) ?></span></span>
      <span class="c-score"><?= h((string)$r['sa']) ?> : <?= h((string)$r['sb']) ?></span>
      <span class="c-b<?= $r['b_win'] ? ' is-win' : '' ?>"><span class="cg-fit"><?= h($view['b']) ?></span></span>
    </div>
<?php endforeach ?>
  </div>
<?php if (!empty($view['mock'])): ?>
  <div class="cg-mock">MOCK</div>
<?php endif ?>
</div>
