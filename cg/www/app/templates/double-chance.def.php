<?php
declare(strict_types=1);

/*
 * #7 더블 찬스 승률 — 두 선수 각각의 더블 찬스 승·패·승률 (레퍼런스 없음)
 * 시트: 승 = 더블 찬스 세트(Results H열 금액 > 0)에서 A열(Winner)인 횟수 + 상금 보정 탭 보정, 패 = 끝장전당 2회 − 승.
 *   선수별 통계 탭(더블 성공 횟수·시도)과 다르면 송출을 막는다 (sheet_data.php sheet_double_chance).
 * MOCK: 집계표(double_chance.json)의 값. 집계에 없는 선수는 값이 비어 수동 입력 전까지 송출할 수 없다.
 */
$side = static fn(string $s, string $who) => [
    "$s.name" => ['label' => "$who 이름", 'type' => 'text', 'max' => 12],
    "$s.wins" => ['label' => "$who 승", 'type' => 'int'],
    "$s.losses" => ['label' => "$who 패", 'type' => 'int'],
    "$s.rate" => ['label' => "$who 승률", 'type' => 'rate', 'derived' => ["$s.wins", "$s.losses"]],
];

return [
    'slug' => 'double-chance',
    'name' => '더블 찬스 승률',
    'short' => '더블찬스',
    'order' => 7,
    'params' => [
        ['key' => 'a.player', 'label' => 'A 선수', 'type' => 'player'],
        ['key' => 'b.player', 'label' => 'B 선수', 'type' => 'player'],
    ],
    'check' => 'check_two_players',
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + $side('a', 'A') + $side('b', 'B'),
    'auto' => static function (array $p, array $ds): array {
        $a = pname($ds['players'], $p['a']['player']);
        $b = pname($ds['players'], $p['b']['player']);
        $auto = ['title' => "$a vs $b 더블 찬스 승률", 'a.name' => $a, 'b.name' => $b];
        foreach (['a', 'b'] as $s) {
            $rec = $ds['double_chance'][$p[$s]['player']] ?? null;
            $auto["$s.wins"] = $rec['wins'] ?? null;
            $auto["$s.losses"] = $rec['losses'] ?? null;
        }
        return $auto;
    },
    'verify' => static fn(array $p, array $ds): array => array_merge(
        verify_double($ds, $p['a']['player'], ['a.wins', 'a.losses']),
        verify_double($ds, $p['b']['player'], ['b.wins', 'b.losses'])),
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s vs %s',
        pname($ctx['players'], $p['a']['player']), pname($ctx['players'], $p['b']['player'])),
    'present' => static function (array $f): array {
        $cols = [];
        foreach (['a', 'b'] as $s) {
            $cols[] = ['name' => (string)$f["$s.name"],
                'record' => text_record_hid($f, "$s.wins", "$s.losses"),
                'rate' => hid($f, "$s.rate") ? '' : text_rate_paren($f["$s.rate"])];
        }
        return ['title' => (string)$f['title'], 'cols' => $cols];
    },
];
