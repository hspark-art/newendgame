<?php
declare(strict_types=1);

/**
 * 웹 버전 계정 화면(로그인·가입·재설정·내 계정·관리자·설치)의 공용 틀.
 * 폼은 POST + 같은 출처 + CSRF 토큰을 확인한다. PC 모드에서는 이 화면들이 열리지 않는다.
 */

/** 웹 전용 화면 시작. PC 모드면 404. */
function portal_start(): void
{
    app_start('portal');
    if (!is_web()) {
        deny(404, 'NOT_FOUND', '없는 주소입니다.');
    }
    auth_session(true);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(auth_csrf_token()) . '">';
}

/** 폼 제출 확인. 통과하지 못하면 거부. */
function form_check(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        deny(405, 'METHOD', 'POST 요청만 허용됩니다.');
    }
    $sent = (string)($_POST['_csrf'] ?? '');
    if (!same_origin() || $sent === '' || !hash_equals(auth_csrf_token(), $sent)) {
        deny(403, 'CSRF', '보안 확인에 실패했습니다. 화면을 새로고침한 뒤 다시 시도하세요.');
    }
}

function post_str(string $key, int $max = 200): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? mb_substr($v, 0, $max) : '';
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function portal_head(string $title, ?array $user = null, bool $wide = false): void
{
    ?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> — 끝장전 CG</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="<?= h(asset_url('portal.css')) ?>">
</head>
<body>
<header class="bar">
  <a class="brand" href="index.php">끝장전 CG</a> <span class="ver">v<?= h(APP_VERSION) ?> · 웹</span>
<?php if ($user !== null): ?>
  <nav>
    <span class="who"><?= h($user['name']) ?> (<?= h($user['username']) ?>)</span>
    <?php if ($user['status'] === 'active'): ?><a href="index.php">조작 패널</a><?php endif ?>
    <?php if ($user['role'] === 'admin' && $user['status'] === 'active'): ?><a href="admin.php">관리자</a><?php endif ?>
    <?php if ($user['status'] === 'active'): ?><a href="account.php">내 계정</a><?php endif ?>
    <form method="post" action="logout.php" class="inline"><?= csrf_field() ?><button class="link">로그아웃</button></form>
  </nav>
<?php endif ?>
</header>
<main class="<?= $wide ? 'wide' : 'narrow' ?>">
  <h1><?= h($title) ?></h1>
<?php foreach (take_flashes() as [$type, $msg]): ?>
  <p class="msg <?= h($type) ?>"><?= h($msg) ?></p>
<?php endforeach ?>
<?php
}

function portal_foot(): void
{
    echo "</main>\n</body>\n</html>\n";
}

/** 오류 메시지 한 줄 */
function portal_error(?string $msg): void
{
    if ($msg !== null && $msg !== '') {
        echo '<p class="msg err">' . h($msg) . "</p>\n";
    }
}
