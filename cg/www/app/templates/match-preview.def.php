<?php
declare(strict_types=1);

/*
 * #11 매치 프리뷰 — 경기 전 두 선수 비교표: 매치 전적, 세트 전적, 상대 종족전(세트), 최근 5경기 흐름, 맞대결, 이번 맵 전적(맵을 고르면).
 * 매치 = 끝장전 승패(이상·제외 경기 뺌), 세트 = Results 모든 세트. 각 줄에서 기록이 더 좋은 쪽을 강조색으로 표시한다.
 */
$side = static fn(string $s, string $who) => [
    "$s.name" => ['label' => "$who 이름", 'type' => 'text', 'max' => 12],
    "$s.nick" => ['label' => "$who 닉네임", 'type' => 'text', 'max' => 16, 'optional' => true],
    "$s.race" => ['label' => "$who 종족", 'type' => 'text', 'max' => 1, 'optional' => true],
    "$s.mw" => ['label' => "$who 매치 승", 'type' => 'int'],
    "$s.ml" => ['label' => "$who 매치 패", 'type' => 'int'],
    "$s.mrate" => ['label' => "$who 매치 승률", 'type' => 'rate', 'derived' => ["$s.mw", "$s.ml"]],
    "$s.sw" => ['label' => "$who 세트 승", 'type' => 'int'],
    "$s.sl" => ['label' => "$who 세트 패", 'type' => 'int'],
    "$s.srate" => ['label' => "$who 세트 승률", 'type' => 'rate', 'derived' => ["$s.sw", "$s.sl"]],
    "$s.vs" => ['label' => "$who 상대 종족", 'type' => 'text', 'max' => 1, 'optional' => true],
    "$s.rw" => ['label' => "$who 상대 종족전 승", 'type' => 'int', 'optional' => true],
    "$s.rl" => ['label' => "$who 상대 종족전 패", 'type' => 'int', 'optional' => true],
    "$s.rrate" => ['label' => "$who 상대 종족전 승률", 'type' => 'rate', 'derived' => ["$s.rw", "$s.rl"], 'optional' => true],
    "$s.form" => ['label' => "$who 최근 5경기 (W/L, 오래된 순)", 'type' => 'text', 'max' => 5, 'optional' => true],
    "$s.pw" => ['label' => "$who 이번 맵 승", 'type' => 'int', 'optional' => true],
    "$s.pl" => ['label' => "$who 이번 맵 패", 'type' => 'int', 'optional' => true],
    "$s.prate" => ['label' => "$who 이번 맵 승률", 'type' => 'rate', 'derived' => ["$s.pw", "$s.pl"], 'optional' => true],
];

return [
    'slug' => 'match-preview',
    'name' => '매치 프리뷰',
    'short' => '프리뷰',
    'order' => 11,
    'params' => [
        ['key' => 'a.player', 'label' => 'A 선수 (왼쪽)', 'type' => 'player'],
        ['key' => 'b.player', 'label' => 'B 선수 (오른쪽)', 'type' => 'player'],
        ['key' => 'map', 'label' => '이번 맵 (고르지 않으면 맵 줄 없음)', 'type' => 'map_any'],
    ],
    'check' => 'check_two_players',
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + $side('a', 'A') + $side('b', 'B') + [
        'h.a' => ['label' => '맞대결 A 승', 'type' => 'int', 'max' => 999],
        'h.b' => ['label' => '맞대결 B 승', 'type' => 'int', 'max' => 999],
        'h.sa' => ['label' => '맞대결 A 세트', 'type' => 'int'],
        'h.sb' => ['label' => '맞대결 B 세트', 'type' => 'int'],
        'map.name' => ['label' => '맵 이름', 'type' => 'text', 'max' => 20, 'optional' => true],
    ],
    'auto' => static function (array $p, array $ds): array {
        $auto = ['title' => '중계진 스타 끝장전 매치 프리뷰'];
        $map = $p['map'];
        foreach (['a' => 'b', 'b' => 'a'] as $s => $o) {
            $pid = $p[$s]['player'];
            $rec = stats_player_record($ds['matches'], $ds['games'], $pid);
            $vs = $ds['players'][$p[$o]['player']]['race'] ?? null; // 상대의 주 종족
            $auto += ["$s.name" => pname($ds['players'], $pid), "$s.nick" => $ds['players'][$pid]['nickname'] ?? null,
                "$s.race" => $ds['players'][$pid]['race'] ?? null,
                "$s.mw" => $rec['match_wins'], "$s.ml" => $rec['match_losses'], "$s.sw" => $rec['set_wins'], "$s.sl" => $rec['set_losses'],
                "$s.form" => stats_recent_form($ds['matches'], $pid, 5) ?: null];
            if ($vs !== null) {
                $r = stats_race_sets($ds['games'], $pid, $vs);
                $auto += ["$s.vs" => $vs, "$s.rw" => $r['wins'], "$s.rl" => $r['losses']];
            }
            if ($map !== '') {
                $r = stats_map_sets($ds['games'], $pid, $map);
                $auto += ["$s.pw" => $r['wins'], "$s.pl" => $r['losses']];
            }
        }
        $h = stats_head_to_head($ds['matches'], $p['a']['player'], $p['b']['player'], 0);
        $auto += ['h.a' => $h['a_wins'], 'h.b' => $h['b_wins'], 'h.sa' => $h['a_sets'], 'h.sb' => $h['b_sets'],
            'map.name' => $map === '' ? null : map_label($ds, $map)];
        return $auto;
    },
    'verify' => static function (array $p, array $ds): array {
        $issues = [];
        foreach (['a' => 'b', 'b' => 'a'] as $s => $o) {
            $pid = $p[$s]['player'];
            $issues = array_merge($issues, verify_matches($ds, $pid, ["$s.mw", "$s.ml", "$s.form", 'h.a', 'h.b', 'h.sa', 'h.sb']),
                verify_sets($ds, $pid, 'all', ["$s.sw", "$s.sl"]));
            $vs = $ds['players'][$p[$o]['player']]['race'] ?? null;
            if ($vs !== null) {
                $issues = array_merge($issues, verify_sets($ds, $pid, $vs, ["$s.rw", "$s.rl"]));
            }
            if ($p['map'] !== '') {
                $issues = array_merge($issues, verify_map_sets($ds, $pid, $p['map'], ["$s.pw", "$s.pl"]));
            }
        }
        return $issues;
    },
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s vs %s%s', pname($ctx['players'], $p['a']['player']),
        pname($ctx['players'], $p['b']['player']), $p['map'] === '' ? '' : ' · ' . ($ctx['maps'][$p['map']]['name'] ?? $p['map'])),
    'present' => static function (array $f): array {
        $rec = static fn($w, $l) => $w === null || $l === null ? null : "{$w}승 {$l}패";
        // 더 좋은 쪽: 승률(0.1%) 비교. 같거나 한쪽이 없으면 강조 없음
        $lead = static fn(?int $a, ?int $b) => $a === null || $b === null || $a === $b ? '' : ($a > $b ? 'a' : 'b');
        $line = static function (string $label, string $k, string $w, string $l, string $rate, string $pre = '') use ($f, $rec, $lead): ?array {
            $a = $rec($f["a.$w"], $f["a.$l"]);
            $b = $rec($f["b.$w"], $f["b.$l"]);
            if ($a === null && $b === null) {
                return null;
            }
            $sub = static fn(string $s) => trim(($pre !== '' && ($f["$s.vs"] ?? null) ? "vs {$f["$s.vs"]} · " : '') . text_pct($f["$s.$rate"]), ' ·');
            return ['kind' => 'rec', 'key' => $k, 'label' => $label, 'a' => $a ?? '—', 'a_sub' => $a === null ? '' : $sub('a'),
                'b' => $b ?? '—', 'b_sub' => $b === null ? '' : $sub('b'), 'lead' => $lead($f["a.$rate"], $f["b.$rate"])];
        };
        $form = static fn(?string $s) => str_split((string)preg_replace('/[^WL]/', '', strtoupper((string)$s))) ?: [];
        $rows = array_values(array_filter([
            $line('매치 전적', 'match', 'mw', 'ml', 'mrate'),
            $line('세트 전적', 'set', 'sw', 'sl', 'srate'),
            $line('상대 종족전', 'race', 'rw', 'rl', 'rrate', 'vs'),
            ($f['a.form'] ?? null) !== null || ($f['b.form'] ?? null) !== null
                ? ['kind' => 'form', 'key' => 'form', 'label' => '최근 5경기', 'a' => $form($f['a.form']), 'b' => $form($f['b.form'])] : null,
            (int)$f['h.a'] + (int)$f['h.b'] === 0
                ? ['kind' => 'note', 'key' => 'h2h', 'label' => '맞대결', 'text' => '첫 맞대결']
                : ['kind' => 'rec', 'key' => 'h2h', 'label' => '맞대결', 'a' => $f['h.a'] . '승', 'a_sub' => '세트 ' . $f['h.sa'],
                    'b' => $f['h.b'] . '승', 'b_sub' => '세트 ' . $f['h.sb'], 'lead' => $lead($f['h.a'], $f['h.b'])],
            ($f['map.name'] ?? null) !== null ? $line($f['map.name'], 'map', 'pw', 'pl', 'prate') : null,
        ]));
        $who = static fn(string $s) => ['name' => (string)$f["$s.name"], 'nick' => (string)($f["$s.nick"] ?? ''),
            'race' => (string)($f["$s.race"] ?? '')];
        return ['title' => (string)$f['title'], 'a' => $who('a'), 'b' => $who('b'), 'rows' => $rows];
    },
];
