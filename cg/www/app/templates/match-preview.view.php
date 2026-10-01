<?php
/** 매치 프리뷰 — $view: {title, a{name,nick,race}, b{…}, rows[{kind: rec|form|note, key, label, a, a_sub, b, b_sub, lead, text}], mock}
 *  줄마다 [A 값] [가운데 항목] [B 값]. 첫 맞대결이면 가운데에 "첫 맞대결"만 표시 */
$side = static function (array $r, string $s): string {
    if ($r['kind'] === 'note') {
        return '';
    }
    if ($r['kind'] === 'form') {
        $out = '';
        foreach ($r[$s] as $x) {
            $out .= '<i class="' . ($x === 'W' ? 'is-w' : 'is-l') . '">' . h($x) . '</i>';
        }
        return '<span class="pv-form">' . $out . '</span>';
    }
    return '<span class="cg-fit"><b>' . h($r[$s]) . '</b>' . ($r[$s . '_sub'] !== '' ? ' <small>' . h($r[$s . '_sub']) . '</small>' : '') . '</span>';
};
?>
<div class="cg-box cg-preview">
<?php if ($view['title'] !== ''): ?>
  <div class="cg-title"><span class="cg-fit"><?= h($view['title']) ?></span></div>
  <div class="cg-band"></div>
<?php endif ?>
  <div class="cg-head">
<?php foreach (['a', 'b'] as $s): $p = $view[$s]; ?>
    <div class="cg-cell pv-<?= $s ?>"><span class="cg-fit"><span class="cg-name"><?= h($p['name']) ?></span><?php if ($p['nick'] !== ''): ?> <small class="cg-nick"><?= h($p['nick']) ?></small><?php endif ?><?php if ($p['race'] !== ''): ?> <span class="cg-race"><?= h($p['race']) ?></span><?php endif ?></span></div>
<?php endforeach ?>
  </div>
  <div class="pv-rows">
<?php foreach ($view['rows'] as $r): ?>
    <div class="pv-row pv-<?= h($r['key']) ?>">
      <span class="pv-side pv-a<?= ($r['lead'] ?? '') === 'a' ? ' is-lead' : '' ?>"><?= $side($r, 'a') ?></span>
      <span class="pv-label<?= $r['kind'] === 'note' ? ' is-note' : '' ?>"><span class="cg-fit"><?= h($r['kind'] === 'note' ? $r['text'] : $r['label']) ?></span></span>
      <span class="pv-side pv-b<?= ($r['lead'] ?? '') === 'b' ? ' is-lead' : '' ?>"><?= $side($r, 'b') ?></span>
    </div>
<?php endforeach ?>
  </div>
<?php if (!empty($view['mock'])): ?>
  <div class="cg-mock">MOCK</div>
<?php endif ?>
</div>
