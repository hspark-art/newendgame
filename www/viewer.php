<?php
/**
 * 시청자 상세 (한 사람의 채팅·후원·당첨 내역)
 */
require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/stats.php';
require APP_DIR . '/prizes.php';

require_login();
$b = load_broadcast(input_int('id'));
$id = (int) $b['id'];
$user = input_str('user', '', 64);
if ($user === '') {
    render_error('시청자 아이디가 없습니다.');
}

$chatInfo = db_one(
    'SELECT COUNT(*) AS cnt, MIN(sent_at) AS first_at, MAX(sent_at) AS last_at, BIT_OR(badges) AS badges FROM chat_messages WHERE broadcast_id = ? AND user_id = ?',
    [$id, $user]
);
$nicknames = array_column(db_all(
    'SELECT nickname FROM (SELECT nickname, MAX(sent_at) AS t FROM chat_messages WHERE broadcast_id = ? AND user_id = ? GROUP BY nickname
      UNION ALL SELECT nickname, MAX(sent_at) AS t FROM donations WHERE broadcast_id = ? AND user_id = ? GROUP BY nickname) x
     GROUP BY nickname ORDER BY MAX(t) DESC',
    [$id, $user, $id, $user]
), 'nickname');
$rawIds = array_column(db_all(
    'SELECT DISTINCT raw_user_id FROM chat_messages WHERE broadcast_id = ? AND user_id = ? UNION SELECT DISTINCT raw_user_id FROM donations WHERE broadcast_id = ? AND user_id = ?',
    [$id, $user, $id, $user]
), 'raw_user_id');
$donations = db_all('SELECT * FROM donations WHERE broadcast_id = ? AND user_id = ? ORDER BY sent_at', [$id, $user]);
$sum = ['balloon' => 0, 'adballoon' => 0, 'subs' => 0, 'gifts' => 0];
foreach ($donations as $d) {
    if ($d['type'] === 'balloon') $sum['balloon'] += (int) $d['amount'];
    elseif ($d['type'] === 'adballoon') $sum['adballoon'] += (int) $d['amount'];
    elseif (in_array($d['subtype'], ['new', 'renew'], true)) $sum['subs']++;
    elseif ($d['subtype'] === 'gift') $sum['gifts']++;
}
$giftsReceived = db_all("SELECT * FROM donations WHERE broadcast_id = ? AND target_user_id = ? ORDER BY sent_at", [$id, $user]);
$prizes = db_all('SELECT p.*, b.title FROM prizes p LEFT JOIN broadcasts b ON b.id = p.broadcast_id WHERE p.user_id = ? ORDER BY p.created_at DESC', [$user]);
$history = db_all(
    "SELECT d.broadcast_id, b.title, b.broadcast_date, SUM(CASE WHEN d.type = 'balloon' THEN d.amount ELSE 0 END) AS balloons,
            SUM(CASE WHEN d.type = 'adballoon' THEN d.amount ELSE 0 END) AS adballoons
     FROM donations d JOIN broadcasts b ON b.id = d.broadcast_id
     WHERE d.user_id = ? AND d.broadcast_id <> ? GROUP BY d.broadcast_id, b.title, b.broadcast_date ORDER BY b.broadcast_date DESC LIMIT 20",
    [$user, $id]
);
$excluded = db_one('SELECT * FROM excluded_users WHERE user_id = ?', [$user]);

$perPage = 200;
$page = max(1, input_int('page', 1));
$chats = db_all(
    "SELECT sent_at, nickname, message, kind FROM chat_messages WHERE broadcast_id = ? AND user_id = ? ORDER BY sent_at LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    [$id, $user]
);
$nickname = $nicknames[0] ?? '';

page_header($nickname . ' · 시청자 상세', ['menu' => 'broadcasts', 'broadcast' => $b, 'tab' => '', 'wide' => true]);
?>
<div class="page-head">
  <div>
    <h1><?= render_badges((int) ($chatInfo['badges'] ?? 0)) ?><?= h($nickname !== '' ? $nickname : $user) ?> <span class="muted"><?= h($user) ?></span></h1>
    <?php if (count($nicknames) > 1): ?><p class="muted small">사용한 닉네임: <?= h(implode(', ', $nicknames)) ?></p><?php endif; ?>
    <?php if (count($rawIds) > 1): ?><p class="muted small">접속 아이디: <?= h(implode(', ', $rawIds)) ?> (하나로 합산)</p><?php endif; ?>
    <?php if ($excluded): ?><p><span class="badge badge-excluded">제외 명단</span> <span class="muted small"><?= h($excluded['reason']) ?></span></p><?php endif; ?>
  </div>
  <div class="actions">
    <a class="btn primary" href="prize_edit.php?broadcast_id=<?= $id ?>&user_id=<?= urlencode($user) ?>&nickname=<?= urlencode($nickname) ?>">당첨 등록</a>
    <?php if (!$excluded): ?>
      <form method="post" action="excluded.php" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="user_id" value="<?= h($user) ?>">
        <input type="hidden" name="nickname" value="<?= h($nickname) ?>">
        <input type="hidden" name="reason" value="시청자 상세에서 추가">
        <input type="hidden" name="back" value="viewer.php?id=<?= $id ?>&user=<?= h(urlencode($user)) ?>">
        <button class="btn" data-confirm="이 시청자를 집계 제외 명단에 추가할까요?">제외 명단에 추가</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="tiles">
  <div class="tile"><div class="tile-label">채팅</div><div class="tile-value"><?= fmt_num($chatInfo['cnt']) ?></div><div class="tile-sub"><?= fmt_dt($chatInfo['first_at']) ?> ~ <?= fmt_dt($chatInfo['last_at']) ?></div></div>
  <div class="tile"><div class="tile-label">별풍선</div><div class="tile-value"><?= fmt_num($sum['balloon']) ?></div></div>
  <div class="tile"><div class="tile-label">애드벌룬</div><div class="tile-value"><?= fmt_num($sum['adballoon']) ?></div></div>
  <div class="tile"><div class="tile-label">구독 / 구독 선물</div><div class="tile-value"><?= fmt_num($sum['subs']) ?> / <?= fmt_num($sum['gifts']) ?></div></div>
</div>

<div class="grid2">
  <div class="card">
    <h2>후원 내역 (이번 회차)</h2>
    <table class="table compact">
      <?php foreach ($donations as $d): ?>
        <tr><td class="nowrap muted small"><?= fmt_dt($d['sent_at']) ?></td><td><?= h(donation_type_label($d['type'], $d['subtype'])) ?></td><td class="num"><?= h(donation_amount_label($d)) ?></td>
          <td class="small"><?= $d['target_nickname'] !== '' ? '→ ' . h($d['target_nickname']) : '' ?> <?= h($d['extra']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$donations): ?><tr><td class="empty">후원 기록이 없습니다.</td></tr><?php endif; ?>
    </table>
    <?php if ($giftsReceived): ?>
      <h3>받은 구독 선물</h3>
      <table class="table compact">
        <?php foreach ($giftsReceived as $d): ?>
          <tr><td class="nowrap muted small"><?= fmt_dt($d['sent_at']) ?></td><td><?= h($d['nickname']) ?> <span class="muted small"><?= h($d['user_id']) ?></span> 님이 선물</td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2>당첨·지급 기록 (전체 회차)</h2>
    <table class="table compact">
      <?php foreach ($prizes as $p): ?>
        <tr><td class="small"><?= h($p['title'] ?? '-') ?></td><td><?= h($p['prize_name']) ?></td><td><?= prize_status_badge($p['status']) ?></td><td><a href="prize_edit.php?id=<?= (int) $p['id'] ?>">보기</a></td></tr>
      <?php endforeach; ?>
      <?php if (!$prizes): ?><tr><td class="empty">당첨 기록이 없습니다.</td></tr><?php endif; ?>
    </table>
    <h3>다른 회차 후원</h3>
    <table class="table compact">
      <?php foreach ($history as $hrow): ?>
        <tr><td class="small"><?= h($hrow['broadcast_date']) ?></td><td><a href="viewer.php?id=<?= (int) $hrow['broadcast_id'] ?>&user=<?= urlencode($user) ?>"><?= h($hrow['title']) ?></a></td><td class="num">별풍선 <?= fmt_num($hrow['balloons']) ?></td><td class="num">애드벌룬 <?= fmt_num($hrow['adballoons']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$history): ?><tr><td class="empty">다른 회차 후원 기록이 없습니다.</td></tr><?php endif; ?>
    </table>
  </div>
</div>

<div class="card">
  <h2>채팅 내역 <span class="muted small">시간순</span></h2>
  <table class="table compact chat-table">
    <?php foreach ($chats as $c): ?>
      <tr><td class="nowrap muted small"><?= fmt_dt($c['sent_at']) ?></td><td><?= h($c['message']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$chats): ?><tr><td class="empty">채팅 기록이 없습니다.</td></tr><?php endif; ?>
  </table>
  <?= pagination((int) $chatInfo['cnt'], $page, $perPage) ?>
</div>
<?php
page_footer();
