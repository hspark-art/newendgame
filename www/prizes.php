<?php
/**
 * 상품 지급 (당첨자 시트)
 *  - 중복 당첨 배너, 최근 당첨자(1/2/3개월), 상품별 개수
 *  - 표에서 바로 고치기, 표 복사(엑셀 붙여넣기용), CSV
 *  - 쪽지: 문안 템플릿 → 받는사람·내용 복사 / 쪽지 창 열기 / 보냄 처리 / (관리자) 서버 일괄 발송
 */
require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/prizes.php';
require APP_DIR . '/note.php';

$admin = require_login();
$isAdmin = $admin['role'] === 'admin';
purge_expired_pii();

// ── 선택 항목 상태 한 번에 바꾸기 ──────────────────────────
if (is_post() && input_str('action') === 'bulk_status') {
    csrf_check();
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $status = input_str('status', '', 20);
    if ($ids && isset(PRIZE_STATUSES[$status])) {
        $in = db_placeholders($ids);
        if ($status === 'paid') {
            db_exec("UPDATE prizes SET status = 'paid', paid_at = COALESCE(paid_at, ?), updated_at = ? WHERE id IN ($in)", array_merge([now(), now()], $ids));
        } else {
            db_exec("UPDATE prizes SET status = ?, paid_at = NULL, updated_at = ? WHERE id IN ($in)", array_merge([$status, now()], $ids));
        }
        audit('prize_bulk_status', '', count($ids) . '건 → ' . PRIZE_STATUSES[$status]);
        flash('success', count($ids) . '건을 [' . PRIZE_STATUSES[$status] . '] 상태로 바꿨습니다.');
    } else {
        flash('error', '바꿀 항목과 상태를 선택해 주세요.');
    }
    redirect(safe_back('prizes.php'));
}

$broadcastId = input_int('broadcast_id');
$status = input_str('status', '', 20);
$prizeFilter = input_str('prize', '', 200);
$note = input_str('note', '', 10);
$q = input_str('q', '', 100);
$recentMonths = max(1, min(3, input_int('rw', 1)));
$perPage = 100;
$page = max(1, input_int('page', 1));

[$where, $params] = prize_list_where($broadcastId, $status, $prizeFilter, $note, $q);
$total = (int) db_value("SELECT COUNT(*) FROM prizes p WHERE $where", $params);
$rows = db_all(
    "SELECT p.*, b.title, b.broadcast_date, i.icon AS item_icon, i.color AS item_color, i.note_type AS item_note_type
     FROM prizes p LEFT JOIN broadcasts b ON b.id = p.broadcast_id LEFT JOIN prize_items i ON i.id = p.item_id
     WHERE $where ORDER BY p.created_at DESC, p.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);

// 상태별 개수, 상품별 개수 (회차 선택을 따름)
$scope = $broadcastId ? ' WHERE broadcast_id = ?' : '';
$scopeParams = $broadcastId ? [$broadcastId] : [];
$counts = [];
foreach (db_all("SELECT status, COUNT(*) AS cnt FROM prizes$scope GROUP BY status", $scopeParams) as $r) {
    $counts[$r['status']] = (int) $r['cnt'];
}
$prizeCounts = db_all("SELECT prize_name, COUNT(*) AS cnt FROM prizes$scope GROUP BY prize_name ORDER BY cnt DESC, prize_name LIMIT 30", $scopeParams);

// 같은 사람이 같은 상품을 두 번 이상 받은 경우 (전체 기록)
// (아이디가 없는 가져온 기록은 닉네임으로 같은 사람을 판단 — 기존 끝장전과 같은 방식)
$dupes = [];
foreach (db_all('SELECT user_id, nickname, prize_name FROM prizes ORDER BY created_at, id') as $r) {
    if (trim($r['prize_name']) === '') {
        continue;
    }
    $d = &$dupes[prize_person_key($r['user_id'], $r['nickname']) . '|' . mb_strtolower(trim($r['prize_name']))];
    $d ??= ['user_id' => $r['user_id'], 'prize_name' => $r['prize_name'], 'cnt' => 0];
    $d['cnt']++;
    $d['nickname'] = $r['nickname'];
    unset($d);
}
$dupes = array_filter($dupes, fn($d) => $d['cnt'] >= 2);
usort($dupes, fn($a, $b) => $b['cnt'] <=> $a['cnt']);
$dupes = array_slice($dupes, 0, 50);

// 최근 당첨자 (1/2/3개월)
$since = date('Y-m-d H:i:s', strtotime("-$recentMonths months"));
$recentRows = db_all(
    'SELECT p.user_id, p.nickname, p.prize_name, p.created_at, i.icon AS item_icon, i.color AS item_color
     FROM prizes p LEFT JOIN prize_items i ON i.id = p.item_id WHERE p.created_at >= ? ORDER BY p.created_at DESC',
    [$since]
);
$recent = [];
foreach ($recentRows as $r) {
    $u = &$recent[prize_person_key($r['user_id'], $r['nickname'])];
    $u ??= ['user_id' => $r['user_id'], 'nickname' => $r['nickname'], 'wins' => []];
    $icon = prize_icon($r);
    array_unshift($u['wins'], ['icon' => $icon['icon'], 'color' => $icon['color'], 'prize' => $r['prize_name'], 'date' => substr($r['created_at'], 0, 10)]);
    unset($u);
}
uasort($recent, fn($a, $b) => count($b['wins']) <=> count($a['wins']));

$broadcasts = db_all('SELECT id, title, broadcast_date FROM broadcasts ORDER BY broadcast_date DESC, id DESC LIMIT 200');
$exportQuery = http_build_query(array_filter(['type' => 'prizes', 'broadcast_id' => $broadcastId ?: null, 'status' => $status, 'q' => $q, 'prize' => $prizeFilter, 'note' => $note]));
$templates = note_templates();
$serverSend = $isAdmin && note_cookie_saved_at() !== '';

page_header('상품 지급', ['menu' => 'prizes', 'wide' => true]);
?>
<div class="page-head">
  <h1>상품 지급</h1>
  <div class="actions">
    <?php if ($isAdmin): ?><a class="btn" href="prize_import.php">기존 당첨 기록 가져오기</a><?php endif; ?>
    <a class="btn primary" href="prize_edit.php<?= $broadcastId ? '?broadcast_id=' . $broadcastId : '' ?>">+ 당첨 등록</a>
  </div>
</div>

<?php if ($dupes): ?>
  <details class="alert alert-error dup-banner">
    <summary>🚨 같은 상품을 두 번 이상 받은 시청자 <?= count($dupes) ?>명 — 눌러서 보기</summary>
    <ul>
      <?php foreach ($dupes as $d): ?>
        <li><a href="<?= h(url_with(['q' => $d['user_id'] !== '' ? $d['user_id'] : $d['nickname'], 'page' => null])) ?>"><?= h($d['nickname']) ?> <span class="muted small"><?= h($d['user_id']) ?></span></a> — <?= h($d['prize_name']) ?> <strong><?= (int) $d['cnt'] ?>회</strong></li>
      <?php endforeach; ?>
    </ul>
  </details>
<?php endif; ?>

<div class="tiles small-tiles">
  <?php foreach (PRIZE_STATUSES as $k => $label): ?>
    <a class="tile link <?= $status === $k ? 'selected' : '' ?>" href="<?= h(url_with(['status' => $status === $k ? null : $k, 'page' => null])) ?>">
      <div class="tile-label"><?= h($label) ?></div><div class="tile-value"><?= fmt_num($counts[$k] ?? 0) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<details class="card recent-card" <?= isset($_GET['rw']) ? 'open' : '' ?>>
  <summary><strong>최근 당첨자</strong> <span class="muted small">최근 <?= $recentMonths ?>개월 · <?= count($recent) ?>명 (2회 이상은 빨간색)</span></summary>
  <nav class="subtabs">
    <?php foreach ([1, 2, 3] as $m): ?><a href="<?= h(url_with(['rw' => $m])) ?>" class="<?= $recentMonths === $m ? 'active' : '' ?>"><?= $m ?>개월</a><?php endforeach; ?>
  </nav>
  <div class="recent-list">
    <?php foreach ($recent as $r): ?>
      <a class="recent-item<?= count($r['wins']) >= 2 ? ' dup' : '' ?>" href="<?= h(url_with(['q' => $r['user_id'] !== '' ? $r['user_id'] : $r['nickname'], 'page' => null])) ?>">
        <?= render_wins($r['wins']) ?> <b><?= h($r['nickname']) ?></b> <span class="muted small"><?= h($r['user_id']) ?></span>
      </a>
    <?php endforeach; ?>
    <?php if (!$recent): ?><span class="muted">최근 <?= $recentMonths ?>개월 안의 당첨 기록이 없습니다.</span><?php endif; ?>
  </div>
</details>

<form class="filters" method="get">
  <label>방송 회차
    <select name="broadcast_id" data-autosubmit>
      <option value="">전체</option>
      <?php foreach ($broadcasts as $bc): ?>
        <option value="<?= (int) $bc['id'] ?>" <?= $broadcastId === (int) $bc['id'] ? 'selected' : '' ?>><?= h($bc['broadcast_date'] . ' ' . $bc['title']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>상태
    <select name="status" data-autosubmit>
      <option value="">전체</option>
      <?php foreach (PRIZE_STATUSES as $k => $label): ?><option value="<?= h($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
    </select>
  </label>
  <label>쪽지
    <select name="note" data-autosubmit>
      <option value="">전체</option>
      <option value="unsent" <?= $note === 'unsent' ? 'selected' : '' ?>>안 보냄</option>
      <option value="sent" <?= $note === 'sent' ? 'selected' : '' ?>>보냄</option>
    </select>
  </label>
  <?php if ($prizeFilter !== ''): ?><input type="hidden" name="prize" value="<?= h($prizeFilter) ?>"><?php endif; ?>
  <label>검색 <input type="search" name="q" value="<?= h($q) ?>" placeholder="아이디·닉네임·상품명·메모"></label>
  <button class="btn primary">조회</button>
  <a class="btn" href="prizes.php">초기화</a>
  <button type="button" class="btn" id="copy-table">표 복사</button>
  <a class="btn" href="export.php?<?= h($exportQuery) ?>">CSV 내려받기<?= $isAdmin ? '' : ' (정보 가림)' ?></a>
</form>

<?php if ($prizeCounts): ?>
  <div class="chips">
    <?php foreach ($prizeCounts as $pc): $cat = prize_category($pc['prize_name']); ?>
      <a class="chip <?= $prizeFilter === $pc['prize_name'] ? 'active' : '' ?>" href="<?= h(url_with(['prize' => $prizeFilter === $pc['prize_name'] ? null : $pc['prize_name'], 'page' => null])) ?>">
        <span class="wi" style="--c:<?= h($cat[2]) ?>"><?= h($cat[3]) ?></span> <?= h($pc['prize_name']) ?> <strong><?= (int) $pc['cnt'] ?></strong>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="post" id="prize-form">
<?= csrf_field() ?>
<input type="hidden" name="action" value="bulk_status">
<div class="card flush">
<table class="table sheet" id="prize-table">
  <thead><tr>
    <th class="chk"><input type="checkbox" data-check-all="ids[]" title="전체 선택"></th>
    <th>등록</th><th>회차</th><th>SOOP 아이디</th><th>닉네임</th><th>선정 사유</th><th>상품</th><th>상태</th><th>쪽지</th><th>수령자</th><th>메모</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $p):
      $name = mask_name(pii_decrypt($p['recipient_name']));
      $phone = mask_phone(pii_decrypt($p['recipient_phone']));
      $addr = $p['recipient_address'] ? '주소 있음' : '';
      $icon = prize_icon($p);
      $noteType = $p['item_note_type'] ?: suggest_note_type($p['prize_name']);
  ?>
    <tr data-id="<?= (int) $p['id'] ?>" data-sid="<?= h($p['user_id']) ?>" data-nick="<?= h($p['nickname']) ?>" data-prize="<?= h($p['prize_name']) ?>"
        data-note-type="<?= h($noteType) ?>" data-date="<?= h(substr($p['created_at'], 0, 10)) ?>" data-bc="<?= h($p['title'] ?? '') ?>">
      <td class="chk"><input type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>"></td>
      <td class="nowrap muted small"><?= fmt_dt($p['created_at'], 'y-m-d') ?></td>
      <td class="small bc-cell"><?= $p['title'] ? '<a href="broadcast.php?id=' . (int) $p['broadcast_id'] . '">' . h($p['title']) . '</a>' : '<span class="muted">-</span>' ?></td>
      <td><input class="cell" data-field="user_id" value="<?= h($p['user_id']) ?>" maxlength="64"></td>
      <td><input class="cell" data-field="nickname" value="<?= h($p['nickname']) ?>" maxlength="100"></td>
      <td><input class="cell" data-field="reason" value="<?= h($p['reason']) ?>" maxlength="200"></td>
      <td class="nowrap"><span class="wi" style="--c:<?= h($icon['color']) ?>"><?= h($icon['icon']) ?></span> <?= h($p['prize_name']) ?></td>
      <td><select class="cell" data-field="status"><?php foreach (PRIZE_STATUSES as $k => $label): ?><option value="<?= h($k) ?>" <?= $p['status'] === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></td>
      <td class="small note-cell"><?php if ($p['note_sent_at']): ?><span class="ok" title="<?= h($p['note_result'] ?? '') ?>">✓ <?= fmt_dt($p['note_sent_at'], 'm-d H:i') ?></span><?php elseif ($p['note_result']): ?><span class="bad" title="<?= h($p['note_result']) ?>">실패</span><?php else: ?><span class="muted">-</span><?php endif; ?></td>
      <td class="small"><?php if ($p['purged_at']): ?><span class="muted">파기됨</span><?php else: ?><?= h(trim($name . ' ' . $phone . ' ' . $addr)) ?: '<span class="muted">-</span>' ?><?php endif; ?></td>
      <td><input class="cell wide-cell" data-field="memo" value="<?= h($p['memo'] ?? '') ?>" maxlength="500"></td>
      <td><a class="btn small" href="prize_edit.php?id=<?= (int) $p['id'] ?>">수정</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="12" class="empty">지급 기록이 없습니다. 후원 순위·채팅 활동량·수집 창에서 [당첨 등록]을 누르거나 오른쪽 위 버튼으로 등록하세요.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?= pagination($total, $page, $perPage) ?>
<?php if ($rows): ?>
<div class="bulk-bar">
  <strong>선택 항목 상태 변경</strong>
  <select name="status">
    <?php foreach (PRIZE_STATUSES as $k => $label): ?><option value="<?= h($k) ?>"><?= h($label) ?></option><?php endforeach; ?>
  </select>
  <button class="btn" data-require-check="ids[]">적용</button>
</div>
<?php endif; ?>
</form>
<p class="muted small">아이디·닉네임·사유·상태·메모 칸은 표에서 바로 고칠 수 있습니다(칸을 벗어나면 저장, 초록 테두리 = 저장됨).
  수령자 정보는 가려서 표시하며, 지급 완료·기한 초과 후 <?= (int) config('privacy_retention_days', 30) ?>일이 지나면 자동 파기됩니다.</p>

<!-- 쪽지 바: 표에서 줄을 고르면 나타납니다 -->
<div id="note-bar" class="note-bar hidden"
     data-templates="<?= h(json_encode($templates, JSON_UNESCAPED_UNICODE)) ?>"
     data-write-url="<?= h(note_write_url()) ?>" data-server="<?= $serverSend ? '1' : '0' ?>">
  <div class="note-head">
    <strong>쪽지 · 선택 <span id="nb-count">0</span>명</strong>
    <span id="nb-ids" class="muted small"></span>
    <label class="small">문안
      <select id="nb-tpl">
        <option value="auto">상품에 맞춰 자동</option>
        <?php foreach (NOTE_TYPES as $k => $label): ?><option value="<?= h($k) ?>"><?= h($label) ?></option><?php endforeach; ?>
      </select>
    </label>
    <button type="button" class="btn small" id="nb-close">닫기</button>
  </div>
  <textarea id="nb-text" rows="6"></textarea>
  <div class="actions">
    <button type="button" class="btn small" id="nb-copy-ids">받는사람 복사</button>
    <button type="button" class="btn small" id="nb-copy-text">내용 복사</button>
    <button type="button" class="btn small" id="nb-open">쪽지 쓰기창 열기</button>
    <button type="button" class="btn small green" id="nb-mark">✅ 보냄 처리</button>
    <?php if ($isAdmin): ?>
      <button type="button" class="btn small primary" id="nb-send" <?= $serverSend ? '' : 'disabled title="[설정]에서 SOOP 쪽지 세션을 먼저 등록하세요"' ?>>🚀 서버로 바로 보내기</button>
    <?php endif; ?>
    <span id="nb-status" class="small"></span>
  </div>
  <p class="muted small">문안의 {nick} {id} {prize} {date} 는 받는 사람마다 바뀝니다. (복사할 때는 첫 번째 사람 기준) 문안은 [설정]에서 고칠 수 있습니다.</p>
</div>
<?php
page_footer(['assets/prizes.js']);
