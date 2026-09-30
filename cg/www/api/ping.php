<?php
declare(strict_types=1);

// 실행기가 서버가 떠 있는지 확인할 때 쓴다. 설정이 없어도 응답한다.
define('API_REQUEST', true);
require dirname(__DIR__) . '/app/bootstrap.php';

send_headers('api');
// lan: PC를 시작-LAN.bat 으로 실행했는지 (실행기가 이미 떠 있는 서버의 모드를 확인할 때 씀)
json_response(['ok' => true, 'app' => 'EndgameCG', 'version' => APP_VERSION, 'lan' => getenv('CG_LAN') === '1']);
