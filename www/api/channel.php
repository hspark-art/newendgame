<?php
/**
 * [수집 화면 전용] SOOP 채팅 서버 정보 조회
 * 요청: POST {"streamer_id": "..."}  (헤더 X-CSRF-Token 필요)
 */
define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_DIR . '/soop.php';

require_login();
if (!is_post()) {
    json_response(['ok' => false, 'error' => 'POST 요청만 허용됩니다.'], 405);
}
csrf_check();
session_write_close();

$input = json_decode((string) file_get_contents('php://input'), true);
$streamerId = is_array($input) && is_string($input['streamer_id'] ?? null) ? trim($input['streamer_id']) : '';
$serverMs = fn() => (int) (microtime(true) * 1000);

if (!valid_streamer_id($streamerId)) {
    json_response(['ok' => false, 'error' => 'SOOP 방송국 ID 형식이 올바르지 않습니다.', 'reason' => 'invalid', 'retryable' => false, 'server_ms' => $serverMs()], 400);
}

try {
    $channel = soop_resolve_channel($streamerId);
    json_response(['ok' => true, 'channel' => $channel, 'server_ms' => $serverMs()]);
} catch (SoopError $e) {
    json_response(['ok' => false, 'error' => $e->getMessage(), 'reason' => $e->reason, 'retryable' => $e->retryable, 'server_ms' => $serverMs()]);
}
