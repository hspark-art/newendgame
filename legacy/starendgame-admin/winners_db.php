<?php
// 당첨자 장부 (SQLite) + 구글시트 "끝장전 상품 명단" 자동 가져오기
//
//  - 저장 위치: /var/lib/starendgame/winners.sqlite (웹 폴더 밖 — 주소로 열 수 없음)
//  - 시트 기록(source='sheet'): 몇 분마다 서버가 시트를 읽어 맞춥니다. 시트가 원본이라
//    시트에서 고치거나 지우면 여기도 따라 바뀝니다. (쪽지 보냄·메모는 이쪽 값이 유지됨)
//  - 관제 화면 기록(source='live'): 지명·추첨으로 저장. 같은 날 같은 사람·같은 상품이 시트에
//    올라오면 화면에는 한 번만 보입니다.
//  - 시트 규칙: "N월 N일" 줄 = 날짜, "닉네임 / 아이디 | 상품" 줄 = 당첨.
//    상품 칸이 빈 줄(당첨 취소·미수령)과 읽을 수 없는 줄은 건너뜁니다.
//    연도는 2026년부터, 월이 앞 줄보다 작아지면(12월 → 1월) 다음 해로 봅니다.
declare(strict_types=1);

const WDB_PATH = '/var/lib/starendgame/winners.sqlite';
const WDB_SHEET_ID = '199yYjrgHvnm1n30tLBb2bD7S9EaftNwa0Qhr93nZDiA';
const WDB_SHEET_GID = '0';
const WDB_START_YEAR = 2026;
const WDB_SYNC_EVERY = 180;          // 시트 다시 읽는 간격(초)

function wdb(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $dir = dirname(WDB_PATH);
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('당첨자 DB 폴더(' . $dir . ')가 없거나 쓸 수 없습니다. 서버 설정이 필요합니다.');
    }
    $pdo = new PDO('sqlite:' . WDB_PATH, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
    $pdo->exec("CREATE TABLE IF NOT EXISTS winners (
        id TEXT PRIMARY KEY, source TEXT NOT NULL, date TEXT NOT NULL, nick TEXT NOT NULL DEFAULT '',
        sid TEXT NOT NULL DEFAULT '', prize TEXT NOT NULL DEFAULT '', how TEXT NOT NULL DEFAULT '',
        at TEXT NOT NULL DEFAULT '', sent TEXT NOT NULL DEFAULT '', memo TEXT NOT NULL DEFAULT '',
        sheet_row INTEGER, updated_at TEXT NOT NULL DEFAULT '')");
    $pdo->exec('CREATE INDEX IF NOT EXISTS winners_sid ON winners (sid)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)');
    wdb_import_json_once($pdo);
    // 구글시트를 기준으로 하므로, 예전 winners.json 에서 옮겨 온 기록은 목록·중복 판단에서 뺍니다. (DB 에는 보관)
    if (!$pdo->query("SELECT v FROM meta WHERE k = 'old_hidden'")->fetchColumn()) {
        $pdo->exec("UPDATE winners SET source = 'old' WHERE source = 'live'
            AND updated_at <= (SELECT v FROM meta WHERE k = 'json_imported')");
        $pdo->exec("INSERT OR REPLACE INTO meta (k, v) VALUES ('old_hidden', '" . date('Y-m-d H:i:s') . "')");
    }
    return $pdo;
}

function wdb_meta(string $k, ?string $set = null): string
{
    if ($set !== null) {
        wdb()->prepare('INSERT INTO meta (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $set]);
        return $set;
    }
    $st = wdb()->prepare('SELECT v FROM meta WHERE k = ?');
    $st->execute([$k]);
    return (string)($st->fetchColumn() ?: '');
}

/** 처음 한 번: 기존 pz/winners.json 기록을 관제 화면 기록으로 옮겨 둡니다. (원본 파일은 그대로 둠) */
function wdb_import_json_once(PDO $pdo): void
{
    $done = $pdo->query("SELECT v FROM meta WHERE k = 'json_imported'")->fetchColumn();
    if ($done) {
        return;
    }
    $file = __DIR__ . '/pz/winners.json';
    $j = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    $st = $pdo->prepare('INSERT OR IGNORE INTO winners (id, source, date, nick, sid, prize, how, at, sent, memo, updated_at)
        VALUES (?, \'live\', ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $pdo->beginTransaction();
    foreach ((array)($j['list'] ?? []) as $w) {
        if (!is_array($w) || trim((string)($w['nick'] ?? '')) === '') {
            continue;
        }
        $st->execute([(string)($w['id'] ?? uniqid('w')), (string)($w['date'] ?? ''), (string)$w['nick'],
            (string)($w['sid'] ?? ''), (string)($w['prize'] ?? ''), (string)($w['how'] ?? ''), (string)($w['at'] ?? ''),
            (string)($w['sent'] ?? ''), (string)($w['memo'] ?? ''), date('Y-m-d H:i:s')]);
    }
    $pdo->exec("INSERT OR REPLACE INTO meta (k, v) VALUES ('json_imported', '" . date('Y-m-d H:i:s') . "')");
    $pdo->commit();
}

// ── 시트 읽기 ─────────────────────────────────────────────

/** 상품 이름 정리: 안경(Wearwhere GW02 등)은 모델명 하나로 묶고, 모델 번호는 메모로 */
function wdb_prize(string $p): array
{
    $p = trim(preg_replace('/\s+/u', ' ', $p));
    if (preg_match('/^wear\s*where\b\s*(.*)$/iu', $p, $m)) {
        return ['Wearwhere 안경', trim($m[1]) !== '' ? '모델 ' . trim($m[1]) : ''];
    }
    // 같은 상품의 줄임 표기 → 시트의 정식 이름 (관제 화면 기록과 시트 기록이 겹치도록)
    $k = strtolower(preg_replace('/[^a-z0-9]/i', '', $p));
    $alias = [
        '/^(razer)?viperv3pro(faker)/' => 'Razer Viper V3 Pro Faker Edition',
        '/^(razer)?viperv3pro(cs2|counterstrike2)/' => 'Razer Viper V3 Pro Counter Strike 2 Edition',
        '/^(razer)?gigantusv2large(cs2|counterstrike2)/' => 'Razer Gigantus V2 Large Counter Strike 2 Edition',
        '/^(razer)?viperv3prowhite/' => 'Razer Viper V3 Pro White',
        '/^jamiewestuniform/' => 'Jamie West Uniform',
    ];
    foreach ($alias as $re => $name) {
        if (preg_match($re, $k)) {
            return [$name, ''];
        }
    }
    return [$p, ''];
}

function wdb_is_id(string $s): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_]{3,30}$/', $s);
}

/** "닉네임 / 아이디" 를 나눕니다. 판단할 수 없으면 null (건너뜀) */
function wdb_person(string $s): ?array
{
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if ($s === '') {
        return null;
    }
    if (preg_match('#^(.+?)\s*/\s*(.+)$#u', $s, $m)) {
        [$a, $b] = [trim($m[1]), trim($m[2])];
    } elseif (preg_match('/^(.+?)\s*\(\s*([A-Za-z0-9_]{3,30})\s*\)$/u', $s, $m)) {
        [$a, $b] = [trim($m[1]), $m[2]];
    } elseif (preg_match('/^(.+)\s+([A-Za-z0-9_]{3,30})$/u', $s, $m)) {
        [$a, $b] = [trim($m[1]), $m[2]];
    } else {
        return null;
    }
    if ($a === '' || $b === '') {
        return null;
    }
    // 순서가 반대로 적힌 줄 (아이디 / 닉네임)
    if (!wdb_is_id($b) && wdb_is_id($a)) {
        [$a, $b] = [$b, $a];
    }
    if (!wdb_is_id($b)) {
        return null;
    }
    return ['nick' => mb_substr($a, 0, 100), 'sid' => strtolower($b)];
}

/** 시트 CSV → 당첨 기록 목록 */
function wdb_parse_sheet(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $rows = [];
    $year = WDB_START_YEAR;
    $prevMonth = 0;
    $date = '';
    $seen = [];
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $csv);
    rewind($fh);
    $n = 0;
    while (($c = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $n++;
        $a = trim((string)($c[0] ?? ''));
        $b = trim((string)($c[1] ?? ''));
        if (preg_match('/^(\d{1,2})\s*월\s*(\d{1,2})\s*일/u', $a, $m)) {
            $mon = (int)$m[1];
            if ($prevMonth && $mon < $prevMonth) {
                $year++;
            }
            $prevMonth = $mon;
            $date = checkdate($mon, (int)$m[2], $year) ? sprintf('%04d-%02d-%02d', $year, $mon, (int)$m[2]) : '';
            continue;
        }
        if ($date === '' || $b === '' || $b === '경품') {
            continue;                         // 날짜 전 줄, 상품 빈 줄(취소·미수령), 머리글
        }
        $p = wdb_person($a);
        if (!$p) {
            continue;                         // 읽을 수 없는 줄은 무시
        }
        [$prize, $memo] = wdb_prize($b);
        // 같은 날·같은 사람·같은 상품이 여러 줄이면 각각 따로 (번호를 붙여 구분)
        $key = $date . '|' . $p['sid'] . '|' . mb_strtolower($prize) . '|' . $memo;
        $seen[$key] = ($seen[$key] ?? 0) + 1;
        $rows[] = ['id' => 'sh_' . substr(sha1($key . '|' . $seen[$key]), 0, 20), 'date' => $date,
            'nick' => $p['nick'], 'sid' => $p['sid'], 'prize' => $prize, 'memo' => $memo, 'row' => $n];
    }
    fclose($fh);
    return $rows;
}

function wdb_fetch_sheet(): string
{
    $url = 'https://docs.google.com/spreadsheets/d/' . WDB_SHEET_ID . '/export?format=csv&gid=' . WDB_SHEET_GID;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0']]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($res === false || $code !== 200 || stripos($type, 'text/csv') === false) {
        throw new RuntimeException('구글시트를 읽지 못했습니다 (HTTP ' . $code . '). 시트 공유가 "링크가 있는 모든 사용자 - 뷰어"인지 확인하세요.');
    }
    return (string)$res;
}

/**
 * 시트를 읽어 DB 의 시트 기록을 맞춥니다.
 * $force=false 면 WDB_SYNC_EVERY 초에 한 번만 실제로 읽습니다.
 */
function wdb_sync(bool $force = false): array
{
    $last = (int)wdb_meta('sync_at');
    if (!$force && time() - $last < WDB_SYNC_EVERY) {
        return ['skipped' => true];
    }
    wdb_meta('sync_at', (string)time());     // 실패해도 다음 시도까지 기다림 (화면이 느려지지 않게)
    try {
        $rows = wdb_parse_sheet(wdb_fetch_sheet());
    } catch (Throwable $e) {
        wdb_meta('sync_error', date('m-d H:i') . ' ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    $pdo = wdb();
    $now = date('Y-m-d H:i:s');
    $up = $pdo->prepare("INSERT INTO winners (id, source, date, nick, sid, prize, how, memo, sheet_row, updated_at)
        VALUES (?, 'sheet', ?, ?, ?, ?, '시트', ?, ?, ?)
        ON CONFLICT(id) DO UPDATE SET date = excluded.date, nick = excluded.nick, sid = excluded.sid,
            prize = excluded.prize, sheet_row = excluded.sheet_row, updated_at = excluded.updated_at,
            memo = CASE WHEN winners.memo = '' THEN excluded.memo ELSE winners.memo END");
    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $up->execute([$r['id'], $r['date'], $r['nick'], $r['sid'], $r['prize'], $r['memo'], $r['row'], $now]);
    }
    // 시트에서 지워진 줄은 여기서도 지움 (단, 시트가 비어 읽힌 경우는 사고로 보고 지우지 않음)
    $removed = 0;
    if ($rows) {
        $removed = $pdo->exec("DELETE FROM winners WHERE source = 'sheet' AND updated_at <> " . $pdo->quote($now));
    }
    $pdo->commit();
    wdb_meta('sync_ok', date('m-d H:i') . ' · ' . count($rows) . '건');
    wdb_meta('sync_error', '');
    return ['ok' => true, 'rows' => count($rows), 'removed' => $removed];
}

// ── 관제 화면용 ────────────────────────────────────────────

/** 관제 화면(winners.json 과 같은 모양)으로 돌려줍니다. 시트에 이미 있는 관제 기록은 한 번만 보입니다. */
function wdb_list(): array
{
    $all = wdb()->query("SELECT * FROM winners WHERE source IN ('sheet', 'live') ORDER BY date, source DESC, sheet_row, at, id")->fetchAll();
    $norm = fn($s) => mb_strtolower(preg_replace('/\s+/u', '', (string)$s));
    $sheetKeys = [];
    $sheetDates = [];   // 아이디|상품 → 시트에 적힌 날짜들 (미수령 판단용)
    foreach ($all as $w) {
        if ($w['source'] === 'sheet') {
            $sheetKeys[$w['date'] . '|' . $w['sid'] . '|' . $norm($w['prize'])] = true;
            $sheetDates[$w['sid'] . '|' . $norm($w['prize'])][] = $w['date'];
        }
    }
    $list = [];
    foreach ($all as $w) {
        if ($w['source'] === 'live') {
            [$p] = wdb_prize($w['prize']);
            if ($w['sid'] !== '' && isset($sheetKeys[$w['date'] . '|' . strtolower($w['sid']) . '|' . $norm($p)])) {
                continue;
            }
        }
        $list[] = ['id' => $w['id'], 'date' => $w['date'], 'nick' => $w['nick'], 'sid' => $w['sid'],
            'prize' => $w['prize'], 'how' => $w['how'], 'at' => $w['at'], 'sent' => $w['sent'],
            'memo' => $w['memo'], 'src' => $w['source']];
    }
    // 미수령: 예전 관제 기록에는 있는데 시트(수령 확인 명단)에는 없는 당첨
    //  - 같은 사람·같은 상품이 시트에 7일 안쪽 날짜로 있으면 같은 건(날짜만 다르게 적힌 것)으로 봄
    //  - 날짜 없는 기록(출연자 지급 등)은 시청자 당첨이 아니므로 제외
    $unclaimed = [];
    $seen = [];
    foreach (wdb()->query("SELECT * FROM winners WHERE source = 'old' AND date <> '' ORDER BY date, at, id")->fetchAll() as $w) {
        if (preg_match('/출연/u', $w['how']) || $w['sid'] === '') {
            continue;
        }
        [$p] = wdb_prize($w['prize']);
        $k = strtolower($w['sid']) . '|' . $norm($p);
        $t = strtotime($w['date']);
        foreach ($sheetDates[$k] ?? [] as $d) {
            if (abs(strtotime($d) - $t) <= 7 * 86400) {
                continue 2;
            }
        }
        if (isset($seen[$k . '|' . $w['date']])) {
            continue;
        }
        $seen[$k . '|' . $w['date']] = true;
        $unclaimed[] = ['id' => $w['id'], 'date' => $w['date'], 'nick' => $w['nick'], 'sid' => $w['sid'],
            'prize' => $p, 'how' => '미수령', 'at' => $w['at'], 'sent' => $w['sent'],
            'memo' => trim('수령 확인 안 됨 · 예전 방식: ' . $w['how'] . ' ' . $w['memo']), 'src' => 'unclaimed'];
    }
    return ['list' => $list, 'unclaimed' => $unclaimed,
        'sheet' => ['ok' => wdb_meta('sync_ok'), 'error' => wdb_meta('sync_error')]];
}

function wdb_add(array $w): void
{
    wdb()->prepare("INSERT INTO winners (id, source, date, nick, sid, prize, how, at, updated_at)
        VALUES (?, 'live', ?, ?, ?, ?, ?, ?, ?)")
        ->execute([uniqid('w'), $w['date'], $w['nick'], strtolower($w['sid']), wdb_prize($w['prize'])[0], $w['how'], date('H:i'), date('Y-m-d H:i:s')]);
}

function wdb_source(string $id): string
{
    $st = wdb()->prepare('SELECT source FROM winners WHERE id = ?');
    $st->execute([$id]);
    return (string)$st->fetchColumn();
}

function wdb_delete(string $id): ?string
{
    if (wdb_source($id) === 'sheet') {
        return '구글시트에서 가져온 기록입니다. 시트에서 지우면 몇 분 안에 여기서도 사라집니다.';
    }
    wdb()->prepare("DELETE FROM winners WHERE id = ? AND source = 'live'")->execute([$id]);
    return null;
}

/** 고치기: 시트 기록은 쪽지(sent)·메모만 여기서 고칠 수 있습니다. (나머지는 시트에서) */
function wdb_update(string $id, array $body): ?string
{
    $src = wdb_source($id);
    if ($src === '') {
        return null;
    }
    $fields = $src === 'sheet' ? ['sent', 'memo'] : ['date', 'nick', 'sid', 'prize', 'how', 'sent', 'memo'];
    $blocked = array_diff(array_intersect(array_keys($body), ['date', 'nick', 'sid', 'prize', 'how']), $fields);
    $set = [];
    $vals = [];
    foreach ($fields as $k) {
        if (array_key_exists($k, $body)) {
            $set[] = "$k = ?";
            $vals[] = $k === 'sid' ? strtolower(trim((string)$body[$k])) : (string)$body[$k];
        }
    }
    if ($set) {
        $vals[] = $id;
        wdb()->prepare('UPDATE winners SET ' . implode(', ', $set) . ', updated_at = updated_at WHERE id = ?')->execute($vals);
    }
    return $blocked ? '시트에서 가져온 기록의 날짜·닉네임·아이디·상품은 구글시트에서 고쳐 주세요. (쪽지·메모는 저장됨)' : null;
}
