<?php
declare(strict_types=1);

/*
 * #22 맵별 누적 종족 전적 (v0.11.0) — 대본의 'EloBoard 맵별 등록 누적 전적' 표: 그 맵 종족 대 종족 전체 등록 전적.
 * 선수는 대본 구역을 고르는 데만 쓴다. A 선수 종족이 오른쪽이면 왼쪽으로 돌려 놓는다.
 * 화면은 #6과 같은 상자(online-h2h.view.php).
 */
$side = static fn(string $s, string $who) => [
    "$s.name" => ['label' => "$who 종족", 'type' => 'text', 'max' => 12],
    "$s.wins" => ['label' => "$who 승", 'type' => 'int'],
    "$s.losses" => ['label' => "$who 패", 'type' => 'int'],
    "$s.rate" => ['label' => "$who 승률", 'type' => 'rate', 'derived' => ["$s.wins", "$s.losses"]],
];

return [
    'slug' => 'online-map-cum',
    'name' => '맵별 누적 종족 전적',
    'short' => '맵누적',
    'order' => 22,
    'view' => 'online-h2h',
    'params' => [
        ['key' => 'a.player', 'label' => 'A 선수', 'type' => 'player'],
        ['key' => 'b.player', 'label' => 'B 선수', 'type' => 'player'],
        ['key' => 'map', 'label' => '맵 (대본)', 'type' => 'script_map'],
    ],
    'check' => static fn(array $p, array $ctx = []) => script_check_map($p, $ctx, false),
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40],
        'sum.label' => ['label' => '요약 바 작은 글씨', 'type' => 'text', 'max' => 12, 'optional' => true],
        'sum' => ['label' => '요약 바', 'type' => 'text', 'max' => 30]] + $side('a', '왼쪽') + $side('b', '오른쪽'),
    'auto' => static function (array $p, array $ds): array {
        $s = script_for($ds, $p['a']['player'], $p['b']['player']);
        $c = $s['cum'][$p['map']] ?? null;
        $auto = ['title' => $p['map'] . ' · 맵 누적 종족 전적', 'sum.label' => '온라인 누적'];
        if ($c === null) {
            return $auto;
        }
        $ra = (string)($ds['players'][$p['a']['player']]['race'] ?? '');
        if ($ra === $c['rr'] && $ra !== $c['lr']) {
            $c = ['lr' => $c['rr'], 'rr' => $c['lr'], 'lw' => $c['rw'], 'rw' => $c['lw']];
        }
        [$l, $r] = [RACE_NAMES[$c['lr']], RACE_NAMES[$c['rr']]];
        return $auto + ['sum' => "$l vs $r 전체 등록 전적", 'a.name' => $l, 'b.name' => $r,
            'a.wins' => $c['lw'], 'a.losses' => $c['rw'], 'b.wins' => $c['rw'], 'b.losses' => $c['lw']];
    },
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s · 누적 종족 전적 · %s', $p['map'],
        script_summary($ctx, $p['a']['player'], $p['b']['player'])),
    'present' => static function (array $f): array {
        $cols = [];
        foreach (['a', 'b'] as $s) {
            $cols[] = ['name' => (string)$f["$s.name"], 'vs' => '', 'record' => text_record_hid($f, "$s.wins", "$s.losses"),
                'rate' => hid($f, "$s.rate") ? '' : text_rate_paren($f["$s.rate"])];
        }
        return ['title' => (string)$f['title'], 'cols' => $cols, 'h2h' => '',
            'sum_label' => hid($f, 'sum.label') ? '' : (string)$f['sum.label'], 'sum' => hid($f, 'sum') ? '' : (string)$f['sum']];
    },
];
