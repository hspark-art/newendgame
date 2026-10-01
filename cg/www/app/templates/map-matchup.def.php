<?php
declare(strict_types=1);

/*
 * #13 맵 종족 상성 — 그 맵에서 저그 vs 프로토스, 테란 vs 저그, 프로토스 vs 테란 세트 승수와 승률 막대 + 총 세트·사용 기간.
 * 2026-10-01 실제 시트로 확인: "MAP 통계" 탭 83개 맵의 세트 수·종족별 승·최초/최종 사용일과 모두 일치. 총 세트에는 동족전도 들어간다.
 */
// 줄마다 묶음(group) — 타이틀 에디터에서 [빼기]로 그 종족전 줄이나 아래 총 세트·기간 줄을 뺄 수 있다
$pair = static fn(string $k, string $l, string $r) => [
    "$k.l" => ['label' => "$l 승", 'type' => 'int', 'group' => "$l vs $r"],
    "$k.r" => ['label' => "$r 승", 'type' => 'int', 'group' => "$l vs $r"],
    "$k.rate" => ['label' => "$l 승률", 'type' => 'rate', 'derived' => ["$k.l", "$k.r"], 'group' => "$l vs $r"],
];
$pairs = ['zp' => ['Z', 'P', 'ZP'], 'tz' => ['T', 'Z', 'TZ'], 'pt' => ['P', 'T', 'PT']];

return [
    'slug' => 'map-matchup',
    'name' => '맵 종족 상성',
    'short' => '맵상성',
    'order' => 13,
    'params' => [
        ['key' => 'map', 'label' => '맵', 'type' => 'map'],
    ],
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + $pair('zp', 'Z', 'P') + $pair('tz', 'T', 'Z')
        + $pair('pt', 'P', 'T') + [
            'sets' => ['label' => '총 세트', 'type' => 'int', 'group' => '총 세트·기간'],
            'first' => ['label' => '처음 사용', 'type' => 'date', 'group' => '총 세트·기간'],
            'last' => ['label' => '마지막 사용', 'type' => 'date', 'group' => '총 세트·기간'],
        ],
    'auto' => static function (array $p, array $ds) use ($pairs): array {
        $m = stats_map_matchup($ds['games'], $p['map']);
        $auto = ['title' => map_label($ds, $p['map']) . ' 종족 상성', 'sets' => $m['sets'], 'first' => $m['first'], 'last' => $m['last']];
        foreach ($pairs as $k => [, , $key]) {
            $auto += ["$k.l" => $m[$key][0], "$k.r" => $m[$key][1]];
        }
        return $auto;
    },
    'verify' => static fn(array $p, array $ds): array => verify_map($ds, $p['map'],
        ['zp.l', 'zp.r', 'tz.l', 'tz.r', 'pt.l', 'pt.r', 'sets', 'first', 'last']),
    'summary' => static fn(array $p, array $ctx): string => (string)($ctx['maps'][$p['map']]['name'] ?? $p['map']),
    'present' => static function (array $f) use ($pairs): array {
        $rows = [];
        foreach ($pairs as $k => [$l, $r]) {
            if ($f["$k.l"] === null && $f["$k.r"] === null) {
                continue; // 그 종족전 줄을 뺌
            }
            $rate = $f["$k.rate"];
            $rows[] = ['l' => $l, 'r' => $r, 'lw' => (string)$f["$k.l"], 'rw' => (string)$f["$k.r"],
                'lrate' => $rate === null ? '' : fmt_rate($rate) . '%', 'rrate' => $rate === null ? '' : fmt_rate(1000 - $rate) . '%',
                'lead' => $rate === null || $rate === 500 ? '' : ($rate > 500 ? 'l' : 'r'),
                // 막대 폭 (SVG 속성 — 송출 화면 CSP가 인라인 style을 막으므로 style 대신 width 속성)
                'lw_pct' => $rate === null ? 0 : $rate / 10, 'empty' => $rate === null];
        }
        return ['title' => (string)$f['title'], 'rows' => $rows,
            'foot' => $f['sets'] === null ? '' : sprintf('총 %s세트 · %s ~ %s', $f['sets'], $f['first'], $f['last'])];
    },
];
