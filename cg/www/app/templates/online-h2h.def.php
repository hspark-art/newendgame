<?php
declare(strict_types=1);

/*
 * #6 온라인 상대 전적 — 두 선수 온라인 맞대결 (게임 1판 = 1건). 끝장전 기록과 섞지 않는다.
 * v0.11.0: 값은 대본(Google 문서, script.php)의 '상대 전적' 표 — 통산 또는 최근 한 달. eloboard 조회는 없앴다.
 * 예전 페이지의 since·until·a.vs 파라미터는 정의에 없으므로 읽지 않는다 (basis가 없으면 최근 한 달).
 */
return [
    'slug' => 'online-h2h',
    'name' => '온라인 상대 전적',
    'short' => '온라인',
    'order' => 6,
    'params' => [
        ['key' => 'a.player', 'label' => 'A 선수', 'type' => 'player'],
        ['key' => 'b.player', 'label' => 'B 선수', 'type' => 'player'],
        ['key' => 'basis', 'label' => '기준 (대본)', 'type' => 'choice', 'default' => 'month',
            'options' => [['month', '최근 한 달'], ['total', '통산']]],
    ],
    'check' => 'check_two_players',
    'fields' => [
        'title' => ['label' => '제목', 'type' => 'text', 'max' => 40],
        'a.name' => ['label' => 'A 이름', 'type' => 'text', 'max' => 12],
        'b.name' => ['label' => 'B 이름', 'type' => 'text', 'max' => 12],
        'h.a' => ['label' => '맞대결 A 승', 'type' => 'int'],
        'h.b' => ['label' => '맞대결 B 승', 'type' => 'int'],
        'h.ra' => ['label' => '맞대결 A 승률', 'type' => 'rate', 'derived' => ['h.a', 'h.b']],
        'h.rb' => ['label' => '맞대결 B 승률', 'type' => 'rate', 'derived' => ['h.b', 'h.a']],
    ],
    'auto' => static function (array $p, array $ds): array {
        $total = ($p['basis'] ?? 'month') === 'total';
        $s = script_for($ds, $p['a']['player'], $p['b']['player']);
        $label = $s['recent']['label'] ?? null;
        $auto = ['title' => $total ? '온라인 상대 전적 (통산)' : '온라인 전적 최근 한 달' . ($label === null ? '' : " ($label)"),
            'a.name' => pname($ds['players'], $p['a']['player']), 'b.name' => pname($ds['players'], $p['b']['player'])];
        // 대본에 없거나 읽지 못한 값은 비워 둔다 → 운영자가 확인해 입력하기 전까지 송출 불가
        $h = $total ? ($s['total'] ?? null) : ($s['recent']['h2h'] ?? null);
        return $auto + ($h === null ? [] : ['h.a' => $h[0], 'h.b' => $h[1]]);
    },
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s vs %s · %s · %s',
        pname($ctx['players'], $p['a']['player']), pname($ctx['players'], $p['b']['player']),
        ($p['basis'] ?? 'month') === 'total' ? '통산' : '최근 한 달', script_summary($ctx, $p['a']['player'], $p['b']['player'])),
    'present' => static function (array $f): array {
        // 본문: A "217승 180패 (54.7%)", B "180승 217패 (45.3%)". 타이틀 에디터에서 뺀 항목은 그 자리를 비운다 (hid)
        $col = static fn(string $s, string $w, string $l, string $r) => ['name' => (string)$f["$s.name"], 'vs' => '',
            'record' => text_record_hid($f, $w, $l), 'rate' => hid($f, $r) ? '' : text_rate_paren($f[$r])];
        return ['title' => (string)$f['title'], 'cols' => [$col('a', 'h.a', 'h.b', 'h.ra'), $col('b', 'h.b', 'h.a', 'h.rb')],
            'h2h' => hid($f, 'h.a', 'h.b') ? '' : $f['h.a'] . ' : ' . $f['h.b']];
    },
];
