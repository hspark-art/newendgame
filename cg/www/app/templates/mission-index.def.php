<?php
declare(strict_types=1);

/*
 * #10 중계진 미션 성공 지수 순위 — 예측마다 건 갯수를 성공이면 더하고 실패면 뺀 "지수", 수익률 = 지수 ÷ 건 갯수 합계.
 * 2026-10-01 실제 시트로 확인: 예측 탭 순위표의 지수·수익률과 3명 모두 일치. 순위는 지수 순.
 * 화면: "+12,609개" / "+8.6%" (수익률 + 빨강, − 파랑). 자리 순서는 승자 예측 순위와 같은 방식.
 */
return [
    'slug' => 'mission-index',
    'name' => '미션 성공 지수',
    'short' => '미션지수',
    'order' => 10,
    'params' => [
        ['key' => 'year', 'label' => '연도', 'type' => 'year'],
        ['key' => 'seats', 'label' => '자리 순서 (비우면 순위순)', 'type' => 'predictor_slots', 'max' => 5],
    ],
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + row_fields(5, [
        'rank' => ['label' => '순위', 'type' => 'int', 'max' => 999],
        'name' => ['label' => '이름', 'type' => 'text', 'max' => 12],
        'index' => ['label' => '지수(개)', 'type' => 'sint'],
        'roi' => ['label' => '수익률', 'type' => 'srate'],
    ]),
    'auto' => static function (array $p, array $ds): array {
        $auto = ['title' => $p['year'] . ' 중계진 미션 성공 지수 순위'];
        $ranking = [];
        foreach (stats_mission_ranking($ds['predictions'], $p['year']) as $r) {
            $ranking[$r['predictor']] = $r;
        }
        $order = $p['seats'] ?: array_keys($ranking);
        foreach (array_slice($order, 0, 5) as $i => $id) {
            $n = $i + 1;
            $r = $ranking[$id] ?? null; // 그해 기록이 없으면 순위·지수는 비움
            $auto += ["r$n.rank" => $r['rank'] ?? null, "r$n.name" => $ds['predictors'][$id]['name'] ?? $id,
                "r$n.index" => $r['index'] ?? null, "r$n.roi" => $r['roi'] ?? null];
        }
        return $auto;
    },
    'verify' => static function (array $p, array $ds): array {
        $ranking = stats_mission_ranking($ds['predictions'], $p['year']);
        $issues = verify_population($ds, 'mission', array_column($ranking, 'predictor'), row_keys(['rank', 'name', 'index', 'roi']));
        foreach (array_slice($p['seats'] ?: array_column($ranking, 'predictor'), 0, 5) as $i => $id) {
            $n = $i + 1;
            $issues = array_merge($issues, verify_mission($ds, (string)$id, ["r$n.rank", "r$n.name", "r$n.index", "r$n.roi"]));
        }
        return $issues;
    },
    'summary' => static fn(array $p, array $ctx): string => $p['year'] . ' · ' . ($p['seats']
        ? '자리 순서: ' . implode(', ', array_map(static fn($id) => $ctx['predictors'][$id]['name'] ?? $id, $p['seats']))
        : '순위순'),
    'present' => static function (array $f): array {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $r = row_visible($f, $i, ['rank', 'name', 'index', 'roi'], 'name');
            if ($r === null) {
                continue;
            }
            $sign = static fn(?int $v) => $v === null ? '' : ($v > 0 ? 'plus' : ($v < 0 ? 'minus' : ''));
            $rows[] = ['rank' => hid($f, "r$i.rank") ? '' : ($r['rank'] === null ? '-' : (string)$r['rank']), 'name' => (string)$r['name'],
                'index' => hid($f, "r$i.index") ? '' : ($r['index'] === null ? '기록 없음' : ($r['index'] > 0 ? '+' : '') . number_format($r['index']) . '개'),
                'roi' => hid($f, "r$i.roi") ? '' : ($r['roi'] === null ? '—' : ($r['roi'] > 0 ? '+' : '') . fmt_srate($r['roi']) . '%'),
                'index_sign' => $sign($r['index']), 'roi_sign' => $sign($r['roi']),
                'top' => $r['rank'] === 1 || ($r['rank'] === null && hid($f, "r$i.rank") && $i === 1)];
        }
        return ['title' => (string)$f['title'], 'rows' => $rows];
    },
];
