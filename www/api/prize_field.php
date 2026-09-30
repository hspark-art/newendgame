<?php
/**
 * [상품 지급 표] 칸을 바로 고치기
 *  POST {id, field, value}          → 한 칸 저장
 *  POST {ids:[...], field:"note_sent", value:"1"|"0"}  → 여러 줄 쪽지 보냄 처리
 * 고칠 수 있는 칸: user_id, nickname, reason, memo, status, note_sent
 */
define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_DIR . '/prizes.php';

require_login();
if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST 요청만 허용됩니다.'], 405);
}
csrf_check();
session_write_close();

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) {
    json_response(['ok' => false, 'error' => '요청 형식이 올바르지 않습니다.'], 400);
}
$field = (string) ($in['field'] ?? '');
$value = is_scalar($in['value'] ?? null) ? trim((string) $in['value']) : '';
$ids = array_values(array_filter(array_map('intval', (array) ($in['ids'] ?? [$in['id'] ?? 0]))));
if (!$ids) {
    json_response(['ok' => false, 'error' => '고칠 줄을 찾을 수 없습니다.'], 400);
}
$in_ = db_placeholders($ids);

switch ($field) {
    case 'user_id':
        $value = normalize_user_id(mb_substr($value, 0, 64));
        if ($value === '') {
            json_response(['ok' => false, 'error' => 'SOOP 아이디는 비울 수 없습니다.'], 400);
        }
        // no break
    case 'nickname':
    case 'reason':
    case 'memo':
        $max = ['user_id' => 64, 'nickname' => 100, 'reason' => 200, 'memo' => 5000][$field];
        $value = mb_substr($value, 0, $max);
        db_exec("UPDATE prizes SET $field = ?, updated_at = ? WHERE id IN ($in_)", array_merge([$value, now()], $ids));
        break;
    case 'status':
        if (!isset(PRIZE_STATUSES[$value])) {
            json_response(['ok' => false, 'error' => '상태 값이 올바르지 않습니다.'], 400);
        }
        if ($value === 'paid') {
            db_exec("UPDATE prizes SET status = 'paid', paid_at = COALESCE(paid_at, ?), updated_at = ? WHERE id IN ($in_)", array_merge([now(), now()], $ids));
        } else {
            db_exec("UPDATE prizes SET status = ?, paid_at = NULL, updated_at = ? WHERE id IN ($in_)", array_merge([$value, now()], $ids));
        }
        break;
    case 'note_sent':
        db_exec("UPDATE prizes SET note_sent_at = ?, note_result = ?, updated_at = ? WHERE id IN ($in_)",
            array_merge([$value === '1' ? now() : null, $value === '1' ? '보냄 처리(직접)' : null, now()], $ids));
        break;
    default:
        json_response(['ok' => false, 'error' => '고칠 수 없는 칸입니다.'], 400);
}
audit('prize_field', 'prize:' . implode(',', array_slice($ids, 0, 20)), $field . ' = ' . mb_substr($value, 0, 100));
json_response(['ok' => true, 'value' => $value, 'note_sent_at' => $field === 'note_sent' && $value === '1' ? now() : null]);
