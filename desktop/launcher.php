<?php
/**
 * 끝장전 관리 PC 버전 실행기
 *
 *  1) 처음 실행이면 data 폴더와 설정 파일(data/config.php)을 만듭니다.
 *  2) 이 컴퓨터 안에서만 접속되는 작은 웹 서버(127.0.0.1)를 켭니다.
 *  3) 브라우저를 앱 창으로 엽니다.
 *
 * 실행한 창(검은 창)을 닫으면 프로그램이 종료됩니다.
 * 모든 경로는 프로그램 폴더 기준 상대 경로로 넘깁니다. (Windows 한글 폴더 경로 문제 방지)
 */
declare(strict_types=1);

const APP_TITLE = '끝장전 채팅·상품 관리';
const FIRST_PORT = 8765;
const LAST_PORT = 8775;

$root = dirname(__DIR__);
chdir($root);

function say(string $line = ''): void
{
    echo $line, PHP_EOL;
}

function fail(string $message): never
{
    say();
    say('[오류] ' . $message);
    exit(1);
}

say('==============================================');
say('  ' . APP_TITLE . ' (PC 버전)');
say('==============================================');

// ── 1. 실행 환경 확인 ─────────────────────────────────────
if (PHP_VERSION_ID < 80100) {
    fail('PHP 8.1 이상이 필요합니다. 지금 버전: ' . PHP_VERSION);
}
$missing = array_filter(['pdo_sqlite', 'openssl', 'mbstring'], fn($ext) => !extension_loaded($ext));
if ($missing) {
    fail('PHP 확장 기능이 빠져 있습니다: ' . implode(', ', $missing)
        . (PHP_OS_FAMILY === 'Windows' ? ' (php 폴더를 지우고 다시 실행하면 자동으로 다시 설치합니다)' : ' (PHP 설치를 확인해 주세요)'));
}

// ── 2. 처음 실행 준비 ─────────────────────────────────────
foreach (['data', 'data/sessions'] as $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        fail("'$dir' 폴더를 만들지 못했습니다. 프로그램 폴더를 쓰기 가능한 곳(예: 문서, 바탕 화면)으로 옮겨주세요.");
    }
}
$configFile = 'data/config.php';
if (!is_file($configFile)) {
    $key = base64_encode(random_bytes(32));
    $config = <<<PHP
<?php
/**
 * PC 버전 설정 (처음 실행할 때 자동으로 만들어졌습니다)
 *
 * ※ app_key 는 수령자 개인정보를 푸는 열쇠입니다. 바꾸거나 지우면 저장된 수령자 정보를 읽을 수 없습니다.
 *   프로그램을 옮기거나 새 버전으로 바꿀 때는 data 폴더를 통째로 함께 옮기세요.
 */
return [
    'desktop' => true,
    'db' => ['driver' => 'sqlite', 'path' => __DIR__ . '/endgame.sqlite'],
    'app_key' => '$key',
    'app_name' => '끝장전 채팅·상품 관리',
    'privacy_retention_days' => 30,
    'session_lifetime' => 43200,
    'session_path' => __DIR__ . '/sessions',
    'debug' => false,
    'soop_live_api' => 'https://live.sooplive.com/afreeca/player_live_api.php',
];

PHP;
    if (@file_put_contents($configFile, $config) === false) {
        fail('설정 파일을 만들지 못했습니다. 프로그램 폴더의 쓰기 권한을 확인해 주세요.');
    }
    say('처음 실행: 설정 파일과 자료 폴더(data)를 만들었습니다.');
}

// ── 3. 서버 주소(포트) 정하기 ─────────────────────────────
/** @return 'free'|'ours'|'other' */
function probe(int $port): string
{
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
    if (!$fp) {
        return 'free';
    }
    fclose($fp);
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $body = @file_get_contents("http://127.0.0.1:$port/__endgame_ping", false, $ctx);
    return $body === 'endgame-ok' ? 'ours' : 'other';
}

function open_browser(string $url): void
{
    if (getenv('ENDGAME_NO_BROWSER')) {
        return;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        // 사용자 폴더(한글 이름일 수 있음)보다 Program Files 쪽을 먼저 찾습니다.
        $candidates = [
            getenv('ProgramFiles') . '\\Google\\Chrome\\Application\\chrome.exe',
            getenv('ProgramFiles(x86)') . '\\Google\\Chrome\\Application\\chrome.exe',
            getenv('ProgramFiles(x86)') . '\\Microsoft\\Edge\\Application\\msedge.exe',
            getenv('ProgramFiles') . '\\Microsoft\\Edge\\Application\\msedge.exe',
            getenv('LOCALAPPDATA') . '\\Google\\Chrome\\Application\\chrome.exe',
        ];
        foreach ($candidates as $exe) {
            if ($exe !== '' && is_file($exe)) {
                // --app: 주소창 없는 프로그램 창으로 엽니다.
                pclose(popen('start "" "' . $exe . '" --app=' . $url . ' --window-size=1400,900', 'r'));
                return;
            }
        }
        pclose(popen('start "" "' . $url . '"', 'r'));
        return;
    }
    if (PHP_OS_FAMILY === 'Darwin') {
        if (is_dir('/Applications/Google Chrome.app')) {
            exec('open -na "Google Chrome" --args --app=' . escapeshellarg($url) . ' --window-size=1400,900');
        } else {
            exec('open ' . escapeshellarg($url));
        }
        return;
    }
    exec('xdg-open ' . escapeshellarg($url) . ' >/dev/null 2>&1 &');
}

$port = null;
for ($p = FIRST_PORT; $p <= LAST_PORT; $p++) {
    $state = probe($p);
    if ($state === 'ours') {
        $url = "http://127.0.0.1:$p/";
        say('이미 실행 중입니다. 프로그램 창을 엽니다: ' . $url);
        open_browser($url);
        exit(0);
    }
    if ($state === 'free') {
        $port = $p;
        break;
    }
}
if ($port === null) {
    fail('사용할 수 있는 포트(' . FIRST_PORT . '~' . LAST_PORT . ')가 없습니다. 컴퓨터를 다시 시작한 뒤 실행해 주세요.');
}
if ($port !== FIRST_PORT) {
    say("알림: 기본 포트 " . FIRST_PORT . " 을 다른 프로그램이 쓰고 있어 $port 으로 실행합니다.");
    say('      (포트가 바뀌면 브라우저 백업 저장소도 따로 보입니다. 가능하면 다른 프로그램을 끄고 다시 실행하세요)');
}

// ── 4. 서버 켜기 ──────────────────────────────────────────
// 프로그램 폴더 안의 PHP(Windows)는 상대 경로로 실행합니다. (한글 폴더 경로 문제 방지)
$binary = PHP_BINARY;
$prefix = rtrim($root, '\\/') . DIRECTORY_SEPARATOR;
if (str_starts_with($binary, $prefix)) {
    $binary = '.' . DIRECTORY_SEPARATOR . substr($binary, strlen($prefix));
}
$cmd = [$binary];
// 실행기와 같은 PHP 설정(확장 기능 위치, 인증서)을 서버에도 그대로 넘깁니다.
foreach (['extension_dir', 'curl.cainfo', 'openssl.cafile'] as $ini) {
    $value = (string) ini_get($ini);
    if ($value !== '') {
        array_push($cmd, '-d', "$ini=$value");
    }
}
array_push($cmd, '-S', "127.0.0.1:$port", '-t', 'www', 'desktop' . DIRECTORY_SEPARATOR . 'router.php');

$env = getenv();
if (PHP_OS_FAMILY !== 'Windows') {
    $env['PHP_CLI_SERVER_WORKERS'] = '4'; // 수집 저장과 화면 조회를 동시에 처리
}
$log = 'data' . DIRECTORY_SEPARATOR . 'server.log';
$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $env);
if (!is_resource($proc)) {
    fail('프로그램 서버를 시작하지 못했습니다.');
}

$ready = false;
for ($i = 0; $i < 40; $i++) {
    usleep(250000);
    if (probe($port) === 'ours') {
        $ready = true;
        break;
    }
    if (!proc_get_status($proc)['running']) {
        break;
    }
}
if (!$ready) {
    $tail = is_file($log) ? implode(PHP_EOL, array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -10)) : '';
    proc_terminate($proc);
    fail('프로그램 서버가 켜지지 않았습니다.' . ($tail !== '' ? PHP_EOL . $tail : ''));
}

$url = "http://127.0.0.1:$port/";
say();
say('실행 중입니다.  주소: ' . $url);
say('자료 위치: ' . $root . DIRECTORY_SEPARATOR . 'data');
say();
say('* 이 창을 닫으면 프로그램이 종료됩니다. 사용하는 동안 켜두세요. (최소화 가능)');
say('* 프로그램 창을 실수로 닫았다면 위 주소를 브라우저에 입력하거나, 실행 파일을 다시 누르세요.');
open_browser($url);

// ── 5. 서버가 켜져 있는 동안 대기 ─────────────────────────
while (proc_get_status($proc)['running']) {
    sleep(1);
}
fail('프로그램 서버가 멈췄습니다. data/server.log 를 확인해 주세요.');
