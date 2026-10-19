<?php
declare(strict_types=1);

/*
 * #18 ASL 선수 기록 (A안) — 두 선수 각각의 ASL 우승·준우승 횟수, 4강 이상 진출 시즌 수, ASL 세트 전적 (레퍼런스 없음)
 * 원본: ASL 시트 '보기용' 탭 (대국민 스타리그 = 시즌 0, ASL Season N). 우승 = 결승전 세트를 더 많이 이긴 선수 (sheet_data.php asl_parse).
 * 시트 자체 집계가 없어 대조는 하지 않는다. ASL을 쓸 수 없거나 ASL 기록에 없는 선수는 수동 입력 전까지 막는다.
 * 화면은 풀세트 접전 확률과 같은 두 칸 모양·크기 (큰 줄: 우승, 둘째 줄: 준우승·4강 이상, 셋째 줄: 세트 전적).
 */
$side = static fn(string $s, string $who) => [
    "$s.name" => ['label' => "$who 이름", 'type' => 'text', 'max' => 12],
    "$s.titles" => ['label' => "$who 우승", 'type' => 'int'],
    "$s.runner" => ['label' => "$who 준우승", 'type' => 'int'],
    "$s.semis" => ['label' => "$who 4강 이상", 'type' => 'int'],
    "$s.wins" => ['label' => "$who ASL 승", 'type' => 'int'],
    "$s.losses" => ['label' => "$who ASL 패", 'type' => 'int'],
    "$s.rate" => ['label' => "$who ASL 승률", 'type' => 'rate', 'derived' => ["$s.wins", "$s.losses"]],
];

return [
    'slug' => 'asl-record',
    'name' => 'ASL 선수 기록',
    'short' => 'ASL기록',
    'order' => 18,
    'params' => [
        ['key' => 'a.player', 'label' => 'A 선수', 'type' => 'player'],
        ['key' => 'b.player', 'label' => 'B 선수', 'type' => 'player'],
    ],
    'check' => 'check_two_players',
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + $side('a', 'A') + $side('b', 'B'),
    'auto' => static function (array $p, array $ds): array {
        $auto = ['title' => 'ASL 선수 기록'];
        foreach (['a', 'b'] as $s) {
            $auto["$s.name"] = pname($ds['players'], $p[$s]['player']);
            if (!($ds['asl']['available'] ?? false)) {
                continue; // ASL 기록을 쓸 수 없음 → 이름만 (수치는 운영자가 확인해 입력)
            }
            // ASL 기록에 없는 선수는 0으로 채우고 verify에서 막는다 (이름 표기가 다를 수 있어 운영자가 확인)
            $r = stats_asl_record($ds['asl'], $p[$s]['player']);
            $auto += ["$s.titles" => $r['titles'], "$s.runner" => $r['runner'], "$s.semis" => $r['semis'],
                "$s.wins" => $r['wins'], "$s.losses" => $r['losses']];
        }
        return $auto;
    },
    'verify' => static fn(array $p, array $ds): array => array_merge(
        verify_asl($ds, $p['a']['player'], ['a.titles', 'a.runner', 'a.semis', 'a.wins', 'a.losses'], ['a.titles', 'a.runner']),
        verify_asl($ds, $p['b']['player'], ['b.titles', 'b.runner', 'b.semis', 'b.wins', 'b.losses'], ['b.titles', 'b.runner'])),
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s / %s · ASL',
        pname($ctx['players'], $p['a']['player']), pname($ctx['players'], $p['b']['player'])),
    'present' => static function (array $f): array {
        $cols = [];
        foreach (['a', 'b'] as $s) {
            $mid = array_filter([hid($f, "$s.runner") ? '' : '준우승 ' . ($f["$s.runner"] ?? '-') . '회',
                hid($f, "$s.semis") ? '' : '4강 이상 ' . ($f["$s.semis"] ?? '-') . '회']);
            $set = text_record_hid($f, "$s.wins", "$s.losses");
            $rate = hid($f, "$s.rate") ? '' : text_rate_paren($f["$s.rate"]);
            $cols[] = ['name' => (string)$f["$s.name"], 'rate' => hid($f, "$s.titles") ? '' : '우승 ' . ($f["$s.titles"] ?? '-') . '회',
                'count' => implode(' · ', $mid), 'detail' => trim(($set === '' ? '' : "세트 $set") . ' ' . $rate)];
        }
        return ['title' => (string)$f['title'], 'cols' => $cols];
    },
];
