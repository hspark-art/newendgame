<?php
declare(strict_types=1);

/*
 * #9 풀세트 접전 확률 — 9전(5선승) 끝장전 중 풀세트(5:4 승 + 4:5 패)까지 간 비율 (레퍼런스 없음)
 * 제목은 원 기획의 "확률"이지만 값은 지난 경기의 풀세트 비율이며 예측이 아니다 (화면에도 "지난 풀세트 비율"로 표기).
 * 9전이 아닌 경기는 세지 않는다 (다른 bestOf 처리는 NEEDS CONFIRMATION).
 */
$side = static fn(string $s, string $who) => [
    "$s.name" => ['label' => "$who 이름", 'type' => 'text', 'max' => 12],
    "$s.matches" => ['label' => "$who 9전 경기 수", 'type' => 'int'],
    "$s.fsw" => ['label' => "$who 5:4 승리", 'type' => 'int'],
    "$s.fsl" => ['label' => "$who 4:5 패배", 'type' => 'int'],
    "$s.rate" => ['label' => "$who 풀세트 비율", 'type' => 'rate',
        'derived' => ['calc' => 'share', 'parts' => ["$s.fsw", "$s.fsl"], 'total' => "$s.matches"]],
];

return [
    'slug' => 'full-set',
    'name' => '풀세트 접전 확률',
    'short' => '풀세트',
    'order' => 9,
    'params' => [
        ['key' => 'a.player', 'label' => 'A 선수', 'type' => 'player'],
        ['key' => 'b.player', 'label' => 'B 선수', 'type' => 'player'],
    ],
    'check' => 'check_two_players',
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + $side('a', 'A') + $side('b', 'B'),
    'auto' => static function (array $p, array $ds): array {
        $auto = ['title' => '풀세트 접전 확률'];
        foreach (['a', 'b'] as $s) {
            $r = stats_full_set($ds['matches'], $p[$s]['player']);
            $auto += ["$s.name" => pname($ds['players'], $p[$s]['player']), "$s.matches" => $r['matches'],
                "$s.fsw" => $r['fs_wins'], "$s.fsl" => $r['fs_losses']];
        }
        return $auto;
    },
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s / %s',
        pname($ctx['players'], $p['a']['player']), pname($ctx['players'], $p['b']['player'])),
    'present' => static function (array $f): array {
        $cols = [];
        foreach (['a', 'b'] as $s) {
            $full = $f["$s.fsw"] === null || $f["$s.fsl"] === null ? null : $f["$s.fsw"] + $f["$s.fsl"];
            $cols[] = ['name' => (string)$f["$s.name"], 'rate' => text_pct($f["$s.rate"]),
                'count' => sprintf('%s경기 중 %s회 풀세트', $f["$s.matches"] ?? '-', $full ?? '-'),
                'detail' => sprintf('5:4 승 %s · 4:5 패 %s', $f["$s.fsw"] ?? '-', $f["$s.fsl"] ?? '-')];
        }
        return ['title' => (string)$f['title'], 'cols' => $cols];
    },
];
