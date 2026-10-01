<?php /** 맵 종족 상성 — $view: {title, rows[{l, r, lw, rw, lrate, rrate, lead, lw_pct, empty}], foot, mock} */ ?>
<div class="cg-box cg-matchup">
  <div class="cg-title"><span class="cg-fit"><?= h($view['title']) ?></span></div>
  <div class="cg-band"></div>
  <div class="mu-rows">
<?php foreach ($view['rows'] as $r): ?>
    <div class="mu-row">
      <span class="mu-race mu-l"><?= h($r['l']) ?></span>
      <span class="mu-win mu-l<?= $r['lead'] === 'l' ? ' is-lead' : '' ?>"><?= h($r['lw']) ?></span>
      <span class="mu-bar<?= $r['empty'] ? ' is-empty' : '' ?>">
        <svg viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true"><rect class="mu-fill-l" x="0" y="0" width="<?= h((string)$r['lw_pct']) ?>" height="10"/><rect class="mu-fill-r" x="<?= h((string)$r['lw_pct']) ?>" y="0" width="<?= h((string)(100 - $r['lw_pct'])) ?>" height="10"/></svg>
        <span class="mu-pct mu-l"><?= h($r['lrate']) ?></span><span class="mu-pct mu-r"><?= h($r['empty'] ? '기록 없음' : $r['rrate']) ?></span>
      </span>
      <span class="mu-win mu-r<?= $r['lead'] === 'r' ? ' is-lead' : '' ?>"><?= h($r['rw']) ?></span>
      <span class="mu-race mu-r"><?= h($r['r']) ?></span>
    </div>
<?php endforeach ?>
  </div>
  <div class="cg-sumbar mu-foot"><span class="cg-fit"><?= h($view['foot']) ?></span></div>
<?php if (!empty($view['mock'])): ?>
  <div class="cg-mock">MOCK</div>
<?php endif ?>
</div>
