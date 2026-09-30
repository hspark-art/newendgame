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
 * 다승 순위 (세트 기준). race가 있으면 그 종족 선수만(선수 정보의 주 종족 — 정해지지 않은 선수는 제외).
 * @return list<array{player:string, wins:int, losses:int, rank:int}>
 */
function stats_win_ranking(array $games, array $players, ?string $race, int $limit): array
{
    $t = [];
    foreach ($games as $g) {
        foreach ([[$g['winner'], 'wins'], [$g['loser'], 'losses']] as [$p, $k]) {
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
 * 선수마다 가장 긴 연승 1개. 마지막 경기까지 이어지면 진행 중.
 * @return list<array{player:string, streak:int, start:string, end:string, ongoing:bool, rank:int}>
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
        foreach ($list as $i => $x) {
            if ($x['my'] > $x['their']) {
                $start = $cur === 0 ? $x['date'] : $start;
                $cur++;
                if ($best === null || $cur >= $best['streak']) {
                    $best = ['player' => $p, 'streak' => $cur, 'start' => $start, 'end' => $x['date'],
                        'ongoing' => $i === count($list) - 1];
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
