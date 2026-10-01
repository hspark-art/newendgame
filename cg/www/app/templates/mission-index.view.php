<?php /** 미션 성공 지수 — $view: {title, rows[{rank, name, index, roi, index_sign, roi_sign, top}], mock} */ ?>
<div class="cg-box cg-list cg-ranking cg-prediction cg-mission cg-n<?= count($view['rows']) ?>">
<?php if ($view['title'] !== ''): ?>
  <div class="cg-title"><span class="cg-fit"><?= h($view['title']) ?></span></div>
  <div class="cg-band"></div>
<?php endif ?>
  <div class="cg-rows cg-bars">
<?php foreach ($view['rows'] as $r): ?>
    <div class="cg-row cg-bar<?= $r['top'] ? ' is-top is-hl' : '' ?>">
      <span class="c-rank"><?= h($r['rank']) ?></span>
      <span class="c-name"><span class="cg-fit"><b><?= h($r['name']) ?></b></span></span>
      <span class="c-index"><span class="cg-fit"><?= h($r['index']) ?></span></span>
      <span class="c-roi<?= $r['roi_sign'] !== '' ? ' is-' . h($r['roi_sign']) : '' ?>"><?= h($r['roi']) ?></span>
    </div>
<?php endforeach ?>
  </div>
<?php if (!empty($view['mock'])): ?>
  <div class="cg-mock">MOCK</div>
<?php endif ?>
</div>
