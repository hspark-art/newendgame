<?php
declare(strict_types=1);

/**
 * 서버 점검 명령 — SSH에서만 실행한다 (웹에서는 열리지 않음: app/.htaccess + 아래 CLI 확인).
 * 문서 루트(예: /var/www/endgame-cg)에서:
 *   php app/cli.php check                 설치·업데이트 점검 (PHP·확장·설정·DB·DB 구조·폴더·파일 무결성)
 *   php app/cli.php check --net           + Google 시트 서버 접속 확인 (방화벽)
 *   php app/cli.php check --url=https://도메인   + 웹에서 내부 폴더(app/)가 막혀 있는지 확인
 *   php app/cli.php migrate               DB 구조 업데이트 실행 (보통은 첫 접속 때 자동)
 *   php app/cli.php version               버전
 * 결과 줄: [정상] [주의] [실패] [안내]. 실패가 하나라도 있으면 종료 코드 1.
 * 이 파일은 PHP 버전 확인 전에 읽히므로 PHP 7에서도 읽을 수 있는 문법만 쓴다.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$fails = 0;
function cli_line($tag, $msg)
{
    global $fails;
    if ($tag === '실패') {
        $fails++;
    }
    echo '[' . $tag . '] ' . $msg . "\n";
}

$cmd = isset($argv[1]) ? $argv[1] : 'check';
$opts = array_slice($argv, 2);
if (!in_array($cmd, ['check', 'migrate', 'version'], true)) {
    echo "사용법: php app/cli.php check [--net] [--url=https://도메인] | migrate | version\n";
    exit(2);
}
if (PHP_VERSION_ID < 80100) {
    cli_line('실패', 'PHP 8.1 이상이 필요합니다. 지금 PHP ' . PHP_VERSION);
    exit(1);
}

require __DIR__ . '/bootstrap.php';

echo '끝장전 CG v' . APP_VERSION . ' · ' . WWW_DIR . "\n";
if ($cmd === 'version') {
    exit(0);
}

// ---------------------------------------------------------------- 설정·DB
if (!config_load()) {
    cli_line('실패', '설정 파일이 없습니다: ' . config_path() . ' (app/config.sample.php를 복사해 만드세요)');
    exit(1);
}
try {
    db();
} catch (Throwable $e) {
    cli_line('실패', 'DB에 연결할 수 없습니다 (' . db_driver() . '): ' . $e->getMessage());
    exit(1);
}
$latest = max(array_keys(migrations()));
if ($cmd === 'migrate') {
    $before = schema_version();
    run_migrations();
    cli_line('정상', 'DB 구조 ' . $before . ' → ' . schema_version() . ($before === schema_version() ? ' (이미 최신)' : ''));
    exit(0);
}

// ---------------------------------------------------------------- check
cli_line('정상', 'PHP ' . PHP_VERSION);
$need = ['pdo', 'mbstring', 'json', db_driver() === 'mysql' ? 'pdo_mysql' : 'pdo_sqlite'];
foreach ($need as $ext) {
    extension_loaded($ext) ? cli_line('정상', "PHP 확장 $ext") : cli_line('실패', "PHP 확장 $ext 이 없습니다");
}
foreach (['openssl' => 'Google 시트 연결', 'zip' => 'xlsx 가져오기', 'intl' => '이름 정규화(없으면 기본 처리)'] as $ext => $use) {
    extension_loaded($ext) ? cli_line('정상', "PHP 확장 $ext") : cli_line('주의', "PHP 확장 $ext 이 없습니다 — $use 에 필요");
}
$post = ini_get('post_max_size');
$bytes = (int)$post * (stripos((string)$post, 'G') !== false ? 1073741824 : (stripos((string)$post, 'M') !== false ? 1048576 : 1));
$bytes >= 16 * 1048576 ? cli_line('정상', "post_max_size $post")
    : cli_line('주의', "post_max_size $post — xlsx 가져오기는 16M 이상 필요 (php.ini)");

config('mode') === 'web' ? cli_line('정상', '설정 파일 ' . config_path() . ' (웹 모드)')
    : cli_line('주의', "설정의 mode 가 'web' 이 아닙니다 (" . (string)config('mode') . ')');
if (config('debug')) {
    cli_line('주의', '설정의 debug 가 true 입니다. 점검이 끝나면 false 로 바꾸세요 (오류 내용이 화면에 보임)');
}
cli_line('정상', 'DB 연결 (' . db_driver() . ')');
$cur = schema_version();
if ($cur === $latest) {
    cli_line('정상', "DB 구조 $cur (최신)");
} elseif ($cur < $latest) {
    cli_line('주의', "DB 구조 $cur → $latest 업데이트 필요: php app/cli.php migrate (또는 첫 접속 때 자동)");
} else {
    cli_line('실패', "DB 구조($cur)가 프로그램($latest)보다 새것입니다. 이전 버전 파일이 올라갔는지 확인하세요");
}

// ---------------------------------------------------------------- 폴더
$inWeb = static function (string $dir): bool {
    $d = realpath($dir);
    $w = realpath(WWW_DIR);
    return $d !== false && $w !== false && str_starts_with($d . '/', rtrim($w, '/') . '/');
};
$folders = ['데이터(storage_dir)' => storage_dir(), '키 보관(secrets_dir)' => secrets_dir()];
if ((string)config('session_path', '') !== '') {
    $folders['세션(session_path)'] = (string)config('session_path');
}
foreach ($folders as $label => $dir) {
    if (!is_dir($dir) || !is_writable($dir)) {
        cli_line('실패', "$label $dir — 없거나 웹 서버가 쓸 수 없습니다 (소유자·권한 확인)");
        continue;
    }
    $inWeb($dir) ? cli_line('주의', "$label $dir — 웹 폴더 안입니다. Apache .htaccess로만 막혀 있으니 웹 폴더 밖 경로를 권장")
        : cli_line('정상', "$label $dir");
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    cli_line('안내', 'root로 실행했습니다. 폴더 쓰기 확인은 웹 서버 계정 기준이 아닐 수 있습니다 (sudo -u 웹서버계정 php app/cli.php check)');
}

// ---------------------------------------------------------------- 계정·설치 파일
$dbReady = $cur === $latest; // DB 구조가 최신이 아니면 아래 DB 확인은 건너뛴다 (migrate 후 다시 점검)
$admins = $dbReady ? (int)db_value("SELECT COUNT(*) FROM cg_users WHERE role = 'admin' AND status = 'active'") : -1;
if ($admins === -1) {
    cli_line('안내', '계정·데이터 소스 확인은 DB 구조 업데이트 뒤에 합니다');
} elseif ($admins === 0) {
    cli_line('안내', '관리자 계정이 없습니다. 브라우저로 https://도메인/install.php 에 접속해 첫 관리자를 만드세요');
} elseif (is_file(WWW_DIR . '/install.php')) {
    cli_line('주의', 'install.php 가 남아 있습니다. 삭제하세요: rm ' . WWW_DIR . '/install.php');
} else {
    cli_line('정상', "관리자 계정 $admins 개, install.php 삭제됨");
}

// ---------------------------------------------------------------- 파일 무결성 (업로드한 파일이 배포본과 같은지)
$v = release_verify();
if ($v['status'] === 'no_manifest') {
    cli_line('주의', 'app/manifest.sha256 이 없어 파일을 대조할 수 없습니다 (배포본 파일을 올리세요)');
} elseif ($v['status'] === 'ok') {
    cli_line('정상', "파일 {$v['checked']}개 모두 배포본과 같습니다");
} else {
    foreach ($v['missing'] as $f) {
        cli_line('실패', "파일 없음: $f");
    }
    foreach ($v['changed'] as $f) {
        cli_line('실패', "배포본과 다름: $f (다시 업로드하세요. app/version.json·app/manifest.sha256 도 함께)");
    }
}

// ---------------------------------------------------------------- 데이터 소스 (안내)
if ($dbReady) {
    $src = source_status();
    $sheet = sheet_config();
    cli_line('안내', 'Google 시트: 주소 ' . ($sheet['id'] !== '' ? '설정됨' : '없음') . ' · 키 ' . (is_file(google_key_path()) ? '등록됨' : '없음')
        . ' · 마지막 정상 ' . ($src['last_success_at'] ?? '없음') . ($src['status'] === 'ERROR' ? ' · 최근 새로고침 실패' : ''));
}

// ---------------------------------------------------------------- 선택: 외부 접속·웹 보호
if (in_array('--net', $opts, true)) {
    foreach (['oauth2.googleapis.com', 'sheets.googleapis.com'] as $host) {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $s = @stream_socket_client("ssl://$host:443", $no, $err, 5, STREAM_CLIENT_CONNECT, $ctx);
        if ($s !== false) {
            fclose($s);
            cli_line('정상', "$host:443 접속 (인증서 확인)");
        } else {
            cli_line('실패', "$host:443 에 접속할 수 없습니다 ($err) — 방화벽·DNS·CA 인증서 확인");
        }
    }
}
foreach ($opts as $o) {
    if (!str_starts_with($o, '--url=')) {
        continue;
    }
    $base = rtrim(substr($o, 6), '/');
    str_starts_with($base, 'https://') ? cli_line('정상', "주소 $base (https)")
        : cli_line('주의', "주소 $base — https가 아닙니다. 로그인 정보·송출 주소 보호를 위해 SSL 인증서를 적용하세요");
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5, 'follow_location' => 0]]);
    foreach (['/app/config.php', '/app/version.json', '/app/cli.php', '/app/storage/'] as $p) {
        @file_get_contents($base . $p, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int)$m[1];
            }
        }
        if ($code === 403 || $code === 404) {
            cli_line('정상', "웹에서 $p 차단됨 ($code)");
        } elseif ($code === 0) {
            // 응답 없음: 웹호스팅은 서버 안에서 자기 도메인으로 접속이 안 되는 경우가 많다 → 판단 불가
            cli_line('주의', "웹에서 $p 확인 불가 (서버 안에서 이 주소로 접속되지 않음) — 브라우저로 $base$p 를 열어 403/404인지 확인하세요");
        } else {
            cli_line('실패', "웹에서 $p 이(가) 막혀 있지 않습니다 (HTTP $code) — 웹 서버 설정(SERVER_KR.md 5) 확인");
        }
    }
}

echo $fails === 0 ? "점검 완료: 실패 없음\n" : "점검 완료: 실패 $fails 건 — 위 [실패] 줄을 확인하세요\n";
exit($fails === 0 ? 0 : 1);
