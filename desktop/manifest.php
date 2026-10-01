<?php
/**
 * 파일 대조표 만들기: www/app/manifest.json (서버 점검 도구 app/check.php 가 씀)
 *   php desktop/manifest.php
 * 서버에 따로 두는 파일(app/config.php, app/storage/)은 넣지 않습니다.
 */
chdir(dirname(__DIR__));
preg_match("/const APP_VERSION = '([^']+)'/", (string) file_get_contents('www/app/bootstrap.php'), $m);
exec('git ls-files www', $tracked);
$files = [];
foreach ($tracked as $f) {
    if (!is_file($f) || $f === 'www/app/manifest.json') {
        continue;
    }
    $files[substr($f, 4)] = sha1(str_replace("\r\n", "\n", (string) file_get_contents($f)));
}
ksort($files);
file_put_contents('www/app/manifest.json', json_encode(['version' => $m[1], 'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "manifest.json: v{$m[1]}, " . count($files) . " 개 파일\n";
