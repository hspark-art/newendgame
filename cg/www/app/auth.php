<?php
declare(strict_types=1);

/**
 * 웹 버전 계정 (mode=web에서만 사용).
 * - 역할: admin(관리자) / operator(운영자). 상태: pending / active / suspended / rejected
 * - 가입: 관리자가 만든 1회용 초대 링크 → 가입 신청(pending) → 관리자 승인
 * - 비밀번호 재설정: 관리자가 1시간짜리 1회용 링크를 만들어 직접 전달 (메일 없음)
 * - 정지·재설정·비밀번호 변경 시 session_gen이 올라가 기존 로그인이 모두 끊긴다
 * - 링크 토큰은 원문을 저장하지 않고 SHA-256만 저장한다
 */

const AUTH_WINDOW_SEC = 300;
const AUTH_LIMIT_IP = 30;
const AUTH_LIMIT_USER = 10;
const INVITE_MAX_HOURS = 168;
const RESET_HOURS = 1;

// ---------------------------------------------------------------- 세션

/** 앱이 설치된 경로 (예: "/" 또는 "/cg/"). 쿠키 경로로 쓴다. */
function app_base_path(): string
{
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    if (str_ends_with($dir, '/api')) {
        $dir = substr($dir, 0, -4);
    }
    return rtrim($dir, '/') . '/';
}

/**
 * @param bool $write false면 읽기만 하고 바로 닫는다 (폴링 요청이 세션 잠금으로 막히지 않게).
 */
function auth_session(bool $write): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (headers_sent()) {
        throw new RuntimeException('세션을 시작할 수 없습니다 (이미 출력됨).');
    }
    $path = (string)config('session_path', '');
    if ($path !== '') {
        session_save_path($path);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '43200');
    session_name('CGSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => app_base_path(),
        'secure' => is_https(),
        'httponly' => true,
        // Lax: 메신저 등 다른 사이트의 링크로 들어와도 로그인 쿠키가 전달되어 새 세션으로 덮어쓰지 않는다.
        // 조작 요청의 위조 방지는 CSRF 토큰 + 같은 출처(Origin) 확인이 맡는다.
        'samesite' => 'Lax',
    ]);
    session_start($write ? [] : ['read_and_close' => true]);
}

function auth_csrf_token(): string
{
    if (!isset($_SESSION['csrf'])) {
        auth_session(true);
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

/** 로그인한 사용자 (상태·세션 세대 확인). 없거나 무효면 null. */
function auth_user_optional(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    if (!isset($_COOKIE['CGSESS']) && session_status() !== PHP_SESSION_ACTIVE) {
        return $cache = null;
    }
    auth_session(false);
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($uid <= 0) {
        return $cache = null;
    }
    $u = db_one('SELECT * FROM cg_users WHERE id = ?', [$uid]);
    if ($u === null || (int)$u['session_gen'] !== (int)($_SESSION['gen'] ?? -1)
        || !in_array($u['status'], ['active', 'pending'], true)) {
        return $cache = null;
    }
    $u['id'] = (int)$u['id'];
    return $cache = $u;
}

/** 조작 권한(승인된 계정). 없으면 로그인 화면(또는 401)으로 보낸다. */
function auth_require_user(): array
{
    $u = auth_user_optional();
    if ($u === null) {
        if (defined('API_REQUEST')) {
            deny(401, 'LOGIN_REQUIRED', '로그인이 필요합니다.');
        }
        redirect('login.php');
    }
    if ($u['status'] !== 'active') {
        if (defined('API_REQUEST')) {
            deny(403, 'PENDING', '관리자 승인을 기다리는 계정입니다.');
        }
        redirect('pending.php');
    }
    return ['name' => $u['name'], 'role' => $u['role'], 'user_id' => $u['id'], 'username' => $u['username']];
}

function auth_require_admin(): array
{
    $op = auth_require_user();
    if ($op['role'] !== 'admin') {
        deny(403, 'ADMIN_ONLY', '관리자만 사용할 수 있습니다.');
    }
    return $op;
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

// ---------------------------------------------------------------- 입력 규칙

function auth_username(string $s): string
{
    $s = strtolower(trim($s));
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,29}$/D', $s)) {
        throw new ActionError('VALIDATION', '아이디는 영문 소문자·숫자·. _ - 로 3~30자입니다.', 422);
    }
    return $s;
}

function auth_display_name(string $s): string
{
    $s = trim($s);
    if ($s === '' || mb_strlen($s) > 30 || preg_match('/[\x00-\x1F\x7F<>]/u', $s)) {
        throw new ActionError('VALIDATION', '이름은 1~30자로 입력하세요.', 422);
    }
    return $s;
}

function auth_password_check(string $pw, string $again): void
{
    $len = mb_strlen($pw);
    if ($len < 12 || $len > 128) {
        throw new ActionError('VALIDATION', '비밀번호는 12~128자입니다.', 422);
    }
    if ($pw !== $again) {
        throw new ActionError('VALIDATION', '비밀번호 확인이 일치하지 않습니다.', 422);
    }
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

// ---------------------------------------------------------------- 시도 제한 (5분 창)

function attempt_blocked(string $bucket, int $limit): bool
{
    $row = db_one('SELECT hits, window_start FROM cg_attempts WHERE bucket = ?', [$bucket]);
    return $row !== null && strtotime($row['window_start']) > time() - AUTH_WINDOW_SEC && (int)$row['hits'] >= $limit;
}

function attempt_fail(string $bucket): void
{
    $row = db_one('SELECT hits, window_start FROM cg_attempts WHERE bucket = ?', [$bucket]);
    if ($row === null) {
        db_exec('INSERT INTO cg_attempts (bucket, hits, window_start) VALUES (?, 1, ?)', [$bucket, now()]);
    } elseif (strtotime($row['window_start']) <= time() - AUTH_WINDOW_SEC) {
        db_exec('UPDATE cg_attempts SET hits = 1, window_start = ? WHERE bucket = ?', [now(), $bucket]);
    } else {
        db_exec('UPDATE cg_attempts SET hits = hits + 1 WHERE bucket = ?', [$bucket]);
    }
}

function attempt_clear(string $bucket): void
{
    db_exec('DELETE FROM cg_attempts WHERE bucket = ?', [$bucket]);
}

// ---------------------------------------------------------------- 로그인

function auth_op(array $u): array
{
    return ['name' => $u['name'] . ' (' . $u['username'] . ')', 'role' => $u['role'], 'user_id' => (int)$u['id']];
}

/**
 * 로그인. 아이디가 없어도 같은 시간이 걸리게 비밀번호 확인을 한다.
 * @return array 로그인한 사용자
 */
function auth_login(string $username, string $password): array
{
    $username = strtolower(trim($username));
    $ipBucket = 'ip:' . client_ip();
    $userBucket = 'user:' . mb_substr($username, 0, 60);
    // 확인 전에 먼저 센다: 동시에 여러 요청을 보내도 제한을 넘을 수 없다 (트랜잭션이 한 줄로 처리)
    $blocked = db_tx(function () use ($ipBucket, $userBucket) {
        if (attempt_blocked($ipBucket, AUTH_LIMIT_IP) || attempt_blocked($userBucket, AUTH_LIMIT_USER)) {
            return true;
        }
        attempt_fail($ipBucket);
        attempt_fail($userBucket);
        return false;
    });
    if ($blocked) {
        throw new ActionError('RATE_LIMIT', '로그인 시도가 너무 많습니다. 5분 뒤 다시 시도하세요.', 429);
    }
    $u = $username === '' ? null : db_one('SELECT * FROM cg_users WHERE username = ?', [$username]);
    static $dummy = null;
    $dummy ??= password_hash('timing-equalizer', PASSWORD_DEFAULT);
    $ok = password_verify($password, $u['password_hash'] ?? $dummy) && $u !== null;
    if (!$ok) {
        throw new ActionError('LOGIN_FAILED', '아이디 또는 비밀번호가 맞지 않습니다.', 401);
    }
    if (!in_array($u['status'], ['active', 'pending'], true)) {
        throw new ActionError('ACCOUNT_BLOCKED', $u['status'] === 'suspended'
            ? '정지된 계정입니다. 관리자에게 문의하세요.' : '가입이 거절된 계정입니다.', 403);
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE cg_users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    db_tx(function () use ($u, $userBucket) {
        attempt_clear($userBucket);
        db_exec('UPDATE cg_users SET last_login_at = ? WHERE id = ?', [now(), $u['id']]);
        cg_log('auth', 'LOGIN', auth_op($u), ['detail' => client_ip()]);
    });
    return $u;
}

/** 로그인 성공 후 세션 고정 공격을 막기 위해 세션 ID를 새로 만든다. */
function auth_start_session_for(array $u): void
{
    auth_session(true);
    session_regenerate_id(true);
    $_SESSION = ['uid' => (int)$u['id'], 'gen' => (int)$u['session_gen'], 'csrf' => bin2hex(random_bytes(32))];
}

function auth_logout(): void
{
    auth_session(true);
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
        'httponly' => true, 'samesite' => 'Lax']);
    session_destroy();
}

// ---------------------------------------------------------------- 설치 (첫 관리자)

function auth_user_count(): int
{
    return (int)db_value('SELECT COUNT(*) FROM cg_users');
}

function auth_install_admin(string $username, string $name, string $pw, string $again): array
{
    $username = auth_username($username);
    $name = auth_display_name($name);
    auth_password_check($pw, $again);
    return db_tx(function () use ($username, $name, $pw) {
        if (auth_user_count() > 0) {
            throw new ActionError('ALREADY_INSTALLED', '이미 관리자 계정이 있습니다.', 409);
        }
        db_exec('INSERT INTO cg_users (username, password_hash, name, role, status, created_at, approved_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)', [$username, password_hash($pw, PASSWORD_DEFAULT), $name, 'admin', 'active', now(), now()]);
        $u = db_one('SELECT * FROM cg_users WHERE id = ?', [db_last_id()]);
        cg_log('auth', 'INSTALL', auth_op($u), ['detail' => '첫 관리자 생성']);
        return $u;
    });
}

// ---------------------------------------------------------------- 초대·재설정 링크

/** @return string 링크 토큰 원문 (한 번만 보여 준다) */
function link_create(string $kind, ?int $userId, string $label, int $hours, array $admin): string
{
    $token = rand_token(32);
    db_exec('INSERT INTO cg_links (token_hash, kind, user_id, label, created_by, expires_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)',
        [hash('sha256', $token), $kind, $userId, $label, (int)$admin['user_id'], date('Y-m-d H:i:s', time() + $hours * 3600), now()]);
    return $token;
}

/** 사용 가능한 링크 (만료·사용·취소 아님) */
function link_valid(string $token, string $kind): ?array
{
    if ($token === '' || strlen($token) > 100) {
        return null;
    }
    $row = db_one('SELECT * FROM cg_links WHERE token_hash = ? AND kind = ?', [hash('sha256', $token), $kind]);
    if ($row === null || $row['used_at'] !== null || $row['revoked_at'] !== null || strtotime($row['expires_at']) <= time()) {
        return null;
    }
    return $row;
}

function auth_invite_create(string $label, int $hours, array $admin): string
{
    $label = trim($label);
    if (mb_strlen($label) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $label)) {
        throw new ActionError('VALIDATION', '메모는 100자 이내로 입력하세요.', 422);
    }
    if ($hours < 1 || $hours > INVITE_MAX_HOURS) {
        throw new ActionError('VALIDATION', '유효 시간은 1~168시간입니다.', 422);
    }
    return db_tx(function () use ($label, $hours, $admin) {
        $t = link_create('invite', null, $label, $hours, $admin);
        cg_log('auth', 'INVITE', $admin, ['detail' => ($label !== '' ? $label : '메모 없음') . " · {$hours}시간"]);
        return $t;
    });
}

function auth_link_revoke(int $id, array $admin): void
{
    db_tx(function () use ($id, $admin) {
        $row = db_one('SELECT * FROM cg_links WHERE id = ?', [$id]);
        if ($row === null || $row['used_at'] !== null) {
            throw new ActionError('NOT_FOUND', '취소할 수 있는 링크가 아닙니다.', 404);
        }
        db_exec('UPDATE cg_links SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [now(), $id]);
        cg_log('auth', 'LINK_REVOKE', $admin, ['detail' => $row['kind'] . ' · ' . $row['label']]);
    });
}

/** 초대 링크로 가입 신청. 링크 사용과 계정 생성은 한 트랜잭션이다 (같은 링크로 두 명 가입 불가). */
function auth_register(string $token, string $username, string $name, string $pw, string $again): array
{
    $username = auth_username($username);
    $name = auth_display_name($name);
    auth_password_check($pw, $again);
    return db_tx(function () use ($token, $username, $name, $pw) {
        $link = link_valid($token, 'invite');
        if ($link === null) {
            throw new ActionError('LINK_INVALID', '초대 링크가 만료되었거나 이미 사용되었습니다. 관리자에게 새 링크를 요청하세요.', 410);
        }
        if (db_value('SELECT 1 FROM cg_users WHERE username = ?', [$username]) !== null) {
            throw new ActionError('USERNAME_TAKEN', '이미 사용 중인 아이디입니다.', 409);
        }
        db_exec('UPDATE cg_links SET used_at = ? WHERE id = ?', [now(), (int)$link['id']]);
        db_exec('INSERT INTO cg_users (username, password_hash, name, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$username, password_hash($pw, PASSWORD_DEFAULT), $name, 'operator', 'pending', now()]);
        $u = db_one('SELECT * FROM cg_users WHERE id = ?', [db_last_id()]);
        db_exec('UPDATE cg_links SET user_id = ? WHERE id = ?', [(int)$u['id'], (int)$link['id']]);
        cg_log('auth', 'REGISTER', auth_op($u), ['detail' => '가입 신청 · 초대 메모: ' . $link['label']]);
        return $u;
    });
}

function auth_reset_create(int $userId, array $admin): string
{
    return db_tx(function () use ($userId, $admin) {
        $u = db_one('SELECT * FROM cg_users WHERE id = ?', [$userId]);
        if ($u === null) {
            throw new ActionError('NOT_FOUND', '계정을 찾을 수 없습니다.', 404);
        }
        db_exec("UPDATE cg_links SET revoked_at = ? WHERE kind = 'reset' AND user_id = ? AND used_at IS NULL AND revoked_at IS NULL",
            [now(), $userId]);
        $t = link_create('reset', $userId, $u['username'], RESET_HOURS, $admin);
        cg_log('auth', 'RESET_LINK', $admin, ['detail' => $u['username']]);
        return $t;
    });
}

function auth_reset_password(string $token, string $pw, string $again): array
{
    auth_password_check($pw, $again);
    return db_tx(function () use ($token, $pw) {
        $link = link_valid($token, 'reset');
        if ($link === null) {
            throw new ActionError('LINK_INVALID', '재설정 링크가 만료되었거나 이미 사용되었습니다.', 410);
        }
        db_exec('UPDATE cg_links SET used_at = ? WHERE id = ?', [now(), (int)$link['id']]);
        db_exec('UPDATE cg_users SET password_hash = ?, session_gen = session_gen + 1 WHERE id = ?',
            [password_hash($pw, PASSWORD_DEFAULT), (int)$link['user_id']]);
        $u = db_one('SELECT * FROM cg_users WHERE id = ?', [(int)$link['user_id']]);
        cg_log('auth', 'PASSWORD_RESET', auth_op($u), ['detail' => '재설정 링크 사용 · 기존 로그인 종료']);
        return $u;
    });
}

/** 내 계정: 현재 비밀번호 확인 후 변경. 다른 기기의 로그인은 끊기고 이 세션은 유지한다. */
function auth_change_password(int $userId, string $current, string $pw, string $again): array
{
    auth_password_check($pw, $again);
    return db_tx(function () use ($userId, $current, $pw) {
        $u = db_one('SELECT * FROM cg_users WHERE id = ?', [$userId]);
        if ($u === null || !password_verify($current, $u['password_hash'])) {
            throw new ActionError('VALIDATION', '현재 비밀번호가 맞지 않습니다.', 422);
        }
        db_exec('UPDATE cg_users SET password_hash = ?, session_gen = session_gen + 1 WHERE id = ?',
            [password_hash($pw, PASSWORD_DEFAULT), $userId]);
        $u = db_one('SELECT * FROM cg_users WHERE id = ?', [$userId]);
        cg_log('auth', 'PASSWORD_CHANGE', auth_op($u), ['detail' => '다른 로그인 종료']);
        return $u;
    });
}

// ---------------------------------------------------------------- 관리자: 계정 관리

/**
 * 계정 상태·역할 변경. 자기 자신은 바꿀 수 없다 (마지막 관리자가 스스로 잠기는 사고 방지).
 * action: approve | reject | suspend | activate | make_admin | make_operator
 */
function auth_user_update(int $userId, string $action, array $admin): void
{
    db_tx(function () use ($userId, $action, $admin) {
        if ($userId === (int)$admin['user_id']) {
            throw new ActionError('SELF', '자기 계정의 상태·역할은 바꿀 수 없습니다.', 409);
        }
        $u = db_one('SELECT * FROM cg_users WHERE id = ?', [$userId]);
        if ($u === null) {
            throw new ActionError('NOT_FOUND', '계정을 찾을 수 없습니다.', 404);
        }
        $allowed = [
            'approve' => ['pending'], 'reject' => ['pending'], 'suspend' => ['active'],
            'activate' => ['suspended', 'rejected'], 'make_admin' => ['active'], 'make_operator' => ['active'],
        ];
        if (!isset($allowed[$action]) || !in_array($u['status'], $allowed[$action], true)) {
            throw new ActionError('BAD_STATE', '지금 상태에서는 할 수 없는 작업입니다.', 409);
        }
        match ($action) {
            'approve' => db_exec("UPDATE cg_users SET status = 'active', approved_at = ? WHERE id = ?", [now(), $userId]),
            'reject' => db_exec("UPDATE cg_users SET status = 'rejected', session_gen = session_gen + 1 WHERE id = ?", [$userId]),
            'suspend' => db_exec("UPDATE cg_users SET status = 'suspended', session_gen = session_gen + 1 WHERE id = ?", [$userId]),
            'activate' => db_exec("UPDATE cg_users SET status = 'active', approved_at = COALESCE(approved_at, ?) WHERE id = ?", [now(), $userId]),
            'make_admin' => db_exec("UPDATE cg_users SET role = 'admin' WHERE id = ?", [$userId]),
            'make_operator' => db_exec("UPDATE cg_users SET role = 'operator' WHERE id = ?", [$userId]),
        };
        cg_log('auth', 'USER_' . strtoupper($action), $admin, ['detail' => $u['username']]);
    });
}

/** 비밀 송출 주소 재발급: 이전 주소는 즉시 막힌다. */
function output_token_rotate(array $admin): void
{
    db_tx(function () use ($admin) {
        setting_set('output_token', rand_token(32));
        cg_log('auth', 'OUTPUT_ROTATE', $admin, ['detail' => '송출 주소 재발급 (이전 주소 사용 불가)']);
        state_bump();
    });
}
