<?php
// 관리자 인증 공통 — 로그인 여부 판단과 세션 설정만 합니다.
declare(strict_types=1);

const ADMIN_CONFIG = __DIR__ . '/config.php';
const LOGIN_WINDOW = 600;    // 10분 동안
const LOGIN_TRIES  = 8;      // 8번 틀리면 잠급니다

function admin_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $https,
    ]);
    session_name('SCADMIN');
    session_start();
}

/** 설정 파일이 있으면 배열로, 없으면 null. */
function admin_config(): ?array
{
    if (!is_file(ADMIN_CONFIG)) {
        return null;
    }
    $cfg = include ADMIN_CONFIG;
    return (is_array($cfg) && isset($cfg['user'], $cfg['hash'])) ? $cfg : null;
}

function admin_save_config(string $user, string $password): bool
{
    $php = "<?php\n// 관리자 계정. 이 파일은 저장소에 올리지 마세요.\nreturn "
        . var_export(['user' => $user,
                      'hash' => password_hash($password, PASSWORD_DEFAULT)], true)
        . ";\n";
    $ok = @file_put_contents(ADMIN_CONFIG, $php, LOCK_EX) !== false;
    if ($ok) {
        @chmod(ADMIN_CONFIG, 0600);
    }
    return $ok;
}

function admin_logged_in(): bool
{
    admin_boot();
    return !empty($_SESSION['admin_user']);
}

/** 로그인 안 했으면 로그인 화면으로 보냅니다. */
function admin_require_login(): void
{
    if (admin_logged_in()) {
        return;
    }
    $to = basename($_SERVER['SCRIPT_NAME'] ?? '');
    header('Location: index.php' . ($to ? '?next=' . rawurlencode($to) : ''));
    exit;
}

function admin_user(): string
{
    admin_boot();
    return (string)($_SESSION['admin_user'] ?? '');
}

/** 남은 시도 횟수. 0 이면 잠긴 상태입니다. */
function admin_tries_left(): int
{
    admin_boot();
    $f = $_SESSION['fail'] ?? null;
    if (!$f || (time() - $f['at']) > LOGIN_WINDOW) {
        return LOGIN_TRIES;
    }
    return max(0, LOGIN_TRIES - $f['n']);
}

function admin_note_fail(): void
{
    admin_boot();
    $f = $_SESSION['fail'] ?? null;
    if (!$f || (time() - $f['at']) > LOGIN_WINDOW) {
        $f = ['n' => 0, 'at' => time()];
    }
    $f['n']++;
    $f['at'] = time();
    $_SESSION['fail'] = $f;
}

function admin_login(string $user, string $password): bool
{
    admin_boot();
    if (admin_tries_left() <= 0) {
        return false;
    }
    $cfg = admin_config();
    if (!$cfg) {
        return false;
    }
    // 아이디가 틀려도 같은 시간이 걸리도록 항상 검증을 돌립니다.
    $userOk = hash_equals((string)$cfg['user'], $user);
    $passOk = password_verify($password, (string)$cfg['hash']);
    if (!($userOk && $passOk)) {
        admin_note_fail();
        return false;
    }
    session_regenerate_id(true);          // 세션 고정 공격 방어
    $_SESSION['admin_user'] = $cfg['user'];
    unset($_SESSION['fail']);
    return true;
}

function admin_logout(): void
{
    admin_boot();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
                  $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function admin_csrf(): string
{
    admin_boot();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function admin_csrf_ok(?string $token): bool
{
    admin_boot();
    return !empty($_SESSION['csrf']) && is_string($token)
        && hash_equals($_SESSION['csrf'], $token);
}
