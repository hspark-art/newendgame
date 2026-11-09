<?php
// 상품 추첨 저장소 API — 로그인한 관리자만 쓸 수 있습니다.
declare(strict_types=1);
require __DIR__ . '/auth.php';
admin_boot();

const PZ = __DIR__ . '/pz';

function pz_boot(): void
{
    if (!is_dir(PZ)) {
        mkdir(PZ, 0755, true);
    }
    $ht = PZ . '/.htaccess';
    if (!file_exists($ht)) {                       // 폴더 직접 접근 차단
        file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
    if (!is_dir(PZ . '/img')) {
        mkdir(PZ . '/img', 0755, true);
    }
}

function jread(string $name, $default)
{
    $p = PZ . '/' . $name;
    if (!file_exists($p)) {
        return $default;
    }
    $j = json_decode((string)file_get_contents($p), true);
    return $j === null ? $default : $j;
}

function jwrite(string $name, $doc): void
{
    file_put_contents(PZ . '/' . $name,
        json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX);
}

function out($doc, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($doc, JSON_UNESCAPED_UNICODE);
    exit;
}


// ── 숲 쪽지 서버 발송 헬퍼 ────────────────────────────────────
// SOOP 은 자기 도메인 밖 요청의 응답을 막아 브라우저가 직접 못 보냅니다.
// talent 로그인 쿠키를 한 번 등록해 두고, 이 서버가 대신 POST 합니다.
const NOTE_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
    . '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

function note_write(string $cookie, string $to, string $content): array
{
    // 실제 전송은 note_api.php 로 갑니다 (작성 폼 action 은 ?page=write 이지만
    // doWrite 가 note_api.php 로 POST 합니다 — 실측 2026-08-21).
    // recv_id·txt_to 둘 다 받는 아이디, szWork=WRITE.
    $ch = curl_init('https://note.sooplive.com/api/note_api.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'szWork' => 'WRITE', 'recv_id' => $to, 'txt_to' => $to,
            'file_key' => '', 'file_size' => '', 'content' => $content]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . NOTE_UA,
            'Origin: https://note.sooplive.com',
            'Referer: https://note.sooplive.com/app/index.php?page=write',
            'X-Requested-With: XMLHttpRequest',
            'Cookie: ' . $cookie],
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false || $code === 0) {
        return ['ok' => false, 'reason' => 'SOOP 접속 실패: ' . $err, 'http' => $code];
    }
    // 로그인 풀림
    if ((stripos($res, 'login') !== false && stripos($res, 'member') !== false)
        || mb_strpos($res, '로그인이 필요') !== false) {
        return ['ok' => false, 'expired' => true, 'http' => $code,
                'reason' => 'talent 세션이 만료됐습니다 — 세션을 다시 등록하세요'];
    }
    // note_api.php 는 JSON 을 돌려줍니다:
    //   {"RESULT":1,"user_nick":"...","all_reject":false,"sender_balck":false,"MSG":"..."}
    $j = json_decode(trim($res), true);
    if (is_array($j) && array_key_exists('RESULT', $j)) {
        $rok = ((string)$j['RESULT'] === '1' || $j['RESULT'] === true);
        $msg = (string)($j['MSG'] ?? $j['MESSAGE'] ?? $j['message'] ?? '');
        // 상대가 쪽지 수신을 막았으면 도달하지 않습니다
        if (!empty($j['all_reject']) || !empty($j['sender_balck'])) {
            return ['ok' => false, 'http' => $code,
                    'reason' => '상대가 쪽지 수신을 거부한 계정입니다',
                    'nick' => (string)($j['user_nick'] ?? '')];
        }
        return ['ok' => $rok, 'http' => $code,
                'reason' => $rok ? '' : ($msg ?: '전송에 실패했습니다'),
                'nick' => (string)($j['user_nick'] ?? '')];
    }
    // JSON 이 아니면(로그인 페이지·오류 HTML 등) 실패로 봅니다 —
    // 성공은 반드시 RESULT:1 로만 인정합니다 (거짓 성공 방지).
    return ['ok' => false, 'http' => $code,
            'reason' => '예상치 못한 응답 (세션 만료이거나 SOOP 변경일 수 있음)',
            'snippet' => mb_substr(trim(preg_replace('/\s+/', ' ',
                strip_tags((string)$res))), 0, 140)];
}

function note_check(string $cookie): array
{
    $ch = curl_init('https://note.sooplive.com/app/index.php?page=recv_list');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['User-Agent: ' . NOTE_UA, 'Cookie: ' . $cookie]]);
    $res = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $valid = ($code === 200
        && (mb_strpos($res, '받은 쪽지') !== false || mb_strpos($res, '쪽지함') !== false)
        && mb_strpos($res, '로그인이 필요') === false);
    return ['ok' => true, 'valid' => $valid,
            'reason' => $valid ? '세션이 유효합니다 ✓'
                               : '로그인 세션이 아닙니다 — 쿠키를 다시 확인하세요'];
}

pz_boot();
$act = $_GET['act'] ?? '';
$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $act = $body['act'] ?? $act;
}

// 로그인 확인 — 공개 리더보드 읽기(predict_public)만 로그인 없이 허용
if (!admin_logged_in() && $act !== 'toto_public') {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => '로그인이 필요합니다']);
    exit;
}

// ── 방송 정보 (SOOP) — 브라우저는 CORS 로 막혀서 서버가 대신 물어봅니다 ──
if ($act === 'live') {
    // 기본은 우리 채널(talent). 시험용으로 ?bj=다른아이디 를 받을 수 있습니다.
    $bj = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_GET['bj'] ?? 'talent')));
    if ($bj === '') { $bj = 'talent'; }
    $ch = curl_init('https://live.sooplive.com/afreeca/player_live_api.php?bjid=' . $bj);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'bid' => $bj, 'type' => 'live', 'confirm_adult' => 'false',
            'player_type' => 'html5', 'mode' => 'landing', 'from_api' => '0',
            'pwd' => '', 'stream_type' => 'common', 'quality' => 'HD']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0'],
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        out(['error' => 'SOOP 접속 실패: ' . curl_error($ch)], 502);
    }
    $j = json_decode($res, true);
    out($j['CHANNEL'] ?? ['RESULT' => 0]);
}

// ── 상태 묶음 ──
if ($act === 'state') {
    out([
        'prizes' => jread('prizes.json', ['items' => []]),
        'winners' => jread('winners.json', ['list' => []]),
        'settings' => jread('settings.json', new stdClass()),
        'overlay' => jread('overlay.json', ['seq' => 0, 'kind' => 'none']),
        'session' => jread('session.json', ['on' => false, 'date' => '', 'startedAt' => '']),
    ]);
}
if ($act === 'overlay') {
    out(jread('overlay.json', ['seq' => 0, 'kind' => 'none']));
}
if ($act === 'overlay_set') {
    $ov = $body['overlay'] ?? [];
    $cur = jread('overlay.json', ['seq' => 0]);
    $ov['seq'] = (int)($cur['seq'] ?? 0) + 1;
    jwrite('overlay.json', $ov);
    out(['ok' => true, 'seq' => $ov['seq']]);
}

// ── 승부토토 — 하루 가상 포인트 배팅 (합동배당) ──────────────────
// toto_day.json    오늘 상태 {date,open,players:{계정:{n,bal,betW,betL,bust}},round,rounds,feed}
// toto_season.json 시즌 누적 {players:{계정:{n,days,champ,betW,betL,totalFinal,bestBal}},days:[]}
// 규칙: '도전'=참여(10,000P) · '이름 금액' 첫 베팅 고정 · 배당=총풀/승자풀 ·
//       승자 없으면 전원 환불 · 파산=그날 끝 · 하루 마감 때 시즌 누적
if ($act === 'toto_get') {
    out(['day' => jread('toto_day.json', null),
         'season' => jread('toto_season.json', ['players' => [], 'days' => []])]);
}
if ($act === 'toto_save') {
    jwrite('toto_day.json', $body['day'] ?? null);
    out(['ok' => true]);
}
if ($act === 'toto_settle') {
    // 정산은 서버가 단독 수행 — 서버에 같은 라운드가 있을 때만 (이중 정산 방지)
    $day = $body['day'] ?? null;
    $srv = jread('toto_day.json', null);
    if (!$day || !$srv || !is_array($day['round'] ?? null)
        || (string)(($srv['round']['id'] ?? '')) !== (string)($day['round']['id'] ?? '')) {
        out(['error' => '정산할 베팅 라운드가 없습니다 (이미 정산됐을 수 있어요)'], 400);
    }
    $winner = (($body['winner'] ?? 'a') === 'b') ? 'b' : 'a';
    $round = (array)$day['round'];
    $bets = (array)($round['bets'] ?? []);
    $pl = (array)($day['players'] ?? []);
    $poolA = 0; $poolB = 0;
    foreach ($bets as $v) { $v = (array)$v;
        if ((($v['p'] ?? 'a')) === 'b') $poolB += (int)($v['amt'] ?? 0);
        else $poolA += (int)($v['amt'] ?? 0); }
    $total = $poolA + $poolB;
    $pw = ($winner === 'a') ? $poolA : $poolB;
    $refund = ($pw <= 0);                    // 승자 쪽에 아무도 없음 → 전원 환불
    $odds = $refund ? 0 : round($total / max(1, $pw), 2);
    $hit = 0; $top = [];
    foreach ($bets as $bid => $v) {
        $v = (array)$v; $amt = (int)($v['amt'] ?? 0);
        $pick = (($v['p'] ?? 'a') === 'b') ? 'b' : 'a';
        $p = (array)($pl[$bid] ?? ['n' => $v['n'] ?? '', 'bal' => 0, 'betW' => 0, 'betL' => 0, 'bust' => false]);
        if ($refund) { $p['bal'] = (int)$p['bal'] + $amt; }
        elseif ($pick === $winner) {
            $gain = (int)floor($amt * $total / $pw);
            $p['bal'] = (int)$p['bal'] + $gain;
            $p['betW'] = (int)($p['betW'] ?? 0) + 1; $hit++;
            $top[] = ['n' => (string)($p['n'] ?? ''), 'gain' => $gain - $amt];
        } else { $p['betL'] = (int)($p['betL'] ?? 0) + 1; }
        if ((int)$p['bal'] <= 0) { $p['bal'] = 0; $p['bust'] = true; }   // 파산 — 오늘 끝
        $pl[$bid] = $p;
    }
    usort($top, function ($x, $y) { return $y['gain'] <=> $x['gain']; });
    $day['players'] = $pl;
    $rl = (array)($day['rounds'] ?? []);
    array_unshift($rl, ['a' => (string)($round['a'] ?? ''), 'b' => (string)($round['b'] ?? ''),
        'winner' => $winner, 'poolA' => $poolA, 'poolB' => $poolB, 'odds' => $odds,
        'hit' => $hit, 'bets' => count($bets), 'refund' => $refund, 'at' => date('H:i')]);
    $day['rounds'] = array_slice($rl, 0, 40);
    $day['round'] = null;
    $fd = (array)($day['feed'] ?? []);
    $wname = ($winner === 'a') ? (string)($round['a'] ?? '') : (string)($round['b'] ?? '');
    array_unshift($fd, ['type' => 'settle', 'at' => date('H:i:s'),
        'msg' => $refund ? '⚖ 적중자 없음 — 전원 환불'
                         : ('🏆 ' . $wname . ' 승 · 배당 ' . number_format($odds, 2) . '배 · 적중 ' . $hit . '명')]);
    $day['feed'] = array_slice($fd, 0, 80);
    jwrite('toto_day.json', $day);
    out(['ok' => true, 'refund' => $refund, 'odds' => $odds, 'hit' => $hit, 'bets' => count($bets),
         'poolA' => $poolA, 'poolB' => $poolB, 'wname' => $wname, 'top' => array_slice($top, 0, 5)]);
}
if ($act === 'toto_closeday') {
    $day = $body['day'] ?? jread('toto_day.json', null);
    if (!$day || !is_array($day['players'] ?? null) || !count((array)$day['players'])) {
        out(['error' => '마감할 참가자가 없습니다'], 400);
    }
    $rows = [];
    foreach ((array)$day['players'] as $pid => $p) { $p = (array)$p;
        $rows[] = ['id' => (string)$pid, 'n' => (string)($p['n'] ?? ''), 'bal' => (int)($p['bal'] ?? 0),
                   'betW' => (int)($p['betW'] ?? 0), 'betL' => (int)($p['betL'] ?? 0)]; }
    usort($rows, function ($x, $y) { return $y['bal'] <=> $x['bal']; });
    $season = jread('toto_season.json', ['players' => [], 'days' => []]);
    $sp = (array)($season['players'] ?? []);
    foreach ($rows as $i => $r) {
        $s2 = (array)($sp[$r['id']] ?? ['n' => '', 'days' => 0, 'champ' => 0, 'betW' => 0,
                                        'betL' => 0, 'totalFinal' => 0, 'bestBal' => 0]);
        if ($r['n'] !== '') $s2['n'] = $r['n'];
        $s2['days'] = (int)$s2['days'] + 1;
        if ($i === 0) $s2['champ'] = (int)$s2['champ'] + 1;
        $s2['betW'] = (int)$s2['betW'] + $r['betW'];
        $s2['betL'] = (int)$s2['betL'] + $r['betL'];
        $s2['totalFinal'] = (int)$s2['totalFinal'] + $r['bal'];
        if ($r['bal'] > (int)$s2['bestBal']) $s2['bestBal'] = $r['bal'];
        $sp[$r['id']] = $s2;
    }
    $season['players'] = $sp;
    $days = (array)($season['days'] ?? []);
    array_unshift($days, ['date' => (string)($day['date'] ?? date('Y-m-d')), 'entries' => count($rows),
        'champ' => ['n' => $rows[0]['n'], 'bal' => $rows[0]['bal']],
        'top' => array_map(function ($r) { return ['n' => $r['n'], 'bal' => $r['bal']]; }, array_slice($rows, 0, 5))]);
    $season['days'] = array_slice($days, 0, 120);
    jwrite('toto_season.json', $season);
    jwrite('toto_day.json', null);
    out(['ok' => true, 'entries' => count($rows),
         'rank' => array_map(function ($r) { return ['n' => $r['n'], 'bal' => $r['bal']]; }, array_slice($rows, 0, 10))]);
}
if ($act === 'toto_public') {
    // 공개 리더보드 — 로그인 불필요, 닉네임만 (계정 비공개)
    $day = jread('toto_day.json', null);
    $dayOut = null;
    if (is_array($day)) {
        $rows = [];
        foreach ((array)($day['players'] ?? []) as $p) { $p = (array)$p;
            $rows[] = ['n' => (string)($p['n'] ?? ''), 'bal' => (int)($p['bal'] ?? 0),
                       'betW' => (int)($p['betW'] ?? 0), 'betL' => (int)($p['betL'] ?? 0),
                       'bust' => !empty($p['bust'])]; }
        usort($rows, function ($x, $y) { return $y['bal'] <=> $x['bal']; });
        $roundPub = null;
        if (is_array($day['round'] ?? null)) {
            $r = (array)$day['round']; $pa = 0; $pb = 0; $nb = 0;
            foreach ((array)($r['bets'] ?? []) as $v) { $v = (array)$v; $nb++;
                if ((($v['p'] ?? 'a')) === 'b') $pb += (int)($v['amt'] ?? 0);
                else $pa += (int)($v['amt'] ?? 0); }
            $roundPub = ['a' => (string)($r['a'] ?? ''), 'b' => (string)($r['b'] ?? ''),
                         'state' => (string)($r['state'] ?? ''), 'poolA' => $pa, 'poolB' => $pb, 'bets' => $nb];
        }
        $dayOut = ['date' => (string)($day['date'] ?? ''), 'open' => !empty($day['open']),
            'entries' => count($rows), 'rows' => array_slice($rows, 0, 200), 'round' => $roundPub,
            'rounds' => array_slice((array)($day['rounds'] ?? []), 0, 20),
            'feed' => array_slice((array)($day['feed'] ?? []), 0, 40)];
    }
    $season = jread('toto_season.json', ['players' => [], 'days' => []]);
    $sr = [];
    foreach ((array)($season['players'] ?? []) as $p) { $p = (array)$p;
        $w = (int)($p['betW'] ?? 0); $l = (int)($p['betL'] ?? 0);
        $sr[] = ['n' => (string)($p['n'] ?? ''), 'days' => (int)($p['days'] ?? 0),
                 'champ' => (int)($p['champ'] ?? 0), 'betW' => $w, 'betL' => $l,
                 'rate' => ($w + $l) ? round($w * 100 / ($w + $l)) : 0,
                 'totalFinal' => (int)($p['totalFinal'] ?? 0), 'bestBal' => (int)($p['bestBal'] ?? 0)]; }
    usort($sr, function ($x, $y) {
        return [$y['champ'], $y['totalFinal']] <=> [$x['champ'], $x['totalFinal']]; });
    out(['day' => $dayOut,
         'season' => ['players' => array_slice($sr, 0, 200),
                      'days' => array_slice((array)($season['days'] ?? []), 0, 30)]]);
}

// ── 당첨자 장부 ──
if ($act === 'pick') {
    $nick = trim((string)($body['nick'] ?? ''));
    if ($nick === '') {
        out(['error' => '닉네임이 없습니다'], 400);
    }
    $w = jread('winners.json', ['list' => []]);
    $w['list'][] = [
        'id' => uniqid('w'),
        'date' => $body['date'] ?? date('Y-m-d'),
        'nick' => $nick,
        'sid' => (string)($body['sid'] ?? ''),   // SOOP 아이디 (있으면 정확한 대조 키)
        'prize' => (string)($body['prize'] ?? ''),
        'how' => (string)($body['how'] ?? '지명'),
        'at' => date('H:i'),
    ];
    jwrite('winners.json', $w);
    out(['ok' => true]);
}
if ($act === 'winner_del') {
    $w = jread('winners.json', ['list' => []]);
    $w['list'] = array_values(array_filter($w['list'],
        fn($x) => ($x['id'] ?? '') !== ($body['id'] ?? '-')));
    jwrite('winners.json', $w);
    out(['ok' => true]);
}
if ($act === 'winner_update') {
    $w = jread('winners.json', ['list' => []]);
    foreach ($w['list'] as &$x) {
        if (($x['id'] ?? '') === ($body['id'] ?? '')) {
            foreach (['date', 'nick', 'sid', 'prize', 'how', 'sent', 'memo'] as $k) {
                if (array_key_exists($k, $body)) { $x[$k] = (string)$body[$k]; }
            }
            break;
        }
    }
    unset($x);
    jwrite('winners.json', $w);
    out(['ok' => true]);
}
if ($act === 'fill_sids') {
    // 모든 방송의 uid(닉→SOOP아이디)를 모아, 비어 있는 SOOP계정을 닉으로 자동 채웁니다.
    $map = [];
    foreach (glob(PZ . '/stats-*.json') ?: [] as $f) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (!empty($j['uid']) && is_array($j['uid'])) {
            foreach ($j['uid'] as $nick => $sid) {
                $sid = trim((string)$sid);
                if ($sid === '') { continue; }
                $key = mb_strtolower(preg_replace('/\s+/u', '', (string)$nick));
                if ($key !== '') { $map[$key] = $sid; }
            }
        }
    }
    $w = jread('winners.json', ['list' => []]);
    $filled = 0;
    foreach ($w['list'] as &$row) {
        if (trim((string)($row['sid'] ?? '')) !== '') { continue; }
        $nick = trim((string)($row['nick'] ?? ''));
        if ($nick === '') { continue; }
        $key = mb_strtolower(preg_replace('/\s+/u', '', $nick));
        if ($key !== '' && isset($map[$key])) { $row['sid'] = $map[$key]; $filled++; }
    }
    unset($row);
    if ($filled > 0) { jwrite('winners.json', $w); }
    out(['filled' => $filled, 'known' => count($map), 'total' => count($w['list'])]);
}
if ($act === 'cumulative') {
    // 여러 방송(stats-날짜.json)을 모아 누적 순위를 냅니다.
    //   연속 출석(최근 방송부터 연속으로 온 횟수) · 기간 채팅합 · 기간 후원합.
    $weeks = max(1, min(104, (int)($_GET['weeks'] ?? $body['weeks'] ?? 8)));
    $files = glob(PZ . '/stats-*.json') ?: [];
    sort($files);
    $dates = [];
    $perDate = [];
    $sidOf = [];
    foreach ($files as $f) {
        if (!preg_match('/stats-(\d{4}-\d{2}-\d{2})\.json$/', $f, $mm)) { continue; }
        $j = json_decode((string)@file_get_contents($f), true);
        $users = (isset($j['users']) && is_array($j['users'])) ? $j['users'] : [];
        if (!$users) { continue; }
        $date = $mm[1];
        $dates[] = $date;
        $perDate[$date] = $users;
        if (!empty($j['uid']) && is_array($j['uid'])) {
            foreach ($j['uid'] as $nk => $sd) {
                $sd = trim((string)$sd);
                if ($sd !== '') { $sidOf[$nk] = $sd; }
            }
        }
    }
    $cutoff = date('Y-m-d', strtotime("-{$weeks} weeks"));
    $agg = [];
    foreach ($dates as $date) {
        foreach ($perDate[$date] as $nick => $u) {
            $c = (int)($u['c'] ?? 0); $b = (int)($u['b'] ?? 0); $g = (int)($u['g'] ?? 0);
            if (!isset($agg[$nick])) { $agg[$nick] = ['c'=>0,'b'=>0,'wc'=>0,'wb'=>0,'days'=>0,'g'=>0,'present'=>[]]; }
            if ($g) { $agg[$nick]['g'] = $g; }   // 날짜 오름차순이라 가장 최근 등급이 남습니다
            $agg[$nick]['present'][$date] = true;
            $agg[$nick]['days']++;
            $agg[$nick]['c'] += $c; $agg[$nick]['b'] += $b;
            if ($date >= $cutoff) { $agg[$nick]['wc'] += $c; $agg[$nick]['wb'] += $b; }
        }
    }
    $rev = array_reverse($dates);
    $streakList = []; $chatList = []; $balloonList = [];
    foreach ($agg as $nick => $a) {
        $streak = 0;
        foreach ($rev as $d) { if (!empty($a['present'][$d])) { $streak++; } else { break; } }
        $sid = $sidOf[$nick] ?? '';
        $streakList[]  = ['nick'=>$nick,'sid'=>$sid,'streak'=>$streak,'days'=>$a['days'],'g'=>$a['g']];
        $chatList[]    = ['nick'=>$nick,'sid'=>$sid,'v'=>$a['wc'],'days'=>$a['days'],'g'=>$a['g']];
        $balloonList[] = ['nick'=>$nick,'sid'=>$sid,'v'=>$a['wb'],'days'=>$a['days'],'g'=>$a['g']];
    }
    usort($streakList, fn($x,$y) => ($y['streak'] <=> $x['streak']) ?: ($y['days'] <=> $x['days']));
    usort($chatList, fn($x,$y) => $y['v'] <=> $x['v']);
    usort($balloonList, fn($x,$y) => $y['v'] <=> $x['v']);
    out([
        'weeks' => $weeks,
        'broadcasts' => count($dates),
        'lastDate' => $dates ? end($dates) : '',
        'streak'  => array_values(array_slice(array_filter($streakList,  fn($x) => $x['streak'] >= 2), 0, 60)),
        'chat'    => array_values(array_slice(array_filter($chatList,    fn($x) => $x['v'] > 0), 0, 60)),
        'balloon' => array_values(array_slice(array_filter($balloonList, fn($x) => $x['v'] > 0), 0, 60)),
    ]);
}

// ── 숲 쪽지 서버 발송 ────────────────────────────────────────
if ($act === 'note_session_set') {
    $cookie = trim((string)($body['cookie'] ?? ''));
    // 여러 줄로 붙여넣어도 한 줄로 정리
    $cookie = preg_replace('/\s*[\r\n]+\s*/', ' ', $cookie);
    jwrite('note_session.json', ['cookie' => $cookie, 'savedAt' => date('Y-m-d H:i')]);
    out($cookie === '' ? ['ok' => true, 'valid' => false, 'reason' => '쿠키가 비었습니다']
                       : note_check($cookie));
}
if ($act === 'note_session_status') {
    $sSess = jread('note_session.json', ['cookie' => '']);
    out(['has' => !empty($sSess['cookie']), 'savedAt' => $sSess['savedAt'] ?? '']);
}
if ($act === 'note_session_check') {
    $sSess = jread('note_session.json', ['cookie' => '']);
    out(empty($sSess['cookie']) ? ['ok' => true, 'valid' => false, 'reason' => '세션 미등록']
                                : note_check((string)$sSess['cookie']));
}
if ($act === 'note_send') {
    $sSess = jread('note_session.json', ['cookie' => '']);
    $cookie = (string)($sSess['cookie'] ?? '');
    if ($cookie === '') {
        out(['ok' => false, 'reason' => '세션이 없습니다 — 먼저 talent 세션을 등록하세요']);
    }
    $to = preg_replace('/[^A-Za-z0-9_]/', '', (string)($body['to'] ?? ''));
    $content = trim((string)($body['content'] ?? ''));
    if ($to === '' || $content === '') {
        out(['ok' => false, 'reason' => '받는사람 또는 내용이 비었습니다']);
    }
    out(note_write($cookie, $to, mb_substr($content, 0, 5000)));
}

// ── 상품 ──
if ($act === 'prize_add') {
    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        out(['error' => '상품 이름이 없습니다'], 400);
    }
    $id = uniqid('p');
    $photo = '';
    if (!empty($body['photo']) && preg_match('/^data:image\/(\w+);base64,(.+)$/s',
            $body['photo'], $m)) {
        $ext = $m[1] === 'jpeg' ? 'jpg' : preg_replace('/[^a-z0-9]/', '', $m[1]);
        $raw = base64_decode($m[2]);
        if ($raw !== false && strlen($raw) < 8 * 1024 * 1024) {
            file_put_contents(PZ . '/img/' . $id . '.' . $ext, $raw);
            $photo = 'prize_api.php?act=img&f=' . $id . '.' . $ext;
        }
    }
    $p = jread('prizes.json', ['items' => []]);
    $p['items'][] = ['id' => $id, 'name' => $name,
                     'note' => (string)($body['note'] ?? ''), 'photo' => $photo];
    jwrite('prizes.json', $p);
    out(['ok' => true]);
}
if ($act === 'prize_del') {
    $p = jread('prizes.json', ['items' => []]);
    $p['items'] = array_values(array_filter($p['items'],
        fn($x) => ($x['id'] ?? '') !== ($body['id'] ?? '-')));
    jwrite('prizes.json', $p);
    out(['ok' => true]);
}
if ($act === 'prize_move') {
    $p = jread('prizes.json', ['items' => []]);
    $items = $p['items'];
    $i = -1;
    foreach ($items as $k => $x) { if (($x['id'] ?? '') === ($body['id'] ?? '')) { $i = $k; break; } }
    $j = $i + ((($body['dir'] ?? '') === 'up') ? -1 : 1);
    if ($i >= 0 && $j >= 0 && $j < count($items)) {
        $tmp = $items[$i]; $items[$i] = $items[$j]; $items[$j] = $tmp;
        $p['items'] = $items; jwrite('prizes.json', $p);
    }
    out(['ok' => true]);
}
if ($act === 'img') {
    $f = basename((string)($_GET['f'] ?? ''));
    $p = PZ . '/img/' . $f;
    if ($f === '' || !file_exists($p)) {
        out(['error' => 'no image'], 404);
    }
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($ext === 'png' ? 'image/png'
        : ($ext === 'webp' ? 'image/webp' : 'image/jpeg')));
    header('Cache-Control: private, max-age=3600');
    readfile($p);
    exit;
}

// ── 설정 ──
if ($act === 'settings_set') {
    jwrite('settings.json', $body['settings'] ?? new stdClass());
    out(['ok' => true]);
}

// ── 방송별 활약 눈금 (관제 창이 45초마다 남깁니다) ──
if ($act === 'stats_save') {
    $date = preg_replace('/[^0-9-]/', '', (string)($body['date'] ?? date('Y-m-d')));
    jwrite('stats-' . $date . '.json', [
        'date' => $date,
        'title' => (string)($body['title'] ?? ''),
        'savedAt' => date('H:i:s'),
        'users' => $body['users'] ?? new stdClass(),
        'uid' => $body['uid'] ?? new stdClass(),
        'rawUnknown' => array_slice($body['rawUnknown'] ?? [], -200),
    ]);
    out(['ok' => true]);
}
if ($act === 'stats_list') {
    $rows = [];
    foreach (glob(PZ . '/stats-*.json') ?: [] as $f) {
        $j = json_decode((string)file_get_contents($f), true) ?: [];
        $u = $j['users'] ?? [];
        $chats = 0; $bal = 0;
        foreach ($u as $x) { $chats += $x['c'] ?? 0; $bal += $x['b'] ?? 0; }
        $rows[] = ['date' => $j['date'] ?? basename($f),
                   'title' => $j['title'] ?? '',
                   'users' => count($u), 'chats' => $chats, 'balloons' => $bal];
    }
    usort($rows, fn($a, $b) => strcmp($b['date'], $a['date']));
    out(['list' => $rows]);
}
if ($act === 'stats_get') {
    $date = preg_replace('/[^0-9-]/', '', (string)($_GET['date'] ?? ''));
    out(jread('stats-' . $date . '.json', ['users' => new stdClass()]));
}

// ── 집계 켜짐/꺼짐 (스타트·종료 버튼 상태 — 창을 껐다 켜도 이어받게) ──
if ($act === 'session_set') {
    $s = $body['session'] ?? [];
    jwrite('session.json', [
        'on' => !empty($s['on']),
        'date' => preg_replace('/[^0-9-]/', '', (string)($s['date'] ?? '')),
        'startedAt' => (string)($s['startedAt'] ?? ''),
    ]);
    out(['ok' => true]);
}

// ── 채팅 로그 — 방송 날짜별 파일에 이어 붙입니다 (초기화 전까지 보존) ──
if ($act === 'toto_chatlog') {
    // 승부예측 진행 중 전 채팅 원본 + 운영 이벤트 마커 (검증·분쟁 근거)
    $date = preg_replace('/[^0-9-]/', '', (string)($body['date'] ?? date('Y-m-d')));
    $lines = array_slice(is_array($body['lines'] ?? null) ? $body['lines'] : [], 0, 2000);
    if ($lines && $date !== '') {
        $txt = '';
        foreach ($lines as $l) {
            $txt .= json_encode($l, JSON_UNESCAPED_UNICODE) . "
";
        }
        file_put_contents(PZ . '/toto_chatlog-' . $date . '.jsonl', $txt, FILE_APPEND | LOCK_EX);
    }
    out(['ok' => true]);
}
if ($act === 'chat_log') {
    $date = preg_replace('/[^0-9-]/', '', (string)($body['date'] ?? date('Y-m-d')));
    $lines = array_slice(is_array($body['lines'] ?? null) ? $body['lines'] : [], 0, 2000);
    if ($lines && $date !== '') {
        $txt = '';
        foreach ($lines as $l) {
            $txt .= json_encode($l, JSON_UNESCAPED_UNICODE) . "\n";
        }
        file_put_contents(PZ . '/chatlog-' . $date . '.jsonl', $txt, FILE_APPEND | LOCK_EX);
    }
    out(['ok' => true]);
}
if ($act === 'chat_tail') {
    $date = preg_replace('/[^0-9-]/', '', (string)($_GET['date'] ?? ''));
    $p = PZ . '/chatlog-' . $date . '.jsonl';
    $rows = [];
    if ($date !== '' && file_exists($p)) {
        $all = explode("\n", trim((string)file_get_contents($p)));
        foreach (array_slice($all, -3000) as $ln) {
            $j = json_decode($ln, true);
            if ($j) { $rows[] = $j; }
        }
    }
    out(['lines' => $rows]);
}
if ($act === 'chat_clear') {
    $date = preg_replace('/[^0-9-]/', '', (string)($body['date'] ?? ''));
    if ($date !== '') { @unlink(PZ . '/chatlog-' . $date . '.jsonl'); }
    out(['ok' => true]);
}

// ── 별풍선 큰 알림(토스트) ──
if ($act === 'toast_add') {
    $t = jread('toasts.json', ['list' => []]);
    $t['list'][] = ['seq' => (int)(($t['list'][count($t['list']) - 1]['seq'] ?? 0) + 1),
                    'nick' => (string)($body['nick'] ?? ''),
                    'count' => (int)($body['count'] ?? 0), 'ts' => time()];
    $t['list'] = array_slice($t['list'], -30);
    jwrite('toasts.json', $t);
    out(['ok' => true]);
}
if ($act === 'toasts') {
    out(jread('toasts.json', ['list' => []]));
}

// ── 구글 문서 당첨자 대조 ──
if ($act === 'gdoc') {
    $st = jread('settings.json', []);
    $docId = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($st['gdocId'] ?? ''));
    if ($docId === '') { out(['names' => [], 'ids' => [], 'note' => '문서 없음']); }
    $cache = jread('gdoc-cache.json', []);
    if (($cache['id'] ?? '') === $docId && (time() - ($cache['ts'] ?? 0)) < 300) {
        out($cache['data']);              // 5분 캐시
    }
    $ch = curl_init('https://docs.google.com/document/d/' . $docId . '/export?format=txt');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0']]);
    $txt = curl_exec($ch);
    if ($txt === false) { out(['names' => [], 'ids' => [], 'note' => '문서 접근 실패']); }
    // '닉네임 / 아이디' 형태를 뽑습니다. 아이디는 소문자 영숫자.
    $ids = []; $names = [];
    foreach (preg_split('/
?
/', $txt) as $line) {
        if (preg_match('#^\s*([^/]{1,30})/\s*([A-Za-z0-9_]{3,30})#', $line, $m)) {
            $names[] = trim($m[1]);
            $ids[] = strtolower(trim($m[2]));
        }
    }
    $data = ['names' => array_values(array_unique($names)),
             'ids' => array_values(array_unique($ids)),
             'note' => count($ids) . '명'];
    jwrite('gdoc-cache.json', ['id' => $docId, 'ts' => time(), 'data' => $data]);
    out($data);
}

// ── 슬랙으로 요약 보내기 ──
if ($act === 'slack_report') {
    $st = jread('settings.json', []);
    $hook = (string)($st['slackWebhook'] ?? '');
    if (strpos($hook, 'hooks.slack.com') === false) {
        out(['error' => '슬랙 웹훅 주소를 설정에 먼저 넣어 주세요'], 400);
    }
    $text = (string)($body['text'] ?? '끝장전 상품 추첨 요약');
    $payload = json_encode(['text' => $text], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($hook);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $res = curl_exec($ch);
    out(['ok' => trim((string)$res) === 'ok', 'resp' => substr((string)$res, 0, 60)]);
}

out(['error' => '모르는 요청: ' . $act], 404);
