<?php
declare(strict_types=1);

// #4 다승 순위 — 세트 승수 기준, 같은 승수는 공동 순위 (레퍼런스 04: "1st 박상현 soma 39W 14L 73.6%")
return [
    'slug' => 'win-ranking',
    'name' => '다승 순위',
    'short' => '다승',
    'order' => 4,
    'params' => [
        ['key' => 'race', 'label' => '종족', 'type' => 'race_any'],
        ['key' => 'count', 'label' => '표시할 인원', 'type' => 'int', 'min' => 1, 'max' => 5, 'default' => 4],
    ],
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + row_fields(5, [
        'rank' => ['label' => '순위', 'type' => 'int', 'max' => 999],
        'name' => ['label' => '이름', 'type' => 'text', 'max' => 12],
        'nick' => ['label' => '닉네임', 'type' => 'text', 'max' => 16],
        'wins' => ['label' => '승', 'type' => 'int'],
        'losses' => ['label' => '패', 'type' => 'int'],
        'rate' => ['label' => '승률', 'type' => 'rate', 'derived' => ['wins', 'losses']],
    ]),
    'auto' => static function (array $p, array $ds): array {
        $race = $p['race'] === '' ? null : $p['race'];
        $auto = ['title' => '중계진 스타 끝장전 ' . ($race ? RACE_NAMES[$race] . ' ' : '') . '다승 순위'];
        foreach (stats_win_ranking($ds['games'], $ds['players'], $race, $p['count']) as $i => $r) {
            $n = $i + 1;
            $auto += ["r$n.rank" => $r['rank'], "r$n.name" => pname($ds['players'], $r['player']),
                "r$n.nick" => $ds['players'][$r['player']]['nickname'] ?? null, "r$n.wins" => $r['wins'], "r$n.losses" => $r['losses']];
        }
        return $auto;
    },
    'verify' => static function (array $p, array $ds): array {
        $race = $p['race'] === '' ? null : $p['race'];
        $all = stats_win_ranking($ds['games'], $ds['players'], $race, PHP_INT_MAX);
        $issues = verify_population($ds, 'sets', players_of_race($ds['players'], $race), row_keys(['rank'], $p['count']));
        foreach (array_slice($all, 0, $p['count']) as $i => $r) {
            $n = $i + 1;
            $issues = array_merge($issues, verify_sets($ds, $r['player'], 'all', ["r$n.wins", "r$n.losses"]));
        }
        return $issues;
    },
    'summary' => static fn(array $p, array $ctx): string => ($p['race'] === '' ? '전체 종족' : RACE_NAMES[$p['race']])
        . ' · ' . $p['count'] . '명',
    'present' => static function (array $f): array {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $r = row_values($f, $i, ['rank', 'name', 'nick', 'wins', 'losses', 'rate']);
            if ($r !== null && $r['name'] !== null) {
                $rows[] = ['rank' => $r['rank'] === null ? '' : english_ordinal($r['rank']), 'name' => $r['name'],
                    'nick' => (string)$r['nick'], 'record' => sprintf('%sW %sL', $r['wins'] ?? '-', $r['losses'] ?? '-'),
                    'rate' => text_pct($r['rate'])];
            }
        }
        return ['title' => (string)$f['title'], 'rows' => $rows];
    },
];
