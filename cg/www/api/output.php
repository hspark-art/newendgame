<?php
declare(strict_types=1);

// 송출 화면 상태 (0.3초마다 폴링). 권한 규칙은 output_access() 참고.
define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

app_start('api');
[$ch, $ghost, $layer] = output_request();
output_access($ch, $ghost);
output_layer_check($layer);
$hb = (string)($_GET['hb'] ?? '');
if ($hb !== '' && $ch === 'program' && !$ghost) {
    output_heartbeat($hb, $layer);
}
$since = isset($_GET['since']) && ctype_digit((string)$_GET['since']) ? (int)$_GET['since'] : null;
json_response(output_payload($ch, $layer, $since));
