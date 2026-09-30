<?php
/**
 * 상품 지급 목록
 */
require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/prizes.php';

$admin = require_login();
purge_expired_pii();

// ── 선택 항목 상태 한 번에 바꾸기 ──────────────────────────
if (is_post() && input_str('action') === 'bulk_status') {
    csrf_check();
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $status = input_str('status', '', 20);
    if ($ids && isset(PRIZE_STATUSES[$status])) {
        $in = db_placeholders($ids);
        if ($status === 'paid') {
            db_exec("UPDATE prizes SET status = 'paid', paid_at = COALESCE(paid_at, ?), updated_at = ? WHERE id IN ($in)", array_merge([now(), now()], $ids));
        } else {
            db_exec("UPDATE prizes SET status = ?, paid_at = NULL, updated_at = ? WHERE id IN ($in)", array_merge([$status, now()], $ids));
        }
        audit('prize_bulk_status', '', count($ids) . '건 → ' . PRIZE_STATUSES[$status]);
        flash('success', count($ids) . '건을 [' . PRIZE_STATUSES[$status] . '] 상태로 바꿨습니다.');
    } else {
        flash('error', '바꿀 항목과 상태를 선택해 주세요.');
    }
    redirect(safe_back('prizes.php'));
}

$broadcastId = input_int('broadcast_id');
$status = input_str('status', '', 20);
$q = input_str('q', '', 100);
$perPage = 50;
$page = max(1, input_int('page', 1));

$where = '1';
$params = [];
if ($broadcastId) {
    $where .= ' AND p.broadcast_id = ?';
    $params[] = $broadcastId;
}
if (isset(PRIZE_STATUSES[$status])) {
    $where .= ' AND p.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where .= ' AND (p.user_id LIKE ? OR p.nickname LIKE ? OR p.prize_name LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
$total = (int) db_value("SELECT COUNT(*) FROM prizes p WHERE $where", $params);
$rows = db_all(
    "SELECT p.*, b.title, b.broadcast_date FROM prizes p LEFT JOIN broadcasts b ON b.id = p.broadcast_id
     WHERE $where ORDER BY p.created_at DESC, p.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);
$counts = [];
foreach (db_all('SELECT status, COUNT(*) AS cnt FROM prizes' . ($broadcastId ? ' WHERE broadcast_id = ?' : '') . ' GROUP BY status', $broadcastId ? [$broadcastId] : []) as $r) {
    $counts[$r['status']] = (int) $r['cnt'];
}
$broadcasts = db_all('SELECT id, title, broadcast_date FROM broadcasts ORDER BY broadcast_date DESC, id DESC LIMIT 200');
$exportQuery = http_build_query(array_filter(['type' => 'prizes', 'broadcast_id' => $broadcastId ?: null, 'status' => $status, 'q' => $q]));

page_header('상품 지급', ['menu' => 'prizes', 'wide' => true]);
?>
<div class="page-head">
  <h1>상품 지급</h1>
  <a class="btn primary" href="prize_edit.php<?= $broadcastId ? '?broadcast_id=' . $broadcastId : '' ?>">+ 당첨 등록</a>
</div>

<div class="tiles small-tiles">
  <?php foreach (PRIZE_STATUSES as $k => $label): ?>
    <a class="tile link <?= $status === $k ? 'selected' : '' ?>" href="<?= h(url_with(['status' => $status === $k ? null : $k, 'page' => null])) ?>">
      <div class="tile-label"><?= h($label) ?></div><div class="tile-value"><?= fmt_num($counts[$k] ?? 0) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<form class="filters" method="get">
  <label>방송 회차
    <select name="broadcast_id" data-autosubmit>
      <option value="">전체</option>
      <?php foreach ($broadcasts as $bc): ?>
        <option value="<?= (int) $bc['id'] ?>" <?= $broadcastId === (int) $bc['id'] ? 'selected' : '' ?>><?= h($bc['broadcast_date'] . ' ' . $bc['title']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>상태
    <select name="status" data-autosubmit>
      <option value="">전체</option>
      <?php foreach (PRIZE_STATUSES as $k => $label): ?><option value="<?= h($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label>검색 <input type="search" name="q" value="<?= h($q) ?>" placeholder="아이디·닉네임·상품명"></label>
  <button class="btn primary">조회</button>
  <a class="btn" href="export.php?<?= h($exportQuery) ?>">CSV 내려받기<?= $admin['role'] === 'admin' ? '' : ' (정보 가림)' ?></a>
</form>

<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="action" value="bulk_status">
<div class="card flush">
<table class="table">
  <thead><tr>
    <th class="chk"><input type="checkbox" data-check-all="ids[]" title="전체 선택"></th>
    <th>회차</th><th>시청자</th><th>선정 사유</th><th>상품</th><th>상태</th><th>수령자</th><th>기한</th><th>등록</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $p):
      $overdue = $p['due_date'] && in_array($p['status'], ['pending'], true) && $p['due_date'] < date('Y-m-d');
      $name = mask_name(pii_decrypt($p['recipient_name']));
      $phone = mask_phone(pii_decrypt($p['recipient_phone']));
      $addr = $p['recipient_address'] ? '주소 있음' : '';
  ?>
    <tr>
      <td class="chk"><input type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>"></td>
      <td class="small"><?= $p['title'] ? h($p['broadcast_date'] . ' ' . $p['title']) : '<span class="muted">-</span>' ?></td>
      <td><?php if ($p['broadcast_id']): ?><a href="viewer.php?id=<?= (int) $p['broadcast_id'] ?>&user=<?= urlencode($p['user_id']) ?>"><?= h($p['nickname']) ?></a><?php else: ?><?= h($p['nickname']) ?><?php endif; ?>
        <span class="muted small"><?= h($p['user_id']) ?></span></td>
      <td class="small"><?= h($p['reason']) ?></td>
      <td><?= h($p['prize_name']) ?> <span class="muted small"><?= h(PRIZE_TYPES[$p['prize_type']] ?? '') ?></span></td>
      <td><?= prize_status_badge($p['status']) ?></td>
      <td class="small"><?php if ($p['purged_at']): ?><span class="muted">파기됨</span><?php else: ?><?= h(trim($name . ' ' . $phone . ' ' . $addr)) ?: '<span class="muted">-</span>' ?><?php endif; ?></td>
      <td class="nowrap small <?= $overdue ? 'bad' : '' ?>"><?= h($p['due_date'] ?? '') ?></td>
      <td class="nowrap muted small"><?= fmt_dt($p['created_at'], 'm-d H:i') ?></td>
      <td><a class="btn small" href="prize_edit.php?id=<?= (int) $p['id'] ?>">수정</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="10" class="empty">지급 기록이 없습니다. 후원 순위·채팅 활동량 화면에서 [당첨 등록]을 누르거나 오른쪽 위 버튼으로 등록하세요.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?= pagination($total, $page, $perPage) ?>
<?php if ($rows): ?>
<div class="bulk-bar">
  <strong>선택 항목 상태 변경</strong>
  <select name="status">
    <?php foreach (PRIZE_STATUSES as $k => $label): ?><option value="<?= h($k) ?>"><?= h($label) ?></option><?php endforeach; ?>
  </select>
  <button class="btn" data-require-check="ids[]">적용</button>
</div>
<?php endif; ?>
</form>
<p class="muted small">수령자 정보는 목록에서 가려서 표시합니다. 지급 완료·기한 초과 후 <?= (int) config('privacy_retention_days', 30) ?>일이 지나면 자동 파기됩니다.</p>
<?php
page_footer();
