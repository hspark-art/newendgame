<?php
declare(strict_types=1);

// #3 끝장전 맞대결 — 요약은 끝장전(경기) 승수, 목록은 경기별 세트 스코어 (레퍼런스 03: "0 : 4", 각 4:5)
return [
    'slug' => 'head-to-head',
    'name' => '끝장전 맞대결',
    'short' => '맞대결',
    'order' => 3,
    'params' => [
        ['key' => 'a.player', 'label' => 'A 선수 (왼쪽)', 'type' => 'player'],
        ['key' => 'b.player', 'label' => 'B 선수 (오른쪽)', 'type' => 'player'],
        ['key' => 'count', 'label' => '표시할 경기 수', 'type' => 'int', 'min' => 1, 'max' => 5, 'default' => 4],
    ],
    'check' => 'check_two_players',
    'fields' => [
        'title' => ['label' => '제목', 'type' => 'text', 'max' => 40],
        'a.name' => ['label' => 'A 이름', 'type' => 'text', 'max' => 12],
        'b.name' => ['label' => 'B 이름', 'type' => 'text', 'max' => 12],
        'a.mw' => ['label' => 'A 끝장전 승', 'type' => 'int', 'max' => 999],
        'b.mw' => ['label' => 'B 끝장전 승', 'type' => 'int', 'max' => 999],
    ] + row_fields(5, [
        'date' => ['label' => '날짜', 'type' => 'date'],
        'sa' => ['label' => 'A 세트', 'type' => 'int', 'max' => 99],
        'sb' => ['label' => 'B 세트', 'type' => 'int', 'max' => 99],
    ]),
    'auto' => static function (array $p, array $ds): array {
        $a = pname($ds['players'], $p['a']['player']);
        $b = pname($ds['players'], $p['b']['player']);
        $h = stats_head_to_head($ds['matches'], $p['a']['player'], $p['b']['player'], $p['count']);
        // 이번 경기가 몇 번째 맞대결인지: 지난 맞대결 수 + 1
        $auto = ['title' => sprintf('%s vs %s 끝장전 %s 번째 맞대결', $a, $b, korean_ordinal($h['count'] + 1)),
            'a.name' => $a, 'b.name' => $b, 'a.mw' => $h['a_wins'], 'b.mw' => $h['b_wins']];
        foreach (array_values($h['rows']) as $i => $m) {
            $n = $i + 1;
            $auto += ["r$n.date" => $m['date'], "r$n.sa" => $m['my'], "r$n.sb" => $m['their']];
        }
        return $auto;
    },
    'verify' => static function (array $p, array $ds): array {
        // 제목("… 번째 맞대결")은 지난 대결 수로 정해지므로 함께 막는다
        $fields = array_merge(['title', 'a.mw', 'b.mw'], row_keys(['date', 'sa', 'sb']));
        return array_merge(verify_matches($ds, $p['a']['player'], $fields), verify_matches($ds, $p['b']['player'], $fields));
    },
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s vs %s · %d경기',
        pname($ctx['players'], $p['a']['player']), pname($ctx['players'], $p['b']['player']), $p['count']),
    'present' => static function (array $f): array {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $r = row_visible($f, $i, ['date', 'sa', 'sb']);
            if ($r !== null) {
                // 이긴 쪽 강조는 점수를 빼도 원래 점수로
                [$sa, $sb] = [raw_val($f, "r$i.sa"), raw_val($f, "r$i.sb")];
                $win = $sa !== null && $sb !== null;
                $rows[] = $r + ['a_win' => $win && $sa > $sb, 'b_win' => $win && $sb > $sa,
                    'score' => hid($f, "r$i.sa", "r$i.sb") ? '' : $r['sa'] . ' : ' . $r['sb']];
            }
        }
        return ['title' => (string)$f['title'], 'a' => (string)$f['a.name'], 'b' => (string)$f['b.name'],
            'summary' => hid($f, 'a.mw', 'b.mw') ? '' : $f['a.mw'] . ' : ' . $f['b.mw'], 'rows' => $rows];
    },
];
