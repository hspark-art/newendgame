<?php
declare(strict_types=1);

// #2 최근 특정 종족전 — 대상 선수(항상 왼쪽)의 최근 끝장전을 오래된 순으로 (레퍼런스 02)
return [
    'slug' => 'recent-race',
    'name' => '최근 종족전 전적',
    'short' => '최근전적',
    'order' => 2,
    'params' => [
        ['key' => 'player', 'label' => '선수', 'type' => 'player'],
        ['key' => 'vs', 'label' => '상대 종족', 'type' => 'race'],
        ['key' => 'count', 'label' => '경기 수', 'type' => 'int', 'min' => 1, 'max' => 5, 'default' => 5],
    ],
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + row_fields(5, [
        'date' => ['label' => '날짜', 'type' => 'date'],
        'a' => ['label' => '선수', 'type' => 'text', 'max' => 12],
        'sa' => ['label' => '선수 세트', 'type' => 'int', 'max' => 99],
        'sb' => ['label' => '상대 세트', 'type' => 'int', 'max' => 99],
        'b' => ['label' => '상대', 'type' => 'text', 'max' => 12],
    ]),
    'auto' => static function (array $p, array $ds): array {
        $auto = ['title' => sprintf('%s 최근 끝장전 %s전 전적', pname($ds['players'], $p['player']), RACE_NAMES[$p['vs']])];
        foreach (array_values(stats_recent_matches($ds['matches'], $p['player'], $p['vs'], $p['count'])) as $i => $m) {
            $n = $i + 1;
            $auto += ["r$n.date" => $m['date'], "r$n.a" => pname($ds['players'], $m['me']), "r$n.sa" => $m['my'],
                "r$n.sb" => $m['their'], "r$n.b" => pname($ds['players'], $m['opp'])];
        }
        return $auto;
    },
    'verify' => static fn(array $p, array $ds): array => verify_matches($ds, $p['player'], row_keys(['date', 'a', 'sa', 'sb', 'b'])),
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s vs %s · 최근 %d경기',
        pname($ctx['players'], $p['player']), $p['vs'], $p['count']),
    'present' => static function (array $f): array {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $r = row_values($f, $i, ['date', 'a', 'sa', 'sb', 'b']);
            if ($r !== null) {
                $rows[] = $r + ['a_win' => $r['sa'] !== null && $r['sb'] !== null && $r['sa'] > $r['sb'],
                    'b_win' => $r['sa'] !== null && $r['sb'] !== null && $r['sb'] > $r['sa']];
            }
        }
        return ['title' => (string)$f['title'], 'rows' => $rows];
    },
];
