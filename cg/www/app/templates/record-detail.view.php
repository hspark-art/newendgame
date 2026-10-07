<?php /** 기록 상세 — $view: {title, num, unit, label, best, head, layout: games|facts, rows[{c1,c2,c3,c4}], foot, mock}
 *  경기 내역: [날짜 | 상대 | 스코어(머리글에 적은 선수 기준) | 결과(승/패 글자)], 사실 표: [항목 | 내용] */ ?>
<div class="cg-box cg-recdetail">
<?php if ($view['title'] !== ''): ?>
  <div class="cg-title"><span class="cg-fit"><?= h($view['title']) ?></span></div>
  <div class="cg-band"></div>
<?php endif ?>
  <div class="rd-hero">
    <div class="rd-num"><span class="cg-fit"><b><?= h($view['num']) ?></b><?php if ($view['unit'] !== ''): ?><small><?= h($view['unit']) ?></small><?php endif ?></span></div>
    <div class="rd-side">
<?php if ($view['label'] !== ''): ?>
      <div class="rd-label"><span class="cg-fit"><?= h($view['label']) ?></span></div>
<?php endif ?>
<?php if ($view['best'] !== ''): ?>
      <div class="rd-best"><span class="cg-fit"><?= h($view['best']) ?></span></div>
<?php endif ?>
    </div>
  </div>
<?php if ($view['layout'] === 'games'): ?>
  <div class="rd-table">
    <div class="rd-tr rd-th"><span class="rd-c1">날짜</span><span class="rd-c2">상대</span><span class="rd-c3"><span class="cg-fit">스코어<?= $view['head'] !== '' ? ' (' . h($view['head']) . ')' : '' ?></span></span><span class="rd-c4">결과</span></div>
<?php foreach ($view['rows'] as $r): ?>
    <div class="rd-tr">
      <span class="rd-c1"><span class="cg-fit"><?= h($r['c1']) ?></span></span>
      <span class="rd-c2"><span class="cg-fit"><?= h($r['c2']) ?></span></span>
      <span class="rd-c3"><span class="cg-fit"><?= h($r['c3']) ?></span></span>
      <span class="rd-c4<?= $r['c4'] === '승' ? ' is-w' : ($r['c4'] === '패' ? ' is-l' : '') ?>"><?= h($r['c4']) ?></span>
    </div>
<?php endforeach ?>
  </div>
<?php else: ?>
  <div class="rd-facts">
<?php foreach ($view['rows'] as $r): ?>
    <div class="rd-fr">
      <span class="rd-k"><span class="cg-fit"><?= h($r['c1']) ?></span></span>
      <span class="rd-v"><span class="cg-fit"><?= h(trim($r['c2'] . ' ' . $r['c3'] . ' ' . $r['c4'])) ?></span></span>
    </div>
<?php endforeach ?>
  </div>
<?php endif ?>
<?php if ($view['foot'] !== ''): ?>
  <div class="cg-sumbar rd-foot"><span class="cg-fit"><?= h($view['foot']) ?></span></div>
<?php endif ?>
<?php if (!empty($view['mock'])): ?>
  <div class="cg-mock">MOCK</div>
<?php endif ?>
</div>
