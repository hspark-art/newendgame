<?php
declare(strict_types=1);

/*
 * #6 온라인 상대 전적 — 두 선수 각각의 온라인 상대 종족 전적 + 두 선수 온라인 맞대결 (게임 1판 = 1건, 레퍼런스 없음)
 * v0.10.0: 운영자가 [eloboard 조회]를 누르면 eloboard.com 값으로 AUTO를 채운다 (eloboard.php). 끝장전 기록과 섞지 않는다.
 * 맞대결은 기간별, 종족전(승·패)은 기간이 '전체'일 때만 (eloboard 종족별 전적은 통산만 제공). 수동 수정은 그대로 우선.
 */
$side = static fn(string $s, string $who) => [
    "$s.name" => ['label' => "$who 이름", 'type' => 'text', 'max' => 12],
    "$s.wins" => ['label' => "$who 온라인 승", 'type' => 'int'],
    "$s.losses" => ['label' => "$who 온라인 패", 'type' => 'int'],
    "$s.rate" => ['label' => "$who 온라인 승률", 'type' => 'rate', 'derived' => ["$s.wins", "$s.losses"]],
];

return [
    'slug' => 'online-h2h',
    'name' => '온라인 상대 전적',
    'short' => '온라인',
    'order' => 6,
    'params' => [
        ['key' => 'a.player', 'label' => 'A 선수', 'type' => 'player'],
        ['key' => 'a.vs', 'label' => 'A 상대 종족', 'type' => 'race', 'auto_from' => 'b.player'],
        ['key' => 'b.player', 'label' => 'B 선수', 'type' => 'player'],
        ['key' => 'b.vs', 'label' => 'B 상대 종족', 'type' => 'race', 'auto_from' => 'a.player'],
        ['key' => 'since', 'label' => '기간 시작 (비우면 전체)', 'type' => 'date_opt'],
        ['key' => 'until', 'label' => '기간 끝 (비우면 전체)', 'type' => 'date_opt'],
    ],
    'check' => static function (array $p): void {
        check_two_players($p);
        if ($p['since'] !== '' && $p['until'] !== '' && $p['since'] > $p['until']) {
            throw new ActionError('BAD_PARAMS', '기간: 시작이 끝보다 늦습니다.', 422);
        }
    },
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + $side('a', 'A') + $side('b', 'B') + [
        'h.a' => ['label' => '맞대결 A 승', 'type' => 'int'],
        'h.b' => ['label' => '맞대결 B 승', 'type' => 'int'],
    ],
    'auto' => static function (array $p, array $ds): array {
        $auto = ['title' => elo_title($p['since'] ?? '', $p['until'] ?? '')];
        $elo = elo_result_for($p, $ds);
        if ($elo !== null) {
            // eloboard 조회 결과 (읽지 못한 칸·기간 지정 시 종족전은 비어 있음 → 직접 입력)
            return $auto + ['a.name' => pname($ds['players'], $p['a']['player']), 'b.name' => pname($ds['players'], $p['b']['player']),
                'a.wins' => $elo['a_wins'], 'a.losses' => $elo['a_losses'], 'b.wins' => $elo['b_wins'], 'b.losses' => $elo['b_losses'],
                'h.a' => $elo['h_a'], 'h.b' => $elo['h_b']];
        }
        if (!($ds['online_available'] ?? false)) {
            // 온라인 기록 소스가 없음 (eloboard 연동 전) → 이름만 채우고 수치는 운영자가 확인해 입력
            return $auto + ['a.name' => pname($ds['players'], $p['a']['player']), 'b.name' => pname($ds['players'], $p['b']['player'])];
        }
        foreach (['a', 'b'] as $s) {
            $rec = stats_online_record($ds['online'], $p[$s]['player'], $p[$s]['vs']);
            $auto["$s.name"] = pname($ds['players'], $p[$s]['player']);
            $auto["$s.wins"] = $rec['wins'];
            $auto["$s.losses"] = $rec['losses'];
        }
        $h = stats_online_h2h($ds['online'], $p['a']['player'], $p['b']['player']);
        return $auto + ['h.a' => $h['a_wins'], 'h.b' => $h['b_wins']];
    },
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s vs %s / %s vs %s · 온라인 %s',
        pname($ctx['players'], $p['a']['player']), $p['a']['vs'], pname($ctx['players'], $p['b']['player']), $p['b']['vs'],
        elo_period_text($p['since'] ?? '', $p['until'] ?? '') ?: '전체'),
    'present' => static function (array $f, array $p): array {
        $cols = [];
        foreach (['a', 'b'] as $s) {
            // 타이틀 에디터에서 뺀 항목은 그 자리를 비운다 (hid)
            $cols[] = ['name' => (string)$f["$s.name"], 'vs' => $p[$s]['vs'],
                'record' => text_record_hid($f, "$s.wins", "$s.losses"),
                'rate' => hid($f, "$s.rate") ? '' : text_rate_paren($f["$s.rate"])];
        }
        return ['title' => (string)$f['title'], 'cols' => $cols, 'h2h' => hid($f, 'h.a', 'h.b') ? '' : $f['h.a'] . ' : ' . $f['h.b']];
    },
];
