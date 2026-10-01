<?php
declare(strict_types=1);

/*
 * #11 매치 프리뷰 — 경기 전 두 선수 비교표: 매치 전적, 세트 전적, 상대 종족전(세트), 최근 5경기 흐름, 맞대결, 이번 맵 전적(맵을 고르면).
 * 매치 = 끝장전 승패(이상·제외 경기 뺌), 세트 = Results 모든 세트. 각 줄에서 기록이 더 좋은 쪽을 강조색으로 표시한다.
 */
/*
 * 필드는 줄(묶음)마다 A·B를 함께 둔다. 묶음(group)은 타이틀 에디터에서 [빼기]로 CG에서 통째로 뺄 수 있다.
 * $pair(묶음, 키 => [이름, 정의]) → "a.키", "b.키" 필드 (이름 앞에 A/B)
 */
$pair = static function (string $group, array $spec): array {
    $out = [];
    foreach (['a' => 'A', 'b' => 'B'] as $s => $who) {
        foreach ($spec as $k => [$label, $def]) {
            if (isset($def['derived'])) {
                $def['derived'] = array_map(static fn($x) => "$s.$x", $def['derived']);
            }
            $out["$s.$k"] = ['label' => "$who $label"] + $def + ($group === '' ? [] : ['group' => $group]);
        }
    }
    return $out;
};
$fields = ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]]
    + $pair('', ['name' => ['이름', ['type' => 'text', 'max' => 12]]])
    + $pair('닉네임', ['nick' => ['닉네임', ['type' => 'text', 'max' => 16, 'optional' => true]]])
    + $pair('종족 표시', ['race' => ['종족', ['type' => 'text', 'max' => 1, 'optional' => true]]])
    + $pair('매치 전적', ['mw' => ['승', ['type' => 'int']], 'ml' => ['패', ['type' => 'int']],
        'mrate' => ['승률', ['type' => 'rate', 'derived' => ['mw', 'ml']]]])
    + $pair('세트 전적', ['sw' => ['승', ['type' => 'int']], 'sl' => ['패', ['type' => 'int']],
        'srate' => ['승률', ['type' => 'rate', 'derived' => ['sw', 'sl']]]])
    + $pair('상대 종족전', ['vs' => ['상대 종족', ['type' => 'text', 'max' => 1, 'optional' => true]],
        'rw' => ['승', ['type' => 'int', 'optional' => true]], 'rl' => ['패', ['type' => 'int', 'optional' => true]],
        'rrate' => ['승률', ['type' => 'rate', 'derived' => ['rw', 'rl'], 'optional' => true]]])
    + $pair('최근 5경기', ['form' => ['W/L (오래된 순)', ['type' => 'text', 'max' => 5, 'optional' => true]]])
    + [
        'h.a' => ['label' => 'A 승', 'type' => 'int', 'max' => 999, 'group' => '맞대결'],
        'h.b' => ['label' => 'B 승', 'type' => 'int', 'max' => 999, 'group' => '맞대결'],
        'h.sa' => ['label' => 'A 세트', 'type' => 'int', 'group' => '맞대결'],
        'h.sb' => ['label' => 'B 세트', 'type' => 'int', 'group' => '맞대결'],
        'map.name' => ['label' => '맵 이름', 'type' => 'text', 'max' => 20, 'optional' => true, 'group' => '이번 맵'],
    ]
    + $pair('이번 맵', ['pw' => ['승', ['type' => 'int', 'optional' => true]], 'pl' => ['패', ['type' => 'int', 'optional' => true]],
        'prate' => ['승률', ['type' => 'rate', 'derived' => ['pw', 'pl'], 'optional' => true]]]);

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
    'fields' => $fields,
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
        // 0승 0패 = 그 조건의 세트가 없음 (예: 동족전을 한 적 없음) → "기록 없음"
        $rec = static fn($w, $l) => $w === null || $l === null ? null : ($w + $l === 0 ? '기록 없음' : "{$w}승 {$l}패");
        // 더 좋은 쪽: 승률(0.1%) 비교. 같거나 한쪽이 없으면 강조 없음
        $lead = static fn(?int $a, ?int $b) => $a === null || $b === null || $a === $b ? '' : ($a > $b ? 'a' : 'b');
        $line = static function (string $label, string $k, string $w, string $l, string $rate, string $pre = '') use ($f, $rec, $lead): ?array {
            $a = $rec($f["a.$w"], $f["a.$l"]);
            $b = $rec($f["b.$w"], $f["b.$l"]);
            if ($a === null && $b === null) {
                return null;
            }
            $sub = static fn(string $s) => trim(($pre !== '' && ($f["$s.vs"] ?? null) ? "vs {$f["$s.vs"]} · " : '')
                . ($f["$s.$rate"] === null ? '' : text_pct($f["$s.$rate"])), ' ·');
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
            match (true) {
                ($f['h.a'] ?? null) === null && ($f['h.b'] ?? null) === null => null, // 맞대결 줄을 뺌
                (int)$f['h.a'] + (int)$f['h.b'] === 0 => ['kind' => 'note', 'key' => 'h2h', 'label' => '맞대결', 'text' => '첫 맞대결'],
                default => ['kind' => 'rec', 'key' => 'h2h', 'label' => '맞대결', 'a' => $f['h.a'] . '승',
                    'a_sub' => $f['h.sa'] === null ? '' : '세트 ' . $f['h.sa'], 'b' => $f['h.b'] . '승',
                    'b_sub' => $f['h.sb'] === null ? '' : '세트 ' . $f['h.sb'], 'lead' => $lead($f['h.a'], $f['h.b'])],
            },
            ($f['map.name'] ?? null) !== null ? $line($f['map.name'], 'map', 'pw', 'pl', 'prate') : null,
        ]));
        $who = static fn(string $s) => ['name' => (string)$f["$s.name"], 'nick' => (string)($f["$s.nick"] ?? ''),
            'race' => (string)($f["$s.race"] ?? '')];
        return ['title' => (string)$f['title'], 'a' => $who('a'), 'b' => $who('b'), 'rows' => $rows];
    },
];
