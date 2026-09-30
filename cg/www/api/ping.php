<?php
declare(strict_types=1);

// 실행기가 서버가 떠 있는지 확인할 때 쓴다. 설정이 없어도 응답한다.
define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

send_headers('api');
json_response(['ok' => true, 'app' => 'EndgameCG', 'version' => APP_VERSION]);
