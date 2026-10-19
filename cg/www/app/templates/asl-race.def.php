<?php
declare(strict_types=1);

/*
 * #19 ASL 상대 종족 승률 (C안) — 두 선수의 ASL 상대 종족별 세트 전적 (상대 종족 = 그 세트의 상대 종족 칸)
 * 원본: ASL 시트 '보기용' 탭. ASL을 쓸 수 없거나 ASL 기록에 없는 선수는 수동 입력 전까지 막는다.
 * 화면은 상대 종족 승률(race-win-rate.view.php)을 그대로 쓴다.
 */
$side = static fn(string $s, string $who) => [
    "$s.name" => ['label' => "$who 이름", 'type' => 'text', 'max' => 12],
    "$s.wins" => ['label' => "$who 승", 'type' => 'int'],
    "$s.losses" => ['label' => "$who 패", 'type' => 'int'],
    "$s.rate" => ['label' => "$who 승률", 'type' => 'rate', 'derived' => ["$s.wins", "$s.losses"]],
];

return [
    'slug' => 'asl-race',
    'name' => 'ASL 상대 종족 승률',
    'short' => 'ASL종족',
    'order' => 19,
    'params' => [
        ['key' => 'a.player', 'label' => 'A 선수', 'type' => 'player'],
        ['key' => 'a.vs', 'label' => 'A 상대 종족', 'type' => 'race', 'auto_from' => 'b.player'],
        ['key' => 'b.player', 'label' => 'B 선수', 'type' => 'player'],
        ['key' => 'b.vs', 'label' => 'B 상대 종족', 'type' => 'race', 'auto_from' => 'a.player'],
    ],
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + $side('a', 'A') + $side('b', 'B'),
    'auto' => static function (array $p, array $ds): array {
        $auto = ['title' => 'ASL 상대 종족 승률'];
        foreach (['a', 'b'] as $s) {
            $auto["$s.name"] = pname($ds['players'], $p[$s]['player']);
            if ($ds['asl']['available'] ?? false) { // ASL 기록에 없는 선수는 0승 0패 → verify에서 막는다
                $rec = stats_asl_race($ds['asl'], $p[$s]['player'], $p[$s]['vs']);
                $auto["$s.wins"] = $rec['wins'];
                $auto["$s.losses"] = $rec['losses'];
            }
        }
        return $auto;
    },
    'verify' => static fn(array $p, array $ds): array => array_merge(
        verify_asl($ds, $p['a']['player'], ['a.wins', 'a.losses']),
        verify_asl($ds, $p['b']['player'], ['b.wins', 'b.losses'])),
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s vs %s / %s vs %s · ASL',
        pname($ctx['players'], $p['a']['player']), $p['a']['vs'], pname($ctx['players'], $p['b']['player']), $p['b']['vs']),
    'present' => static function (array $f, array $p): array {
        $cols = [];
        foreach (['a', 'b'] as $s) {
            $cols[] = ['name' => (string)$f["$s.name"], 'vs' => $p[$s]['vs'],
                'record' => text_record_hid($f, "$s.wins", "$s.losses"),
                'rate' => hid($f, "$s.rate") ? '' : text_rate_paren($f["$s.rate"])];
        }
        return ['title' => (string)$f['title'], 'cols' => $cols];
    },
];
