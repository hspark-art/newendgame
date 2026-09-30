<?php
/**
 * 관리자 로그인·권한
 *
 * 권한 종류
 *  - admin : 전체 기능 + 관리자 계정 관리 + 수령자 개인정보 전체 보기·내보내기
 *  - staff : 조회·수집·상품 지급 관리 (수령자 개인정보는 가려서 표시)
 */
declare(strict_types=1);

const LOGIN_MAX_FAILURES = 5;      // 이 횟수만큼 틀리면
const LOGIN_LOCK_MINUTES = 10;     // 이 시간 동안 로그인 차단

function current_admin(): ?array
{
    static $cache = [];
    $id = (int) ($_SESSION['admin_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = db_one('SELECT id, username, display_name, role FROM admins WHERE id = ? AND is_active = 1', [$id]);
    }
    return $cache[$id];
}

function is_admin_role(): bool
{
    return (current_admin()['role'] ?? '') === 'admin';
}

function require_login(): array
{
    $admin = current_admin();
    if ($admin) {
        return $admin;
    }
    if (defined('API_REQUEST')) {
        json_response(['ok' => false, 'error' => '로그인이 필요합니다.', 'code' => 'LOGIN_REQUIRED'], 401);
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $_SESSION['after_login'] = basename(parse_url($uri, PHP_URL_PATH) ?: 'index.php')
        . (($q = parse_url($uri, PHP_URL_QUERY)) ? '?' . $q : '');
    redirect('login.php');
}

function require_admin_role(): array
{
    $admin = require_login();
    if ($admin['role'] !== 'admin') {
        render_error('관리자(admin) 권한이 필요한 화면입니다.', 403);
    }
    return $admin;
}

function login_locked(string $username): bool
{
    $since = date('Y-m-d H:i:s', time() - LOGIN_LOCK_MINUTES * 60);
    $failures = (int) db_value(
        'SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at >= ? AND (ip = ? OR username = ?)',
        [$since, client_ip(), $username]
    );
    return $failures >= LOGIN_MAX_FAILURES;
}

/** 로그인 시도. 성공하면 null, 실패하면 오류 문구를 돌려줍니다. */
function attempt_login(string $username, string $password): ?string
{
    if ($username === '' || $password === '') {
        return '아이디와 비밀번호를 입력해 주세요.';
    }
    if (login_locked($username)) {
        return '로그인 실패가 반복되어 ' . LOGIN_LOCK_MINUTES . '분간 로그인이 제한됩니다.';
    }
    $admin = db_one('SELECT id, password_hash, is_active FROM admins WHERE username = ?', [$username]);
    $ok = $admin && (int) $admin['is_active'] === 1 && password_verify($password, $admin['password_hash']);

    db_exec(
        'INSERT INTO login_attempts (ip, username, success, created_at) VALUES (?, ?, ?, ?)',
        [client_ip(), mb_substr($username, 0, 50), $ok ? 1 : 0, now()]
    );
    // 오래된 기록 정리
    db_exec('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400 * 30)]);

    if (!$ok) {
        return '아이디 또는 비밀번호가 올바르지 않습니다.';
    }
    if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    db_exec('UPDATE admins SET last_login_at = ? WHERE id = ?', [now(), $admin['id']]);
    audit('login');
    return null;
}

function logout(): void
{
    audit('logout');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function password_policy_error(string $password): ?string
{
    if (mb_strlen($password) < 8) {
        return '비밀번호는 8자 이상이어야 합니다.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return '비밀번호에는 영문과 숫자를 함께 넣어주세요.';
    }
    return null;
}
