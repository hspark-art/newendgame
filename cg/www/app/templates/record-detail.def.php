<?php
declare(strict_types=1);

/*
 * #16 기록 상세 — '매치 기록'(요약)에서 고른 기록 1개의 근거를 보여 주는 CG (stats_match_records의 detail).
 * 위: 제목(선수명 + 기록) · 큰 숫자 + 범위/개인 최다 비교 / 아래: 경기 내역 표 또는 사실 표.
 * - 경기 내역(연승·연패·맞대결 연승·최근 5매치): [YYYY.MM.DD | vs 상대 | 4–5 | 패] 최대 5줄, 스코어는 머리글에 적은 선수 기준.
 *   5경기를 넘으면 1쪽 = 최근 5경기, 2쪽 = 6~10번째 경기 (범위를 큰 숫자 옆에 표시). 끊기기 직전 경기는 마지막 쪽 아래 한 줄.
 * - 사실 표(출전 간격·맞대결 간격): 마지막 경기 날짜·상대(승자)·스코어(이름과 함께)·경과 일수·경기일 이전 상대전적(매치/세트).
 * 폭은 표준 560, 높이는 줄 수만큼. 확정할 수 없는 기록은 '매치 기록'과 같은 기준으로 송출을 막는다.
 */
$rdKeys = static function (): array {
    $k = ['title', 'num', 'unit', 'label', 'best', 'head', 'foot'];
    for ($i = 1; $i <= 5; $i++) {
        array_push($k, "r$i.c1", "r$i.c2", "r$i.c3", "r$i.c4");
    }
    return $k;
};
$isGames = static fn(string $key) => in_array(explode('.', $key)[1] ?? '', ['win_streak', 'loss_streak', 'streak', 'recent'], true);
$isStreak = static fn(string $key) => in_array(explode('.', $key)[1] ?? '', ['win_streak', 'loss_streak', 'streak'], true);

return [
    'slug' => 'record-detail',
    'name' => '기록 상세',
    'short' => '상세',
    'order' => 16,
    'params' => [
        ['key' => 'a.player', 'label' => 'A 선수', 'type' => 'player'],
        ['key' => 'b.player', 'label' => 'B 선수', 'type' => 'player'],
        ['key' => 'date', 'label' => '경기일 (이날 이전 경기로 계산)', 'type' => 'date'],
        ['key' => 'records', 'label' => '기록 (1개)', 'type' => 'record_slots', 'max' => 1],
        ['key' => 'part', 'label' => '쪽 (1 = 최근 5경기, 2 = 6~10번째 — 5경기 넘는 연승·연패만)', 'type' => 'int', 'min' => 1, 'max' => 2, 'default' => 1],
    ],
    'check' => static function (array $p) use ($isStreak): void {
        check_two_players($p);
        if ($p['part'] > 1 && !$isStreak($p['records'][0])) {
            throw new ActionError('BAD_PARAMS', '2쪽(6~10번째 경기)은 연승·연패 기록에만 쓸 수 있습니다.', 422);
        }
    },
    'fields' => [
        'title' => ['label' => '제목', 'type' => 'text', 'max' => 40],
        'num' => ['label' => '숫자', 'type' => 'int', 'max' => 99999],
        'unit' => ['label' => '단위', 'type' => 'text', 'max' => 6],
        'label' => ['label' => '범위·설명', 'type' => 'text', 'max' => 30, 'optional' => true],
        'best' => ['label' => '개인 최다 비교', 'type' => 'text', 'max' => 48, 'optional' => true],
        'head' => ['label' => '스코어 기준 (표 머리글)', 'type' => 'text', 'max' => 20, 'optional' => true],
    ] + row_fields(5, [
        'c1' => ['label' => '날짜·항목', 'type' => 'text', 'max' => 16],
        'c2' => ['label' => '상대·내용', 'type' => 'text', 'max' => 40],
        'c3' => ['label' => '스코어', 'type' => 'text', 'max' => 12],
        'c4' => ['label' => '결과', 'type' => 'text', 'max' => 4],
    ]) + [
        'foot' => ['label' => '아래 한 줄 (끊기기 직전 경기)', 'type' => 'text', 'max' => 60, 'optional' => true],
    ],
    'auto' => static function (array $p, array $ds) use ($isGames): array {
        $key = $p['records'][0];
        $it = stats_match_records($ds, $p['a']['player'], $p['b']['player'], $p['date'])['items'][$key] ?? null;
        $d = $it['detail'] ?? null;
        $auto = ['title' => $d['title'] ?? ($it['text'] ?? MATCH_RECORD_KINDS[$key]), 'num' => $it['value'] ?? null, 'unit' => $it['unit'] ?? null];
        if ($d === null) {
            return $auto; // 기록 없음·확인 필요(값 없음) → 검증 사유로 막는다
        }
        if ($d['type'] === 'facts') {
            $auto['label'] = $it['desc'];
            foreach (array_slice($d['facts'], 0, 5) as $i => [$k, $v]) {
                $auto += ['r' . ($i + 1) . '.c1' => $k, 'r' . ($i + 1) . '.c2' => $v];
            }
            return $auto;
        }
        // 경기 내역: 쪽마다 5경기
        $total = $d['total'];
        $from = ($p['part'] - 1) * 5;
        $games = array_slice($d['games'], $from, 5);
        if (isset($d['record'])) { // 최근 매치 흐름: 큰 숫자 = 승, 단위에 패까지
            $auto['unit'] = '승 ' . ($total - $it['value']) . '패';
            $auto['label'] = "최근 {$total}경기";
        } elseif ($total <= 5) {
            $auto['label'] = ($it['kind'] === 'streak' ? '맞대결 ' : '끝장전 매치 ') . "{$total}경기";
        } else {
            $auto['label'] = ($p['part'] === 1 ? '최근 5경기' : ($from + 1) . '~' . min($from + 5, $total) . '번째 경기') . " · 전체 {$total}경기";
        }
        $auto['head'] = $d['who'] . ' 기준';
        $auto['best'] = $p['part'] === 1 && $d['best'] !== '' ? $d['best'] : null;
        foreach ($games as $i => $g) {
            $n = $i + 1;
            $auto += ["r$n.c1" => $g['date'], "r$n.c2" => 'vs ' . $g['opp'], "r$n.c3" => "{$g['my']}–{$g['their']}", "r$n.c4" => $g['win'] ? '승' : '패'];
        }
        // 끊기기 직전 경기: 가장 오래된 경기가 보이는 쪽에만
        if ($d['break'] !== null && $from + 5 >= $total) {
            $b = $d['break'];
            $auto['foot'] = "{$d['break_label']} · {$b['date']} vs {$b['opp']} {$b['my']}–{$b['their']} " . ($b['win'] ? '승' : '패');
        }
        return $auto;
    },
    'verify' => static function (array $p, array $ds) use ($rdKeys): array {
        $key = $p['records'][0];
        $it = stats_match_records($ds, $p['a']['player'], $p['b']['player'], $p['date'])['items'][$key] ?? null;
        if ($it !== null && $it['status'] === 'ok') {
            return [];
        }
        return [verify_issue($rdKeys(), ($it['text'] ?? MATCH_RECORD_KINDS[$key]) . ' — '
            . ($it === null ? '기록을 계산할 수 없습니다' : ($it['status'] === 'hold' ? '확인 필요: ' : '기록 없음: ') . rtrim($it['reason'], '.')))];
    },
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s vs %s · %s · %s%s', pname($ctx['players'], $p['a']['player']),
        pname($ctx['players'], $p['b']['player']), $p['date'], MATCH_RECORD_KINDS[$p['records'][0]] ?? $p['records'][0],
        $p['part'] > 1 ? ' (6~10번째 경기)' : ''),
    'present' => static function (array $f, array $p) use ($isGames): array {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $r = row_values($f, $i, ['c1', 'c2', 'c3', 'c4']); // 뺀 칸은 비어 있다 — 넷 다 비면 줄 없음
            if ($r !== null) {
                $rows[] = array_map(static fn($v) => (string)$v, $r);
            }
        }
        return ['title' => (string)$f['title'], 'num' => $f['num'] === null ? '' : number_format($f['num']), 'unit' => (string)$f['unit'],
            'label' => (string)($f['label'] ?? ''), 'best' => (string)($f['best'] ?? ''), 'head' => (string)($f['head'] ?? ''),
            'layout' => $isGames($p['records'][0] ?? '') ? 'games' : 'facts', 'rows' => $rows, 'foot' => (string)($f['foot'] ?? '')];
    },
];
