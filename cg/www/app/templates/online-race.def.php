<?php
declare(strict_types=1);

/*
 * #20 온라인 상대 종족 전적 — 두 선수 각각의 온라인 상대 종족 전적 (통산, 게임 1판 = 1건). v0.10.1에 #6에서 나눔.
 * 운영자가 [eloboard 조회]를 누르면 eloboard 종족별 전적(통산만 제공)으로 AUTO를 채운다 (2페이지). 기간 없음.
 * 화면은 #6과 같은 상자(online-h2h.view.php, .cg-online)를 쓴다.
 */
$side = static fn(string $s, string $who) => [
    "$s.name" => ['label' => "$who 이름", 'type' => 'text', 'max' => 12],
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
        ['key' => 'a.vs', 'label' => 'A 상대 종족', 'type' => 'race', 'auto_from' => 'b.player'],
        ['key' => 'b.player', 'label' => 'B 선수', 'type' => 'player'],
        ['key' => 'b.vs', 'label' => 'B 상대 종족', 'type' => 'race', 'auto_from' => 'a.player'],
    ],
    'check' => 'check_two_players',
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + $side('a', 'A') + $side('b', 'B'),
    'auto' => static function (array $p, array $ds): array {
        $auto = ['title' => '온라인 상대 종족 전적 (통산)'];
        foreach (['a', 'b'] as $s) {
            $auto["$s.name"] = pname($ds['players'], $p[$s]['player']);
        }
        $elo = elo_result_for('online-race', $p, $ds);
        if ($elo !== null) {
            return $auto + ['a.wins' => $elo['a_wins'], 'a.losses' => $elo['a_losses'], 'b.wins' => $elo['b_wins'], 'b.losses' => $elo['b_losses']];
        }
        if (!($ds['online_available'] ?? false)) {
            return $auto;
        }
        foreach (['a', 'b'] as $s) {
            $rec = stats_online_record($ds['online'], $p[$s]['player'], $p[$s]['vs']);
            $auto["$s.wins"] = $rec['wins'];
            $auto["$s.losses"] = $rec['losses'];
        }
        return $auto;
    },
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s vs %s / %s vs %s · 온라인 통산',
        pname($ctx['players'], $p['a']['player']), $p['a']['vs'], pname($ctx['players'], $p['b']['player']), $p['b']['vs']),
    'present' => static function (array $f, array $p): array {
        $cols = [];
        foreach (['a', 'b'] as $s) {
            $cols[] = ['name' => (string)$f["$s.name"], 'vs' => $p[$s]['vs'],
                'record' => text_record_hid($f, "$s.wins", "$s.losses"),
                'rate' => hid($f, "$s.rate") ? '' : text_rate_paren($f["$s.rate"])];
        }
        // 요약 바: 상자 높이를 #6과 같게 두는 짧은 한 줄 (출처 표기 없음)
        return ['title' => (string)$f['title'], 'cols' => $cols, 'h2h' => '',
            'sum' => $cols[0]['name'] . ' vs ' . $cols[0]['vs'] . ' · ' . $cols[1]['name'] . ' vs ' . $cols[1]['vs']];
    },
];
