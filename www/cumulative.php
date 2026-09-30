<?php
/**
 * 누적 순위 — 여러 방송을 모아 🔥연속 출석·참여 회차·기간 채팅·기간 후원 (기존 끝장전 누적 순위)
 */
require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/stats.php';
require APP_DIR . '/prizes.php';

require_login();

$weeks = input_int('weeks', 8);
$sort = input_str('sort', 'streak', 20);
$q = input_str('q', '', 100);
$exclude = !isset($_GET['submitted']) || isset($_GET['exclude']);
$perPage = 100;
$page = max(1, input_int('page', 1));

$data = cumulative_ranking($weeks, $exclude, $q, $sort);
$weeks = $data['weeks'];
$sort = $data['sort'];
$total = count($data['rows']);
$rows = array_slice($data['rows'], ($page - 1) * $perPage, $perPage);
$max = $data['rows'] ? max(1, ...array_column($data['rows'], $sort)) : 1;
$winners = winners_by_user(array_column($rows, 'user_id'));
$offset = ($page - 1) * $perPage;
$exportQuery = http_build_query(array_filter(['type' => 'cumulative', 'weeks' => $weeks, 'sort' => $sort, 'q' => $q,
    'submitted' => isset($_GET['submitted']) ? 1 : null, 'exclude' => $exclude ? 1 : null]));

page_header('누적 순위', ['menu' => 'cumulative']);
?>
<div class="page-head">
  <h1>누적 순위</h1>
  <a class="btn" href="export.php?<?= h($exportQuery) ?>">CSV 내려받기</a>
</div>
<p class="muted">최근 <?= $weeks ?>주 방송 <?= fmt_num($data['broadcasts']) ?>회 (<?= h($data['from']) ?> 이후)<?= $data['last_date'] ? ' · 가장 최근 방송 ' . h($data['last_date']) : '' ?>.
  🔥 연속 출석은 가장 최근 방송부터 거꾸로 빠짐없이 온 횟수(기간과 상관없이 전체 기록), 참여는 채팅 또는 후원이 1건 이상인 방송입니다.</p>
<?php if ($data['pending']): ?>
  <div class="alert alert-error">오래된 회차 <?= count($data['pending']) ?>개는 아직 집계 중입니다. 잠시 뒤 새로고침하면 반영됩니다.</div>
<?php endif; ?>

<div class="chips">
  <?php foreach (CUMULATIVE_WEEKS as $w): ?>
    <a class="chip<?= $w === $weeks ? ' active' : '' ?>" href="<?= h(url_with(['weeks' => $w, 'page' => null])) ?>"><?= $w ?>주</a>
  <?php endforeach; ?>
  <span class="chip-sep"></span>
  <?php foreach (CUMULATIVE_SORTS as $k => [$label]): ?>
    <a class="chip<?= $k === $sort ? ' active' : '' ?>" href="<?= h(url_with(['sort' => $k, 'page' => null])) ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</div>

<form class="filters" method="get">
  <input type="hidden" name="weeks" value="<?= $weeks ?>">
  <input type="hidden" name="sort" value="<?= h($sort) ?>">
  <input type="hidden" name="submitted" value="1">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="아이디 또는 닉네임">
  <label class="check"><input type="checkbox" name="exclude" value="1" <?= $exclude ? 'checked' : '' ?>> 제외 명단·방송인·매니저 빼기</label>
  <button class="btn">조회</button>
</form>

<div class="card flush">
<table class="table">
  <thead><tr>
    <th class="num">순위</th><th>시청자</th>
    <th class="num">🔥 연속</th><th class="num">참여 회차</th><th class="num">기간 채팅</th><th class="num">기간 별풍선</th><th class="num">기간 애드벌룬</th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $i => $r): $rank = $offset + $i + 1; ?>
    <tr>
      <td class="num rank"><?= rank_label($rank) ?></td>
      <td class="actcell"<?= actbar_attr($r[$sort], $max) ?>><?= render_nick($r['nickname'], (int) $r['badges']) ?> <span class="muted small"><?= h($r['user_id']) ?></span>
        <?= render_wins($winners[$r['user_id']] ?? []) ?></td>
      <td class="num"><?= $r['streak'] >= 2 ? '🔥 ' . fmt_num($r['streak']) . '회' : '<span class="muted">' . fmt_num($r['streak']) . '</span>' ?></td>
      <td class="num"><?= fmt_num($r['attend']) ?>회</td>
      <td class="num"><?= fmt_num($r['chats']) ?></td>
      <td class="num"><?= $r['balloons'] ? fmt_num($r['balloons']) . '개' : '<span class="muted">0</span>' ?></td>
      <td class="num"><?= $r['adballoons'] ? fmt_num($r['adballoons']) . '개' : '<span class="muted">0</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?>
    <tr><td colspan="7" class="empty"><?= $sort === 'streak' ? '2회 이상 연속으로 온 시청자가 없습니다.' : '이 기간에 해당하는 시청자가 없습니다.' ?></td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>
<?= pagination($total, $page, $perPage) ?>
<?php
page_footer();
