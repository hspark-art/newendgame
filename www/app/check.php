<?php
/**
 * 서버 점검 도구 (SSH 에서 실행)
 *
 *   cd /var/www/endgame && php app/check.php
 *
 * 파일을 올린 뒤 이 명령 하나로 확인합니다.
 *  1. PHP 버전·확장 기능
 *  2. 모든 PHP 파일 문법 검사 (올리다 잘린 파일 찾기)
 *  3. 파일 목록 대조 (manifest.json 과 비교: 안 올린 파일·옛 파일·서버에서 직접 고친 파일)
 *  4. 설정 파일·DB 접속·DB 구조 자동 업데이트
 *  5. 저장 폴더 쓰기 권한
 * 브라우저로는 열 수 없습니다. (app 폴더 접근 차단 + 명령줄 전용)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$fail = 0;
$warn = 0;
function line(string $mark, string $text): void
{
    echo "  $mark $text\n";
}
function ok(string $t): void { line('[정상]', $t); }
function bad(string $t): void { global $fail; $fail++; line('[오류]', $t); }
function warn(string $t): void { global $warn; $warn++; line('[주의]', $t); }

$version = preg_match("/const APP_VERSION = '([^']+)'/", (string) @file_get_contents(__DIR__ . '/bootstrap.php'), $m) ? $m[1] : '?';
echo "\n끝장전 채팅·상품 관리 v$version 서버 점검 (" . date('Y-m-d H:i:s') . ")\n";
echo "폴더: $root\n\n";

// 1. PHP
echo "1. PHP\n";
version_compare(PHP_VERSION, '8.1.0', '>=') ? ok('PHP ' . PHP_VERSION) : bad('PHP ' . PHP_VERSION . ' — 8.1 이상이 필요합니다.');
foreach (['pdo_mysql' => 'DB 접속', 'mbstring' => '한글 처리', 'openssl' => '개인정보 암호화', 'curl' => 'SOOP 조회·쪽지', 'json' => 'JSON', 'zlib' => '백업 압축 파일', 'gd' => '상품 사진 확인(없으면 fileinfo)', 'fileinfo' => '업로드 파일 확인'] as $ext => $why) {
    extension_loaded($ext) ? ok("$ext ($why)") : (in_array($ext, ['gd', 'fileinfo'], true) ? warn("$ext 없음 ($why)") : bad("$ext 없음 ($why) — 설치 필요"));
}
$upload = ini_get('upload_max_filesize');
echo "    업로드 한도: upload_max_filesize=$upload, post_max_size=" . ini_get('post_max_size') . "\n";

// 2. 문법 검사
echo "\n2. PHP 파일 문법 검사\n";
$php = PHP_BINARY ?: 'php';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$count = 0;
$broken = 0;
foreach ($files as $f) {
    $path = $f->getPathname();
    if (substr($path, -4) !== '.php' || str_contains($path, '/app/storage/')) {
        continue;
    }
    $count++;
    $out = [];
    exec(escapeshellarg($php) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $broken++;
        bad(substr($path, strlen($root) + 1) . ' — ' . trim(implode(' ', $out)));
    }
}
$broken === 0 ? ok("$count 개 파일 문법 이상 없음") : null;

// 3. 파일 목록 대조
echo "\n3. 파일 대조 (manifest.json)\n";
$manifest = json_decode((string) @file_get_contents(__DIR__ . '/manifest.json'), true);
if (!is_array($manifest) || !isset($manifest['files'])) {
    warn('app/manifest.json 이 없어 대조를 건너뜁니다.');
} else {
    if (($manifest['version'] ?? '') !== $version) {
        warn("manifest 버전({$manifest['version']})과 프로그램 버전($version)이 다릅니다. 두 파일 중 하나를 안 올렸을 수 있습니다.");
    }
    $missing = $changed = [];
    foreach ($manifest['files'] as $rel => $hash) {
        $p = "$root/$rel";
        if (!is_file($p)) {
            $missing[] = $rel;
        } elseif (sha1_file($p) !== $hash) {
            // 줄바꿈만 다른 경우(FTP 텍스트 모드)는 같은 것으로 봄
            $norm = sha1(str_replace("\r\n", "\n", (string) file_get_contents($p)));
            if ($norm !== $hash) {
                $changed[] = $rel;
            }
        }
    }
    foreach ($missing as $r) bad("없음: $r — 올려야 합니다.");
    foreach ($changed as $r) warn("내용 다름: $r — 안 올린 옛 파일이거나 서버에서 직접 고친 파일입니다.");
    if (!$missing && !$changed) {
        ok(count($manifest['files']) . " 개 파일이 v{$manifest['version']} 과 모두 같습니다.");
    }
}

// 4. 설정·DB
echo "\n4. 설정·DB\n";
if (!is_file(__DIR__ . '/config.php')) {
    bad('app/config.php 가 없습니다. config.sample.php 를 복사해 만들거나 브라우저로 install.php 를 여세요.');
} else {
    ok('app/config.php 있음');
    try {
        define('CHECK_CLI', true);
        require __DIR__ . '/bootstrap.php';
        ok('DB 접속 (' . db_driver() . ')');
        run_migrations();
        $sv = schema_version();
        $latest = max(array_keys(migrations()));
        $sv >= $latest ? ok("DB 구조 버전 $sv (최신)") : bad("DB 구조 버전 $sv / 필요 $latest");
        app_key_valid() ? ok('암호화 키(app_key)') : bad('config.php 의 app_key 가 비었거나 형식이 틀렸습니다.');
        config('debug') ? warn("config.php 의 debug 가 true 입니다. 운영 중에는 false 로 두세요.") : ok('debug 꺼짐');
        $admins = (int) db_value('SELECT COUNT(*) FROM admins');
        $admins > 0 ? ok("관리자 계정 $admins 명") : warn('관리자 계정이 없습니다. 브라우저로 접속해 첫 관리자를 만드세요.');
    } catch (Throwable $e) {
        bad('DB: ' . $e->getMessage());
    }
}

// 5. 쓰기 권한
echo "\n5. 저장 폴더\n";
$dirs = [__DIR__ . '/storage', __DIR__ . '/storage/uploads/prizes', __DIR__ . '/storage/import'];
$sess = function_exists('config') ? (string) config('session_path', '') : '';
if ($sess !== '') {
    $dirs[] = $sess;
}
foreach ($dirs as $d) {
    if (!is_dir($d)) {
        @mkdir($d, 0770, true);
    }
    is_dir($d) && is_writable($d) ? ok(str_replace($root . '/', '', $d) . ' 쓰기 가능') : bad("$d 쓰기 불가 — chown -R <웹서버계정> 으로 권한을 주세요.");
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    echo "    (root 로 실행했습니다. 권한 판단은 웹서버 계정 기준과 다를 수 있습니다: sudo -u www-data php app/check.php)\n";
}

echo "\n결과: " . ($fail ? "오류 $fail 건" : '오류 없음') . ($warn ? ", 주의 $warn 건" : '') . "\n\n";
exit($fail ? 1 : 0);
