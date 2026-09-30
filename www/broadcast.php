<?php
/**
 * 방송 회차 등록·수정·요약
 */
require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/stats.php';
require APP_DIR . '/soop.php';

$admin = require_login();
$action = input_str('action', '', 20);
$id = input_int('id');

// ── 저장 ───────────────────────────────────────────────────
if (is_post() && in_array($action, ['new', 'edit'], true)) {
    csrf_check();
    $title = input_str('title', '', 200);
    $streamerId = input_str('streamer_id', '', 50);
    $date = input_str('broadcast_date', '', 10);
    $memo = input_str('memo', '', 5000);
    $errors = [];
    if ($title === '') $errors[] = '회차 제목을 입력해 주세요.';
    if (!valid_streamer_id($streamerId)) $errors[] = 'SOOP 방송국 ID 를 확인해 주세요. (방송국 주소 끝부분, 예: sooplive.com/station/abc123 → abc123)';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $errors[] = '방송일을 입력해 주세요.';

    if (!$errors) {
        if ($action === 'new') {
            db_exec(
                'INSERT INTO broadcasts (title, streamer_id, broadcast_date, memo, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$title, $streamerId, $date, $memo, $admin['id'], now(), now()]
            );
            $id = db_last_id();
            audit('broadcast_create', "broadcast:$id", $title);
            flash('success', '방송 회차를 등록했습니다. [실시간 수집] 탭에서 수집을 시작할 수 있습니다.');
        } else {
            load_broadcast($id);
            db_exec(
                'UPDATE broadcasts SET title = ?, streamer_id = ?, broadcast_date = ?, memo = ?, updated_at = ? WHERE id = ?',
                [$title, $streamerId, $date, $memo, now(), $id]
            );
            audit('broadcast_update', "broadcast:$id", $title);
            flash('success', '방송 정보를 수정했습니다.');
        }
        redirect("broadcast.php?id=$id");
    }
    foreach ($errors as $e) {
        flash('error', $e);
    }
}

// ── 채팅 데이터 정리 (DB 용량 확보용, admin 전용) ──────────
if (is_post() && $action === 'purge_chats') {
    csrf_check();
    require_admin_role();
    $b = load_broadcast($id);
    if (input_str('confirm', '', 50) !== $b['streamer_id']) {
        flash('error', '확인 문구가 일치하지 않아 삭제하지 않았습니다.');
    } else {
        $deleted = db_exec('DELETE FROM chat_messages WHERE broadcast_id = ?', [$id]);
        db_exec('UPDATE broadcasts SET chat_count = 0, updated_at = ? WHERE id = ?', [now(), $id]);
        audit('chat_purge', "broadcast:$id", "채팅 {$deleted}건 삭제");
        flash('success', "채팅 {$deleted}건을 삭제했습니다. (후원 기록과 상품 지급 기록은 그대로 남아 있습니다)");
    }
    redirect("broadcast.php?id=$id");
}

// ── 등록·수정 화면 ─────────────────────────────────────────
if (in_array($action, ['new', 'edit'], true)) {
    $b = $action === 'edit' ? load_broadcast($id) : ['title' => '', 'streamer_id' => '', 'broadcast_date' => date('Y-m-d'), 'memo' => ''];
    if (is_post()) {
        $b = array_merge($b, ['title' => input_str('title'), 'streamer_id' => input_str('streamer_id'), 'broadcast_date' => input_str('broadcast_date'), 'memo' => input_str('memo', '', 5000)]);
    }
    page_header($action === 'new' ? '새 회차 등록' : '방송 정보 수정', ['menu' => 'broadcasts']);
    ?>
    <div class="narrow">
      <h1><?= $action === 'new' ? '새 회차 등록' : '방송 정보 수정' ?></h1>
      <div class="card">
        <form method="post" class="form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="<?= h($action) ?>">
          <?php if ($action === 'edit'): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>
          <label>회차 제목 <input name="title" required maxlength="200" value="<?= h($b['title']) ?>" placeholder="예: 끝장전 12회 - 선수A vs 선수B"></label>
          <label>SOOP 방송국 ID <input name="streamer_id" required maxlength="50" value="<?= h($b['streamer_id']) ?>" placeholder="예: abc123">
            <span class="muted small">방송국 주소 끝부분입니다. (sooplive.com/station/<strong>abc123</strong>)</span></label>
          <label>방송일 <input type="date" name="broadcast_date" required value="<?= h($b['broadcast_date']) ?>"></label>
          <label>메모 <textarea name="memo" rows="4" placeholder="대진, 이벤트 내용 등"><?= h($b['memo']) ?></textarea></label>
          <div class="actions">
            <button type="submit" class="btn primary">저장</button>
            <a class="btn" href="<?= $action === 'edit' ? 'broadcast.php?id=' . (int) $id : 'index.php' ?>">취소</a>
          </div>
        </form>
      </div>
    </div>
    <?php
    page_footer();
    exit;
}

function collector_status_html(string $status, string $message, bool $fresh): string
{
    if ($status === 'imported') {
        return '<span class="muted">파일 업로드</span>';
    }
    if (!$fresh && in_array($status, ['live', 'resolving', 'connecting', 'waiting'], true)) {
        return '<span class="dot error"></span> 응답 없음 <span class="muted small">(창이 닫혔거나 PC 절전·인터넷 끊김)</span>';
    }
    $labels = ['live' => '수집 중', 'resolving' => '확인 중', 'connecting' => '연결 중', 'waiting' => '재연결 대기',
        'offline' => '방송 없음', 'ended' => '방송 종료', 'error' => '오류', 'stopped' => '중지됨', 'idle' => '대기'];
    $dot = match ($status) { 'live' => 'live', 'error' => 'error', 'resolving', 'connecting', 'waiting' => 'wait', default => '' };
    return '<span class="dot ' . $dot . '"></span> ' . h($labels[$status] ?? $status)
        . ($message !== '' && $status !== 'live' ? ' <span class="muted small">' . h($message) . '</span>' : '');
}

// ── 요약 화면 ──────────────────────────────────────────────
$b = load_broadcast($id);
$s = broadcast_summary($id);
$filters = ['from' => null, 'to' => null, 'exclude' => true, 'q' => ''];
$topDonors = donation_ranking($id, $filters, 'balloon', 0, 5)['rows'];
$topChatters = db_all(
    'SELECT user_id, COUNT(*) AS cnt, MAX(nickname) AS nickname FROM chat_messages WHERE broadcast_id = ? GROUP BY user_id ORDER BY cnt DESC LIMIT 5',
    [$id]
);
$d = $s['donations'];
$subs = (int) db_value("SELECT COUNT(*) FROM donations WHERE broadcast_id = ? AND type = 'subscription' AND subtype IN ('new','renew')", [$id]);
$gifts = (int) db_value("SELECT COUNT(*) FROM donations WHERE broadcast_id = ? AND type = 'subscription' AND subtype = 'gift'", [$id]);

page_header($b['title'], ['menu' => 'broadcasts', 'broadcast' => $b, 'tab' => 'summary']);
?>
<div class="tiles">
  <div class="tile"><div class="tile-label">채팅</div><div class="tile-value"><?= fmt_num($s['chat']['cnt']) ?></div><div class="tile-sub">참여 <?= fmt_num($s['chat']['users']) ?>명</div></div>
  <div class="tile"><div class="tile-label">별풍선</div><div class="tile-value"><?= fmt_num($d['balloon']['amount'] ?? 0) ?></div><div class="tile-sub">후원 <?= fmt_num($d['balloon']['users'] ?? 0) ?>명</div></div>
  <div class="tile"><div class="tile-label">애드벌룬</div><div class="tile-value"><?= fmt_num($d['adballoon']['amount'] ?? 0) ?></div><div class="tile-sub">후원 <?= fmt_num($d['adballoon']['users'] ?? 0) ?>명</div></div>
  <div class="tile"><div class="tile-label">구독</div><div class="tile-value"><?= fmt_num($subs) ?></div><div class="tile-sub">구독 선물 <?= fmt_num($gifts) ?>건</div></div>
</div>

<div class="card">
  <h2>수집 현황</h2>
  <p class="muted small">채팅 수집 기간: <?= fmt_dt($s['chat']['first_at'], 'Y-m-d H:i:s') ?> ~ <?= fmt_dt($s['chat']['last_at'], 'Y-m-d H:i:s') ?></p>
  <table class="table compact">
    <thead><tr><th>수집창</th><th>담당</th><th>상태</th><th>마지막 통신</th><th class="num">채팅</th><th class="num">후원</th></tr></thead>
    <tbody>
    <?php foreach ($s['collectors'] as $c): ?>
      <?php $fresh = strtotime($c['last_seen_at']) > time() - 60; ?>
      <tr>
        <td><?= h($c['label'] !== '' ? $c['label'] : $c['id']) ?></td>
        <td><?= h($c['display_name'] ?? '-') ?></td>
        <td><?= collector_status_html($c['status'], $c['status_message'], $fresh) ?></td>
        <td><?= fmt_dt($c['last_seen_at']) ?></td>
        <td class="num"><?= fmt_num($c['chat_count']) ?></td>
        <td class="num"><?= fmt_num($c['donation_count']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$s['collectors']): ?><tr><td colspan="6" class="empty">아직 수집 기록이 없습니다. [실시간 수집] 탭에서 시작하세요.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="grid2">
  <div class="card">
    <div class="card-head"><h2>별풍선 상위 5명</h2><a href="donations.php?id=<?= $id ?>">전체 보기</a></div>
    <table class="table compact">
      <?php foreach ($topDonors as $i => $r): ?>
        <tr><td class="rank"><?= $i + 1 ?></td><td><a href="viewer.php?id=<?= $id ?>&user=<?= urlencode($r['user_id']) ?>"><?= h($r['nickname']) ?></a> <span class="muted small"><?= h($r['user_id']) ?></span></td><td class="num"><?= fmt_num($r['balloons']) ?>개</td></tr>
      <?php endforeach; ?>
      <?php if (!$topDonors): ?><tr><td class="empty">후원 기록이 없습니다.</td></tr><?php endif; ?>
    </table>
    <p class="muted small">제외 명단·방송인·매니저는 빠진 순위입니다.</p>
  </div>
  <div class="card">
    <div class="card-head"><h2>채팅 많이 친 5명</h2><a href="activity.php?id=<?= $id ?>">활동량 보기</a></div>
    <table class="table compact">
      <?php foreach ($topChatters as $i => $r): ?>
        <tr><td class="rank"><?= $i + 1 ?></td><td><a href="viewer.php?id=<?= $id ?>&user=<?= urlencode($r['user_id']) ?>"><?= h($r['nickname']) ?></a> <span class="muted small"><?= h($r['user_id']) ?></span></td><td class="num"><?= fmt_num($r['cnt']) ?>건</td></tr>
      <?php endforeach; ?>
      <?php if (!$topChatters): ?><tr><td class="empty">채팅 기록이 없습니다.</td></tr><?php endif; ?>
    </table>
    <p class="muted small">단순 채팅 수입니다. 도배 제외·방송인 제외는 [채팅 활동량]에서 확인하세요.</p>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2>방송 정보</h2><a class="btn small" href="broadcast.php?action=edit&id=<?= $id ?>">수정</a></div>
  <p class="pre"><?= $b['memo'] !== '' && $b['memo'] !== null ? h($b['memo']) : '<span class="muted">메모 없음</span>' ?></p>
</div>

<?php if (is_admin_role()): ?>
<details class="card danger">
  <summary>채팅 데이터 정리 (DB 용량 확보)</summary>
  <p>이 회차의 <strong>채팅 기록만</strong> 삭제합니다. 후원 기록과 상품 지급 기록은 남습니다. 삭제 전에 [채팅 검색] 탭에서 CSV 로 내려받아 보관하세요.</p>
  <form method="post" class="form inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="purge_chats">
    <input type="hidden" name="id" value="<?= $id ?>">
    <label>확인을 위해 방송국 ID(<strong><?= h($b['streamer_id']) ?></strong>)를 입력하세요 <input name="confirm" autocomplete="off"></label>
    <button type="submit" class="btn danger" data-confirm="채팅 기록을 삭제합니다. 되돌릴 수 없습니다. 계속할까요?">채팅 기록 삭제</button>
  </form>
</details>
<?php endif; ?>
<?php
page_footer();
