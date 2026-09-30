<?php
/**
 * 후원 순위 · 후원 기록
 */
require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/stats.php';
require APP_DIR . '/prizes.php';

require_login();
$b = load_broadcast(input_int('id'));
$id = (int) $b['id'];
$view = input_str('view', 'rank', 10) === 'log' ? 'log' : 'rank';
$f = stats_filters();
$perPage = 100;
$page = max(1, input_int('page', 1));

page_header('후원 · ' . $b['title'], ['menu' => 'broadcasts', 'broadcast' => $b, 'tab' => 'donations', 'wide' => true]);
?>
<nav class="subtabs">
  <a href="donations.php?id=<?= $id ?>" class="<?= $view === 'rank' ? 'active' : '' ?>">후원 순위</a>
  <a href="donations.php?id=<?= $id ?>&view=log" class="<?= $view === 'log' ? 'active' : '' ?>">후원 기록 (전체 내역)</a>
</nav>
<?php
// ── 후원 순위 ──────────────────────────────────────────────
if ($view === 'rank'):
    $sort = input_str('sort', 'balloon', 20);
    $sort = isset(DONATION_SORTS[$sort]) ? $sort : 'balloon';
    $min = max(0, input_int('min', 0));
    $data = donation_ranking($id, $f, $sort, $min, $perPage, ($page - 1) * $perPage);
    $winners = winners_by_user(array_column($data['rows'], 'user_id'));
    $badgeMap = user_badges($id, array_column($data['rows'], 'user_id'));
    $maxVal = $data['rows'] ? max(array_map(fn($r) => (int) $r[DONATION_SORTS[$sort][0] === 'last_at' ? 'balloons' : DONATION_SORTS[$sort][0]], $data['rows'])) : 0;
    $exportQuery = http_build_query(array_filter(['type' => 'donations', 'id' => $id, 'sort' => $sort, 'min' => $min ?: null, 'from' => input_str('from'), 'to' => input_str('to'), 'q' => $f['q'], 'submitted' => 1, 'exclude' => $f['exclude'] ? 1 : null]));
    ?>
<form class="filters" method="get">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="submitted" value="1">
  <label>정렬 <select name="sort">
    <?php foreach (DONATION_SORTS as $k => [, $label]): ?><option value="<?= h($k) ?>" <?= $sort === $k ? 'selected' : '' ?>><?= h($label) ?> 많은 순</option><?php endforeach; ?>
  </select></label>
  <label>최소 별풍선(개) <input type="number" name="min" min="0" value="<?= $min ?: '' ?>" placeholder="0" class="w-num"></label>
  <?= range_inputs() ?>
  <label>시청자 <input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="아이디 또는 닉네임"></label>
  <label class="check"><input type="checkbox" name="exclude" value="1" <?= $f['exclude'] ? 'checked' : '' ?>> 제외 명단·방송인·매니저 빼기</label>
  <button class="btn primary">조회</button>
  <a class="btn" href="export.php?<?= h($exportQuery) ?>">CSV 내려받기</a>
</form>

<div class="tiles small-tiles">
  <div class="tile"><div class="tile-label">후원자</div><div class="tile-value"><?= fmt_num($data['total']) ?>명</div></div>
  <div class="tile"><div class="tile-label">별풍선</div><div class="tile-value"><?= fmt_num($data['sums']['balloons']) ?></div></div>
  <div class="tile"><div class="tile-label">애드벌룬</div><div class="tile-value"><?= fmt_num($data['sums']['adballoons']) ?></div></div>
  <div class="tile"><div class="tile-label">구독 / 구독 선물</div><div class="tile-value"><?= fmt_num($data['sums']['subs']) ?> / <?= fmt_num($data['sums']['gifts']) ?></div></div>
</div>

<form method="post" action="prize_edit.php">
<div class="card flush">
<table class="table">
  <thead><tr>
    <th class="chk"><input type="checkbox" data-check-all="pick[]" title="전체 선택"></th>
    <th class="num">순위</th><th>시청자</th>
    <th class="num">별풍선</th><th class="num">애드벌룬</th><th class="num">구독</th><th class="num">구독 선물</th>
    <th>첫 후원</th><th>마지막 후원</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($data['rows'] as $i => $r): $rank = ($page - 1) * $perPage + $i + 1; ?>
    <tr>
      <td class="chk"><input type="checkbox" name="pick[]" value="<?= h($r['user_id']) ?>"><input type="hidden" name="nick[<?= h($r['user_id']) ?>]" value="<?= h($r['nickname']) ?>"></td>
      <td class="num rank"><?= rank_label($rank) ?></td>
      <td class="actcell"<?= actbar_attr((int) $r[DONATION_SORTS[$sort][0] === 'last_at' ? 'balloons' : DONATION_SORTS[$sort][0]], $maxVal) ?>><?= render_nick($r['nickname'], $badgeMap[$r['user_id']] ?? 0, 'viewer.php?id=' . $id . '&user=' . urlencode($r['user_id'])) ?> <span class="muted small"><?= h($r['user_id']) ?></span>
        <?= render_wins($winners[$r['user_id']] ?? []) ?></td>
      <td class="num strong"><?= fmt_num($r['balloons']) ?></td>
      <td class="num"><?= fmt_num($r['adballoons']) ?></td>
      <td class="num"><?= fmt_num($r['subs']) ?></td>
      <td class="num"><?= fmt_num($r['gifts']) ?></td>
      <td class="nowrap muted small"><?= fmt_dt($r['first_at']) ?></td>
      <td class="nowrap muted small"><?= fmt_dt($r['last_at']) ?></td>
      <td><a class="btn small" href="prize_edit.php?broadcast_id=<?= $id ?>&user_id=<?= urlencode($r['user_id']) ?>&nickname=<?= urlencode($r['nickname']) ?>&reason=<?= urlencode('후원 순위 ' . $rank . '위') ?>">당첨 등록</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$data['rows']): ?><tr><td colspan="10" class="empty">조건에 맞는 후원 기록이 없습니다.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?= pagination($data['total'], $page, $perPage) ?>
<?php if ($data['rows']): ?><?= bulk_prize_fields($id, '후원 순위') ?><?php endif; ?>
</form>

<p class="muted small">
  별풍선에는 일반·중계방·영상풍선·도전미션·대결미션 후원이 모두 포함됩니다. 구독은 구독·연속 구독 알림 건수, 구독 선물은 선물 받은 사람 수 기준입니다.
  같은 계정이 여러 곳에서 접속해 아이디 뒤에 (2) 등이 붙은 경우는 하나로 합산합니다.
</p>

<?php
// ── 후원 기록 ──────────────────────────────────────────────
else:
    $type = input_str('type', '', 20);
    $params = [$id];
    $where = 'broadcast_id = ?' . time_where($f, $params);
    if (in_array($type, ['balloon', 'adballoon', 'subscription'], true)) {
        $where .= ' AND type = ?';
        $params[] = $type;
    }
    if ($f['q'] !== '') {
        $where .= ' AND (user_id = ? OR nickname LIKE ? OR target_user_id = ?)';
        array_push($params, $f['q'], '%' . $f['q'] . '%', $f['q']);
    }
    $total = (int) db_value("SELECT COUNT(*) FROM donations WHERE $where", $params);
    $rows = db_all("SELECT * FROM donations WHERE $where ORDER BY sent_at DESC, id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
    $exportQuery = http_build_query(array_filter(['type' => 'donation_log', 'id' => $id, 'dtype' => $type, 'from' => input_str('from'), 'to' => input_str('to'), 'q' => $f['q']]));
    ?>
<form class="filters" method="get">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input type="hidden" name="view" value="log">
  <label>종류 <select name="type">
    <option value="">전체</option>
    <option value="balloon" <?= $type === 'balloon' ? 'selected' : '' ?>>별풍선</option>
    <option value="adballoon" <?= $type === 'adballoon' ? 'selected' : '' ?>>애드벌룬</option>
    <option value="subscription" <?= $type === 'subscription' ? 'selected' : '' ?>>구독</option>
  </select></label>
  <?= range_inputs() ?>
  <label>시청자 <input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="아이디 또는 닉네임"></label>
  <button class="btn primary">조회</button>
  <a class="btn" href="export.php?<?= h($exportQuery) ?>">CSV 내려받기</a>
</form>
<p class="muted small">총 <?= fmt_num($total) ?>건 · 최신순</p>
<div class="card flush">
<table class="table">
  <thead><tr><th>시각</th><th>종류</th><th class="num">수량</th><th>시청자</th><th>받은 사람 / 내용</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="nowrap muted"><?= fmt_dt($r['sent_at']) ?></td>
      <td><span class="dtype dtype-<?= h($r['type']) ?>"><?= h(donation_type_label($r['type'], $r['subtype'])) ?></span></td>
      <td class="num"><?= h(donation_amount_label($r)) ?></td>
      <td><?php if ($r['user_id'] !== ''): ?><a href="viewer.php?id=<?= $id ?>&user=<?= urlencode($r['user_id']) ?>"><?= h($r['nickname']) ?></a> <span class="muted small"><?= h($r['raw_user_id']) ?></span><?php else: ?><span class="muted">(알 수 없음)</span><?php endif; ?></td>
      <td><?= $r['target_nickname'] !== '' ? '→ ' . h($r['target_nickname']) . ' <span class="muted small">' . h($r['target_user_id']) . '</span>' : '' ?><?= $r['extra'] !== '' ? ' <span class="muted">' . h($r['extra']) . '</span>' : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="empty">후원 기록이 없습니다.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?= pagination($total, $page, $perPage) ?>
<?php endif; ?>
<?php
page_footer();
