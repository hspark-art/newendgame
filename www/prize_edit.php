<?php
/**
 * 상품 지급 등록·수정 (1건), 순위 화면에서 여러 명 한 번에 등록
 */
require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/prizes.php';

$admin = require_login();
$isAdmin = $admin['role'] === 'admin';
$action = input_str('action', '', 20);

// ── 여러 명 한 번에 등록 (후원 순위·채팅 활동량 화면) ───────
if (is_post() && $action === 'bulk') {
    csrf_check();
    $broadcastId = input_int('broadcast_id');
    load_broadcast($broadcastId);
    $picks = array_values(array_unique(array_filter((array) ($_POST['pick'] ?? []), fn($v) => is_string($v) && $v !== '')));
    $nicks = (array) ($_POST['nick'] ?? []);
    [$itemId, $prizeName] = resolve_prize_input();
    $prizeType = input_str('prize_type', 'coupon', 20);
    $reason = input_str('reason', '', 200);
    $due = input_str('due_date', '', 10);
    if (!$picks) {
        flash('error', '당첨 등록할 시청자를 선택해 주세요.');
        redirect(safe_back("donations.php?id=$broadcastId"));
    }
    if ($prizeName === '') {
        flash('error', '상품을 고르거나 상품명을 입력해 주세요.');
        redirect(safe_back("donations.php?id=$broadcastId"));
    }
    $prizeType = isset(PRIZE_TYPES[$prizeType]) ? $prizeType : 'other';
    $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null;
    $dupes = existing_winners($broadcastId, $picks);
    foreach ($picks as $userId) {
        $userId = mb_substr($userId, 0, 64);
        db_exec(
            'INSERT INTO prizes (broadcast_id, user_id, nickname, reason, item_id, prize_name, prize_type, status, due_date, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?, ?, ?)',
            [$broadcastId, $userId, mb_substr((string) ($nicks[$userId] ?? ''), 0, 100), $reason, $itemId, $prizeName, $prizeType, $due, $admin['id'], now(), now()]
        );
    }
    audit('prize_bulk_create', "broadcast:$broadcastId", count($picks) . "명 · $prizeName");
    flash('success', count($picks) . '명을 당첨 등록했습니다.');
    if ($dupes) {
        flash('warn', '이미 이 회차에 당첨 기록이 있는 시청자가 포함되어 있습니다: ' . implode(', ', array_keys($dupes)));
    }
    redirect("prizes.php?broadcast_id=$broadcastId");
}

// ── 삭제 (admin) ───────────────────────────────────────────
if (is_post() && $action === 'delete') {
    csrf_check();
    require_admin_role();
    $id = input_int('id');
    $p = db_one('SELECT * FROM prizes WHERE id = ?', [$id]);
    if ($p) {
        db_exec('DELETE FROM prizes WHERE id = ?', [$id]);
        audit('prize_delete', "prize:$id", $p['user_id'] . ' · ' . $p['prize_name']);
        flash('success', '지급 기록을 삭제했습니다.');
    }
    redirect('prizes.php');
}

// ── 저장 ───────────────────────────────────────────────────
$id = input_int('id');
$prize = $id ? db_one('SELECT * FROM prizes WHERE id = ?', [$id]) : null;
if ($id && !$prize) {
    render_error('지급 기록을 찾을 수 없습니다.', 404);
}

$errors = [];
if (is_post() && $action === 'save') {
    csrf_check();
    $data = [
        'broadcast_id' => input_int('broadcast_id') ?: null,
        'user_id'      => input_str('user_id', '', 64),
        'nickname'     => input_str('nickname', '', 100),
        'reason'       => input_str('reason', '', 200),
        'item_id'      => null,
        'prize_name'   => '',
        'prize_type'   => input_str('prize_type', 'coupon', 20),
        'status'       => input_str('status', 'pending', 20),
        'due_date'     => input_str('due_date', '', 10),
        'memo'         => input_str('memo', '', 5000),
    ];
    [$data['item_id'], $data['prize_name']] = resolve_prize_input();
    if ($data['user_id'] === '') $errors[] = 'SOOP 아이디를 입력해 주세요.';
    if ($data['prize_name'] === '') $errors[] = '상품을 고르거나 상품명을 입력해 주세요.';
    if (!isset(PRIZE_TYPES[$data['prize_type']])) $data['prize_type'] = 'other';
    if (!isset(PRIZE_STATUSES[$data['status']])) $data['status'] = 'pending';
    $data['due_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['due_date']) ? $data['due_date'] : null;
    if ($data['broadcast_id'] && !db_value('SELECT 1 FROM broadcasts WHERE id = ?', [$data['broadcast_id']])) {
        $data['broadcast_id'] = null;
    }

    // 수령자 정보: admin 은 입력칸 내용 그대로 저장, staff 는 빈칸이면 기존 값 유지
    $pii = [];
    foreach (['recipient_name' => 100, 'recipient_phone' => 30, 'recipient_address' => 300] as $field => $max) {
        $value = input_str($field, '', $max);
        if ($isAdmin || $value !== '' || !$prize) {
            $pii[$field] = pii_encrypt($value);
        }
    }
    if (!$isAdmin && isset($_POST['clear_pii'])) {
        $pii = ['recipient_name' => null, 'recipient_phone' => null, 'recipient_address' => null];
    }

    if (!$errors) {
        $paidAt = $data['status'] === 'paid' ? (($prize['paid_at'] ?? null) ?: now()) : null;
        $fields = $data + $pii + ['paid_at' => $paidAt, 'updated_at' => now()];
        if ($prize) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            db_exec("UPDATE prizes SET $set WHERE id = ?", array_merge(array_values($fields), [$id]));
            audit('prize_update', "prize:$id", $data['user_id'] . ' · ' . $data['prize_name'] . ' · ' . PRIZE_STATUSES[$data['status']]);
        } else {
            $fields += ['created_by' => $admin['id'], 'created_at' => now()];
            $cols = implode(', ', array_keys($fields));
            db_exec("INSERT INTO prizes ($cols) VALUES (" . db_placeholders($fields) . ')', array_values($fields));
            $id = db_last_id();
            audit('prize_create', "prize:$id", $data['user_id'] . ' · ' . $data['prize_name']);
        }
        flash('success', '저장했습니다.');
        $dupes = existing_winners($data['broadcast_id'], [$data['user_id']], $id);
        if ($dupes) {
            flash('warn', '이 시청자는 같은 회차에 다른 당첨 기록이 있습니다: ' . implode(', ', $dupes[$data['user_id']]));
        }
        redirect('prizes.php' . ($data['broadcast_id'] ? '?broadcast_id=' . $data['broadcast_id'] : ''));
    }
    $prize = array_merge($prize ?? [], $data);
}

// ── 화면 ───────────────────────────────────────────────────
if (!$prize) {
    $prize = [
        'broadcast_id' => input_int('broadcast_id') ?: null,
        'user_id'      => input_str('user_id', '', 64),
        'nickname'     => input_str('nickname', '', 100),
        'reason'       => input_str('reason', '', 200),
        'item_id'      => input_int('item_id') ?: null,
        'prize_name'   => '', 'prize_type' => 'coupon', 'status' => 'pending', 'due_date' => null, 'memo' => '',
        'recipient_name' => null, 'recipient_phone' => null, 'recipient_address' => null, 'paid_at' => null, 'purged_at' => null,
    ];
}
$pii = [
    'recipient_name'    => pii_decrypt($prize['recipient_name'] ?? null),
    'recipient_phone'   => pii_decrypt($prize['recipient_phone'] ?? null),
    'recipient_address' => pii_decrypt($prize['recipient_address'] ?? null),
];
if ($isAdmin && $id && array_filter($pii)) {
    audit('pii_view', "prize:$id", '수령자 정보 열람');
}
$masked = [
    'recipient_name'    => mask_name($pii['recipient_name']),
    'recipient_phone'   => mask_phone($pii['recipient_phone']),
    'recipient_address' => mask_address($pii['recipient_address']),
];
$broadcasts = db_all('SELECT id, title, broadcast_date FROM broadcasts ORDER BY broadcast_date DESC, id DESC LIMIT 200');
$dupes = $prize['user_id'] !== '' ? existing_winners($prize['broadcast_id'] ? (int) $prize['broadcast_id'] : null, [$prize['user_id']], $id) : [];
$warnings = winner_warnings((string) $prize['user_id'], $prize['item_id'] !== null ? (int) $prize['item_id'] : null, (string) $prize['prize_name'], $id);
$history = $prize['user_id'] !== '' ? (winners_by_user([$prize['user_id']])[$prize['user_id']] ?? []) : [];

page_header($id ? '지급 정보 수정' : '당첨 등록', ['menu' => 'prizes']);
?>
<div class="narrow">
  <h1><?= $id ? '지급 정보 수정' : '당첨 등록' ?></h1>
  <?php foreach ($errors as $e): ?><div class="alert alert-error"><?= h($e) ?></div><?php endforeach; ?>
  <?php if ($dupes): ?><div class="alert alert-warn">이 시청자는 같은 회차에 이미 당첨 기록이 있습니다: <?= h(implode(', ', $dupes[$prize['user_id']])) ?></div><?php endif; ?>
  <?php if ($history): ?>
    <div class="alert <?= array_filter($warnings, fn($w) => str_starts_with($w, '🚫')) ? 'alert-error' : 'alert-info' ?>">
      <?= render_wins($history) ?> <?= h(implode(' · ', $warnings)) ?>
    </div>
  <?php endif; ?>

  <form method="post" class="form card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($id): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>

    <h2>당첨 정보</h2>
    <label>방송 회차
      <select name="broadcast_id">
        <option value="">(회차 없음)</option>
        <?php foreach ($broadcasts as $bc): ?>
          <option value="<?= (int) $bc['id'] ?>" <?= (int) $prize['broadcast_id'] === (int) $bc['id'] ? 'selected' : '' ?>><?= h($bc['broadcast_date'] . ' ' . $bc['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="row2">
      <label>SOOP 아이디 <input name="user_id" required maxlength="64" value="<?= h($prize['user_id']) ?>"></label>
      <label>닉네임 <input name="nickname" maxlength="100" value="<?= h($prize['nickname']) ?>"></label>
    </div>
    <label>선정 사유 <input name="reason" maxlength="200" value="<?= h($prize['reason']) ?>" placeholder="예: 후원 순위 1위, 채팅 활동 추첨"></label>
    <div class="row2">
      <?= prize_item_select($prize['item_id'] !== null ? (int) $prize['item_id'] : null, '상품 (상품 목록)') ?>
      <label>상품명 <input name="prize_name" maxlength="200" value="<?= h($prize['item_id'] ? '' : $prize['prize_name']) ?>" placeholder="목록에 없을 때 직접 입력"></label>
    </div>
    <div class="row2">
      <label>상품 유형
        <select name="prize_type"><?php foreach (PRIZE_TYPES as $k => $v): ?><option value="<?= h($k) ?>" <?= $prize['prize_type'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
      </label>
      <label>진행 상태
        <select name="status"><?php foreach (PRIZE_STATUSES as $k => $v): ?><option value="<?= h($k) ?>" <?= $prize['status'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
      </label>
    </div>
    <div class="row2">
      <label>정보 제출 기한 <input type="date" name="due_date" value="<?= h($prize['due_date'] ?? '') ?>"></label>
    </div>
    <?php if (!empty($prize['paid_at'])): ?><p class="muted small">지급 완료 처리: <?= h($prize['paid_at']) ?></p><?php endif; ?>

    <h2>수령자 정보 <span class="muted small">암호화 저장</span></h2>
    <?php if (!empty($prize['purged_at'])): ?>
      <div class="alert alert-info">보관 기간이 지나 <?= h($prize['purged_at']) ?> 에 수령자 정보를 파기했습니다.</div>
    <?php endif; ?>
    <p class="muted small">모바일 쿠폰은 연락처만, 실물 배송은 이름·연락처·주소를 받습니다. 지급 완료 후 <?= (int) config('privacy_retention_days', 30) ?>일이 지나면 자동으로 파기됩니다.
      <?= $isAdmin ? '' : '(담당자 권한: 저장된 정보는 가려서 표시되며, 빈칸으로 두면 기존 정보가 유지됩니다)' ?></p>
    <?php foreach (['recipient_name' => ['이름', 100], 'recipient_phone' => ['연락처', 30], 'recipient_address' => ['주소', 300]] as $field => [$label, $max]): ?>
      <label><?= h($label) ?>
        <?php if ($isAdmin): ?>
          <input name="<?= $field ?>" maxlength="<?= $max ?>" value="<?= h($pii[$field] ?? '') ?>" autocomplete="off">
        <?php else: ?>
          <input name="<?= $field ?>" maxlength="<?= $max ?>" value="" placeholder="<?= h($masked[$field] !== '' ? '저장됨: ' . $masked[$field] : '') ?>" autocomplete="off">
        <?php endif; ?>
      </label>
    <?php endforeach; ?>
    <?php if (!$isAdmin && $id && array_filter($pii)): ?>
      <label class="check"><input type="checkbox" name="clear_pii" value="1"> 저장된 수령자 정보 삭제</label>
    <?php endif; ?>

    <label>메모 <textarea name="memo" rows="3" placeholder="발송 번호, 연락 기록 등"><?= h($prize['memo'] ?? '') ?></textarea></label>

    <div class="actions">
      <button type="submit" class="btn primary">저장</button>
      <a class="btn" href="prizes.php">목록</a>
    </div>
  </form>

  <?php if ($id && $isAdmin): ?>
    <form method="post" class="danger-inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <button class="btn danger small" data-confirm="이 지급 기록을 삭제할까요? 되돌릴 수 없습니다.">지급 기록 삭제</button>
    </form>
  <?php endif; ?>
</div>
<?php
page_footer();
