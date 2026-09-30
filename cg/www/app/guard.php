<?php
declare(strict_types=1);

/**
 * 접근 규칙.
 * - desktop: 조작 화면·API는 이 PC(루프백)에서, 허용된 Host 이름으로 접속했을 때만.
 * - web: 로그인한 승인 계정만 (auth.php).
 * 조작 요청(POST)은 공통으로 같은 출처(Origin), CSRF 토큰, JSON 형식을 확인한다.
 */

function client_is_loopback(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/** DNS 리바인딩 방지: Host 머리글이 로컬 주소 이름이어야 한다. */
function desktop_host_allowed(): bool
{
    $port = (string)($_SERVER['SERVER_PORT'] ?? '');
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    return in_array($host, ["127.0.0.1:$port", "localhost:$port", "[::1]:$port"], true);
}

function deny(int $status, string $code, string $message): never
{
    if (defined('API_REQUEST')) {
        json_response(['ok' => false, 'code' => $code, 'error' => $message], $status);
    }
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><link rel="icon" href="data:,"><title>' . h((string)$status) . '</title><p>' . h($message) . '</p>';
    exit;
}

/**
 * 조작 권한 확인. 통과하면 현재 운영자 정보를 돌려준다.
 * @return array{name:string, role:string, user_id:?int}
 */
function guard_control(): array
{
    if (is_desktop()) {
        if (!client_is_loopback() || !desktop_host_allowed()) {
            deny(403, 'LOCAL_ONLY', '조작 화면은 프로그램을 실행한 PC에서만 열 수 있습니다.');
        }
        return ['name' => (string)config('operator', '운영자'), 'role' => 'admin', 'user_id' => null];
    }
    return auth_require_user();
}

function csrf_token(): string
{
    if (is_desktop()) {
        return hash_hmac('sha256', 'cg-control', (string)setting_get('csrf_secret', ''));
    }
    return auth_csrf_token();
}

/** 조작 요청 공통 확인: POST, 같은 출처, CSRF 토큰, JSON 본문 */
function guard_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        deny(405, 'METHOD', 'POST 요청만 허용됩니다.');
    }
    if (!same_origin()) {
        deny(403, 'ORIGIN', '다른 사이트에서 보낸 요청은 처리하지 않습니다.');
    }
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        deny(403, 'CSRF', '보안 토큰이 맞지 않습니다. 화면을 새로고침하세요.');
    }
    $type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (!str_starts_with($type, 'application/json')) {
        deny(415, 'CONTENT_TYPE', 'JSON 요청만 허용됩니다.');
    }
}

/** 송출 화면 요청 값: [채널, 조작 패널용 모니터 여부, 레이어] */
function output_request(): array
{
    $ch = ($_GET['ch'] ?? 'program') === 'preview' ? 'preview' : 'program';
    $ghost = ($_GET['ghost'] ?? '') === '1';
    $layer = ctype_digit((string)($_GET['layer'] ?? '1')) ? (int)$_GET['layer'] : 1;
    return [$ch, $ghost, max(1, min(8, $layer))];
}

/** 만들어지지 않은 레이어면 404 (지금은 레이어 1만 있음) */
function output_layer_check(int $layer): void
{
    if (db_value('SELECT 1 FROM cg_channels WHERE layer = ? AND kind = ?', [$layer, 'program']) === null) {
        deny(404, 'NO_LAYER', '없는 레이어입니다.');
    }
}

/**
 * 송출 화면 접근 규칙.
 * - PROGRAM 송출(OBS/vMix): PC는 서버에 닿으면 허용(LAN 모드 포함), 웹은 비밀 출력 주소(t) 또는 로그인 사용자.
 * - PREVIEW·패널 모니터(ghost): 조작 권한이 있어야 한다. 방송 전 준비 화면이 밖으로 나가지 않게 한다.
 */
function output_access(string $ch, bool $ghost): void
{
    if ($ch === 'program' && !$ghost) {
        if (is_desktop()) {
            return;
        }
        $t = (string)($_GET['t'] ?? '');
        if ($t !== '' && hash_equals((string)setting_get('output_token', ''), $t)) {
            return;
        }
        if ((auth_user_optional()['status'] ?? '') === 'active') {
            return;
        }
        deny(404, 'NOT_FOUND', '없는 주소입니다.');
    }
    guard_control();
}

/** Origin 머리글이 현재 접속 주소와 같은지 (없으면 거부) */
function same_origin(): bool
{
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return false;
    }
    $expected = (is_https() ? 'https://' : 'http://') . strtolower($_SERVER['HTTP_HOST'] ?? '');
    return strtolower(rtrim($origin, '/')) === $expected;
}
