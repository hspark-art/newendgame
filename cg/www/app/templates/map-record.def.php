<?php
declare(strict_types=1);

/*
 * #12 맵 전적 — 이번 맵에서 두 선수의 세트 전적 (상대 종족 승률과 같은 모양). 아래 줄은 그 맵에서 상대 종족전 세트 전적.
 * 2026-10-01 실제 시트로 확인: 선수×맵 세트 승·패가 "MAP 선수별 전적" 탭 1,081행과 모두 일치.
 * 맵 이름: 프로그램·맵 이름 탭의 한글 이름, 없으면 Results 표기(영문).
 */
$side = static fn(string $s, string $who) => [
    "$s.name" => ['label' => "$who 이름", 'type' => 'text', 'max' => 12],
    "$s.race" => ['label' => "$who 종족", 'type' => 'text', 'max' => 1, 'optional' => true],
    "$s.wins" => ['label' => "$who 맵 승", 'type' => 'int'],
    "$s.losses" => ['label' => "$who 맵 패", 'type' => 'int'],
    "$s.rate" => ['label' => "$who 맵 승률", 'type' => 'rate', 'derived' => ["$s.wins", "$s.losses"]],
    // 아래 줄(그 맵 상대 종족전)은 타이틀 에디터에서 [빼기]로 뺄 수 있다
    "$s.vs" => ['label' => "$who 상대 종족", 'type' => 'text', 'max' => 1, 'optional' => true, 'group' => '그 맵 상대 종족전'],
    "$s.vw" => ['label' => "$who 승", 'type' => 'int', 'optional' => true, 'group' => '그 맵 상대 종족전'],
    "$s.vl" => ['label' => "$who 패", 'type' => 'int', 'optional' => true, 'group' => '그 맵 상대 종족전'],
];

return [
    'slug' => 'map-record',
    'name' => '맵 전적',
    'short' => '맵전적',
    'order' => 12,
    'params' => [
        ['key' => 'map', 'label' => '맵', 'type' => 'map'],
        ['key' => 'a.player', 'label' => 'A 선수 (왼쪽)', 'type' => 'player'],
        ['key' => 'b.player', 'label' => 'B 선수 (오른쪽)', 'type' => 'player'],
    ],
    'check' => 'check_two_players',
    // 묶음 필드(그 맵 상대 종족전)는 에디터에서 한데 보이게 맨 뒤로
    'fields' => (static function () use ($side): array {
        $all = ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + $side('a', 'A') + $side('b', 'B');
        return array_filter($all, static fn($d) => !isset($d['group'])) + array_filter($all, static fn($d) => isset($d['group']));
    })(),
    'auto' => static function (array $p, array $ds): array {
        $auto = ['title' => map_label($ds, $p['map']) . ' 맵 전적'];
        foreach (['a' => 'b', 'b' => 'a'] as $s => $o) {
            $pid = $p[$s]['player'];
            $r = stats_map_sets($ds['games'], $pid, $p['map']);
            $vs = $ds['players'][$p[$o]['player']]['race'] ?? null;
            $auto += ["$s.name" => pname($ds['players'], $pid), "$s.race" => $ds['players'][$pid]['race'] ?? null,
                "$s.wins" => $r['wins'], "$s.losses" => $r['losses']];
            if ($vs !== null) {
                $v = stats_map_sets($ds['games'], $pid, $p['map'], $vs);
                $auto += ["$s.vs" => $vs, "$s.vw" => $v['wins'], "$s.vl" => $v['losses']];
            }
        }
        return $auto;
    },
    'verify' => static function (array $p, array $ds): array {
        $issues = [];
        foreach (['a' => 'b', 'b' => 'a'] as $s => $o) {
            $pid = $p[$s]['player'];
            // 상대 종족전은 같은 맵 세트를 종족으로 나눈 값: 맵 전적 + 선수 종족별 세트 전적이 모두 맞아야 한다
            $issues = array_merge($issues, verify_map_sets($ds, $pid, $p['map'], ["$s.wins", "$s.losses", "$s.vw", "$s.vl"]));
            $vs = $ds['players'][$p[$o]['player']]['race'] ?? null;
            if ($vs !== null) {
                $issues = array_merge($issues, verify_sets($ds, $pid, $vs, ["$s.vw", "$s.vl"]));
            }
        }
        return $issues;
    },
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s · %s vs %s', $ctx['maps'][$p['map']]['name'] ?? $p['map'],
        pname($ctx['players'], $p['a']['player']), pname($ctx['players'], $p['b']['player'])),
    'present' => static function (array $f): array {
        $cols = [];
        foreach (['a', 'b'] as $s) {
            $vrec = hid($f, "$s.vw") || hid($f, "$s.vl") || ($f["$s.vw"] !== null && $f["$s.vl"] !== null)
                ? text_record_hid($f, "$s.vw", "$s.vl") : '';
            $detail = $vrec === '' ? '' : trim(($f["$s.vs"] !== null ? "vs {$f["$s.vs"]} " : '') . $vrec);
            $cols[] = ['name' => (string)$f["$s.name"], 'race' => (string)($f["$s.race"] ?? ''),
                'record' => text_record_hid($f, "$s.wins", "$s.losses"),
                'rate' => hid($f, "$s.rate") ? '' : text_rate_paren($f["$s.rate"]), 'detail' => $detail];
        }
        return ['title' => (string)$f['title'], 'cols' => $cols];
    },
];
