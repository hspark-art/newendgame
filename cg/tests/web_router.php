<?php
declare(strict_types=1);

// 테스트용: 웹 호스팅(Apache + .htaccess)을 흉내 낸다. app/ 와 숨김 파일만 막고 나머지는 그대로 연다.
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (preg_match('#(^|/)(app|\.)#i', rawurldecode($path))) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
return false;
