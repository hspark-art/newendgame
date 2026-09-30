<?php
/**
 * 화면 공통 틀 (상단 메뉴, 하단, 오류 화면, 페이지 번호)
 */
declare(strict_types=1);

/**
 * @param array{broadcast?:array, tab?:string, menu?:string, bare?:bool, scripts?:string[]} $opt
 */
function page_header(string $title, array $opt = []): void
{
    $admin = current_admin();
    $appName = (string) config('app_name', '끝장전 채팅·상품 관리');
    $menu = $opt['menu'] ?? '';
    ?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<title><?= h($title) ?> · <?= h($appName) ?></title>
<link rel="stylesheet" href="assets/app.css?v=<?= h(APP_VERSION) ?>">
</head>
<body class="<?= h($opt['body_class'] ?? '') ?>">
<div class="brandbar"></div>
<?php if (empty($opt['bare']) && $admin): ?>
<header class="topbar">
  <a class="brand" href="index.php"><?= h($appName) ?></a>
  <a id="live-ind" class="live-ind hidden" href="#" title="수집 창으로 이동"></a>
  <nav class="mainnav">
    <a href="index.php" class="<?= $menu === 'broadcasts' ? 'active' : '' ?>">방송 회차</a>
    <a href="cumulative.php" class="<?= $menu === 'cumulative' ? 'active' : '' ?>">누적 순위</a>
    <a href="prizes.php" class="<?= $menu === 'prizes' ? 'active' : '' ?>">상품 지급</a>
    <a href="items.php" class="<?= $menu === 'items' ? 'active' : '' ?>">상품 목록</a>
    <a href="excluded.php" class="<?= $menu === 'excluded' ? 'active' : '' ?>">제외 명단</a>
    <?php if ($admin['role'] === 'admin'): ?>
      <a href="settings.php" class="<?= $menu === 'settings' ? 'active' : '' ?>">설정</a>
      <a href="admins.php" class="<?= $menu === 'admins' ? 'active' : '' ?>">관리자</a>
      <a href="logs.php" class="<?= $menu === 'logs' ? 'active' : '' ?>">작업 기록</a>
    <?php endif; ?>
  </nav>
  <div class="usernav">
    <a href="account.php" class="<?= $menu === 'account' ? 'active' : '' ?>"><?= h($admin['display_name']) ?></a>
    <form method="post" action="logout.php" class="inline">
      <?= csrf_field() ?>
      <button type="submit" class="linklike">로그아웃</button>
    </form>
  </div>
</header>
<?php endif; ?>
<main class="container<?= !empty($opt['wide']) ? ' wide' : '' ?>">
<?php
    if (!empty($opt['broadcast'])) {
        broadcast_tabs($opt['broadcast'], $opt['tab'] ?? '');
    }
    foreach (take_flashes() as $f) {
        echo '<div class="alert alert-' . h($f['type']) . '">' . h($f['message']) . '</div>';
    }
}

function broadcast_tabs(array $b, string $tab): void
{
    $id = (int) $b['id'];
    $tabs = [
        'summary'   => ['broadcast.php', '요약'],
        'collector' => ['collector.php', '실시간 수집'],
        'chats'     => ['chats.php', '채팅 검색'],
        'donations' => ['donations.php', '후원 순위'],
        'activity'  => ['activity.php', '채팅 활동량'],
        'import'    => ['import.php', '백업 업로드'],
    ];
    ?>
<div class="bc-head">
  <div>
    <div class="bc-title"><?= h($b['title']) ?></div>
    <div class="muted small"><?= h($b['broadcast_date']) ?> · SOOP 방송국 ID <strong><?= h($b['streamer_id']) ?></strong></div>
  </div>
</div>
<nav class="tabs">
  <?php foreach ($tabs as $key => [$page, $label]): ?>
    <a href="<?= h($page) ?>?id=<?= $id ?>" class="<?= $tab === $key ? 'active' : '' ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php
}

function page_footer(array $scripts = []): void
{
    ?>
</main>
<footer class="footer muted small">v<?= h(APP_VERSION) ?><?= is_desktop() ? ' · PC 버전' : '' ?></footer>
<script src="assets/app.js?v=<?= h(APP_VERSION) ?>"></script>
<?php foreach ($scripts as $src): ?>
<script src="<?= h($src) ?>?v=<?= h(APP_VERSION) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

function render_error(string $message, int $status = 400): never
{
    if (defined('API_REQUEST')) {
        json_response(['ok' => false, 'error' => $message], $status);
    }
    http_response_code($status);
    page_header('안내');
    echo '<div class="card"><p>' . h($message) . '</p><p><a href="index.php">처음으로</a></p></div>';
    page_footer();
    exit;
}

/** 방송 회차를 불러옵니다. 없으면 오류 화면. */
function load_broadcast(int $id): array
{
    $b = $id > 0 ? db_one('SELECT * FROM broadcasts WHERE id = ?', [$id]) : null;
    if (!$b) {
        render_error('방송 회차를 찾을 수 없습니다.', 404);
    }
    return $b;
}

function pagination(int $total, int $page, int $perPage): string
{
    $pages = max(1, (int) ceil($total / $perPage));
    if ($pages <= 1) {
        return '';
    }
    $html = '<nav class="pagination">';
    $start = max(1, $page - 4);
    $end = min($pages, $page + 4);
    if ($page > 1) {
        $html .= '<a href="' . h(url_with(['page' => $page - 1])) . '">이전</a>';
    }
    if ($start > 1) {
        $html .= '<a href="' . h(url_with(['page' => 1])) . '">1</a>' . ($start > 2 ? '<span>…</span>' : '');
    }
    for ($p = $start; $p <= $end; $p++) {
        $html .= $p === $page
            ? '<span class="current">' . $p . '</span>'
            : '<a href="' . h(url_with(['page' => $p])) . '">' . $p . '</a>';
    }
    if ($end < $pages) {
        $html .= ($end < $pages - 1 ? '<span>…</span>' : '') . '<a href="' . h(url_with(['page' => $pages])) . '">' . $pages . '</a>';
    }
    if ($page < $pages) {
        $html .= '<a href="' . h(url_with(['page' => $page + 1])) . '">다음</a>';
    }
    return $html . '</nav>';
}

/** 시간 범위 입력칸 (from, to) */
function range_inputs(): string
{
    return '<label>시작 <input type="datetime-local" name="from" value="' . h(input_str('from', '', 20)) . '"></label>'
        . '<label>종료 <input type="datetime-local" name="to" value="' . h(input_str('to', '', 20)) . '"></label>';
}
