<?php
declare(strict_types=1);

/**
 * PC 내장 서버(php -S) 라우터. 허용목록(www/app/route.php)에 있는 주소만 연다.
 * app/ (설정·DB) 폴더는 어떤 형태의 주소로도 열리지 않는다.
 */
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
require_once $_SERVER['DOCUMENT_ROOT'] . '/app/route.php';

if (!router_allowed($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    return true;
}
return false; // 허용된 주소: 내장 서버가 파일을 그대로 처리
