<?php
/**
 * 채팅 검색
 */
require __DIR__ . '/app/bootstrap.php';

require_login();
$b = load_broadcast(input_int('id'));
$id = (int) $b['id'];

$q = input_str('q', '', 100);
$user = input_str('user', '', 100);
$from = input_datetime('from');
$to = input_datetime('to');
$perPage = 100;
$order = input_str('order', 'asc', 4) === 'desc' ? 'desc' : 'asc';  // 기본: 시간순(SOOP 채팅창과 같은 방향)

$where = 'broadcast_id = ?';
$params = [$id];
if ($q !== '') {
    $where .= ' AND message LIKE ?';
    $params[] = '%' . $q . '%';
}
if ($user !== '') {
    $where .= ' AND (user_id = ? OR nickname LIKE ?)';
    $params[] = $user;
    $params[] = '%' . $user . '%';
}
if ($from) {
    $where .= ' AND sent_at >= ?';
    $params[] = $from;
}
if ($to) {
    $where .= ' AND sent_at <= ?';
    $params[] = $to;
}
$total = (int) db_value("SELECT COUNT(*) FROM chat_messages WHERE $where", $params);
$lastPage = max(1, (int) ceil($total / $perPage));
$page = min($lastPage, max(1, input_int('page', 1)));
$dir = $order === 'desc' ? 'DESC' : 'ASC';
$rows = db_all(
    "SELECT sent_at, user_id, raw_user_id, nickname, message, kind, badges FROM chat_messages WHERE $where ORDER BY sent_at $dir, id $dir LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);
$exportQuery = http_build_query(array_filter(['type' => 'chats', 'id' => $id, 'q' => $q, 'user' => $user, 'from' => input_str('from'), 'to' => input_str('to')]));

page_header('채팅 검색 · ' . $b['title'], ['menu' => 'broadcasts', 'broadcast' => $b, 'tab' => 'chats', 'wide' => true]);
?>
<form class="filters" method="get">
  <input type="hidden" name="id" value="<?= $id ?>">
  <label>내용 <input type="search" name="q" value="<?= h($q) ?>" placeholder="채팅 내용 검색"></label>
  <label>시청자 <input type="search" name="user" value="<?= h($user) ?>" placeholder="아이디 또는 닉네임"></label>
  <?= range_inputs() ?>
  <button class="btn primary">검색</button>
  <a class="btn" href="chats.php?id=<?= $id ?>">초기화</a>
  <a class="btn" href="export.php?<?= h($exportQuery) ?>">CSV 내려받기</a>
</form>

<div class="list-head">
  <span class="muted small">검색 결과 <?= fmt_num($total) ?>건</span>
  <nav class="subtabs">
    <a href="<?= h(url_with(['order' => null, 'page' => null])) ?>" class="<?= $order === 'asc' ? 'active' : '' ?>">시간순</a>
    <a href="<?= h(url_with(['order' => 'desc', 'page' => null])) ?>" class="<?= $order === 'desc' ? 'active' : '' ?>">최신순</a>
  </nav>
  <?php if ($order === 'asc' && $lastPage > 1 && $page !== $lastPage): ?>
    <a class="btn small" href="<?= h(url_with(['page' => $lastPage])) ?>">마지막 페이지(최근 채팅) ↓</a>
  <?php endif; ?>
</div>
<div class="card flush">
<table class="table chat-table">
  <thead><tr><th>시각</th><th>시청자</th><th>내용</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="nowrap muted"><?= fmt_dt($r['sent_at'], 'm-d H:i:s') ?></td>
      <td class="nowrap"><?= render_nick($r['nickname'], (int) $r['badges'], 'viewer.php?id=' . $id . '&user=' . urlencode($r['user_id'])) ?> <span class="muted small"><?= h($r['raw_user_id']) ?></span></td>
      <td><?= $r['kind'] === 'emoticon' && $r['message'] !== '[이모티콘]' ? '<span class="muted">[이모티콘]</span> ' : '' ?><?= h($r['message']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="3" class="empty">조건에 맞는 채팅이 없습니다.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?= pagination($total, $page, $perPage) ?>
<?php
page_footer();
