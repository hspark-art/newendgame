<?php
declare(strict_types=1);

/**
 * 통계 엔진. 모두 순수 함수이며 DB·화면에 의존하지 않는다.
 */

/** 승률(0.1% 단위 정수, 반올림). 경기가 없거나 값이 없으면 null = "자료 없음" */
function stats_rate_tenths(?int $wins, ?int $losses): ?int
{
    if ($wins === null || $losses === null) {
        return null;
    }
    $games = $wins + $losses;
    if ($games <= 0) {
        return null;
    }
    return intdiv(2000 * $wins + $games, 2 * $games);
}

/**
 * 선수의 상대 종족 전적. 선수가 A측·B측 어느 쪽에 기록되어 있어도 센다.
 * NEEDS CONFIRMATION: CG의 "승·패"가 세트 합계인지 끝장전(매치) 승패인지 미확정.
 * 두 가지를 모두 돌려준다: wins/losses = 세트 합계, match_wins/match_losses = 끝장전 단위.
 *
 * @return array{wins:int, losses:int, match_wins:int, match_losses:int, matches:int}
 */
function stats_race_record(array $matches, string $playerId, string $vsRace): array
{
    $r = ['wins' => 0, 'losses' => 0, 'match_wins' => 0, 'match_losses' => 0, 'matches' => 0];
    foreach ($matches as $m) {
        if ($m['playerA'] === $playerId && $m['raceB'] === $vsRace) {
            [$mine, $theirs] = [$m['scoreA'], $m['scoreB']];
        } elseif ($m['playerB'] === $playerId && $m['raceA'] === $vsRace) {
            [$mine, $theirs] = [$m['scoreB'], $m['scoreA']];
        } else {
            continue;
        }
        $r['wins'] += $mine;
        $r['losses'] += $theirs;
        $r['matches']++;
        $mine > $theirs ? $r['match_wins']++ : $r['match_losses']++;
    }
    return $r;
}

/**
 * 세트 단위 상대 종족 전적: 세트마다 기록된 종족으로 센다 (한 경기 안에서 종족을 바꿔도 정확).
 * @return array{wins:int, losses:int}
 */
function stats_race_sets(array $games, string $pid, string $vsRace): array
{
    $r = ['wins' => 0, 'losses' => 0];
    foreach ($games as $g) {
        if ($g['winner'] === $pid && $g['lrace'] === $vsRace) {
            $r['wins']++;
        } elseif ($g['loser'] === $pid && $g['wrace'] === $vsRace) {
            $r['losses']++;
        }
    }
    return $r;
}

// ---------------------------------------------------------------- 공용

/** 날짜·id 순으로 정렬 (오래된 경기 먼저) */
function matches_sorted(array $matches): array
{
    usort($matches, static fn($x, $y) => [$x['date'], $x['id']] <=> [$y['date'], $y['id']]);
    return $matches;
}

/** 경기를 대상 선수 기준으로 바꾼다 (대상 선수가 항상 왼쪽). 대상 선수 경기가 아니면 null */
function match_for(array $m, string $pid): ?array
{
    if ($m['playerA'] === $pid) {
        return ['id' => $m['id'], 'date' => $m['date'], 'me' => $pid, 'opp' => $m['playerB'], 'my_race' => $m['raceA'],
            'opp_race' => $m['raceB'], 'my' => $m['scoreA'], 'their' => $m['scoreB'], 'bestOf' => $m['bestOf']];
    }
    if ($m['playerB'] === $pid) {
        return ['id' => $m['id'], 'date' => $m['date'], 'me' => $pid, 'opp' => $m['playerA'], 'my_race' => $m['raceB'],
            'opp_race' => $m['raceA'], 'my' => $m['scoreB'], 'their' => $m['scoreA'], 'bestOf' => $m['bestOf']];
    }
    return null;
}

/**
 * 공동 순위(1, 2, 2, 4 방식). $rows는 이미 정렬되어 있어야 한다.
 * @param callable $key 같은 순위로 볼 값
 */
function stats_rank(array $rows, callable $key): array
{
    $prev = null;
    foreach ($rows as $i => &$r) {
        $k = $key($r);
        $r['rank'] = ($i > 0 && $k === $prev) ? $rows[$i - 1]['rank'] : $i + 1;
        $prev = $k;
    }
    unset($r);
    return $rows;
}

/** 1st, 2nd, 3rd, 4th, 11th, 21st … */
function english_ordinal(int $n): string
{
    $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
    return $n . $suffix;
}

/** 첫, 두, 세, 네, 다섯 … 스무, 스물한 … (1~99). 그 밖은 숫자 */
function korean_ordinal(int $n): string
{
    if ($n === 1) {
        return '첫';
    }
    if ($n === 20) {
        return '스무';
    }
    $units = ['', '한', '두', '세', '네', '다섯', '여섯', '일곱', '여덟', '아홉'];
    $tens = ['', '열', '스물', '서른', '마흔', '쉰', '예순', '일흔', '여든', '아흔'];
    if ($n < 1 || $n > 99) {
        return (string)$n;
    }
    return $tens[intdiv($n, 10)] . $units[$n % 10];
}

// ---------------------------------------------------------------- 끝장전 기록 (세트 스코어가 있는 경기 단위)

/**
 * 최근 특정 종족전: 상대 종족이 vsRace인 최근 limit경기를 오래된 순으로.
 * @return list<array> match_for() 형식
 */
function stats_recent_matches(array $matches, string $pid, string $vsRace, int $limit): array
{
    $rows = [];
    foreach (matches_sorted($matches) as $m) {
        $r = match_for($m, $pid);
        if ($r !== null && $r['opp_race'] === $vsRace) {
            $rows[] = $r;
        }
    }
    return array_slice($rows, -$limit);
}

/**
 * 끝장전 맞대결. 요약은 끝장전(경기) 승수, 세트 합계도 함께 준다. 목록은 최근 limit경기를 오래된 순으로.
 * @return array{a_wins:int, b_wins:int, a_sets:int, b_sets:int, count:int, rows:list<array>}
 */
function stats_head_to_head(array $matches, string $a, string $b, int $limit): array
{
    $r = ['a_wins' => 0, 'b_wins' => 0, 'a_sets' => 0, 'b_sets' => 0, 'count' => 0, 'rows' => []];
    foreach (matches_sorted($matches) as $m) {
        $x = match_for($m, $a);
        if ($x === null || $x['opp'] !== $b) {
            continue;
        }
        $r['count']++;
        $x['my'] > $x['their'] ? $r['a_wins']++ : $r['b_wins']++;
        $r['a_sets'] += $x['my'];
        $r['b_sets'] += $x['their'];
        $r['rows'][] = $x;
    }
    $r['rows'] = array_slice($r['rows'], -$limit);
    return $r;
}

/**
 * 다승 순위 (끝장전 승패 기준 — 2026-10-01 사용자 결정, 레퍼런스 04 "박상현 39W 14L"과 일치).
 * race가 있으면 그 종족 선수만(선수 정보의 주 종족 — 정해지지 않은 선수는 제외). 같은 승수는 공동 순위.
 * @return list<array{player:string, wins:int, losses:int, rank:int}>
 */
function stats_win_ranking(array $matches, array $players, ?string $race, int $limit): array
{
    $t = [];
    foreach ($matches as $m) {
        $aWin = $m['scoreA'] > $m['scoreB'];
        foreach ([[$m['playerA'], $aWin ? 'wins' : 'losses'], [$m['playerB'], $aWin ? 'losses' : 'wins']] as [$p, $k]) {
            if ($race !== null && ($players[$p]['race'] ?? '') !== $race) {
                continue;
            }
            $t[$p] ??= ['player' => $p, 'wins' => 0, 'losses' => 0];
            $t[$p][$k]++;
        }
    }
    $rows = array_values($t);
    usort($rows, static fn($x, $y) => [$y['wins'], $x['losses'], $players[$x['player']]['name'] ?? '']
        <=> [$x['wins'], $y['losses'], $players[$y['player']]['name'] ?? '']);
    return array_slice(stats_rank($rows, static fn($r) => $r['wins']), 0, $limit);
}

/**
 * 연승 순위 (끝장전 경기 단위 — 세트 순서 기록이 없어 세트 연승은 계산할 수 없음).
 * 선수마다 가장 긴 연승 1개. end = 연승의 마지막 경기 날짜 (지금도 이어지는 연승이면 그 선수의 마지막 출전일).
 * @return list<array{player:string, streak:int, start:string, end:string, rank:int}>
 */
function stats_win_streaks(array $matches, array $players, ?string $race, int $limit): array
{
    $hist = [];
    foreach (matches_sorted($matches) as $m) {
        foreach ([$m['playerA'], $m['playerB']] as $p) {
            if ($race === null || ($players[$p]['race'] ?? '') === $race) {
                $hist[$p][] = match_for($m, $p);
            }
        }
    }
    $rows = [];
    foreach ($hist as $p => $list) {
        $p = (string)$p; // 숫자로만 된 이름도 문자열로
        $best = null;
        $cur = 0;
        $start = null;
        foreach ($list as $x) {
            if ($x['my'] > $x['their']) {
                $start = $cur === 0 ? $x['date'] : $start;
                $cur++;
                if ($best === null || $cur >= $best['streak']) {
                    $best = ['player' => $p, 'streak' => $cur, 'start' => $start, 'end' => $x['date']];
                }
            } else {
                $cur = 0;
            }
        }
        if ($best !== null) {
            $rows[] = $best;
        }
    }
    usort($rows, static fn($x, $y) => [$y['streak'], $y['end'], $x['player']] <=> [$x['streak'], $x['end'], $y['player']]);
    return array_slice(stats_rank($rows, static fn($r) => $r['streak']), 0, $limit);
}

/**
 * 풀세트 접전: 9전(5선승) 경기 중 5:4 승리·4:5 패배 수. 다른 bestOf 경기는 세지 않는다.
 * @return array{matches:int, fs_wins:int, fs_losses:int}
 */
function stats_full_set(array $matches, string $pid): array
{
    $r = ['matches' => 0, 'fs_wins' => 0, 'fs_losses' => 0];
    foreach ($matches as $m) {
        $x = match_for($m, $pid);
        if ($x === null || $x['bestOf'] !== 9) {
            continue;
        }
        $r['matches']++;
        if ($x['my'] === 5 && $x['their'] === 4) {
            $r['fs_wins']++;
        } elseif ($x['my'] === 4 && $x['their'] === 5) {
            $r['fs_losses']++;
        }
    }
    return $r;
}

// ---------------------------------------------------------------- 승자 예측

/** 예측 결과가 있는 연도 (최근 순). $predictions = [{date, predictor, correct}] */
function stats_prediction_years(array $predictions): array
{
    $years = [];
    foreach ($predictions as $p) {
        $years[substr($p['date'], 0, 4)] = true;
    }
    $years = array_map('strval', array_keys($years));
    rsort($years, SORT_STRING);
    return $years;
}

/**
 * 중계진 승자 예측 순위: 연도 안의 예측 결과(성공/실패). 순위는 적중률(0.1% 단위) → 적중 수.
 * 시트는 세트마다 예측 1건, MOCK은 끝장전마다 1건이다.
 * @return list<array{predictor:string, correct:int, wrong:int, rate:?int, rank:int}>
 */
function stats_prediction_ranking(array $predictions, string $year): array
{
    $t = [];
    foreach ($predictions as $p) {
        if (substr($p['date'], 0, 4) !== $year) {
            continue;
        }
        $t[$p['predictor']] ??= ['predictor' => $p['predictor'], 'correct' => 0, 'wrong' => 0];
        $p['correct'] ? $t[$p['predictor']]['correct']++ : $t[$p['predictor']]['wrong']++;
    }
    $rows = array_map(static fn($r) => $r + ['rate' => stats_rate_tenths($r['correct'], $r['wrong'])], array_values($t));
    usort($rows, static fn($x, $y) => [$y['rate'], $y['correct'], $x['predictor']] <=> [$x['rate'], $x['correct'], $y['predictor']]);
    return stats_rank($rows, static fn($r) => [$r['rate'], $r['correct']]);
}

// ---------------------------------------------------------------- 온라인 (게임 1판 = 1건)

/** @return array{wins:int, losses:int} 상대 종족별 온라인 전적 */
function stats_online_record(array $games, string $pid, string $vsRace): array
{
    $r = ['wins' => 0, 'losses' => 0];
    foreach ($games as $g) {
        $opp = $g['playerA'] === $pid ? 'B' : ($g['playerB'] === $pid ? 'A' : null);
        if ($opp !== null && $g['race' . $opp] === $vsRace) {
            $g['winner'] === $pid ? $r['wins']++ : $r['losses']++;
        }
    }
    return $r;
}

/** @return array{a_wins:int, b_wins:int} 두 선수의 온라인 맞대결 */
function stats_online_h2h(array $games, string $a, string $b): array
{
    $r = ['a_wins' => 0, 'b_wins' => 0];
    foreach ($games as $g) {
        if (($g['playerA'] === $a && $g['playerB'] === $b) || ($g['playerA'] === $b && $g['playerB'] === $a)) {
            $g['winner'] === $a ? $r['a_wins']++ : $r['b_wins']++;
        }
    }
    return $r;
}

// ---------------------------------------------------------------- 매치 프리뷰·맵 (v0.5)

/**
 * 끝장전 전체 전적: 매치 승패(끝장전 통계용 경기) + 세트 합계(모든 세트).
 * @return array{match_wins:int, match_losses:int, set_wins:int, set_losses:int}
 */
function stats_player_record(array $matches, array $games, string $pid): array
{
    $r = ['match_wins' => 0, 'match_losses' => 0, 'set_wins' => 0, 'set_losses' => 0];
    foreach ($matches as $m) {
        $x = match_for($m, $pid);
        if ($x !== null) {
            $x['my'] > $x['their'] ? $r['match_wins']++ : $r['match_losses']++;
        }
    }
    foreach ($games as $g) {
        if ($g['winner'] === $pid) {
            $r['set_wins']++;
        } elseif ($g['loser'] === $pid) {
            $r['set_losses']++;
        }
    }
    return $r;
}

/** 최근 limit경기 흐름 (오래된 순): 'W' / 'L' 문자열. 예: "LWWLW" */
function stats_recent_form(array $matches, string $pid, int $limit): string
{
    $form = '';
    foreach (matches_sorted($matches) as $m) {
        $x = match_for($m, $pid);
        if ($x !== null) {
            $form .= $x['my'] > $x['their'] ? 'W' : 'L';
        }
    }
    return substr($form, -$limit);
}

/**
 * 선수의 맵 세트 전적. vsRace가 있으면 그 종족 상대 세트만.
 * (2026-10-01 실제 시트로 확인: "MAP 선수별 전적" 탭 1,081행과 모두 일치)
 * @return array{wins:int, losses:int}
 */
function stats_map_sets(array $games, string $pid, string $map, ?string $vsRace = null): array
{
    $r = ['wins' => 0, 'losses' => 0];
    foreach ($games as $g) {
        if ($g['map'] !== $map) {
            continue;
        }
        if ($g['winner'] === $pid && ($vsRace === null || $g['lrace'] === $vsRace)) {
            $r['wins']++;
        } elseif ($g['loser'] === $pid && ($vsRace === null || $g['wrace'] === $vsRace)) {
            $r['losses']++;
        }
    }
    return $r;
}

/**
 * 맵 종족 상성: 저그 vs 프로토스(ZP), 테란 vs 저그(TZ), 프로토스 vs 테란(PT)의 세트 승수.
 * sets = 그 맵의 모든 세트(동족전 포함), first/last = 처음·마지막 사용일, days = 사용한 날 수.
 * (2026-10-01 실제 시트로 확인: "MAP 통계" 탭 83개 맵 모두 일치)
 * @return array{sets:int, mirror:int, first:?string, last:?string, days:int, ZP:array{0:int,1:int}, TZ:array{0:int,1:int}, PT:array{0:int,1:int}}
 */
function stats_map_matchup(array $games, string $map): array
{
    $r = ['sets' => 0, 'mirror' => 0, 'first' => null, 'last' => null, 'days' => 0, 'ZP' => [0, 0], 'TZ' => [0, 0], 'PT' => [0, 0]];
    $days = [];
    foreach ($games as $g) {
        if ($g['map'] !== $map) {
            continue;
        }
        $r['sets']++;
        $days[$g['date']] = true;
        $r['first'] = $r['first'] === null ? $g['date'] : min($r['first'], $g['date']);
        $r['last'] = $r['last'] === null ? $g['date'] : max($r['last'], $g['date']);
        if ($g['wrace'] === $g['lrace']) {
            $r['mirror']++;
            continue;
        }
        foreach (['ZP', 'TZ', 'PT'] as $k) {
            if (str_contains($k, $g['wrace']) && str_contains($k, $g['lrace'])) {
                $r[$k][$k[0] === $g['wrace'] ? 0 : 1]++;
            }
        }
    }
    $r['days'] = count($days);
    return $r;
}

/**
 * 맵 고르기 순서: 최근 끝장전 $recentMatches경기에서 쓴 맵을 먼저(그 경기들에서 쓴 세트 많은 순 → 마지막 사용일 최근 순),
 * 그다음 나머지 맵을 마지막 사용일 최근 순으로. 끝장전 1경기 = 같은 날 같은 두 선수의 세트 묶음 (sheet_matches와 같은 기준).
 * @return array<string, array{sets:int, recent:int, last:string}> 맵 => {전체 세트, 최근 경기 세트, 마지막 사용일}
 */
function stats_map_usage(array $games, int $recentMatches = 20): array
{
    $byMatch = [];
    $u = [];
    foreach ($games as $g) {
        if ($g['map'] === '') {
            continue;
        }
        $pair = [$g['winner'], $g['loser']];
        sort($pair, SORT_STRING);
        $byMatch[$g['date'] . '|' . implode('|', $pair)][] = $g['map'];
        $u[$g['map']] ??= ['sets' => 0, 'recent' => 0, 'last' => $g['date']];
        $u[$g['map']]['sets']++;
        $u[$g['map']]['last'] = max($u[$g['map']]['last'], $g['date']);
    }
    krsort($byMatch, SORT_STRING); // 날짜 최근 순 (키가 날짜로 시작)
    foreach (array_slice($byMatch, 0, $recentMatches, true) as $maps) {
        foreach ($maps as $m) {
            $u[$m]['recent']++;
        }
    }
    uksort($u, static fn($a, $b) => [$u[$b]['recent'] > 0, $u[$b]['recent'], $u[$b]['last'], $u[$b]['sets'], (string)$a]
        <=> [$u[$a]['recent'] > 0, $u[$a]['recent'], $u[$a]['last'], $u[$a]['sets'], (string)$b]);
    return $u;
}

/** 맵 목록: 사용 세트 수 많은 순 (같으면 이름순). @return array<string, int> 맵 => 세트 수 */
function stats_maps(array $games): array
{
    $n = [];
    foreach ($games as $g) {
        if ($g['map'] !== '') {
            $n[$g['map']] = ($n[$g['map']] ?? 0) + 1;
        }
    }
    uksort($n, static fn($a, $b) => [$n[$b], (string)$a] <=> [$n[$a], (string)$b]);
    return $n;
}

/**
 * 중계진 미션 성공 지수: 예측마다 걸린 갯수를 성공이면 더하고 실패면 뺀 합. 수익률 = 지수 ÷ 건 갯수 합계.
 * (2026-10-01 실제 시트로 확인: 예측 탭 "지수·수익률" 열과 3명 모두 일치)
 * 순위는 지수 순 (같은 지수는 공동 순위, 표시 순서만 적중 수 많은 쪽 먼저). 갯수가 없는 기록은 쓰지 않는다 (sheet_dataset이 미리 막음).
 * @return list<array{predictor:string, index:int, staked:int, roi:?int, correct:int, wrong:int, rank:int}> roi = 0.1% 단위
 */
function stats_mission_ranking(array $predictions, string $year): array
{
    $t = [];
    foreach ($predictions as $p) {
        if (substr($p['date'], 0, 4) !== $year || !isset($p['amount'])) {
            continue;
        }
        $t[$p['predictor']] ??= ['predictor' => $p['predictor'], 'index' => 0, 'staked' => 0, 'correct' => 0, 'wrong' => 0];
        $row = &$t[$p['predictor']];
        $row['index'] += $p['correct'] ? $p['amount'] : -$p['amount'];
        $row['staked'] += $p['amount'];
        $p['correct'] ? $row['correct']++ : $row['wrong']++;
        unset($row);
    }
    $rows = array_map(static function ($r) {
        // 반올림(0에서 먼 쪽): -1.55% → -1.6%
        $r['roi'] = $r['staked'] > 0 ? (int)round(1000 * $r['index'] / $r['staked']) : null;
        return $r;
    }, array_values($t));
    usort($rows, static fn($x, $y) => [$y['index'], $y['correct'], $x['predictor']] <=> [$x['index'], $x['correct'], $y['predictor']]);
    return stats_rank($rows, static fn($r) => $r['index']);
}

/** 매치 기록: 시트가 첫 끝장전부터 빠짐없이 기록됐는지 (사용자 확인 전 false — 시트 첫 기록까지 이어지는 연승은 '확인 필요') */
const RECORDS_FULL_HISTORY = false;
/** 매치 기록 종류 (키 → 운영자용 이름). a·b = 선수, h = 두 선수 맞대결 */
const MATCH_RECORD_KINDS = [
    'a.win_streak' => 'A 매치 연승', 'a.loss_streak' => 'A 매치 연패', 'a.gap' => 'A 출전 간격',
    'b.win_streak' => 'B 매치 연승', 'b.loss_streak' => 'B 매치 연패', 'b.gap' => 'B 출전 간격',
    'h.gap' => '맞대결 간격', 'h.streak' => '맞대결 연승',
];

/** 두 날짜(Y-m-d)의 달력 날짜 차이 (한국 시간 — bootstrap의 Asia/Seoul) */
function stats_days(string $from, string $to): int
{
    return (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days;
}

/** "1,990일 (5년 5개월 12일)" */
function stats_days_text(string $from, string $to): string
{
    $d = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to));
    $ymd = trim(($d->y ? "{$d->y}년 " : '') . ($d->m ? "{$d->m}개월 " : '') . ($d->d ? "{$d->d}일" : ''));
    return number_format($d->days) . '일' . ($d->y || $d->m ? " ($ymd)" : '');
}

/**
 * 매치 기록 후보 (매치 시작 전 확인용). 경기일($date) 이전에 확정된 끝장전만 쓴다.
 * - 한 매치 = 같은 날 같은 두 선수의 세트 묶음 (sheet_matches). 세트·중복 입력이 여러 번 출전으로 세어지지 않는다.
 * - 이상 경기(확인 필요)는 기록을 확정하지 않는다(status hold). 운영자가 '통계 제외'를 확정한 경기는 끝장전이 아니므로 보지 않는다.
 * - 경기일 당일·이후에 입력된 세트·경기는 계산에서 빼고 notes로 알린다 (진행 중이거나 입력 중).
 * - 시트 첫 기록까지 이어지는 연승은 그 이전 기록을 알 수 없어 hold (RECORDS_FULL_HISTORY가 false일 때).
 * @return array{date:string, from:?string, to:?string, notes:list<string>, items:array<string, array>}
 *   item = {key, kind, name, value:?int, unit, desc, text, recommended:bool, status: ok|hold|none, reason, basis:list<string>}
 */
function stats_match_records(array $ds, string $a, string $b, string $date): array
{
    $players = $ds['players'] ?? [];
    $nm = static fn(string $p) => (string)($players[$p]['name'] ?? $p);
    $all = $ds['matches_all'] ?? $ds['matches'] ?? [];
    $dates = array_column($all, 'date');
    $out = ['date' => $date, 'from' => $dates ? min($dates) : null, 'to' => $dates ? max($dates) : null, 'notes' => [], 'items' => []];

    // 경기일 이전 · 당일 · 이후 (통계 제외 확정 경기는 끝장전이 아니므로 뺀다)
    $before = $sameDay = $after = [];
    foreach (matches_sorted($all) as $m) {
        if (!empty($m['excluded']) || !in_array($a, [$m['playerA'], $m['playerB']], true) && !in_array($b, [$m['playerA'], $m['playerB']], true)) {
            continue;
        }
        if ($m['date'] < $date) {
            $before[] = $m;
        } elseif ($m['date'] === $date) {
            $sameDay[] = $m;
        } else {
            $after[] = $m;
        }
    }
    foreach ($sameDay as $m) {
        $out['notes'][] = sprintf('경기일 %s에 이미 입력된 %s vs %s 세트 %d개(%d:%d)는 진행 중이거나 입력 중일 수 있어 계산에서 뺐습니다.',
            $date, $nm($m['playerA']), $nm($m['playerB']), $m['sets'], $m['scoreA'], $m['scoreB']);
    }
    if ($after) {
        $out['notes'][] = sprintf('경기일 이후 경기 %d개(%s~)는 계산에서 뺐습니다.', count($after), $after[0]['date']);
    }

    $uncertain = static fn(array $m) => ($m['anomaly'] ?? null) !== null;
    $line = static function (array $m, string $p) use ($nm): string {
        $x = match_for($m, $p);
        return sprintf('%s vs %s %d:%d %s%s', $m['date'], $nm($x['opp']), $x['my'], $x['their'], $x['my'] > $x['their'] ? '승' : '패',
            ($m['anomaly'] ?? null) !== null ? ' (이상 경기: ' . $m['anomaly'] . ')' : '');
    };
    $item = static fn(string $key, string $name, ?int $value, string $unit, string $desc, string $text, bool $rec, string $status,
        string $reason, array $basis) => ['key' => $key, 'kind' => explode('.', $key)[1], 'name' => $name, 'value' => $value,
        'unit' => $unit, 'desc' => $desc, 'text' => $text, 'recommended' => $rec && $status === 'ok', 'status' => $status,
        'reason' => $reason, 'basis' => $basis];
    $more = static fn(array $lines, int $n = 8) => count($lines) > $n
        ? array_merge(array_slice($lines, 0, $n), ['… 외 ' . (count($lines) - $n) . '경기']) : $lines;

    /*
     * 연승·연패: 가장 최근 경기부터 거꾸로, 같은 결과가 이어지는 수. 반대 결과(확정 경기)에서 끊긴다.
     * $win(m) = 대상이 이긴 경기인지. 반환 [방향(true=연승), 수, 상태, 사유, 근거]
     */
    $streak = static function (array $list, callable $win, string $who, callable $show) use ($uncertain, $more): array {
        if (!$list) {
            return [null, 0, 'none', '', []];
        }
        $rev = array_reverse($list);
        $first = $rev[0];
        if ($uncertain($first)) {
            return [null, 0, 'hold', "가장 최근 경기({$first['date']})가 이상 경기라 연승·연패를 확정할 수 없습니다.", [$show($first)]];
        }
        $dir = $win($first);
        $n = 0;
        $basis = [];
        foreach ($rev as $m) {
            if ($uncertain($m)) {
                return [$dir, $n, 'hold', "기록 사이에 이상 경기({$m['date']})가 있어 확정할 수 없습니다.", $more(array_merge($basis, [$show($m)]))];
            }
            if ($win($m) !== $dir) {
                return [$dir, $n, 'ok', '', $more(array_merge($basis, ['끊긴 경기: ' . $show($m)]))];
            }
            $n++;
            $basis[] = $show($m);
        }
        if (RECORDS_FULL_HISTORY || $n < 2) { // 1경기뿐이면 연승·연패 후보가 아니다
            return [$dir, $n, 'ok', '', $more($basis)];
        }
        return [$dir, $n, 'hold', "$who 시트 첫 기록({$list[0]['date']})부터 이어지는 기록이라 시트 이전 기록이 있으면 달라집니다.", $more($basis)];
    };

    foreach (['a' => $a, 'b' => $b] as $s => $p) {
        $mine = array_values(array_filter($before, static fn($m) => in_array($p, [$m['playerA'], $m['playerB']], true)));
        $show = static fn(array $m) => $line($m, $p);
        // 매치 연승 / 연패
        [$dir, $n, $st, $why, $basis] = $streak($mine, static fn($m) => match_for($m, $p)['my'] > match_for($m, $p)['their'], $nm($p), $show);
        foreach (['win_streak' => '연승', 'loss_streak' => '연패'] as $kind => $unit) {
            $want = $kind === 'win_streak';
            $label = "{$nm($p)} 매치 $unit";
            if ($dir === null) { // 출전 기록 없음(none) 또는 가장 최근 경기가 이상 경기(hold)
                $res = [null, $st, $st === 'hold' ? $why : '시트 기록에 이전 출전이 없습니다.'];
            } elseif ($dir !== $want) {
                $res = [null, 'none', '현재 ' . ($want ? '연승' : '연패') . ' 중이 아닙니다 (최근 경기 ' . ($dir ? '승' : '패') . ').'];
            } elseif ($st === 'hold') {
                $res = [$n, 'hold', $why];
            } elseif ($n >= 2) {
                $res = [$n, 'ok', ''];
            } else {
                $res = [null, 'none', "현재 1$unit — 2$unit 이상이 아닙니다."];
            }
            [$v, $vst, $vwhy] = $res;
            $out['items']["$s.$kind"] = $item("$s.$kind", $nm($p), $v, $unit, '현재 끝장전 매치', $v === null ? $label : $label . ' ' . $v . $unit,
                $want && $v !== null && $v >= 3, $vst, $vwhy, $basis ?: ($mine ? [$show(end($mine))] : []));
        }
        // 출전 간격: 경기일 - 마지막 출전일
        $last = $mine ? end($mine) : null;
        if ($last === null) {
            $out['items']["$s.gap"] = $item("$s.gap", $nm($p), null, '일', '만에 끝장전 출전', "{$nm($p)} 출전 간격", false, 'none',
                "시트 기록({$out['from']}~)에 이전 출전이 없습니다.", []);
        } else {
            $days = stats_days($last['date'], $date);
            $out['items']["$s.gap"] = $item("$s.gap", $nm($p), $days, '일', '만에 끝장전 출전', "{$nm($p)} " . number_format($days) . '일 만에 출전',
                $days >= 365, $uncertain($last) ? 'hold' : 'ok', $uncertain($last) ? "마지막 출전 경기({$last['date']})가 이상 경기라 확정할 수 없습니다." : '',
                ['마지막 출전: ' . $show($last), "경기일 $date → " . stats_days_text($last['date'], $date)]);
        }
    }

    // 두 선수 맞대결
    $meet = array_values(array_filter($before, static fn($m) => in_array($a, [$m['playerA'], $m['playerB']], true)
        && in_array($b, [$m['playerA'], $m['playerB']], true)));
    $pair = $nm($a) . ' vs ' . $nm($b);
    $showA = static fn(array $m) => $line($m, $a);
    if (!$meet) {
        $none = "시트 기록({$out['from']}~)에 두 선수의 맞대결이 없습니다.";
        $out['items']['h.gap'] = $item('h.gap', $pair, null, '일', '만에 펼쳐지는 맞대결', "$pair 맞대결 간격", false, 'none', $none, []);
        $out['items']['h.streak'] = $item('h.streak', $pair, null, '연승', '맞대결', "$pair 맞대결 연승", false, 'none', $none, []);
        return $out;
    }
    $last = end($meet);
    $days = stats_days($last['date'], $date);
    $out['items']['h.gap'] = $item('h.gap', $pair, $days, '일', '만에 펼쳐지는 맞대결', "$pair " . number_format($days) . '일 만의 맞대결',
        $days >= 365, $uncertain($last) ? 'hold' : 'ok', $uncertain($last) ? "마지막 맞대결({$last['date']})이 이상 경기라 확정할 수 없습니다." : '',
        ['마지막 맞대결: ' . $showA($last), "경기일 $date → " . stats_days_text($last['date'], $date)]);
    // 맞대결 연승: 최근 맞대결부터 같은 선수가 이긴 수 (A 기준으로 계산한 뒤 이긴 쪽 이름으로)
    [$dir, $n, $st, $why, $basis] = $streak($meet, static fn($m) => match_for($m, $a)['my'] > match_for($m, $a)['their'], '두 선수의', $showA);
    $holder = $dir === false ? $b : $a;
    $opp = $holder === $a ? $b : $a;
    if ($dir === null) { // 가장 최근 맞대결이 이상 경기
        $out['items']['h.streak'] = $item('h.streak', $pair, null, '연승', '맞대결', "$pair 맞대결 연승", false, 'hold', $why, $basis);
    } elseif ($st === 'hold' || $n >= 2) {
        $out['items']['h.streak'] = $item('h.streak', $nm($holder), $n, '연승', $nm($opp) . ' 상대 맞대결',
            "{$nm($holder)} {$nm($opp)} 상대 맞대결 {$n}연승", $n >= 3, $st, $why, $basis);
    } else {
        $out['items']['h.streak'] = $item('h.streak', $pair, null, '연승', '맞대결', "$pair 맞대결 연승", false, 'none',
            '맞대결 2연승 이상이 아닙니다 (최근 맞대결 ' . $nm($holder) . ' 승).', $basis);
    }
    return $out;
}
