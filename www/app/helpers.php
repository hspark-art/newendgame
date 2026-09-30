<?php
/**
 * 공통 도우미 함수
 */
declare(strict_types=1);

// ── 설정 ───────────────────────────────────────────────────
function config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['APP_CONFIG'][$key] ?? $default;
}

function app_configured(): bool
{
    $db = config('db');
    if (!is_array($db)) {
        return false;
    }
    if (($db['driver'] ?? 'mysql') === 'sqlite') {
        return !empty($db['path']);
    }
    return !empty($db['name']) && !empty($db['user']);
}

/** PC 버전(내 컴퓨터에서 실행)인지 */
function is_desktop(): bool
{
    return (bool) config('desktop', false);
}

// ── 출력·이동 ──────────────────────────────────────────────
function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** 현재 페이지의 쿼리 문자열 일부를 바꾼 주소를 만듭니다. */
function url_with(array $changes, ?string $page = null): string
{
    $page ??= basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $query = array_merge($_GET, $changes);
    $query = array_filter($query, fn($v) => $v !== null && $v !== '');
    return $page . ($query ? '?' . http_build_query($query) : '');
}

/** 이전 화면 주소 (같은 사이트일 때만). 아니면 $fallback */
function safe_back(string $fallback): string
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $myHost = explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0];
    if ($ref === '' || parse_url($ref, PHP_URL_HOST) !== $myHost) {
        return $fallback;
    }
    $path = basename((string) parse_url($ref, PHP_URL_PATH));
    $query = parse_url($ref, PHP_URL_QUERY);
    return local_url_or($path . ($query ? '?' . $query : ''), $fallback);
}

/** 사이트 안쪽 주소인지 확인 (예: viewer.php?id=1) */
function local_url_or(string $url, string $fallback): string
{
    return preg_match('/^[A-Za-z0-9_-]+\.php(\?[^\r\n]*)?$/', $url) ? $url : $fallback;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

// ── 입력값 ─────────────────────────────────────────────────
function input_str(string $key, string $default = '', int $maxLen = 1000): string
{
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    if (!is_string($value)) {
        return $default;
    }
    return mb_substr(trim($value), 0, $maxLen);
}

function input_int(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? $_GET[$key] ?? null;
    if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
        return (int) trim($value);
    }
    return $default;
}

/** datetime-local 입력값(2026-10-01T13:00)을 DB 형식으로 바꿉니다. 형식이 틀리면 null. */
function input_datetime(string $key): ?string
{
    $value = input_str($key, '', 30);
    if ($value === '') {
        return null;
    }
    $value = str_replace('T', ' ', $value);
    $ts = strtotime($value);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

function to_datetime_local(?string $dbValue): string
{
    if (!$dbValue) {
        return '';
    }
    $ts = strtotime($dbValue);
    return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
}

// ── CSRF 보호 ──────────────────────────────────────────────
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_valid(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/** POST 요청의 CSRF 토큰을 확인합니다. 실패하면 중단합니다. */
function csrf_check(): void
{
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!csrf_valid(is_string($token) ? $token : null)) {
        render_error('보안 확인에 실패했습니다. 페이지를 새로고침한 뒤 다시 시도해 주세요.', 400);
    }
}

// ── 알림 메시지 ────────────────────────────────────────────
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

// ── 표시 형식 ──────────────────────────────────────────────
function fmt_num(int|float|string|null $n): string
{
    return number_format((float) ($n ?? 0));
}

function fmt_dt(?string $value, string $format = 'm-d H:i:s'): string
{
    if (!$value) {
        return '-';
    }
    $ts = strtotime($value);
    return $ts === false ? '-' : date($format, $ts);
}

function fmt_duration(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h > 0 ? "{$h}시간 {$m}분" : "{$m}분";
}

// ── 시청자 아이디 ──────────────────────────────────────────
/**
 * SOOP 은 같은 계정이 여러 곳에서 접속하면 아이디 뒤에 (2) 같은 번호를 붙입니다.
 * 집계할 때는 번호를 뗀 기본 아이디로 합산합니다. (원래 아이디는 raw_user_id 에 보관)
 */
function normalize_user_id(string $rawId): string
{
    return preg_replace('/\(\d+\)$/', '', trim($rawId)) ?? trim($rawId);
}

/** 사용자 배지(권한) 값 */
const BADGE_BJ = 1;
const BADGE_MANAGER = 2;
const BADGE_TOPFAN = 4;
const BADGE_FAN = 8;
const BADGE_SUBSCRIBER = 16;
const BADGE_ADMIN = 32;

/**
 * 배지 표시: [BJ][매] [구][열|F]
 * 기존 끝장전 관제 화면과 같은 모양·색 (구독 파랑, 열혈 빨강, 팬 초록). 열혈이면 팬 배지는 생략합니다.
 */
function badge_labels(int $badges): array
{
    $labels = [];
    if ($badges & BADGE_BJ) $labels[] = ['bj', 'BJ', '방송인'];
    if ($badges & BADGE_MANAGER) $labels[] = ['mgr', '매', '매니저'];
    if ($badges & BADGE_SUBSCRIBER) $labels[] = ['sub', '구', '구독자'];
    if ($badges & BADGE_TOPFAN) {
        $labels[] = ['yeol', '열', '열혈팬'];
    } elseif ($badges & BADGE_FAN) {
        $labels[] = ['fan', 'F', '팬클럽'];
    }
    return $labels;
}

function render_badges(int $badges): string
{
    $html = '';
    foreach (badge_labels($badges) as [$class, $label, $title]) {
        $html .= '<span class="fb ' . $class . '" title="' . h($title) . '">' . h($label) . '</span>';
    }
    return $html;
}

/** 닉네임 색 class: 열혈 > 구독 > 팬 > 일반 */
function nick_class(int $badges): string
{
    return 'nk' . match (true) {
        (bool) ($badges & BADGE_TOPFAN)     => ' nk-yeol',
        (bool) ($badges & BADGE_SUBSCRIBER) => ' nk-sub',
        (bool) ($badges & BADGE_FAN)        => ' nk-fan',
        default                             => '',
    };
}

/** 배지 + 색 입힌 닉네임 (링크 주소를 주면 링크로) */
function render_nick(string $nickname, int $badges, ?string $href = null): string
{
    $name = '<b class="' . nick_class($badges) . '">' . h($nickname !== '' ? $nickname : '(알 수 없음)') . '</b>';
    return render_badges($badges) . ($href !== null ? '<a class="nick-link" href="' . h($href) . '">' . $name . '</a>' : $name);
}

/** 순위 표시: 1~3위는 메달 */
function rank_label(int $rank): string
{
    return [1 => '🥇', 2 => '🥈', 3 => '🥉'][$rank] ?? (string) $rank;
}

/** 활약 막대(값 ÷ 최댓값) — 표 칸 배경에 깔리는 그라디언트 */
function actbar_attr(float|int $value, float|int $max): string
{
    $p = $max > 0 ? max(0, min(100, round($value / $max * 100))) : 0;
    return ' style="--p:' . $p . '%"';
}

/** 이 회차 채팅 기록에서 시청자별 배지를 모읍니다. (후원 순위처럼 배지가 없는 목록용) */
function user_badges(int $broadcastId, array $userIds): array
{
    $userIds = array_values(array_unique(array_filter($userIds, fn($v) => $v !== '')));
    if (!$userIds) {
        return [];
    }
    $map = [];
    foreach (db_all(
        'SELECT DISTINCT user_id, badges FROM chat_messages WHERE broadcast_id = ? AND user_id IN (' . db_placeholders($userIds) . ')',
        array_merge([$broadcastId], $userIds)
    ) as $r) {
        $map[$r['user_id']] = ($map[$r['user_id']] ?? 0) | (int) $r['badges'];
    }
    return $map;
}

// ── 개인정보 가림 표시 ─────────────────────────────────────
function mask_name(?string $name): string
{
    $name = trim((string) $name);
    $len = mb_strlen($name);
    if ($len === 0) return '';
    if ($len === 1) return '*';
    if ($len === 2) return mb_substr($name, 0, 1) . '*';
    return mb_substr($name, 0, 1) . str_repeat('*', $len - 2) . mb_substr($name, -1);
}

function mask_phone(?string $phone): string
{
    $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
    if ($digits === '') return '';
    if (strlen($digits) < 8) return str_repeat('*', strlen($digits));
    return substr($digits, 0, 3) . '-****-' . substr($digits, -4);
}

function mask_address(?string $address): string
{
    $parts = preg_split('/\s+/u', trim((string) $address)) ?: [];
    if (!$parts || $parts[0] === '') return '';
    return implode(' ', array_slice($parts, 0, 2)) . ' ***';
}

// ── 화면에서 바꾸는 설정 (settings 테이블) ──────────────────
function setting_get(string $key, string $default = ''): string
{
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = db_value('SELECT v FROM settings WHERE k = ?', [$key]);
    }
    return $cache[$key] === null ? $default : (string) $cache[$key];
}

function setting_set(string $key, string $value): void
{
    db_upsert('settings', ['k' => $key, 'v' => $value], ['k'], ['v' => '{new.v}']);
}

/**
 * 업로드 파일 저장 폴더
 * PC 버전: 프로그램 폴더의 data/ 아래 (새 버전으로 바꿔도 유지) / 웹: www/app/storage/ 아래 (주소로 직접 열 수 없음)
 */
function storage_dir(string $sub): string
{
    $base = (string) config('storage_dir', '');
    if ($base === '') {
        $base = is_desktop() ? dirname(APP_ROOT) . '/data' : APP_DIR . '/storage';
    }
    $dir = rtrim($base, '/\\') . '/' . $sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

// ── 작업 기록 ──────────────────────────────────────────────
function audit(string $action, string $target = '', string $detail = ''): void
{
    try {
        db_exec(
            'INSERT INTO audit_logs (admin_id, action, target, detail, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [current_admin()['id'] ?? null, $action, mb_substr($target, 0, 100), mb_substr($detail, 0, 2000), client_ip(), now()]
        );
    } catch (Throwable) {
        // 기록 실패가 본 작업을 막지 않도록 합니다.
    }
}

// ── 세션·보안 헤더 ─────────────────────────────────────────
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $lifetime = (int) config('session_lifetime', 43200);
    // 세션 파일은 기본적으로 호스팅의 기본 위치(웹 폴더 밖)에 저장합니다.
    // 호스팅이 세션을 너무 빨리 지워 자주 로그아웃된다면 config.php 의 session_path 에
    // 웹 폴더 밖의 쓰기 가능한 폴더를 지정하세요.
    $path = (string) config('session_path', '');
    if ($path !== '' && is_dir($path) && is_writable($path)) {
        session_save_path($path);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('EGSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    // 실시간 수집 화면이 SOOP 채팅 서버(wss://)에 직접 연결하므로 connect-src 에 wss: 를 허용합니다.
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self' data:; img-src 'self' data: blob:; connect-src 'self' wss:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

function handle_uncaught_exception(Throwable $e): void
{
    // 오류 기록은 .php 파일로 저장하고 첫 줄에 exit 를 넣어, 주소로 열어도 내용이 보이지 않게 합니다.
    $logDir = APP_DIR . '/storage';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0700, true);
    }
    $logFile = $logDir . '/error_log.php';
    if (!is_file($logFile)) {
        @file_put_contents($logFile, "<?php exit; ?>\n");
    }
    @error_log(sprintf("[%s] %s in %s:%d\n%s\n", now(), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()), 3, $logFile);

    $message = config('debug') ? $e->getMessage() : '처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.';
    if ($e instanceof PDOException && !config('debug')) {
        $message = 'DB 처리 중 오류가 발생했습니다. DB 접속 정보와 상태를 확인해 주세요.';
    }
    if (defined('API_REQUEST')) {
        json_response(['ok' => false, 'error' => $message], 500);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!doctype html><meta charset="utf-8"><title>오류</title>'
        . '<div style="font-family:sans-serif;max-width:640px;margin:60px auto;padding:0 16px">'
        . '<h2>오류가 발생했습니다</h2><p>' . h($message) . '</p><p><a href="index.php">처음으로</a></p></div>';
}
