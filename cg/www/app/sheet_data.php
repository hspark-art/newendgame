<?php
declare(strict_types=1);

/**
 * Google 시트(끝장전 데이터) 표 → 내부 데이터. 순수 함수이며 네트워크·DB에 의존하지 않는다.
 * 입력 표는 행 목록(행 = 셀 값 목록)이다. Sheets API와 xlsx 가져오기가 같은 모양으로 넘긴다.
 *
 * 원본: Results 탭 (1행 = 1세트: Winner, Race, Loser, Race, Map, Date). 상금 열은 읽지 않는다.
 * 끝장전(매치) = 같은 날 같은 두 선수의 세트 묶음. 9세트를 모두 치르는 방식이며, 9세트가 아닌 묶음·동점·
 * 경기 중 종족 변경은 "이상 사례"로 표시하고 매치 통계에서 뺀다 (세트 통계에는 그대로 센다).
 *
 * 교차 검증 (시트가 스스로 계산한 집계와 비교):
 *   Players 탭        → 선수별 세트 승·패 (전체, vs Z/T/P)
 *   상대전적조회NEW 탭 → 선수별 끝장전 목록 (날짜·상대·세트 승·패)
 *   예측 탭 순위표     → 중계진별 전체·적중 수
 * 검증 탭을 찾지 못하거나 값이 다르면 해당 수치를 쓰는 CG 필드를 송출 차단 대상으로 표시한다.
 */

const SHEET_TABS_DEFAULT = [
    'results' => 'Results',
    'players' => 'Players',
    'matches' => '상대전적조회NEW',
    'predictions' => '중계진 예측 현황입력용',
];

/** 예측 탭의 중계진 표기 "박상현 캐스터"에서 떼어 낼 직책 */
const PREDICTOR_ROLES = ['캐스터', '해설', '해설위원', '위원', 'MC', '아나운서'];

/** 표에서 이름 비교용: 모든 공백 제거 + 소문자 */
function cell_key(mixed $v): string
{
    return mb_strtolower((string)preg_replace('/\s+/u', '', is_scalar($v) ? (string)$v : ''));
}

function cell_str(mixed $v): string
{
    return is_scalar($v) ? trim((string)$v) : '';
}

/** 선수 이름 정규화: 보이지 않는 문자 제거, 공백 정리, NFC. 형식이 이상하면 null */
function name_norm(mixed $v): ?string
{
    $s = cell_str($v);
    $s = (string)preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s);
    $s = trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $s));
    if (class_exists('Normalizer')) {
        $s = (string)Normalizer::normalize($s, Normalizer::FORM_C);
    }
    if ($s === '' || mb_strlen($s) > 20 || preg_match('/[\x00-\x1F\x7F<>"]/u', $s)) {
        return null;
    }
    return $s;
}

/**
 * 날짜: "2019-05-14", "2019/05/14", "2019. 5. 14" (연도가 앞인 형식만), xlsx 날짜 일련번호(숫자, $serialOk일 때만).
 * 다른 형식은 추측하지 않고 null.
 */
function date_norm(mixed $v, bool $serialOk = false): ?string
{
    if ((is_int($v) || is_float($v)) && $serialOk) {
        if ($v < 1 || $v > 2958465 || floor((float)$v) != $v) {
            return null;
        }
        return (new DateTimeImmutable('1899-12-30'))->modify('+' . (int)$v . ' days')->format('Y-m-d');
    }
    if (!is_string($v) || !preg_match('/^(\d{4})\s*[-.\/]\s*(\d{1,2})\s*[-.\/]\s*(\d{1,2})\.?$/D', trim($v), $m)) {
        return null;
    }
    [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/** 0 이상 정수 (숫자 셀·숫자 문자열). 그 밖은 null */
function int_norm(mixed $v): ?int
{
    if (is_int($v)) {
        return $v >= 0 ? $v : null;
    }
    if (is_float($v) && floor($v) == $v && $v >= 0 && $v < 1e9) {
        return (int)$v;
    }
    if (is_string($v) && preg_match('/^\d{1,9}$/D', trim($v))) {
        return (int)trim($v);
    }
    return null;
}

/** 머리글 행 찾기: $expect = [열 번호 => 기대 글자(공백 무시)]. 처음 $scan행 안에서 찾는다 */
function table_header_row(array $rows, array $expect, int $scan = 15): ?int
{
    foreach (array_slice($rows, 0, $scan, true) as $i => $row) {
        $ok = true;
        foreach ($expect as $col => $want) {
            if (cell_key($row[$col] ?? '') !== cell_key($want)) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            return $i;
        }
    }
    return null;
}

/** 빈 행 (모든 셀이 비어 있음) */
function row_blank(array $row): bool
{
    foreach ($row as $c) {
        if (cell_str($c) !== '') {
            return false;
        }
    }
    return true;
}

/**
 * 시트 표들 → 내부 데이터.
 * @param array{results:list<array>, players?:?list<array>, matches?:?list<array>, predictions?:?list<array>} $tables
 *   검증·예측 탭은 없으면 null (탭 없음)
 * @throws ProviderError Results 탭에 형식 오류가 하나라도 있으면 (마지막 정상 데이터를 유지하도록)
 */
function sheet_dataset(array $tables, string $method, bool $serialDates = false): array
{
    $games = sheet_games($tables['results'] ?? [], $serialDates);
    [$matches, $anomalies] = sheet_matches($games);
    $setRecords = sheet_set_records($games);

    $check = ['method' => $method, 'anomalies' => $anomalies, 'mismatches' => [], 'unavailable' => [], 'notes' => []];
    $verify = ['sets' => ['available' => false, 'players' => []], 'matches' => ['available' => false, 'players' => []],
        'predictions' => ['available' => false, 'predictors' => []]];

    // 선수: Results에 나온 이름. 주 종족은 Players 탭의 Race, 없으면 가장 많이 쓴 종족(동률이면 정하지 않음)
    $sheetPlayers = isset($tables['players']) ? sheet_players_table($tables['players']) : null;
    $players = [];
    foreach ($setRecords as $pid => $rec) {
        $pid = (string)$pid; // 숫자로만 된 이름도 문자열로
        $race = $sheetPlayers['rows'][$pid]['race'] ?? null;
        if (!in_array($race, RACES, true)) {
            arsort($rec['used']);
            $top = array_slice($rec['used'], 0, 2, true);
            $race = count($top) === 2 && array_values($top)[0] === array_values($top)[1] ? null : array_key_first($top);
            if ($race === null) {
                $check['anomalies'][] = ['kind' => 'race_ambiguous', 'text' => "$pid: 주 종족을 정할 수 없음 (종족별 세트 수 같음)"];
            }
        }
        $players[$pid] = ['id' => $pid, 'name' => $pid, 'nickname' => null, 'race' => $race, 'aliases' => [], 'active' => true];
    }

    // 검증 1: 세트 전적 (Players 탭)
    if ($sheetPlayers === null || $sheetPlayers['error'] !== null) {
        $check['unavailable'][] = '세트 전적 검증 불가: ' . ($sheetPlayers['error'] ?? 'Players 탭 없음');
    } else {
        $verify['sets']['available'] = true;
        foreach ($setRecords as $pid => $rec) {
            $pid = (string)$pid;
            $row = $sheetPlayers['rows'][$pid] ?? null;
            foreach (['all', 'P', 'T', 'Z'] as $k) {
                $calc = $rec[$k];
                $sheet = $row[$k] ?? null;
                $ok = $sheet !== null && $sheet === $calc;
                $verify['sets']['players'][$pid][$k] = $ok;
                if (!$ok) {
                    $check['mismatches'][] = ['kind' => 'sets', 'who' => $pid, 'item' => $k === 'all' ? '세트 전적' : "세트 전적 vs $k",
                        'sheet' => $row === null ? '시트에 없음' : ($sheet === null ? '읽을 수 없음' : "{$sheet[0]}승 {$sheet[1]}패"),
                        'calc' => "{$calc[0]}승 {$calc[1]}패"];
                }
            }
        }
        // 시트 집계에만 있는 선수 (Results에 기록 없음) — 순위 모집단이 확실하지 않다
        $verify['sets']['extra'] = [];
        foreach ($sheetPlayers['rows'] as $pid => $row) {
            $pid = (string)$pid;
            if (isset($setRecords[$pid]) || ($row['all'] !== null && $row['all'][0] + $row['all'][1] === 0)) {
                continue;
            }
            $verify['sets']['extra'][] = $pid;
            $check['mismatches'][] = ['kind' => 'sets', 'who' => $pid, 'item' => '세트 전적',
                'sheet' => $row['all'] === null ? '읽을 수 없음' : "{$row['all'][0]}승 {$row['all'][1]}패", 'calc' => 'Results에 기록 없음'];
        }
    }

    // 검증 2: 선수별 끝장전 목록 (상대전적조회NEW 탭) + 이상 사례가 있는 선수
    $sheetMatches = isset($tables['matches']) ? sheet_match_list_table($tables['matches']) : null;
    if ($sheetMatches === null || $sheetMatches['error'] !== null) {
        $check['unavailable'][] = '끝장전 목록 검증 불가: ' . ($sheetMatches['error'] ?? '상대전적조회NEW 탭 없음');
    } else {
        $verify['matches']['available'] = true;
        $calcLists = [];
        foreach ($matches as $m) {
            $calcLists[$m['playerA']][] = [$m['date'], $m['playerB'], $m['scoreA'], $m['scoreB'], (string)$m['raceA'], (string)$m['raceB']];
            $calcLists[$m['playerB']][] = [$m['date'], $m['playerA'], $m['scoreB'], $m['scoreA'], (string)$m['raceB'], (string)$m['raceA']];
        }
        $withAnomaly = [];
        foreach ($matches as $m) {
            if ($m['anomaly'] !== null) {
                $withAnomaly[$m['playerA']] = $withAnomaly[$m['playerB']] = true;
            }
        }
        foreach ($calcLists as $pid => $list) {
            $pid = (string)$pid;
            $sheetList = $sheetMatches['rows'][$pid] ?? [];
            $diff = sheet_list_diff($list, $sheetList);
            $verify['matches']['players'][$pid] = $diff === null && !isset($withAnomaly[$pid]);
            if ($diff !== null) {
                $check['mismatches'][] = ['kind' => 'matches', 'who' => $pid, 'item' => '끝장전 목록'] + $diff;
            }
        }
        $verify['matches']['extra'] = [];
        foreach ($sheetMatches['rows'] as $pid => $list) {
            $pid = (string)$pid;
            if (!isset($calcLists[$pid])) {
                $verify['matches']['extra'][] = $pid;
                $check['mismatches'][] = ['kind' => 'matches', 'who' => $pid, 'item' => '끝장전 목록',
                    'sheet' => count($list) . '경기', 'calc' => 'Results에 기록 없음'];
            }
        }
    }

    // 승자 예측 (예측 탭): 기록 + 순위표 검증. 탭에 문제가 있으면 예측 CG만 쓸 수 없고 나머지는 그대로 쓴다.
    $pred = isset($tables['predictions']) ? sheet_predictions_table($tables['predictions'], $serialDates) : null;
    $predictions = [];
    $predictors = [];
    if ($pred === null || $pred['error'] !== null) {
        $check['unavailable'][] = '승자 예측 사용 불가: ' . ($pred['error'] ?? '예측 탭 없음');
    } else {
        $predictions = $pred['records'];
        foreach ($predictions as $r) {
            $predictors[$r['predictor']] = ['id' => (string)$r['predictor'], 'name' => (string)$r['predictor']];
        }
        if ($pred['ranking'] === null) {
            $check['unavailable'][] = '승자 예측 검증 불가: 순위표를 찾을 수 없음';
        } else {
            $verify['predictions']['available'] = true;
            $calc = [];
            foreach ($predictions as $r) {
                $calc[$r['predictor']] ??= [0, 0];
                $calc[$r['predictor']][0]++;
                $r['correct'] && $calc[$r['predictor']][1]++;
            }
            foreach ($calc as $id => [$total, $wins]) {
                $id = (string)$id;
                $s = $pred['ranking'][$id] ?? null;
                $ok = $s !== null && $s === [$total, $wins];
                $verify['predictions']['predictors'][$id] = $ok;
                if (!$ok) {
                    $check['mismatches'][] = ['kind' => 'predictions', 'who' => $id, 'item' => '예측 전체·적중',
                        'sheet' => $s === null ? '순위표에 없음' : "{$s[0]}회 중 {$s[1]}회", 'calc' => "{$total}회 중 {$wins}회"];
                }
            }
            $verify['predictions']['extra'] = [];
            foreach ($pred['ranking'] as $id => [$total, $wins]) {
                $id = (string)$id;
                if (!isset($calc[$id]) && $total > 0) {
                    $verify['predictions']['extra'][] = $id;
                    $check['mismatches'][] = ['kind' => 'predictions', 'who' => $id, 'item' => '예측 전체·적중',
                        'sheet' => "{$total}회 중 {$wins}회", 'calc' => '예측 기록 없음'];
                }
            }
        }
    }

    $valid = array_values(array_filter($matches, static fn($m) => $m['anomaly'] === null));
    $check['counts'] = ['games' => count($games), 'matches' => count($matches), 'valid_matches' => count($valid),
        'players' => count($players), 'predictions' => count($predictions),
        'first_date' => $games ? min(array_column($games, 'date')) : null, 'last_date' => $games ? max(array_column($games, 'date')) : null];

    return [
        'source' => 'sheet',
        'mock' => false,
        'players' => $players,
        'games' => $games,
        'matches' => $valid,          // 통계용: 이상 사례 제외
        'matches_all' => $matches,    // 점검·검증용
        'predictions' => $predictions,
        'predictors' => $predictors,
        'online' => [],
        'online_available' => false,  // 온라인 기록은 시트에 없음 (eloboard 연동 전까지 수동 입력)
        'double_chance' => [],        // 정의 확인 전까지 자동값 없음
        'verify' => $verify,
        'check' => $check,
    ];
}

/** Results 탭 → 세트 목록. 형식 오류가 있으면 ProviderError */
function sheet_games(array $rows, bool $serialDates): array
{
    $h = table_header_row($rows, [0 => 'Winner', 1 => 'Race', 2 => 'Loser', 3 => 'Race', 4 => 'Map', 5 => 'Date']);
    if ($h === null) {
        throw new ProviderError('Results 탭의 머리글(Winner, Race, Loser, Race, Map, Date)을 찾을 수 없습니다.');
    }
    $games = [];
    $problems = [];
    foreach ($rows as $i => $row) {
        if ($i <= $h || row_blank($row)) {
            continue;
        }
        $n = $i + 1; // 시트 행 번호
        $w = name_norm($row[0] ?? '');
        $l = name_norm($row[2] ?? '');
        $wr = strtoupper(cell_str($row[1] ?? ''));
        $lr = strtoupper(cell_str($row[3] ?? ''));
        $date = date_norm($row[5] ?? null, $serialDates);
        $err = [];
        if ($w === null || $l === null) {
            $err[] = '선수 이름';
        } elseif ($w === $l) {
            $err[] = '같은 선수';
        }
        if (!in_array($wr, RACES, true) || !in_array($lr, RACES, true)) {
            $err[] = "종족($wr/$lr)";
        }
        if ($date === null) {
            $err[] = '날짜(' . mb_substr(cell_str($row[5] ?? ''), 0, 20) . ')';
        }
        if ($err) {
            $problems[] = "Results {$n}행: " . implode(', ', $err) . ' 오류';
            continue;
        }
        $games[] = ['row' => $n, 'date' => $date, 'winner' => $w, 'wrace' => $wr, 'loser' => $l, 'lrace' => $lr,
            'map' => mb_substr(cell_str($row[4] ?? ''), 0, 60)];
    }
    if ($problems) {
        throw new ProviderError('Results 탭 형식 오류 ' . count($problems) . '건 — 시트를 고친 뒤 다시 불러오세요', array_slice($problems, 0, 30));
    }
    if (!$games) {
        throw new ProviderError('Results 탭에 세트 기록이 없습니다.');
    }
    return $games;
}

/**
 * 세트 → 끝장전. 같은 날짜·같은 두 선수 = 1경기. A는 그 경기 첫 세트의 승자.
 * @return array{0:list<array>, 1:list<array>} [경기 목록(이상 표시 포함), 이상 사례]
 */
function sheet_matches(array $games): array
{
    $groups = [];
    foreach ($games as $g) {
        $pair = [$g['winner'], $g['loser']];
        sort($pair, SORT_STRING);
        $groups[$g['date'] . '|' . implode('|', $pair)][] = $g;
    }
    $matches = [];
    $anomalies = [];
    foreach ($groups as $key => $sets) {
        $a = $sets[0]['winner'];
        $b = $sets[0]['loser'];
        $score = [$a => 0, $b => 0];
        $races = [$a => [], $b => []];
        foreach ($sets as $s) {
            $score[$s['winner']]++;
            $races[$s['winner']][$s['wrace']] = true;
            $races[$s['loser']][$s['lrace']] = true;
        }
        $n = count($sets);
        $anomaly = null;
        if ($n !== 9) {
            $anomaly = "세트 수 {$n}개 (9세트가 아님)";
        } elseif ($score[$a] === $score[$b]) {
            $anomaly = '승자 없음 (동점)';
        } elseif (count($races[$a]) > 1 || count($races[$b]) > 1) {
            $anomaly = '경기 중 종족 변경';
        }
        $m = [
            'id' => $key, 'date' => $sets[0]['date'], 'playerA' => $a, 'playerB' => $b,
            'raceA' => count($races[$a]) === 1 ? array_key_first($races[$a]) : null,
            'raceB' => count($races[$b]) === 1 ? array_key_first($races[$b]) : null,
            'scoreA' => $score[$a], 'scoreB' => $score[$b], 'sets' => $n,
            'bestOf' => $n === 9 ? 9 : null, 'rows' => [$sets[0]['row'], $sets[$n - 1]['row']], 'anomaly' => $anomaly,
            'source' => 'sheet',
        ];
        $matches[] = $m;
        if ($anomaly !== null) {
            $anomalies[] = ['kind' => 'match', 'text' => sprintf('%s %s vs %s %d:%d — %s (Results %d~%d행)',
                $m['date'], $a, $b, $m['scoreA'], $m['scoreB'], $anomaly, $m['rows'][0], $m['rows'][1])];
        }
    }
    usort($matches, static fn($x, $y) => [$x['date'], $x['rows'][0]] <=> [$y['date'], $y['rows'][0]]);
    return [$matches, $anomalies];
}

/** 선수별 세트 전적: all/P/T/Z = [승, 패] (상대 종족 기준), used = 자기가 쓴 종족별 세트 수 */
function sheet_set_records(array $games): array
{
    $r = [];
    $blank = static fn() => ['all' => [0, 0], 'P' => [0, 0], 'T' => [0, 0], 'Z' => [0, 0], 'used' => []];
    foreach ($games as $g) {
        $r[$g['winner']] ??= $blank();
        $r[$g['loser']] ??= $blank();
        $r[$g['winner']]['all'][0]++;
        $r[$g['winner']][$g['lrace']][0]++;
        $r[$g['loser']]['all'][1]++;
        $r[$g['loser']][$g['wrace']][1]++;
        $r[$g['winner']]['used'][$g['wrace']] = ($r[$g['winner']]['used'][$g['wrace']] ?? 0) + 1;
        $r[$g['loser']]['used'][$g['lrace']] = ($r[$g['loser']]['used'][$g['lrace']] ?? 0) + 1;
    }
    ksort($r, SORT_STRING);
    return $r;
}

/**
 * Players 탭: 위 행에 "vs All / vs Zerg / vs Terran / vs Protoss", 아래 행에 "ID / Race / W / L".
 * @return array{error:?string, rows:array<string, array>} 선수 => {race, all:[W,L], Z, T, P} (값을 못 읽으면 null)
 */
function sheet_players_table(array $rows): array
{
    foreach (array_slice($rows, 0, 15, true) as $i => $row) {
        $cols = [];
        foreach ($row as $c => $v) {
            $k = cell_key($v);
            $map = ['vsall' => 'all', 'vszerg' => 'Z', 'vsterran' => 'T', 'vsprotoss' => 'P'];
            if (isset($map[$k])) {
                $cols[$map[$k]] = $c;
            }
        }
        if (count($cols) !== 4) {
            continue;
        }
        $sub = array_map('cell_key', $rows[$i + 1] ?? []);
        $idCol = array_search('id', $sub, true);
        $raceCol = array_search('race', $sub, true);
        foreach ($cols as $c) {
            if (($sub[$c] ?? '') !== 'w' || ($sub[$c + 1] ?? '') !== 'l') {
                return ['error' => 'Players 탭의 W/L 머리글 위치가 예상과 다름', 'rows' => []];
            }
        }
        if ($idCol === false || $raceCol === false) {
            return ['error' => 'Players 탭의 ID/Race 머리글을 찾을 수 없음', 'rows' => []];
        }
        $out = [];
        foreach (array_slice($rows, $i + 2) as $r) {
            $pid = name_norm($r[$idCol] ?? '');
            if ($pid === null) {
                continue;
            }
            $rec = ['race' => strtoupper(cell_str($r[$raceCol] ?? ''))];
            foreach ($cols as $k => $c) {
                $w = int_norm($r[$c] ?? null);
                $l = int_norm($r[$c + 1] ?? null);
                $rec[$k] = $w === null || $l === null ? null : [$w, $l];
            }
            if (isset($out[$pid])) {
                return ['error' => "Players 탭에 같은 선수가 두 번 있음: $pid", 'rows' => []];
            }
            $out[$pid] = $rec;
        }
        return ['error' => $out ? null : 'Players 탭에 선수가 없음', 'rows' => $out];
    }
    return ['error' => 'Players 탭의 머리글(vs All, vs Zerg, vs Terran, vs Protoss)을 찾을 수 없음', 'rows' => []];
}

/**
 * 상대전적조회NEW 탭: 출전 날짜, 요일, 출전 선수, 종족, 상대 선수, 종족, 승(세트), 패(세트) …
 * 선수별 묶음 머리행("▶ 김명운 (Z종족)")처럼 날짜가 아닌 행은 건너뛴다.
 * @return array{error:?string, rows:array<string, list<array>>} 선수 => [[날짜, 상대, 세트 승, 세트 패, 내 종족, 상대 종족], …]
 */
function sheet_match_list_table(array $rows): array
{
    $h = table_header_row($rows, [0 => '출전 날짜', 2 => '출전 선수', 4 => '상대 선수', 6 => '승(세트)', 7 => '패(세트)']);
    if ($h === null) {
        return ['error' => '상대전적조회NEW 탭의 머리글을 찾을 수 없음', 'rows' => []];
    }
    $out = [];
    foreach ($rows as $i => $r) {
        if ($i <= $h) {
            continue;
        }
        $date = date_norm($r[0] ?? null, true);
        if ($date === null) {
            continue;
        }
        $p = name_norm($r[2] ?? '');
        $o = name_norm($r[4] ?? '');
        $w = int_norm($r[6] ?? null);
        $l = int_norm($r[7] ?? null);
        if ($p === null || $o === null || $w === null || $l === null) {
            return ['error' => '상대전적조회NEW ' . ($i + 1) . '행을 읽을 수 없음', 'rows' => []];
        }
        $out[$p][] = [$date, $o, $w, $l, strtoupper(cell_str($r[3] ?? '')), strtoupper(cell_str($r[5] ?? ''))];
    }
    return ['error' => $out ? null : '상대전적조회NEW 탭에 기록이 없음', 'rows' => $out];
}

/** 두 끝장전 목록 비교 (순서 무관). 같으면 null, 다르면 {sheet, calc} 첫 차이 설명 */
function sheet_list_diff(array $calc, array $sheet): ?array
{
    $fmt = static fn(array $x) => sprintf('%s %s %d:%d (%s/%s)', ...$x);
    $a = array_map($fmt, $calc);
    $b = array_map($fmt, $sheet);
    sort($a, SORT_STRING);
    sort($b, SORT_STRING);
    if ($a === $b) {
        return null;
    }
    $onlyCalc = array_values(array_diff($a, $b));
    $onlySheet = array_values(array_diff($b, $a));
    return ['sheet' => count($b) . '경기' . ($onlySheet ? ' (예: ' . $onlySheet[0] . ')' : ''),
        'calc' => count($a) . '경기' . ($onlyCalc ? ' (예: ' . $onlyCalc[0] . ')' : '')];
}

/**
 * 예측 탭: 왼쪽 기록(날짜, 선수1, 선수2, 세트, 맵, 갯수, 중계진, 선택, 성공/실패), 오른쪽 순위표(순위, 이름, 전체, 승 …).
 * 성공/실패가 비어 있으면 아직 결과가 없는 예측으로 보고 건너뛴다. 다른 값이 있으면 예측 전체를 쓰지 않는다.
 * @return array{error:?string, records:list<array>, ranking:?array<string, array{0:int,1:int}>}
 */
function sheet_predictions_table(array $rows, bool $serialDates): array
{
    $h = table_header_row($rows, [0 => '날짜', 6 => '중계진', 7 => '선택', 8 => '성공/실패']);
    if ($h === null) {
        return ['error' => '예측 탭의 머리글(날짜 … 중계진, 선택, 성공/실패)을 찾을 수 없음', 'records' => [], 'ranking' => null];
    }
    $head = array_map('cell_key', $rows[$h]);
    $nameCol = $totalCol = $winCol = null;
    foreach ($head as $c => $k) {
        if ($c > 8 && $k === '이름') {
            $nameCol = $c;
            $totalCol = ($head[$c + 1] ?? '') === '전체' ? $c + 1 : null;
            $winCol = ($head[$c + 2] ?? '') === '승' ? $c + 2 : null;
            break;
        }
    }
    $records = [];
    $ranking = $nameCol !== null && $totalCol !== null && $winCol !== null ? [] : null;
    foreach ($rows as $i => $r) {
        if ($i <= $h) {
            continue;
        }
        if ($ranking !== null && ($rn = name_norm($r[$nameCol] ?? '')) !== null) {
            $t = int_norm($r[$totalCol] ?? null);
            $w = int_norm($r[$winCol] ?? null);
            if ($t === null || $w === null || isset($ranking[$rn])) {
                return ['error' => '예측 순위표 ' . ($i + 1) . '행을 읽을 수 없음', 'records' => [], 'ranking' => null];
            }
            $ranking[$rn] = [$t, $w];
        }
        $date = date_norm($r[0] ?? null, $serialDates);
        $who = cell_str($r[6] ?? '');
        $res = cell_key($r[8] ?? '');
        if ($date === null && $who === '' && $res === '') {
            continue;
        }
        if ($res === '') {
            continue; // 결과 입력 전
        }
        $pid = predictor_name($who);
        if ($date === null || $pid === null || !in_array($res, ['성공', '실패'], true)) {
            return ['error' => '예측 탭 ' . ($i + 1) . '행을 읽을 수 없음 (날짜·중계진·성공/실패)', 'records' => [], 'ranking' => null];
        }
        $records[] = ['date' => $date, 'predictor' => $pid, 'correct' => $res === '성공', 'row' => $i + 1];
    }
    return ['error' => $records ? null : '예측 기록이 없음', 'records' => $records, 'ranking' => $ranking];
}

/** "박상현 캐스터" → "박상현". 끝말이 알려진 직책이 아니면 전체를 이름으로 본다 (추측하지 않음) */
function predictor_name(string $s): ?string
{
    $n = name_norm($s);
    if ($n === null) {
        return null;
    }
    $parts = explode(' ', $n);
    if (count($parts) >= 2 && in_array(end($parts), PREDICTOR_ROLES, true)) {
        array_pop($parts);
        return implode(' ', $parts);
    }
    return $n;
}
