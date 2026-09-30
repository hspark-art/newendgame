<?php
declare(strict_types=1);

// 조작 요청. POST + JSON + CSRF 토큰 + 같은 출처만 받는다. 응답에 새 패널 상태를 함께 보낸다.
define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

app_start('api');
$op = guard_control();
guard_post();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$in = request_json();
try {
    $result = action_dispatch((string)($in['action'] ?? ''), $in, $op);
    json_response(['ok' => true, 'result' => $result, 'state' => panel_state($op)]);
} catch (ActionError $e) {
    json_response([
        'ok' => false, 'code' => $e->errCode, 'error' => $e->getMessage(), 'fields' => $e->fields,
        'state' => panel_state($op),
    ], $e->status);
}
