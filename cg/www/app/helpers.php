<?php
declare(strict_types=1);

/**
 * 공용 도우미: 설정, 출력, 오류 형식.
 */

/** 조작 중 발생한 사용자용 오류. code는 화면·테스트가 구분하는 짧은 이름이다. */
final class ActionError extends RuntimeException
{
    public function __construct(
        public readonly string $errCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}

/** 설정 값. 점으로 하위 키를 찾는다: config('db.driver') */
function config(string $key, mixed $default = null): mixed
{
    $cur = $GLOBALS['CG_CONFIG'] ?? [];
    foreach (explode('.', $key) as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) {
            return $default;
        }
        $cur = $cur[$part];
    }
    return $cur;
}

/** 설정 파일 위치: 환경변수 CG_CONFIG(PC 실행기) → app/config.php(웹) */
function config_path(): string
{
    $env = getenv('CG_CONFIG');
    return ($env !== false && $env !== '') ? $env : APP_DIR . '/config.php';
}

function config_load(): bool
{
    if (isset($GLOBALS['CG_CONFIG'])) {
        return true;
    }
    $path = config_path();
    if (!is_file($path)) {
        return false;
    }
    $cfg = require $path;
    if (!is_array($cfg) || !in_array($cfg['mode'] ?? '', ['desktop', 'web'], true)) {
        throw new RuntimeException('설정 파일의 mode는 desktop 또는 web이어야 합니다.');
    }
    $GLOBALS['CG_CONFIG'] = $cfg;
    return true;
}

function is_desktop(): bool
{
    return config('mode') === 'desktop';
}

function is_web(): bool
{
    return config('mode') === 'web';
}

function storage_dir(): string
{
    $dir = (string)config('storage_dir', APP_DIR . '/storage');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_enc(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function json_dec(?string $s): mixed
{
    return $s === null ? null : json_decode($s, true, 64, JSON_THROW_ON_ERROR);
}

function rand_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_enc($data);
    exit;
}

/** JSON 요청 본문 (최대 64KB) */
function request_json(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 65536);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new ActionError('BAD_REQUEST', '요청 형식이 올바르지 않습니다.', 400);
    }
    return $data;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/** 현재 접속 주소의 기준 URL (예: http://127.0.0.1:3100) */
function base_url(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (str_ends_with($dir, '/api')) {
        $dir = substr($dir, 0, -4);
    }
    return (is_https() ? 'https://' : 'http://') . $host . $dir;
}

/** 보안 헤더. kind: panel | output | api | portal */
function send_headers(string $kind): void
{
    if (headers_sent()) {
        return;
    }
    $frame = $kind === 'output' ? "'self'" : "'none'";
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors $frame");
    header('X-Content-Type-Options: nosniff');
    // 송출 화면은 주소에 비밀 토큰이 있으므로 Referer를 보내지 않는다.
    // 나머지는 same-origin: 폼 제출 때 브라우저가 Origin을 정상적으로 보내야 같은 출처 확인이 된다
    // (no-referrer면 폼 POST의 Origin이 'null'이 됨).
    header('Referrer-Policy: ' . ($kind === 'output' ? 'no-referrer' : 'same-origin'));
    if ($kind !== 'output') {
        header('X-Frame-Options: DENY');
    }
    if ($kind === 'api' || $kind === 'output' || $kind === 'portal') {
        header('Cache-Control: no-store');
    }
    if (is_web() && is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function asset_url(string $file): string
{
    return 'assets/' . $file . '?v=' . rawurlencode(APP_VERSION);
}

/** 예상하지 못한 오류: 기록하고, 내부 내용은 화면에 보여 주지 않는다. */
function handle_uncaught_exception(Throwable $e): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . get_class($e) . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    @file_put_contents(storage_dir() . '/error.log', $line, FILE_APPEND | LOCK_EX);
    $msg = config('debug') ? $e->getMessage() : '서버 오류가 발생했습니다. 잠시 후 다시 시도하세요.';
    if (defined('API_REQUEST')) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_enc(['ok' => false, 'code' => 'SERVER_ERROR', 'error' => $msg]);
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>오류</title><p>' . h($msg) . '</p>';
}
