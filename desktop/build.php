<?php
/**
 * 배포 파일 만들기
 *
 *   php desktop/build.php          PC 버전 + 웹 업로드용 둘 다
 *   php desktop/build.php desktop  PC 버전만   → dist/endgame-desktop-v버전.zip
 *   php desktop/build.php web      웹 업로드용 → dist/endgame-web-v버전.zip (www 폴더 내용)
 *
 * 저장소에 등록된(git) 파일만 담습니다. 설정 파일(config.php)·자료(data)는 들어가지 않습니다.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "PHP zip 확장 기능이 필요합니다.\n");
    exit(1);
}
preg_match("/const APP_VERSION = '([^']+)'/", (string) file_get_contents('www/app/bootstrap.php'), $m);
$version = $m[1] ?? 'dev';
$what = $argv[1] ?? 'all';

exec('git ls-files -z', $out, $code);
if ($code !== 0) {
    fwrite(STDERR, "git 저장소에서 실행해 주세요.\n");
    exit(1);
}
$tracked = array_filter(explode("\0", implode('', $out)));
$www = array_values(array_filter($tracked, fn($f) => str_starts_with($f, 'www/') && is_file($f)));

@mkdir('dist', 0755, true);

function make_zip(string $path, array $entries): void
{
    @unlink($path);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE) !== true) {
        throw new RuntimeException("압축 파일을 만들 수 없습니다: $path");
    }
    foreach ($entries as $name => $source) {
        if ($source === null) {
            $zip->addEmptyDir($name);
            continue;
        }
        $zip->addFile($source, $name);
        $mode = str_ends_with($name, '.command') ? 0100755 : 0100644;
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16);
    }
    $zip->close();
    printf("만듦: %s (%d개, %s KB)\n", $path, count($entries), number_format(filesize($path) / 1024));
}

if ($what === 'all' || $what === 'web') {
    $entries = [];
    foreach ($www as $f) {
        $entries[substr($f, 4)] = $f;
    }
    make_zip("dist/endgame-web-v$version.zip", $entries);
}

if ($what === 'all' || $what === 'desktop') {
    $base = "endgame-desktop-v$version/";
    $entries = [$base . 'data/' => null];
    foreach ($www as $f) {
        $entries[$base . $f] = $f;
    }
    foreach (['desktop/launcher.php', 'desktop/router.php', 'desktop/windows/setup-php.ps1'] as $f) {
        $entries[$base . $f] = $f;
    }
    foreach (['start-windows.bat', 'start-mac.command', '사용안내.txt'] as $f) {
        $entries[$base . $f] = "desktop/package/$f";
    }
    make_zip("dist/endgame-desktop-v$version.zip", $entries);
}
