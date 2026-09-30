<?php
/**
 * PC 버전 내장 서버용 라우터
 *  - app 폴더(내부 파일)는 주소로 열 수 없게 막습니다.
 *  - 실행기가 "이미 켜져 있는지" 확인하는 주소를 제공합니다.
 */
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if ($path === '/__endgame_ping') {
    header('Content-Type: text/plain');
    header('Cache-Control: no-store');
    echo 'endgame-ok';
    return true;
}
if (preg_match('#^/app(/|$)#i', $path) || str_contains($path, '..')) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
return false; // 나머지는 www 폴더의 파일을 그대로 실행·전달
