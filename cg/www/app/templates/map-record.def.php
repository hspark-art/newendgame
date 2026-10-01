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
    "$s.vs" => ['label' => "$who 상대 종족", 'type' => 'text', 'max' => 1, 'optional' => true],
    "$s.vw" => ['label' => "$who 이 맵 상대 종족전 승", 'type' => 'int', 'optional' => true],
    "$s.vl" => ['label' => "$who 이 맵 상대 종족전 패", 'type' => 'int', 'optional' => true],
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
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + $side('a', 'A') + $side('b', 'B'),
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
            $detail = $f["$s.vs"] !== null && $f["$s.vw"] !== null && $f["$s.vl"] !== null
                ? "vs {$f["$s.vs"]} " . text_record($f["$s.vw"], $f["$s.vl"]) : '';
            $cols[] = ['name' => (string)$f["$s.name"], 'race' => (string)($f["$s.race"] ?? ''),
                'record' => text_record($f["$s.wins"], $f["$s.losses"]), 'rate' => text_rate_paren($f["$s.rate"]), 'detail' => $detail];
        }
        return ['title' => (string)$f['title'], 'cols' => $cols];
    },
];
