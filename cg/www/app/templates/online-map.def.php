<?php
declare(strict_types=1);

/*
 * #21 맵별 온라인 전적 (v0.11.0) — 대본의 맵 칸: 그 맵 온라인 맞대결 + 두 선수 각각의 그 맵 상대 종족 전적.
 * 맵은 대본 구역의 맵 목록에서 고른다 (밴 맵 제외). 화면은 #6과 같은 상자(online-h2h.view.php).
 */
$side = static fn(string $s, string $who) => [
    "$s.name" => ['label' => "$who 이름", 'type' => 'text', 'max' => 12],
    "$s.vs" => ['label' => "$who 상대 종족", 'type' => 'text', 'max' => 4],
    "$s.wins" => ['label' => "$who 맵 승", 'type' => 'int'],
    "$s.losses" => ['label' => "$who 맵 패", 'type' => 'int'],
    "$s.rate" => ['label' => "$who 맵 승률", 'type' => 'rate', 'derived' => ["$s.wins", "$s.losses"]],
];

return [
    'slug' => 'online-map',
    'name' => '맵별 온라인 전적',
    'short' => '온라맵',
    'order' => 21,
    'view' => 'online-h2h',
    'params' => [
        ['key' => 'a.player', 'label' => 'A 선수', 'type' => 'player'],
        ['key' => 'b.player', 'label' => 'B 선수', 'type' => 'player'],
        ['key' => 'map', 'label' => '맵 (대본)', 'type' => 'script_map'],
    ],
    'check' => static fn(array $p, array $ctx = []) => script_check_map($p, $ctx, true),
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40],
        'h.a' => ['label' => '맞대결 A 승', 'type' => 'int'],
        'h.b' => ['label' => '맞대결 B 승', 'type' => 'int']] + $side('a', 'A') + $side('b', 'B'),
    'auto' => static function (array $p, array $ds): array {
        $s = script_for($ds, $p['a']['player'], $p['b']['player']);
        $m = $s['map'][$p['map']] ?? null;
        $auto = ['title' => $p['map'] . ' · 온라인 맵 전적'];
        if (($m['h2h'] ?? null) !== null) {
            $auto += ['h.a' => $m['h2h'][0], 'h.b' => $m['h2h'][1]];
        }
        foreach (['a', 'b'] as $k) {
            $auto["$k.name"] = pname($ds['players'], $p[$k]['player']);
            $r = $m[$k] ?? null;
            if ($r !== null) {
                $auto += ["$k.vs" => $r['vs'], "$k.wins" => $r['w'], "$k.losses" => $r['l']];
            }
        }
        return $auto;
    },
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s vs %s · %s · %s',
        pname($ctx['players'], $p['a']['player']), pname($ctx['players'], $p['b']['player']), $p['map'],
        script_summary($ctx, $p['a']['player'], $p['b']['player'])),
    'present' => static function (array $f): array {
        $cols = [];
        foreach (['a', 'b'] as $s) {
            $cols[] = ['name' => (string)$f["$s.name"], 'vs' => hid($f, "$s.vs") ? '' : (string)$f["$s.vs"],
                'record' => text_record_hid($f, "$s.wins", "$s.losses"),
                'rate' => hid($f, "$s.rate") ? '' : text_rate_paren($f["$s.rate"])];
        }
        return ['title' => (string)$f['title'], 'cols' => $cols, 'h2h' => hid($f, 'h.a', 'h.b') ? '' : $f['h.a'] . ' : ' . $f['h.b']];
    },
];
