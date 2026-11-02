<?php
declare(strict_types=1);

/*
 * #20 온라인 상대 종족 전적 — 두 선수 각각의 최근 한 달 온라인 상대 종족 전적 (게임 1판 = 1건).
 * v0.11.0: 값은 대본(Google 문서)의 '최근 한 달 상대 종족 전적' 줄. 상대 종족도 대본 표기 (a.vs·b.vs 필드).
 * 예전 페이지의 a.vs·b.vs 파라미터는 정의에 없으므로 읽지 않는다. 화면은 #6과 같은 상자(online-h2h.view.php).
 */
$side = static fn(string $s, string $who) => [
    "$s.name" => ['label' => "$who 이름", 'type' => 'text', 'max' => 12],
    "$s.vs" => ['label' => "$who 상대 종족", 'type' => 'text', 'max' => 4],
    "$s.wins" => ['label' => "$who 온라인 승", 'type' => 'int'],
    "$s.losses" => ['label' => "$who 온라인 패", 'type' => 'int'],
    "$s.rate" => ['label' => "$who 온라인 승률", 'type' => 'rate', 'derived' => ["$s.wins", "$s.losses"]],
];

return [
    'slug' => 'online-race',
    'name' => '온라인 상대 종족 전적',
    'short' => '온라종족',
    'order' => 20,
    'view' => 'online-h2h',
    'params' => [
        ['key' => 'a.player', 'label' => 'A 선수', 'type' => 'player'],
        ['key' => 'b.player', 'label' => 'B 선수', 'type' => 'player'],
    ],
    'check' => 'check_two_players',
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40],
        'sum.label' => ['label' => '요약 바 기간', 'type' => 'text', 'max' => 12, 'optional' => true]] + $side('a', 'A') + $side('b', 'B'),
    'auto' => static function (array $p, array $ds): array {
        $s = script_for($ds, $p['a']['player'], $p['b']['player']);
        $auto = ['title' => '온라인 종족 전적 최근 한 달', 'sum.label' => $s['recent']['label'] ?? '최근 한 달'];
        foreach (['a', 'b'] as $k) {
            $auto["$k.name"] = pname($ds['players'], $p[$k]['player']);
            $r = $s['recent']['race'][$k] ?? null;
            if ($r !== null) {
                $auto += ["$k.vs" => $r['vs'], "$k.wins" => $r['w'], "$k.losses" => $r['l']];
            }
        }
        return $auto;
    },
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s vs %s · 최근 한 달 · %s',
        pname($ctx['players'], $p['a']['player']), pname($ctx['players'], $p['b']['player']),
        script_summary($ctx, $p['a']['player'], $p['b']['player'])),
    'present' => static function (array $f): array {
        $cols = [];
        foreach (['a', 'b'] as $s) {
            $cols[] = ['name' => (string)$f["$s.name"], 'vs' => hid($f, "$s.vs") ? '' : (string)$f["$s.vs"],
                'record' => text_record_hid($f, "$s.wins", "$s.losses"),
                'rate' => hid($f, "$s.rate") ? '' : text_rate_paren($f["$s.rate"])];
        }
        $vs = static fn(array $c) => $c['name'] . ($c['vs'] === '' ? '' : ' vs ' . $c['vs']);
        // 요약 바: 상자 높이를 #6과 같게 두는 짧은 한 줄. 작은 글씨 = 기간
        return ['title' => (string)$f['title'], 'cols' => $cols, 'h2h' => '',
            'sum_label' => hid($f, 'sum.label') ? '' : (string)$f['sum.label'], 'sum' => $vs($cols[0]) . ' · ' . $vs($cols[1])];
    },
];
