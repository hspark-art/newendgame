<?php
declare(strict_types=1);

/*
 * #6 온라인 상대 전적 — 두 선수 온라인 맞대결 (게임 1판 = 1건, 레퍼런스 없음). 기간 지정 가능.
 * v0.10.0: 운영자가 [eloboard 조회]를 누르면 eloboard.com 값으로 AUTO를 채운다 (eloboard.php). 끝장전 기록과 섞지 않는다.
 * v0.10.1: 종족전은 #20 온라인 상대 종족 전적(online-race)으로 나눴다. 맞대결만 (eloboard 1페이지). 수동 수정은 그대로 우선.
 * 이전 페이지의 a.vs·b.vs 파라미터, a.wins 등 수동값은 정의에 없으므로 읽지 않는다.
 */
return [
    'slug' => 'online-h2h',
    'name' => '온라인 상대 전적',
    'short' => '온라인',
    'order' => 6,
    'params' => [
        ['key' => 'a.player', 'label' => 'A 선수', 'type' => 'player'],
        ['key' => 'b.player', 'label' => 'B 선수', 'type' => 'player'],
        ['key' => 'since', 'label' => '기간 시작 (비우면 전체)', 'type' => 'date_opt'],
        ['key' => 'until', 'label' => '기간 끝 (비우면 전체)', 'type' => 'date_opt'],
    ],
    'check' => static function (array $p): void {
        check_two_players($p);
        if ($p['since'] !== '' && $p['until'] !== '' && $p['since'] > $p['until']) {
            throw new ActionError('BAD_PARAMS', '기간: 시작이 끝보다 늦습니다.', 422);
        }
    },
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
        $auto = ['title' => elo_title($p['since'], $p['until']),
            'a.name' => pname($ds['players'], $p['a']['player']), 'b.name' => pname($ds['players'], $p['b']['player'])];
        $elo = elo_result_for('online-h2h', $p, $ds);
        if ($elo !== null) {
            // eloboard 조회 결과 (읽지 못하면 비어 있음 → 직접 입력)
            return $auto + ['h.a' => $elo['h_a'], 'h.b' => $elo['h_b']];
        }
        if (!($ds['online_available'] ?? false)) {
            return $auto; // 온라인 기록 소스가 없음 → 수치는 운영자가 조회·입력
        }
        $h = stats_online_h2h($ds['online'], $p['a']['player'], $p['b']['player']);
        return $auto + ['h.a' => $h['a_wins'], 'h.b' => $h['b_wins']];
    },
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s vs %s · 온라인 맞대결 %s',
        pname($ctx['players'], $p['a']['player']), pname($ctx['players'], $p['b']['player']),
        elo_period_text($p['since'], $p['until']) ?: '전체'),
    'present' => static function (array $f): array {
        // 본문: A "217승 180패 (54.7%)", B "180승 217패 (45.3%)". 타이틀 에디터에서 뺀 항목은 그 자리를 비운다 (hid)
        $col = static fn(string $s, string $w, string $l, string $r) => ['name' => (string)$f["$s.name"], 'vs' => '',
            'record' => text_record_hid($f, $w, $l), 'rate' => hid($f, $r) ? '' : text_rate_paren($f[$r])];
        return ['title' => (string)$f['title'], 'cols' => [$col('a', 'h.a', 'h.b', 'h.ra'), $col('b', 'h.b', 'h.a', 'h.rb')],
            'h2h' => hid($f, 'h.a', 'h.b') ? '' : $f['h.a'] . ' : ' . $f['h.b']];
    },
];
