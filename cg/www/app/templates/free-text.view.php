<?php /** 자유 입력 — $view: {title, lines[{text, sub}], mock} */ ?>
<div class="cg-box cg-free">
<?php if ($view['title'] !== ''): ?>
  <div class="cg-title"><span class="cg-fit"><?= h($view['title']) ?></span></div>
  <div class="cg-band"></div>
<?php endif ?>
<?php if ($view['lines']): ?>
  <div class="fr-lines">
<?php foreach ($view['lines'] as $l): ?>
    <div class="fr-line<?= $l['sub'] === '' ? ' is-center' : '' ?>">
      <span class="fr-text"><span class="cg-fit"><?= h($l['text']) ?></span></span>
<?php if ($l['sub'] !== ''): ?>
      <span class="fr-sub"><span class="cg-fit"><?= h($l['sub']) ?></span></span>
<?php endif ?>
    </div>
<?php endforeach ?>
  </div>
<?php endif ?>
<?php if (!empty($view['mock'])): ?>
  <div class="cg-mock">MOCK</div>
<?php endif ?>
</div>
