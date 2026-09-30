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
