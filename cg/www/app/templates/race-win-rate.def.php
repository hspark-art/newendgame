<?php
declare(strict_types=1);

// #1 상대 종족 승률 — 두 선수의 상대 종족별 세트 전적 (레퍼런스 01)
$side = static fn(string $s, string $who) => [
    "$s.name" => ['label' => "$who 이름", 'type' => 'text', 'max' => 12],
    "$s.wins" => ['label' => "$who 승", 'type' => 'int'],
    "$s.losses" => ['label' => "$who 패", 'type' => 'int'],
    "$s.rate" => ['label' => "$who 승률", 'type' => 'rate', 'derived' => ["$s.wins", "$s.losses"]],
];

return [
    'slug' => 'race-win-rate',
    'name' => '상대 종족 승률',
    'short' => '종족승률',
    'order' => 1,
    'params' => [
        ['key' => 'a.player', 'label' => 'A 선수', 'type' => 'player'],
        ['key' => 'a.vs', 'label' => 'A 상대 종족', 'type' => 'race', 'auto_from' => 'b.player'],
        ['key' => 'b.player', 'label' => 'B 선수', 'type' => 'player'],
        ['key' => 'b.vs', 'label' => 'B 상대 종족', 'type' => 'race', 'auto_from' => 'a.player'],
    ],
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + $side('a', 'A') + $side('b', 'B'),
    'auto' => static function (array $p, array $ds): array {
        $auto = ['title' => '중계진 스타 끝장전 상대 종족 승률'];
        foreach (['a', 'b'] as $s) {
            $rec = stats_race_sets($ds['games'], $p[$s]['player'], $p[$s]['vs']); // 세트 기준
            $auto["$s.name"] = pname($ds['players'], $p[$s]['player']);
            $auto["$s.wins"] = $rec['wins'];
            $auto["$s.losses"] = $rec['losses'];
        }
        return $auto;
    },
    'verify' => static fn(array $p, array $ds): array => array_merge(
        verify_sets($ds, $p['a']['player'], $p['a']['vs'], ['a.wins', 'a.losses']),
        verify_sets($ds, $p['b']['player'], $p['b']['vs'], ['b.wins', 'b.losses'])),
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s vs %s / %s vs %s',
        pname($ctx['players'], $p['a']['player']), $p['a']['vs'], pname($ctx['players'], $p['b']['player']), $p['b']['vs']),
    'present' => static function (array $f, array $p): array {
        $cols = [];
        foreach (['a', 'b'] as $s) {
            $cols[] = ['name' => (string)$f["$s.name"], 'vs' => $p[$s]['vs'],
                'record' => text_record($f["$s.wins"], $f["$s.losses"]), 'rate' => text_rate_paren($f["$s.rate"])];
        }
        return ['title' => (string)$f['title'], 'cols' => $cols];
    },
];
