<?php
declare(strict_types=1);

/*
 * #5 중계진 승자 예측 순위 — 끝장전마다 예측한 승자가 맞았는지 (1경기 = 예측 1건). 순위는 적중률.
 * 레퍼런스 05는 표시 순서가 1→3→2 (좌석 순서로 추정, NEEDS CONFIRMATION) → "자리 순서"를 고르면 그 순서로 표시하고,
 * 비워 두면 순위순으로 표시한다.
 */
return [
    'slug' => 'prediction-ranking',
    'name' => '승자 예측 순위',
    'short' => '예측',
    'order' => 5,
    'params' => [
        ['key' => 'year', 'label' => '연도', 'type' => 'year'],
        ['key' => 'seats', 'label' => '자리 순서 (비우면 순위순)', 'type' => 'predictor_slots', 'max' => 5],
    ],
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + row_fields(5, [
        'rank' => ['label' => '순위', 'type' => 'int', 'max' => 999],
        'name' => ['label' => '이름', 'type' => 'text', 'max' => 12],
        'wins' => ['label' => '적중', 'type' => 'int'],
        'losses' => ['label' => '실패', 'type' => 'int'],
        'rate' => ['label' => '적중률', 'type' => 'rate', 'derived' => ['wins', 'losses']],
    ]),
    'auto' => static function (array $p, array $ds): array {
        $auto = ['title' => $p['year'] . ' 중계진 승자 예측 순위'];
        $ranking = [];
        foreach (stats_prediction_ranking($ds['predictions'], $p['year']) as $r) {
            $ranking[$r['predictor']] = $r;
        }
        $order = $p['seats'] ?: array_keys($ranking);
        foreach (array_slice($order, 0, 5) as $i => $id) {
            $n = $i + 1;
            $r = $ranking[$id] ?? null; // 그해 예측 기록이 없으면 순위·기록은 비움
            $auto += ["r$n.rank" => $r['rank'] ?? null, "r$n.name" => $ds['predictors'][$id]['name'] ?? $id,
                "r$n.wins" => $r['correct'] ?? null, "r$n.losses" => $r['wrong'] ?? null];
        }
        return $auto;
    },
    'verify' => static function (array $p, array $ds): array {
        $ranking = stats_prediction_ranking($ds['predictions'], $p['year']);
        $issues = verify_population($ds, 'predictions', array_column($ranking, 'predictor'), row_keys(['rank', 'name', 'wins', 'losses']));
        foreach (array_slice($p['seats'] ?: array_column($ranking, 'predictor'), 0, 5) as $i => $id) {
            $n = $i + 1;
            $issues = array_merge($issues, verify_predictor($ds, (string)$id, ["r$n.rank", "r$n.name", "r$n.wins", "r$n.losses"]));
        }
        return $issues;
    },
    'summary' => static fn(array $p, array $ctx): string => $p['year'] . ' · ' . ($p['seats']
        ? '자리 순서: ' . implode(', ', array_map(static fn($id) => $ctx['predictors'][$id]['name'] ?? $id, $p['seats']))
        : '순위순'),
    'present' => static function (array $f): array {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $r = row_values($f, $i, ['rank', 'name', 'wins', 'losses', 'rate']);
            if ($r !== null && $r['name'] !== null) {
                $rows[] = ['rank' => $r['rank'] === null ? '-' : (string)$r['rank'], 'name' => $r['name'],
                    'record' => $r['wins'] === null ? '기록 없음' : sprintf('%sW %sL', $r['wins'], $r['losses'] ?? '-'),
                    'rate' => text_pct($r['rate']), 'top' => $r['rank'] === 1];
            }
        }
        return ['title' => (string)$f['title'], 'rows' => $rows];
    },
];
