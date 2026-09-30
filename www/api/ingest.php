<?php
/**
 * [수집 화면 전용] 채팅·후원 저장
 * 요청: POST {broadcast_id, collector_id, label, status, status_message, soop_broadcast_no, events:[...]}
 * 이벤트 형식은 app/ingest.php 설명 참고
 */
define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_DIR . '/ingest.php';

require_login();
if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST 요청만 허용됩니다.'], 405);
}
csrf_check();
// 수집 창이 2초마다 요청하므로, 같은 로그인으로 다른 화면을 볼 때 기다리지 않도록 세션을 바로 닫습니다.
session_write_close();

$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 8 * 1024 * 1024) {
    json_response(['ok' => false, 'error' => '한 번에 보내는 양이 너무 큽니다.'], 413);
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    json_response(['ok' => false, 'error' => '요청 형식이 올바르지 않습니다.'], 400);
}

$broadcastId = (int) ($input['broadcast_id'] ?? 0);
$collectorId = (string) ($input['collector_id'] ?? '');
$events = $input['events'] ?? [];
if (!preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $collectorId) || !is_array($events) || count($events) > 2000) {
    json_response(['ok' => false, 'error' => '요청 형식이 올바르지 않습니다.'], 400);
}
if (!db_value('SELECT 1 FROM broadcasts WHERE id = ?', [$broadcastId])) {
    json_response(['ok' => false, 'error' => '방송 회차를 찾을 수 없습니다.'], 404);
}

$str = fn($key) => is_string($input[$key] ?? null) ? $input[$key] : '';
// 수집창을 먼저 등록해야 PC 여러 대 동시 수집 때 중복 확인이 정확합니다.
touch_collector($collectorId, $broadcastId, [
    'label'             => $str('label'),
    'status'            => $str('status'),
    'status_message'    => $str('status_message'),
    'soop_broadcast_no' => $str('soop_broadcast_no'),
]);
$saved = ingest_events($broadcastId, array_values($events), 'live');
if ($saved['chats'] || $saved['donations']) {
    touch_collector($collectorId, $broadcastId, [], $saved['chats'], $saved['donations']);
}

json_response(['ok' => true, 'saved' => $saved, 'server_ms' => (int) (microtime(true) * 1000)]);
