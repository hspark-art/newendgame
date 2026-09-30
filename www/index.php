<?php
/**
 * 방송 회차 목록
 */
require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/prizes.php';
require APP_DIR . '/stats.php';

require_login();
purge_expired_pii();

$perPage = 20;
$page = max(1, input_int('page', 1));
$q = input_str('q', '', 100);

$where = '1';
$params = [];
if ($q !== '') {
    $where .= ' AND (title LIKE ? OR streamer_id LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
}
$total = (int) db_value("SELECT COUNT(*) FROM broadcasts WHERE $where", $params);
$rows = db_all(
    "SELECT b.*,
        (SELECT MAX(last_seen_at) FROM collectors c WHERE c.broadcast_id = b.id AND c.status = 'live') AS live_seen,
        (SELECT COUNT(*) FROM prizes p WHERE p.broadcast_id = b.id) AS prize_count
     FROM broadcasts b WHERE $where ORDER BY broadcast_date DESC, id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);
// 참여 인원·별풍선 합 (회차별 시청자 요약에서, 수집 중인 회차는 1분마다 다시 계산)
$pending = array_flip(refresh_broadcast_users(array_column($rows, 'id'), 60, 5));
$sums = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    foreach (db_all('SELECT broadcast_id, COUNT(*) AS users, SUM(balloons) AS balloons FROM broadcast_users WHERE broadcast_id IN (' . db_placeholders($ids) . ') GROUP BY broadcast_id', $ids) as $r) {
        $sums[(int) $r['broadcast_id']] = $r;
    }
}

page_header('방송 회차', ['menu' => 'broadcasts']);
?>
<div class="page-head">
  <h1>방송 회차</h1>
  <a class="btn primary" href="broadcast.php?action=new">+ 새 회차 등록</a>
</div>

<form class="filters" method="get">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="회차 제목 또는 방송국 ID">
  <button class="btn">검색</button>
</form>

<div class="card flush">
<table class="table">
  <thead>
    <tr><th>방송일</th><th>제목</th><th>방송국 ID</th><th class="num">채팅</th><th class="num">참여 인원</th><th class="num">별풍선 합</th><th class="num">후원 기록</th><th class="num">지급 등록</th><th>수집 상태</th></tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $b): ?>
    <?php $live = $b['live_seen'] && strtotime($b['live_seen']) > time() - 60; ?>
    <tr>
      <td><?= h($b['broadcast_date']) ?></td>
      <td><a href="broadcast.php?id=<?= (int) $b['id'] ?>"><strong><?= h($b['title']) ?></strong></a></td>
      <td><?= h($b['streamer_id']) ?></td>
      <td class="num"><?= fmt_num($b['chat_count']) ?></td>
      <?php $sum = $sums[(int) $b['id']] ?? null; ?>
      <td class="num"><?= $b['users_sig'] === null && isset($pending[(int) $b['id']]) ? '<span class="muted" title="아직 집계 전입니다. 잠시 뒤 새로고침하세요.">…</span>' : fmt_num($sum['users'] ?? 0) ?></td>
      <td class="num"><?= $sum && $sum['balloons'] ? fmt_num($sum['balloons']) . '개' : '<span class="muted">0</span>' ?></td>
      <td class="num"><?= fmt_num($b['donation_count']) ?></td>
      <td class="num"><?= fmt_num($b['prize_count']) ?></td>
      <td><?= $live ? '<span class="dot live"></span> 수집 중' : '<span class="muted">-</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?>
    <tr><td colspan="9" class="empty">등록된 방송 회차가 없습니다. 오른쪽 위 [+ 새 회차 등록]으로 시작하세요.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>
<?= pagination($total, $page, $perPage) ?>
<?php
page_footer();
