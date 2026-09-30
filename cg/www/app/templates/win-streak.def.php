<?php
declare(strict_types=1);

/*
 * #8 연승 순위 — 끝장전(경기) 연승. 세트 순서 기록이 없어 세트 연승은 계산할 수 없다.
 * 선수마다 가장 긴 연승 1개, 마지막 경기까지 이어지면 "진행 중". (레퍼런스 없음)
 */
return [
    'slug' => 'win-streak',
    'name' => '연승 순위',
    'short' => '연승',
    'order' => 8,
    'params' => [
        ['key' => 'race', 'label' => '종족', 'type' => 'race_any', 'default_value' => 'T'],
        ['key' => 'count', 'label' => '표시할 인원', 'type' => 'int', 'min' => 1, 'max' => 5, 'default' => 4],
    ],
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + row_fields(5, [
        'rank' => ['label' => '순위', 'type' => 'int', 'max' => 999],
        'name' => ['label' => '이름', 'type' => 'text', 'max' => 12],
        'nick' => ['label' => '닉네임', 'type' => 'text', 'max' => 16],
        'streak' => ['label' => '연승', 'type' => 'int', 'max' => 999],
        'start' => ['label' => '시작일', 'type' => 'date'],
        'end' => ['label' => '종료일', 'type' => 'text', 'max' => 12],
    ]),
    'auto' => static function (array $p, array $ds): array {
        $race = $p['race'] === '' ? null : $p['race'];
        $auto = ['title' => '끝장전 ' . ($race ? RACE_NAMES[$race] . ' ' : '') . '연승 순위'];
        foreach (stats_win_streaks($ds['matches'], $ds['players'], $race, $p['count']) as $i => $r) {
            $n = $i + 1;
            $auto += ["r$n.rank" => $r['rank'], "r$n.name" => pname($ds['players'], $r['player']),
                "r$n.nick" => $ds['players'][$r['player']]['nickname'] ?? null, "r$n.streak" => $r['streak'],
                "r$n.start" => $r['start'], "r$n.end" => $r['ongoing'] ? '진행 중' : $r['end']];
        }
        return $auto;
    },
    'verify' => static function (array $p, array $ds): array {
        $race = $p['race'] === '' ? null : $p['race'];
        $all = stats_win_streaks($ds['matches'], $ds['players'], $race, PHP_INT_MAX);
        $issues = verify_population($ds, 'matches', players_of_race($ds['players'], $race), row_keys(['rank'], $p['count']));
        foreach (array_slice($all, 0, $p['count']) as $i => $r) {
            $n = $i + 1;
            $issues = array_merge($issues, verify_matches($ds, $r['player'], ["r$n.streak", "r$n.start", "r$n.end"]));
        }
        return $issues;
    },
    'summary' => static fn(array $p, array $ctx): string => ($p['race'] === '' ? '전체 종족' : RACE_NAMES[$p['race']])
        . ' · ' . $p['count'] . '명',
    'present' => static function (array $f): array {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $r = row_values($f, $i, ['rank', 'name', 'nick', 'streak', 'start', 'end']);
            if ($r !== null && $r['name'] !== null) {
                $rows[] = ['rank' => $r['rank'] === null ? '' : english_ordinal($r['rank']), 'name' => $r['name'],
                    'nick' => (string)$r['nick'], 'streak' => ($r['streak'] ?? '-') . '연승',
                    'period' => trim(($r['start'] ?? '') . ' ~ ' . ($r['end'] ?? ''), ' ~'), 'ongoing' => $r['end'] === '진행 중'];
            }
        }
        return ['title' => (string)$f['title'], 'rows' => $rows];
    },
];
