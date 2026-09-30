<?php
/**
 * [관리자] 기존 당첨 기록 가져오기
 *  - 기존 끝장전 시스템의 winners.json (admin/pz/winners.json) 또는 당첨자 시트 [⬇ CSV] 파일
 *    (CSV 열: 날짜, 방식, 닉네임, SOOP계정, 상품, 쪽지, 메모 — 엑셀에서 저장한 CP949 파일도 됨)
 *  - 미리 보기 → [가져오기] 2단계. 같은 파일을 다시 올려도 이미 가져온 줄은 건너뜁니다. (ext_id)
 *  - 아이디가 비어 있으면 닉네임으로 지금까지 모인 기록에서 아이디를 찾아 채웁니다. (기존 [SOOP계정 자동 채우기]와 같음)
 */
require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/prizes.php';
require APP_DIR . '/stats.php';

$admin = require_admin_role();

const IMPORT_MAX_BYTES = 20 * 1024 * 1024;
const IMPORT_PREVIEW_ROWS = 100;

/** 날짜 글자에서 Y-m-d 를 꺼냅니다. (2025-03-04, 2025.3.4, 2025/03/04 …) */
function import_date(string $s): ?string
{
    if (!preg_match('/(20\d{2})\s*[-.\/년]\s*(\d{1,2})\s*[-.\/월]\s*(\d{1,2})/u', $s, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
}

function import_nick_key(string $nick): string
{
    return mb_strtolower((string) preg_replace('/\s+/u', '', $nick));
}

/** 올린 파일을 읽어 [줄 목록, 오류 목록] 을 돌려줍니다. */
function import_parse(string $raw, string $filename): array
{
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'CP949');
    }
    $items = [];
    $errors = [];
    $trim = ltrim($raw);
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            return [[], ['JSON 파일을 읽지 못했습니다. winners.json 파일이 맞는지 확인해 주세요.']];
        }
        foreach ((isset($j['list']) && is_array($j['list'])) ? $j['list'] : $j as $x) {
            if (is_array($x)) {
                $items[] = ['id' => (string) ($x['id'] ?? ''), 'date' => (string) ($x['date'] ?? ''), 'at' => (string) ($x['at'] ?? ''),
                    'nick' => (string) ($x['nick'] ?? ''), 'sid' => (string) ($x['sid'] ?? ''), 'prize' => (string) ($x['prize'] ?? ''),
                    'how' => (string) ($x['how'] ?? ''), 'sent' => (string) ($x['sent'] ?? ''), 'memo' => (string) ($x['memo'] ?? '')];
            }
        }
    } else {
        $lines = preg_split('/\r\n|\n|\r/', $raw);
        $first = (string) array_shift($lines);
        $delim = substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',';
        $head = array_map(fn($v) => preg_replace('/\s+/u', '', (string) $v), str_getcsv($first, $delim, '"', ''));
        $col = [];
        foreach ($head as $i => $name) {
            $key = match (true) {
                in_array($name, ['날짜', '일자', '방송일'], true) => 'date',
                in_array($name, ['방식', '선정방식', '선정사유', '사유'], true) => 'how',
                in_array($name, ['닉네임', '닉'], true) => 'nick',
                in_array($name, ['SOOP계정', 'SOOP아이디', '아이디', '계정', 'ID', 'id'], true) => 'sid',
                in_array($name, ['상품', '상품명'], true) => 'prize',
                in_array($name, ['쪽지', '발송', '쪽지발송'], true) => 'sent',
                in_array($name, ['메모', '비고'], true) => 'memo',
                default => null,
            };
            if ($key !== null && !isset($col[$key])) {
                $col[$key] = $i;
            }
        }
        if (!isset($col['date'], $col['nick'], $col['prize'])) {
            return [[], ["첫 줄(머리글)에서 '날짜', '닉네임', '상품' 열을 찾지 못했습니다. 기존 당첨자 시트의 [⬇ CSV] 파일을 올려 주세요."]];
        }
        foreach ($lines as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $delim, '"', '');
            $get = fn($k) => isset($col[$k]) ? trim((string) ($cells[$col[$k]] ?? '')) : '';
            $items[] = ['id' => '', 'date' => $get('date'), 'at' => '', 'nick' => $get('nick'), 'sid' => $get('sid'),
                'prize' => $get('prize'), 'how' => $get('how'), 'sent' => $get('sent'), 'memo' => $get('memo'), 'line' => $n + 2];
        }
    }
    return [$items, $errors];
}

/** 읽은 줄을 저장할 모양으로 정리하고, 이미 가져온 줄·회차·상품 연결을 표시합니다. */
function import_prepare(array $items, bool $skipPractice): array
{
    // 닉네임 → 아이디 (지금까지 모인 당첨 기록·방송 기록)
    if (array_filter($items, fn($x) => trim($x['sid']) === '')) {
        refresh_broadcast_users(array_column(db_all('SELECT id FROM broadcasts'), 'id'), 0, 20);
    }
    $nickMap = [];
    foreach (db_all("SELECT nickname, user_id FROM broadcast_users WHERE nickname <> ''") as $r) {
        $nickMap[import_nick_key($r['nickname'])] = $r['user_id'];
    }
    foreach (db_all("SELECT nickname, user_id FROM prizes WHERE user_id <> '' AND nickname <> '' ORDER BY created_at") as $r) {
        $nickMap[import_nick_key($r['nickname'])] = $r['user_id'];
    }
    $dateMap = [];
    foreach (db_all('SELECT id, broadcast_date FROM broadcasts ORDER BY broadcast_date, id') as $b) {
        $dateMap[(string) $b['broadcast_date']] ??= (int) $b['id'];
    }
    $itemMap = [];
    foreach (prize_items() as $it) {
        $itemMap[mb_strtolower(trim($it['name']))] = (int) $it['id'];
    }

    $rows = [];
    $stat = ['total' => count($items), 'new' => 0, 'dup' => 0, 'invalid' => 0, 'practice' => 0, 'nick_found' => 0, 'no_id' => 0];
    $problems = [];
    $seen = [];
    foreach ($items as $i => $x) {
        $where = isset($x['line']) ? $x['line'] . '번째 줄' : ($i + 1) . '번째 기록';
        $date = import_date($x['date']);
        $nick = mb_substr(trim($x['nick']), 0, 100);
        if ($date === null || $nick === '') {
            $stat['invalid']++;
            if (count($problems) < 20) {
                $problems[] = "$where: " . ($date === null ? '날짜를 읽을 수 없음' : '닉네임 없음') . " ({$x['date']} / {$x['nick']})";
            }
            continue;
        }
        if ($skipPractice && mb_strpos($x['how'], '연습') !== false) {
            $stat['practice']++;
            continue;
        }
        $sid = normalize_user_id(mb_substr(trim($x['sid']), 0, 64));
        $byNick = false;
        if ($sid === '' && isset($nickMap[import_nick_key($nick)])) {
            $sid = $nickMap[import_nick_key($nick)];
            $byNick = true;
            $stat['nick_found']++;
        } elseif ($sid === '') {
            $stat['no_id']++;
        }
        $prize = mb_substr(trim($x['prize']), 0, 200);
        $how = mb_substr(trim($x['how']), 0, 150);
        $time = preg_match('/^(\d{1,2}):(\d{2})/', trim($x['at']), $tm) ? sprintf('%02d:%02d:00', $tm[1], $tm[2]) : '00:00:00';
        $extId = $x['id'] !== ''
            ? 'old:' . mb_substr($x['id'], 0, 60)
            : 'csv:' . substr(sha1(implode("\x1f", [$date, import_nick_key($nick), $x['sid'], $prize, $how])), 0, 32);
        // 같은 파일 안의 똑같은 줄(CSV)은 한 번만
        if (isset($seen[$extId])) {
            $extId .= ':' . (++$seen[$extId]);
        } else {
            $seen[$extId] = 1;
        }
        $sent = mb_substr(trim($x['sent']), 0, 100);
        $rows[] = [
            'ext_id'       => $extId,
            'date'         => $date,
            'created_at'   => "$date $time",
            'nickname'     => $nick,
            'user_id'      => $sid,
            'by_nick'      => $byNick,
            'prize_name'   => $prize,
            'how'          => $how,
            'sent'         => $sent,
            'sent_at'      => $sent !== '' ? (import_date($sent) ? import_date($sent) . ' 00:00:00' : "$date $time") : null,
            'memo'         => mb_substr(trim($x['memo']), 0, 5000),
            'broadcast_id' => $dateMap[$date] ?? null,
            'item_id'      => $itemMap[mb_strtolower($prize)] ?? null,
        ];
    }
    // 이미 가져온 줄
    $exists = [];
    foreach (array_chunk(array_column($rows, 'ext_id'), 500) as $chunk) {
        foreach (db_all('SELECT ext_id FROM prizes WHERE ext_id IN (' . db_placeholders($chunk) . ')', $chunk) as $r) {
            $exists[$r['ext_id']] = true;
        }
    }
    foreach ($rows as &$r) {
        $r['exists'] = isset($exists[$r['ext_id']]);
        $stat[$r['exists'] ? 'dup' : 'new']++;
    }
    unset($r);
    return [$rows, $stat, $problems];
}

function import_file(string $token): string
{
    return storage_dir('import') . '/' . preg_replace('/[^a-f0-9]/', '', $token) . '.json';
}

// ── 가져오기 실행 ──────────────────────────────────────────
if (is_post() && input_str('action') === 'import') {
    csrf_check();
    $token = (string) ($_SESSION['prize_import'] ?? '');
    $file = $token !== '' ? import_file($token) : '';
    $saved = $file !== '' && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($saved)) {
        flash('error', '미리 보기 내용이 없습니다. 파일을 다시 올려 주세요.');
        redirect('prize_import.php');
    }
    // 미리 보기 뒤에 다른 사람이 가져왔을 수도 있으니 다시 확인
    [$rows] = import_prepare($saved['items'], (bool) $saved['skip_practice']);
    $added = 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            if ($r['exists']) {
                continue;
            }
            $cat = prize_category($r['prize_name']);
            $type = $cat[0] === '쿠폰·코드' ? 'coupon' : ($cat[0] === PRIZE_CATEGORY_OTHER[0] ? 'other' : 'delivery');
            db_exec(
                'INSERT INTO prizes (broadcast_id, user_id, nickname, reason, item_id, prize_name, prize_type, status, memo, paid_at,
                    note_sent_at, note_result, ext_id, created_by, created_at, updated_at) VALUES (' . db_placeholders(range(1, 16)) . ')',
                [$r['broadcast_id'], $r['user_id'], $r['nickname'], $r['how'] !== '' ? '기존 시스템(' . $r['how'] . ')' : '기존 시스템', $r['item_id'], $r['prize_name'],
                    $type, $r['sent_at'] ? 'paid' : 'pending', $r['memo'] !== '' ? $r['memo'] : null, $r['sent_at'],
                    $r['sent_at'], $r['sent_at'] ? '기존 시스템: ' . $r['sent'] : null, $r['ext_id'], $admin['id'], $r['created_at'], now()]
            );
            $added++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    @unlink($file);
    unset($_SESSION['prize_import']);
    audit('prize_import', 'prizes', "{$saved['name']} · {$added}건 가져옴");
    flash('success', "당첨 기록 {$added}건을 가져왔습니다." . (count($rows) - $added > 0 ? ' (이미 있던 ' . (count($rows) - $added) . '건은 건너뜀)' : ''));
    redirect('prizes.php');
}

// ── 미리 보기 ──────────────────────────────────────────────
$preview = null;
if (is_post() && input_str('action') === 'preview') {
    csrf_check();
    $f = $_FILES['file'] ?? null;
    if (!is_array($f) || (int) $f['error'] !== UPLOAD_ERR_OK || $f['size'] > IMPORT_MAX_BYTES) {
        flash('error', '파일을 올리지 못했습니다. (20MB 이하의 winners.json 또는 CSV)');
        redirect('prize_import.php');
    }
    [$items, $errors] = import_parse((string) file_get_contents($f['tmp_name']), (string) $f['name']);
    if ($errors || !$items) {
        flash('error', $errors[0] ?? '가져올 기록이 없습니다.');
        redirect('prize_import.php');
    }
    $skipPractice = isset($_POST['skip_practice']);
    [$rows, $stat, $problems] = import_prepare($items, $skipPractice);
    // 이전 미리 보기 파일(이 세션 것, 하루 지난 것)은 지우고 새로 저장
    if (!empty($_SESSION['prize_import'])) {
        @unlink(import_file((string) $_SESSION['prize_import']));
    }
    foreach (glob(storage_dir('import') . '/*.json') ?: [] as $old) {
        if (filemtime($old) < time() - 86400) {
            @unlink($old);
        }
    }
    $token = bin2hex(random_bytes(16));
    file_put_contents(import_file($token), json_encode(['name' => mb_substr((string) $f['name'], 0, 100), 'skip_practice' => $skipPractice, 'items' => $items], JSON_UNESCAPED_UNICODE));
    $_SESSION['prize_import'] = $token;
    $preview = ['name' => (string) $f['name'], 'rows' => $rows, 'stat' => $stat, 'problems' => $problems];
}

$broadcastTitles = [];
if ($preview) {
    foreach (db_all('SELECT id, title FROM broadcasts') as $b) {
        $broadcastTitles[(int) $b['id']] = $b['title'];
    }
}

page_header('기존 당첨 기록 가져오기', ['menu' => 'prizes', 'wide' => (bool) $preview]);
?>
<div class="page-head"><h1>기존 당첨 기록 가져오기</h1><a class="btn" href="prizes.php">상품 지급으로</a></div>

<?php if ($preview): $s = $preview['stat']; ?>
  <div class="card">
    <h2>미리 보기 — <?= h($preview['name']) ?></h2>
    <div class="tiles small-tiles">
      <div class="tile"><div class="tile-label">새로 가져올 기록</div><div class="tile-value"><?= fmt_num($s['new']) ?></div></div>
      <div class="tile"><div class="tile-label">이미 가져온 기록</div><div class="tile-value"><?= fmt_num($s['dup']) ?></div></div>
      <div class="tile"><div class="tile-label">닉네임으로 아이디 찾음</div><div class="tile-value"><?= fmt_num($s['nick_found']) ?></div></div>
      <div class="tile"><div class="tile-label">아이디 없음</div><div class="tile-value"><?= fmt_num($s['no_id']) ?></div></div>
      <?php if ($s['practice']): ?><div class="tile"><div class="tile-label">연습 기록 (빼기)</div><div class="tile-value"><?= fmt_num($s['practice']) ?></div></div><?php endif; ?>
      <?php if ($s['invalid']): ?><div class="tile"><div class="tile-label">읽을 수 없는 줄</div><div class="tile-value bad"><?= fmt_num($s['invalid']) ?></div></div><?php endif; ?>
    </div>
    <?php if ($preview['problems']): ?>
      <details class="alert alert-error"><summary>읽을 수 없는 줄 <?= fmt_num($s['invalid']) ?>개 (건너뜀)</summary><ul class="small"><?php foreach ($preview['problems'] as $p): ?><li><?= h($p) ?></li><?php endforeach; ?></ul></details>
    <?php endif; ?>
    <?php if ($s['no_id']): ?>
      <p class="muted small">아이디가 없는 기록은 닉네임만으로 들어갑니다. 채팅 옆 당첨 아이콘에는 나오지 않고, 중복 당첨은 닉네임으로 판단합니다. 가져온 뒤 [상품 지급] 표에서 아이디 칸을 채우면 됩니다.</p>
    <?php endif; ?>
    <div class="actions">
      <?php if ($s['new'] > 0): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="import">
          <button class="btn primary" data-confirm="<?= fmt_num($s['new']) ?>건을 가져올까요?"><?= fmt_num($s['new']) ?>건 가져오기</button></form>
      <?php else: ?>
        <span class="muted">새로 가져올 기록이 없습니다.</span>
      <?php endif; ?>
      <a class="btn" href="prize_import.php">다른 파일 올리기</a>
    </div>
  </div>

  <div class="card flush">
  <table class="table">
    <thead><tr><th></th><th>날짜</th><th>닉네임</th><th>아이디</th><th>상품</th><th>방식</th><th>쪽지</th><th>회차 연결</th><th>메모</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($preview['rows'], 0, IMPORT_PREVIEW_ROWS) as $r): $icon = prize_icon(['item_icon' => null, 'prize_name' => $r['prize_name']]); ?>
      <tr class="<?= $r['exists'] ? 'muted' : '' ?>">
        <td><?= $r['exists'] ? '<span class="muted small">있음</span>' : '<span class="ok small">새로</span>' ?></td>
        <td><?= h($r['date']) ?></td>
        <td><?= h($r['nickname']) ?></td>
        <td><?= $r['user_id'] !== '' ? h($r['user_id']) . ($r['by_nick'] ? ' <span class="muted small" title="닉네임으로 찾은 아이디">(닉네임으로)</span>' : '') : '<span class="muted">-</span>' ?></td>
        <td><span class="wi" style="--c:<?= h($icon['color']) ?>"><?= h($icon['icon']) ?></span> <?= h($r['prize_name']) ?><?= $r['item_id'] ? ' <span class="muted small">(상품 목록)</span>' : '' ?></td>
        <td><?= h($r['how']) ?></td>
        <td><?= $r['sent'] !== '' ? '<span class="ok">✓</span> ' . h($r['sent']) : '<span class="muted">-</span>' ?></td>
        <td><?= $r['broadcast_id'] ? h($broadcastTitles[$r['broadcast_id']] ?? '') : '<span class="muted">-</span>' ?></td>
        <td class="small"><?= h(mb_strimwidth($r['memo'], 0, 40, '…')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if (count($preview['rows']) > IMPORT_PREVIEW_ROWS): ?><p class="muted small">앞의 <?= IMPORT_PREVIEW_ROWS ?>줄만 보여 줍니다. (전체 <?= fmt_num(count($preview['rows'])) ?>줄)</p><?php endif; ?>

<?php else: ?>
  <div class="card narrow-card">
    <form method="post" enctype="multipart/form-data" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="preview">
      <label>파일 (winners.json 또는 CSV, 20MB 이하)
        <input type="file" name="file" required accept=".json,.csv,.txt,.tsv,application/json,text/csv">
      </label>
      <label class="check"><input type="checkbox" name="skip_practice" value="1" checked> 방식에 '연습'이 들어간 기록은 빼기</label>
      <div class="actions"><button class="btn primary">미리 보기</button></div>
    </form>
  </div>
  <div class="card">
    <h2>가져올 수 있는 파일</h2>
    <ul>
      <li><strong>winners.json</strong> — 기존 사이트 서버의 <code>admin/pz/winners.json</code> (FTP 로 내려받기). 가장 정확합니다.</li>
      <li><strong>CSV</strong> — 기존 당첨자 시트의 <code>⬇ CSV</code> 로 받은 파일. 열: 날짜, 방식, 닉네임, SOOP계정, 상품, 쪽지, 메모 (엑셀에서 고쳐 저장한 파일도 됩니다)</li>
    </ul>
    <ul class="muted small">
      <li>같은 파일을 다시 올려도 이미 가져온 기록은 건너뜁니다.</li>
      <li>날짜가 같은 방송 회차가 있으면 그 회차에 연결하고, 상품 이름이 [상품 목록]과 같으면 그 상품에 연결합니다.</li>
      <li>쪽지 칸이 채워진 기록은 '지급 완료·쪽지 보냄'으로, 비어 있으면 '정보 대기'로 들어갑니다. 선정 사유는 "기존 시스템(방식)"으로 적힙니다.</li>
      <li>SOOP계정이 비어 있으면 닉네임으로 지금까지 모인 방송·당첨 기록에서 아이디를 찾아 채웁니다.</li>
    </ul>
  </div>
<?php endif; ?>
<?php
page_footer();
