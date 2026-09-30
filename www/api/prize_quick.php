<?php
/**
 * [수집 창 전용] 채팅에서 지명한 시청자를 바로 당첨 등록
 * 요청: POST {broadcast_id, user_id, nickname, item_id, prize_name, reason}
 */
define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_DIR . '/prizes.php';

$admin = require_login();
if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST 요청만 허용됩니다.'], 405);
}
csrf_check();
session_write_close();

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
    json_response(['ok' => false, 'error' => '요청 형식이 올바르지 않습니다.'], 400);
}
$str = fn($k, $max) => mb_substr(trim(is_scalar($in[$k] ?? null) ? (string) $in[$k] : ''), 0, $max);
$broadcastId = (int) ($in['broadcast_id'] ?? 0);
$userId = normalize_user_id($str('user_id', 64));
$nickname = $str('nickname', 100);
$item = prize_item((int) ($in['item_id'] ?? 0) ?: null);
$prizeName = $str('prize_name', 200);
if ($item && $prizeName === '') {
    $prizeName = $item['name'];
}
$reason = $str('reason', 200);

if (!db_value('SELECT 1 FROM broadcasts WHERE id = ?', [$broadcastId])) {
    json_response(['ok' => false, 'error' => '방송 회차를 찾을 수 없습니다.'], 404);
}
if ($userId === '' || $prizeName === '') {
    json_response(['ok' => false, 'error' => '시청자와 상품을 확인해 주세요.'], 400);
}

$warnings = winner_warnings($userId, $item ? (int) $item['id'] : null, $prizeName);
db_exec(
    "INSERT INTO prizes (broadcast_id, user_id, nickname, reason, item_id, prize_name, prize_type, status, created_by, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, 'coupon', 'pending', ?, ?, ?)",
    [$broadcastId, $userId, $nickname, $reason !== '' ? $reason : '채팅 지명', $item ? (int) $item['id'] : null, $prizeName, $admin['id'], now(), now()]
);
$id = db_last_id();
audit('prize_create', "prize:$id", "$userId · $prizeName (채팅 지명)");

$icon = prize_icon(['item_icon' => $item['icon'] ?? '', 'item_color' => $item['color'] ?? '', 'prize_name' => $prizeName]);
json_response(['ok' => true, 'prize_id' => $id, 'warnings' => $warnings,
    'win' => [$icon['icon'], $icon['color'], $prizeName, date('Y-m-d'), $item ? (int) $item['id'] : null]]);
