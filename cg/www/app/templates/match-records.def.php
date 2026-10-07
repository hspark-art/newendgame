<?php
declare(strict_types=1);

/*
 * #15 매치 기록 — 매치 시작 전, 출전 선수의 끝장전 기록 중 운영자가 고른 1~3개 (stats_match_records).
 * 줄마다 [선수명 또는 매치명] [큰 숫자 + 단위] [짧은 설명]. 1개면 크게, 2~3개면 줄로. 규격은 다른 CG와 같은 560×250.
 * 경기일 이전에 확정된 끝장전만 계산한다. 이상 경기·시트 첫 기록까지 이어지는 연승 등 확정할 수 없는 기록은
 * 송출을 막고(검증 사유) 이유를 알린다 — 운영자가 확인한 값을 직접 입력하거나 그 줄을 빼면 송출할 수 있다.
 * 선수 끝장전 목록이 시트 자체 집계와 다르면 그 선수 기록도 확인 필요 (stats_records_crosscheck).
 * 기록 후보와 계산 근거(경기 날짜·결과)는 '오늘 매치' 창과 페이지 추가 창에서 본다 (match_records 액션).
 * v0.8: 기록을 1개만 고르면 큰 숫자 아래에 근거 한 줄(마지막 출전·맞대결 날짜와 스코어, 개인 최다 비교 등). 경기 내역 전체는 '기록 상세'(#16).
 */
$rowKeys = static fn(int $n) => ["r$n.name", "r$n.num", "r$n.unit", "r$n.desc", "r$n.note"];

return [
    'slug' => 'match-records',
    'name' => '매치 기록',
    'short' => '기록',
    'order' => 15,
    'params' => [
        ['key' => 'a.player', 'label' => 'A 선수', 'type' => 'player'],
        ['key' => 'b.player', 'label' => 'B 선수', 'type' => 'player'],
        ['key' => 'date', 'label' => '경기일 (이날 이전 경기로 계산)', 'type' => 'date'],
        ['key' => 'records', 'label' => '기록 (최대 3개, 고른 순서대로)', 'type' => 'record_slots', 'max' => 3],
    ],
    'check' => 'check_two_players',
    'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]] + row_fields(3, [
        'name' => ['label' => '이름', 'type' => 'text', 'max' => 20],
        'num' => ['label' => '숫자', 'type' => 'int', 'max' => 99999],
        'unit' => ['label' => '단위', 'type' => 'text', 'max' => 4],
        'desc' => ['label' => '설명', 'type' => 'text', 'max' => 24],
        'note' => ['label' => '근거 한 줄 (기록 1개일 때만 표시)', 'type' => 'text', 'max' => 60],
    ]),
    'auto' => static function (array $p, array $ds): array {
        $r = stats_match_records($ds, $p['a']['player'], $p['b']['player'], $p['date']);
        $auto = ['title' => '이번 매치 주요 기록'];
        $one = count($p['records']) === 1; // 근거 한 줄은 기록 1개일 때만 그린다 → 2~3개면 값도 넣지 않는다
        foreach ($p['records'] as $i => $key) {
            $n = $i + 1;
            $it = $r['items'][$key] ?? null;
            // 기록이 없어져도(조건이 바뀜) 이름은 남겨 검증 사유로 막는다 — 줄이 조용히 사라지지 않게
            $auto += ["r$n.name" => $it['name'] ?? null, "r$n.num" => $it['value'] ?? null, "r$n.unit" => $it['unit'] ?? null,
                "r$n.desc" => $it['desc'] ?? null, "r$n.note" => !$one || ($it['note'] ?? '') === '' ? null : $it['note']];
        }
        return $auto;
    },
    'verify' => static function (array $p, array $ds) use ($rowKeys): array {
        $r = stats_match_records($ds, $p['a']['player'], $p['b']['player'], $p['date']);
        $issues = [];
        foreach ($p['records'] as $i => $key) {
            $it = $r['items'][$key] ?? null;
            if ($it === null || $it['status'] !== 'ok') {
                $issues[] = verify_issue($rowKeys($i + 1), ($it['text'] ?? MATCH_RECORD_KINDS[$key]) . ' — '
                    . ($it === null ? '기록을 계산할 수 없습니다' : ($it['status'] === 'hold' ? '확인 필요: ' : '기록 없음: ') . rtrim($it['reason'], '.')));
            }
            // 시트 집계와의 대조는 stats_records_crosscheck가 상태(확인 필요)에 넣는다 — 후보 목록과 송출 차단이 같은 기준
        }
        return $issues;
    },
    // 이름·설명은 있는데 숫자가 비면(직접 입력으로 검증 사유만 풀고 숫자를 안 넣은 경우) 그 줄이 조용히 빠지지 않게 막는다
    'problems' => static function (array $f, array $hidden, array $p): array {
        $out = [];
        $shown = static fn(string $k) => !in_array($k, $hidden, true);
        foreach (array_keys($p['records']) as $i) {
            $n = $i + 1;
            $text = array_filter(["r$n.name", "r$n.unit", "r$n.desc", "r$n.note"], static fn($k) => $shown($k) && (string)($f[$k] ?? '') !== '');
            if ($text && $shown("r$n.num") && ($f["r$n.num"] ?? null) === null) {
                $out[] = "{$n}행 숫자가 비어 있습니다. 확인한 숫자를 직접 입력하거나 그 줄을 빼세요 (빨간 −).";
            }
        }
        return $out;
    },
    'summary' => static fn(array $p, array $ctx): string => sprintf('%s vs %s · %s · %s', pname($ctx['players'], $p['a']['player']),
        pname($ctx['players'], $p['b']['player']), $p['date'], implode(', ', array_map(static fn($k) => MATCH_RECORD_KINDS[$k] ?? $k, $p['records']))),
    'present' => static function (array $f): array {
        $rows = [];
        for ($i = 1; $i <= 3; $i++) {
            $r = row_visible($f, $i, ['name', 'num', 'unit', 'desc', 'note'], 'num');
            if ($r === null) {
                continue;
            }
            $rows[] = ['name' => (string)$r['name'], 'num' => $r['num'] === null ? '' : number_format($r['num']),
                'unit' => (string)$r['unit'], 'desc' => (string)$r['desc'], 'note' => (string)($r['note'] ?? '')];
        }
        return ['title' => (string)$f['title'], 'rows' => $rows];
    },
];
