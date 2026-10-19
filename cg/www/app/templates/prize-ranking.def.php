<?php
declare(strict_types=1);

/*
 * #17 총상금 순위 — 선수별 합계 상금(기본 상금 + 더블 찬스 상금) 많은 순, 같은 금액은 공동 순위 (레퍼런스 없음)
 * 시트: 기본 = 이긴 세트의 Results G(Prize) 합, 더블 찬스 = 이긴 세트의 H 금액 합, 상금 보정 탭 행이 있으면 그 날짜 값을 대신
 *   (sheet_data.php sheet_prize). 선수별 통계 탭 J~L과 다르면 송출을 막는다. 진행 중 경기는 끝날 때까지 넣지 않는다.
 * 화면은 다승 순위와 같은 줄 모양·크기 (기록 칸에 "35,200,000원", 승률 칸은 비움).
 */
return [
    'slug' => 'prize-ranking',
    'name' => '총상금 순위',
    'short' => '상금',
    'order' => 17,
    'params' => [
        ['key' => 'count', 'label' => '표시할 인원', 'type' => 'int', 'min' => 1, 'max' => 5, 'default' => 5],
    ],
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + row_fields(5, [
        'rank' => ['label' => '순위', 'type' => 'int', 'max' => 999],
        'name' => ['label' => '이름', 'type' => 'text', 'max' => 12],
        'nick' => ['label' => '닉네임', 'type' => 'text', 'max' => 16],
        'total' => ['label' => '총상금(원)', 'type' => 'int', 'max' => 999999999],
    ]),
    'auto' => static function (array $p, array $ds): array {
        $auto = ['title' => '중계진 스타 끝장전 총상금 순위'];
        foreach (stats_prize_ranking($ds['prize'] ?? [], $ds['players'], $p['count']) as $i => $r) {
            $n = $i + 1;
            $auto += ["r$n.rank" => $r['rank'], "r$n.name" => pname($ds['players'], $r['player']),
                "r$n.nick" => $ds['players'][$r['player']]['nickname'] ?? null, "r$n.total" => $r['total']];
        }
        return $auto;
    },
    'verify' => static function (array $p, array $ds): array {
        $rows = row_keys(['rank', 'name', 'nick', 'total'], $p['count']);
        $issues = verify_population($ds, 'prize', array_keys($ds['prize'] ?? []), $rows);
        foreach (stats_prize_ranking($ds['prize'] ?? [], $ds['players'], $p['count']) as $i => $r) {
            $n = $i + 1;
            $issues = array_merge($issues, verify_prize($ds, $r['player'], ["r$n.rank", "r$n.name", "r$n.total"]));
        }
        return $issues;
    },
    'summary' => static fn(array $p, array $ctx): string => $p['count'] . '명',
    'present' => static function (array $f): array {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $r = row_visible($f, $i, ['rank', 'name', 'nick', 'total'], 'name');
            if ($r !== null) {
                $rows[] = ['rank' => $r['rank'] === null ? '' : english_ordinal($r['rank']), 'name' => (string)$r['name'],
                    'nick' => (string)$r['nick'], 'record' => hid($f, "r$i.total") ? '' : text_won($r['total']), 'rate' => '',
                    'top' => raw_val($f, "r$i.rank") === 1];
            }
        }
        return ['title' => (string)$f['title'], 'rows' => $rows];
    },
];
