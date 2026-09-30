<?php
/**
 * 채팅 활동량 순위
 */
require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/stats.php';
require APP_DIR . '/prizes.php';

require_login();
$b = load_broadcast(input_int('id'));
$id = (int) $b['id'];
$f = stats_filters();
$rule = activity_rule_from_request();
$sort = input_str('sort', 'effective', 20);
$sort = isset(ACTIVITY_SORTS[$sort]) ? $sort : 'effective';
$min = max(0, input_int('min', 0));
$perPage = 100;
$page = max(1, input_int('page', 1));

$data = activity_ranking($id, $f, $rule, $sort, $min, $perPage, ($page - 1) * $perPage);
$winners = existing_winners($id, array_column($data['rows'], 'user_id'));
$exportQuery = http_build_query(array_filter([
    'type' => 'activity', 'id' => $id, 'sort' => $sort, 'min' => $min ?: null, 'from' => input_str('from'), 'to' => input_str('to'),
    'q' => $f['q'], 'interval' => $rule['interval'], 'repeat' => $rule['repeat'], 'slot' => $rule['slot'], 'submitted' => 1, 'exclude' => $f['exclude'] ? 1 : null,
], fn($v) => $v !== null && $v !== ''));

page_header('채팅 활동량 · ' . $b['title'], ['menu' => 'broadcasts', 'broadcast' => $b, 'tab' => 'activity', 'wide' => true]);
?>
<form class="filters" method="get">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="submitted" value="1">
  <label>정렬 <select name="sort">
    <?php foreach (ACTIVITY_SORTS as $k => $label): ?><option value="<?= h($k) ?>" <?= $sort === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
  </select></label>
  <label>최소 인정 채팅(건) <input type="number" name="min" min="0" value="<?= $min ?: '' ?>" placeholder="0" class="w-num"></label>
  <?= range_inputs() ?>
  <label>시청자 <input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="아이디 또는 닉네임"></label>
  <label class="check"><input type="checkbox" name="exclude" value="1" <?= $f['exclude'] ? 'checked' : '' ?>> 제외 명단·방송인·매니저 빼기</label>
  <details class="rule-box">
    <summary>도배 제외 기준 (연속 <?= $rule['interval'] ?>초 · 같은 내용 <?= $rule['repeat'] ?>초 · 구간 <?= $rule['slot'] ?>분)</summary>
    <label>직전 채팅 후 <input type="number" name="interval" min="0" max="600" value="<?= $rule['interval'] ?>" class="w-num"> 초 안에 친 채팅은 제외</label>
    <label>같은 내용을 <input type="number" name="repeat" min="0" max="3600" value="<?= $rule['repeat'] ?>" class="w-num"> 초 안에 다시 치면 제외</label>
    <label>활동 구간 단위 <input type="number" name="slot" min="1" max="240" value="<?= $rule['slot'] ?>" class="w-num"> 분</label>
  </details>
  <button class="btn primary">조회</button>
  <a class="btn" href="export.php?<?= h($exportQuery) ?>">CSV 내려받기</a>
</form>

<div class="tiles small-tiles">
  <div class="tile"><div class="tile-label">참여자</div><div class="tile-value"><?= fmt_num($data['total']) ?>명</div></div>
  <div class="tile"><div class="tile-label">전체 채팅</div><div class="tile-value"><?= fmt_num($data['sum_total']) ?></div></div>
  <div class="tile"><div class="tile-label">인정 채팅 (도배 제외)</div><div class="tile-value"><?= fmt_num($data['sum_effective']) ?></div></div>
</div>

<form method="post" action="prize_edit.php">
<div class="card flush">
<table class="table">
  <thead><tr>
    <th class="chk"><input type="checkbox" data-check-all="pick[]" title="전체 선택"></th>
    <th class="num">순위</th><th>시청자</th>
    <th class="num">인정 채팅</th><th class="num">전체 채팅</th><th class="num">활동 구간</th>
    <th>첫 채팅</th><th>마지막 채팅</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($data['rows'] as $i => $r): $rank = ($page - 1) * $perPage + $i + 1; ?>
    <tr>
      <td class="chk"><input type="checkbox" name="pick[]" value="<?= h($r['user_id']) ?>"><input type="hidden" name="nick[<?= h($r['user_id']) ?>]" value="<?= h($r['nickname']) ?>"></td>
      <td class="num rank"><?= $rank ?></td>
      <td><?= render_badges((int) $r['badges']) ?><a href="viewer.php?id=<?= $id ?>&user=<?= urlencode($r['user_id']) ?>"><?= h($r['nickname']) ?></a> <span class="muted small"><?= h($r['user_id']) ?></span>
        <?php if (isset($winners[$r['user_id']])): ?><span class="badge badge-won" title="<?= h(implode(', ', $winners[$r['user_id']])) ?>">당첨 등록됨</span><?php endif; ?></td>
      <td class="num strong"><?= fmt_num($r['effective']) ?></td>
      <td class="num"><?= fmt_num($r['total']) ?></td>
      <td class="num"><?= fmt_num($r['slots']) ?></td>
      <td class="nowrap muted small"><?= fmt_dt($r['first_at']) ?></td>
      <td class="nowrap muted small"><?= fmt_dt($r['last_at']) ?></td>
      <td><a class="btn small" href="prize_edit.php?broadcast_id=<?= $id ?>&user_id=<?= urlencode($r['user_id']) ?>&nickname=<?= urlencode($r['nickname']) ?>&reason=<?= urlencode('채팅 활동 ' . $rank . '위') ?>">당첨 등록</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$data['rows']): ?><tr><td colspan="9" class="empty">조건에 맞는 채팅 기록이 없습니다.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?= pagination($data['total'], $page, $perPage) ?>
<?php if ($data['rows']): ?><?= bulk_prize_fields($id, '채팅 활동') ?><?php endif; ?>
</form>

<div class="card muted small">
  <strong>집계 기준</strong>
  <ul>
    <li><strong>인정 채팅</strong>: 도배를 뺀 채팅 수입니다. 직전에 인정된 채팅 후 <?= $rule['interval'] ?>초 안에 친 채팅, 같은 내용을 <?= $rule['repeat'] ?>초 안에 다시 친 채팅은 세지 않습니다.</li>
    <li><strong>활동 구간</strong>: 방송 시간을 <?= $rule['slot'] ?>분 단위로 나눴을 때 인정 채팅이 1건 이상 있는 구간 수입니다. 긴 방송 동안 꾸준히 참여했는지 볼 때 씁니다.</li>
    <li>같은 계정이 여러 곳에서 접속해 아이디 뒤에 (2) 등이 붙은 경우는 하나로 합산합니다.</li>
  </ul>
</div>
<?php
page_footer();
