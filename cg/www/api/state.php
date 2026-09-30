<?php
declare(strict_types=1);

// 조작 패널 상태 (1초마다 폴링). since가 현재 변경 번호와 같으면 짧은 응답만 보낸다.
define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

app_start('api');
$op = guard_control();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$since = isset($_GET['since']) && ctype_digit((string)$_GET['since']) ? (int)$_GET['since'] : null;
$rev = (int)setting_get('state_rev', '0');
if ($since === $rev) {
    json_response(['ok' => true, 'rev' => $rev, 'same' => true, 'server_ts' => time(), 'seen' => output_seen(1)]);
}
json_response(panel_state($op) + ['csrf' => csrf_token()]);
