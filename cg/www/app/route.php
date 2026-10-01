<?php
declare(strict_types=1);

/**
 * PC 내장 서버(php -S)에서 열 수 있는 주소 목록. 허용목록 방식이다.
 * Windows는 경로 대소문자·끝 점·인코딩을 느슨하게 해석하므로(/APP/, /app./, %61pp)
 * 막을 경로를 나열하지 않고, 정확히 이 형태인 주소만 통과시킨다.
 */
function router_allowed(string $uriPath): bool
{
    static $pages = ['/', '/index.php', '/output.php', '/design.php'];
    if (str_contains($uriPath, '//')) {
        return false;
    }
    if (in_array($uriPath, $pages, true)) {
        return true;
    }
    if (preg_match('#^/api/(ping|state|output|action)\.php$#D', $uriPath)) {
        return true;
    }
    // CG 폰트: assets/fonts/ (파일 이름에 대문자 있음, OFL 원본 ttf 포함)
    return (bool)preg_match('#^/assets/[a-z0-9_-]+(\.[a-z0-9_-]+)*\.(css|js|png|svg|woff2)$#D', $uriPath)
        || (bool)preg_match('#^/assets/fonts/[A-Za-z0-9_-]+\.(woff2|ttf)$#D', $uriPath);
}
