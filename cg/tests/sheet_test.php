<?php
declare(strict_types=1);

// Google 시트 데이터 → 세트·끝장전·검증·송출 차단 (합성 데이터. 실제 시트 내용은 저장소에 넣지 않는다)

/** 합성 끝장전: [날짜, A, A종족, B, B종족, A세트, B세트]. A가 첫 세트 승자 */
function fx_match_list(): array
{
    return [
        ['2024-01-06', '가선수', 'Z', '나선수', 'P', 5, 4],
        ['2024-02-03', '가선수', 'Z', '다선수', 'T', 7, 2],
        ['2024-03-02', '다선수', 'T', '나선수', 'P', 6, 3],
        ['2024-04-06', '나선수', 'P', '가선수', 'Z', 5, 4],
        ['2025-01-04', '다선수', 'T', '라선수', 'Z', 3, 1], // 이상: 4세트
    ];
}

/** 시트 탭 모양의 합성 표. $tamper로 검증 탭 값을 일부러 틀리게 만들 수 있다 */
function fx_tables(?callable $tamper = null): array
{
    $results = [['Winner', 'Race', 'Loser', 'Race', 'Map', 'Date', 'Prize', 'Double Chance']];
    $sets = [];      // 선수 => [all, P, T, Z] 각 [W, L]
    $lists = [];     // 선수 => 끝장전 목록
    $race = [];
    $dcWon = [];     // "선수|날짜" => 더블 찬스 세트 승리 수
    $dcMatches = []; // 선수 => 경기 수 (시도 = 경기 × 2)
    foreach (fx_match_list() as [$d, $a, $ar, $b, $br, $aw, $bw]) {
        $race[$a] = $ar;
        $race[$b] = $br;
        $left = [$a => $aw, $b => $bw];
        $turn = $a;
        for ($i = 0; $i < $aw + $bw; $i++) {
            if ($left[$turn] === 0) {
                $turn = $turn === $a ? $b : $a;
            }
            $w = $turn;
            $l = $w === $a ? $b : $a;
            $left[$w]--;
            // 2·4세트를 더블 찬스 세트로 (H열 금액 > 0). 상금 열(G) 값은 프로그램이 읽지 않는다
            $dc = in_array($i, [1, 3], true);
            $results[] = [$w, $race[$w], $l, $race[$l], 'Map ' . ($i + 1), $d, 100000, $dc ? 100000 : 0];
            if ($dc) {
                $dcWon["$w|$d"] = ($dcWon["$w|$d"] ?? 0) + 1;
            }
            foreach ([[$w, 0, $race[$l]], [$l, 1, $race[$w]]] as [$p, $k, $vs]) {
                $sets[$p]['all'][$k] = ($sets[$p]['all'][$k] ?? 0) + 1;
                $sets[$p][$vs][$k] = ($sets[$p][$vs][$k] ?? 0) + 1;
            }
            $turn = $turn === $a ? $b : $a;
        }
        $lists[$a][] = [$d, $a, $ar, $b, $br, $aw, $bw];
        $lists[$b][] = [$d, $b, $br, $a, $ar, $bw, $aw];
        $dcMatches[$a] = ($dcMatches[$a] ?? 0) + 1;
        $dcMatches[$b] = ($dcMatches[$b] ?? 0) + 1;
    }
    // 상금 보정: 나선수 2024-01-06 경기의 더블 찬스 횟수를 1로 보정 (Results로 세면 2회. 시트 집계는 보정값을 쓴다)
    $adjust = [['날짜', '선수명', '기본 상금', '더블 찬스 상금', '더블 찬스 횟수', '메모'],
        ['2024-01-06', '나선수', 500000, 0, 1, '기존 시트 값 유지']];
    $dcWon['나선수|2024-01-06'] = 1;
    $stats = [['', '', '', '⚡ 끝장전 선수별 누적 통계'], [], ['선수 정보', '', '', '📊 매치 성적'],
        ['#', '선수명', '종족', '매치 승', '매치 패', '매치 승률', '세트 승', '세트 패', '세트 승률', '기본 상금', "더블 찬스\n상금",
            '합계 상금', "더블 성공\n횟수", "더블 시도\n(총매치)", "더블\n성공률"]];
    $n = 0;
    foreach ($dcMatches as $p => $cnt) {
        $succ = 0;
        foreach ($lists[$p] as [$d]) {
            $succ += $dcWon["$p|$d"] ?? 0;
        }
        $stats[] = [++$n, $p, $race[$p], 0, 0, 0, 0, 0, 0, 999, 999, 999, $succ, $cnt * 2, $succ / ($cnt * 2)];
    }
    $players = [['Player', '', '', 'vs All', '', '', 'vs Zerg', '', '', 'vs Terran', '', '', 'vs Protoss', '', ''],
        ['#', 'ID', 'Race', 'W', 'L', '%', 'W', 'L', '%', 'W', 'L', '%', 'W', 'L', '%']];
    $n = 0;
    foreach ($sets as $p => $s) {
        $wl = static fn($k) => [$s[$k][0] ?? 0, $s[$k][1] ?? 0];
        $players[] = [++$n, $p, $race[$p], ...$wl('all'), 'x', ...$wl('Z'), 'x', ...$wl('T'), 'x', ...$wl('P'), 'x'];
    }
    $matchList = [['출전 날짜', '요일', '출전 선수', "출전 선수\n종족", '상대 선수', "상대 선수\n종족", '승(세트)', '패(세트)', '승패']];
    foreach ($lists as $p => $rows) {
        $matchList[] = ["▶ $p  ({$race[$p]}종족)"];
        foreach ($rows as [$d, $me, $mr, $op, $or, $w, $l]) {
            $matchList[] = [$d, 'Saturday', $me, $mr, $op, $or, $w, $l, $w > $l ? '승' : '패'];
        }
    }
    $pred = [['2026 끝장전 중계진 승자 예측 정리'],
        ['날짜', '선수1', '선수2', '세트', '맵', '갯수', '중계진', '선택', '성공/실패', '', '순위', '이름', '전체', '승', '승률', '지수', '수익률']];
    $recs = [['김중계 캐스터', '성공'], ['김중계 캐스터', '성공'], ['이해설 해설', '실패'], ['김중계 캐스터', '실패'],
        ['이해설 해설', '성공'], ['김중계 캐스터', '성공'], ['이해설 해설', '실패'], ['김중계 캐스터', '']];
    $rank = [[1, '김중계', 4, 3], [2, '이해설', 3, 1]];
    foreach ($recs as $i => [$who, $res]) {
        $row = ['2026-01-' . sprintf('%02d', 5 + $i), '가선수(Z)', '나선수(P)', 'SET ' . ($i + 1), 'Map', 100, $who, '가선수(Z)', $res, ''];
        if (isset($rank[$i])) {
            $row = array_merge($row, $rank[$i], ['75%', '+1', '+1%']);
        }
        $pred[] = $row;
    }
    // 실제 시트처럼 순위표 아래 빈 행 뒤에 다른 표("SET별 성공률")가 이어진다 — 순위표로 읽으면 안 됨
    $pred[] = array_merge(array_fill(0, 11, ''), ['SET별 성공률']);
    $pred[] = array_merge(array_fill(0, 11, ''), [1, 19, 0.4211]);
    // 닉네임 탭 (선택): 나선수만 입력, 다선수는 닉네임 칸이 비어 있음
    $nicks = [['선수명', '닉네임'], ['나선수', 'Na'], ['다선수', '']];
    $t = ['results' => $results, 'players' => $players, 'matches' => $matchList, 'predictions' => $pred,
        'adjust' => $adjust, 'stats' => $stats, 'nicks' => $nicks];
    if ($tamper) {
        $tamper($t);
    }
    return $t;
}

/** 시트 데이터를 소스로 반영한 새 DB */
function setup_sheet(?callable $tamper = null): array
{
    fresh_db();
    setting_set('data_source', 'sheet');
    $ds = sheet_dataset(fx_tables($tamper), 'api');
    data_refresh(op(), $ds);
    return $ds;
}

test('sheet: 세트 → 끝장전 묶기, 9세트가 아닌 경기는 이상 사례로 통계에서 제외', function () {
    $ds = sheet_dataset(fx_tables(), 'api');
    assert_same(40, count($ds['games']));
    assert_same(5, count($ds['matches_all']));
    assert_same(4, count($ds['matches']), '이상 경기 제외');
    $m = $ds['matches'][0];
    assert_same(['2024-01-06', '가선수', '나선수', 'Z', 'P', 5, 4, 9], [$m['date'], $m['playerA'], $m['playerB'], $m['raceA'],
        $m['raceB'], $m['scoreA'], $m['scoreB'], $m['bestOf']]);
    assert_same(1, count($ds['check']['anomalies']));
    assert_true(str_contains($ds['check']['anomalies'][0]['text'], '세트 수 4개'));
    // 세트 통계에는 이상 경기 세트도 들어간다 (세트는 확실한 기록)
    assert_same(['wins' => 6, 'losses' => 3], stats_race_sets($ds['games'], '다선수', 'P'));
    assert_same(['wins' => 5, 'losses' => 8], stats_race_sets($ds['games'], '다선수', 'Z'), '이상 경기 세트 포함');
    assert_same(['wins' => 9, 'losses' => 9], stats_race_sets($ds['games'], '가선수', 'P'));
    assert_same(['Z', 'P', 'T', 'Z'], array_column(array_values($ds['players']), 'race'));
    // 검증: 세트는 모두 일치, 끝장전은 이상 경기가 있는 선수(다선수·라선수)만 확실하지 않음
    assert_same([], $ds['check']['mismatches']);
    assert_same(true, $ds['verify']['sets']['players']['가선수']['P']);
    assert_same([true, true, false, false], [$ds['verify']['matches']['players']['가선수'], $ds['verify']['matches']['players']['나선수'],
        $ds['verify']['matches']['players']['다선수'], $ds['verify']['matches']['players']['라선수']]);
    // 예측: 직책 떼기, 결과 입력 전 건너뛰기, 순위표 대조
    assert_same(['김중계', '이해설'], array_keys($ds['predictors']));
    assert_same(7, count($ds['predictions']));
    assert_same(['김중계' => true, '이해설' => true], $ds['verify']['predictions']['predictors']);
    assert_same(['2026'], stats_prediction_years($ds['predictions']));
});

test('sheet: 형식 오류는 새로고침 실패 → 마지막 정상 데이터·PROGRAM 유지', function () {
    $bad = static fn(array $patch) => function (array &$t) use ($patch) {
        $t['results'][3] = array_replace($t['results'][3], $patch);
    };
    foreach ([[1 => 'X'], [5 => '2024-13-01'], [5 => '01/06/2024'], [0 => ''], [2 => '가선수', 0 => '가선수']] as $patch) {
        $e = assert_throws(ProviderError::class, fn() => sheet_dataset(fx_tables($bad($patch)), 'api'));
        assert_true(str_contains(implode(' ', $e->problems), 'Results 4행'), '행 번호 안내: ' . implode(' ', $e->problems));
    }
    assert_throws(ProviderError::class, fn() => sheet_dataset(['results' => [['Win', 'R']]], 'api'), '머리글 없음');
    // 허용하는 날짜 형식 (연도가 앞)
    foreach (['2024. 1. 6', '2024/01/06', '2024.01.06'] as $d) {
        assert_same('2024-01-06', date_norm($d));
    }
    assert_same(null, date_norm('06-01-2024'));
    assert_same('2019-05-14', date_norm(43599, true), 'xlsx 일련번호');
    assert_same(null, date_norm(43599), '일련번호는 xlsx에서만');

    setup_sheet();
    page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => '가선수', 'vs' => 'P'], 'b' => ['player' => '나선수', 'vs' => 'Z']]], op());
    take_now();
    $snap = program_json();
    $e = assert_throws(ActionError::class, fn() => data_refresh(op(), null), 'SOURCE_ERROR'); // 키·주소 없음
    assert_true(str_contains($e->getMessage(), '마지막 정상 데이터를 유지'));
    assert_same($snap, program_json());
    assert_true(source_status()['stale'], 'STALE 표시');
    assert_same('9', pv_field('a.wins')['final_text'], '마지막 정상 AUTO 유지');
    assert_true(dataset_cache_get('sheet') !== null, '캐시 유지');
});

test('sheet: 시트 집계와 다르면 그 수치를 쓰는 CG만 송출 차단, 직접 입력하면 송출 가능', function () {
    // Players 탭에서 가선수 vs Protoss 승을 틀리게
    $ds = setup_sheet(function (array &$t) {
        foreach ($t['players'] as &$row) {
            if (($row[1] ?? '') === '가선수') {
                $row[12] = 99;
            }
        }
    });
    assert_same(1, count($ds['check']['mismatches']));
    assert_same(['sets', '가선수', '세트 전적 vs P', '99승 9패', '9승 9패'], array_values($ds['check']['mismatches'][0]));
    $r = page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => '가선수', 'vs' => 'P'], 'b' => ['player' => '나선수', 'vs' => 'Z']]], op());
    $problems = panel_state(op())['preview']['problems'];
    assert_same(1, count($problems));
    assert_true(str_contains($problems[0], '가선수 세트 전적(vs P)이 시트 집계와 다릅니다') && str_contains($problems[0], 'A 승, A 패'), $problems[0]);
    assert_throws(ActionError::class, fn() => take_now(), 'NOT_SENDABLE');
    $iid = channel_get('preview')['instance_id'];
    preview_save($iid, ['a.wins' => '9'], op());
    assert_throws(ActionError::class, fn() => take_now(), 'NOT_SENDABLE');
    preview_save($iid, ['a.losses' => '9'], op());
    take_now();
    // 다른 선수·종족 CG는 영향 없음
    page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => '나선수', 'vs' => 'Z'], 'b' => ['player' => '다선수', 'vs' => 'P']]], op());
    cue_page(2);
    assert_same([], panel_state(op())['preview']['problems']);
    // 다승 순위(끝장전 기준)는 세트 전적 불일치와 무관. 확인 안 된 이상 경기(다선수·라선수)가 있으면 막히고, 제외 확정하면 풀린다
    $st = type_state('win-ranking', ['race' => '', 'count' => '3']);
    assert_true(str_contains(implode(' ', $st['problems']), '다선수'), '확인 안 된 이상 경기');
    match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => true], op());
    $st = instance_state(instance_get(channel_get('preview')['instance_id']), current_session_id());
    assert_same([], $st['problems']);
    // 끝장전 목록이 다르면 순위 칸까지 막는다 (라선수는 표시 행에 없어도 순위 후보라서)
    setup_sheet(function (array &$t) {
        foreach ($t['matches'] as &$row) {
            if (($row[2] ?? '') === '라선수') {
                $row[6] = 2;
            }
        }
    });
    match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => true], op());
    $st = type_state('win-ranking', ['race' => '', 'count' => '3']);
    assert_same(1, count($st['problems']), implode(' / ', $st['problems']));
    assert_true(str_contains($st['problems'][0], '순위 확인 불가: 라선수'), $st['problems'][0]);
});

test('sheet: 검증 탭이 없으면 확실하지 않은 것으로 보고 차단, 이상 경기 선수의 끝장전 CG도 차단', function () {
    setup_sheet(function (array &$t) {
        unset($t['players']);
    });
    $st = type_state('race-win-rate', ['a' => ['player' => '가선수', 'vs' => 'P'], 'b' => ['player' => '나선수', 'vs' => 'Z']]);
    assert_true(str_contains(implode(' ', $st['problems']), '대조할 수 없습니다'));
    $check = data_check_view();
    assert_true(str_contains(implode(' ', $check['unavailable']), 'Players 탭 없음'));
    // 끝장전 목록은 정상 → 가선수 맞대결은 송출 가능, 다선수(이상 경기)는 차단
    $st = type_state('head-to-head', ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수']]);
    assert_same([], $st['problems']);
    assert_same('1 : 1', $st['view']['summary']);
    $st = type_state('full-set', ['a' => ['player' => '가선수'], 'b' => ['player' => '다선수']]);
    assert_true(str_contains(implode(' ', $st['problems']), '다선수 끝장전 기록이 시트 집계와 다르거나 이상 경기가 있습니다'));
    assert_same([], array_filter($st['problems'], fn($p) => str_contains($p, '가선수')), '가선수 쪽은 문제 없음');
});

test('sheet: 끝장전 목록이 다르면 차단, 예측 탭 오류는 예측 CG만 막음', function () {
    $ds = setup_sheet(function (array &$t) {
        foreach ($t['matches'] as &$row) {
            if (($row[2] ?? '') === '나선수' && ($row[0] ?? '') === '2024-01-06') {
                $row[6] = 5;
                $row[7] = 4; // 나선수 입장에서 5:4 승으로 잘못 기록
            }
        }
        $t['predictions'][4][8] = '보류';
    });
    assert_same('matches', $ds['check']['mismatches'][0]['kind']);
    assert_same('나선수', $ds['check']['mismatches'][0]['who']);
    assert_true(str_contains(implode(' ', $ds['check']['unavailable']), '예측 탭 5행'));
    $st = type_state('recent-race', ['player' => '나선수', 'vs' => 'Z']);
    assert_true(str_contains(implode(' ', $st['problems']), '나선수 끝장전 기록'));
    $st = type_state('recent-race', ['player' => '가선수', 'vs' => 'P']);
    assert_same([], $st['problems']);
    assert_same(['2024-01-06', '2024-04-06'], array_column($st['view']['rows'], 'date'));
    $st = type_state('prediction-ranking', ['year' => '2026']);
    assert_true((bool)$st['problems'], '예측 기록 없음 → 표시할 행 없음');
});

test('sheet: 예측 순위·연승·다승이 시트 데이터로 계산됨, 닉네임은 입력한 것만', function () {
    setup_sheet();
    $st = type_state('prediction-ranking', []);
    assert_same('2026 중계진 승자 예측 순위', $st['view']['title']);
    assert_same([['1', '김중계', '3W 1L', '75.0%'], ['2', '이해설', '1W 2L', '33.3%']],
        array_map(fn($r) => [$r['rank'], $r['name'], $r['record'], $r['rate']], $st['view']['rows']));
    // 연승: 다선수는 확인 안 된 이상 경기가 있어 순위·기록 칸이 막힌다
    $st = type_state('win-streak', ['race' => '', 'count' => '3']);
    assert_true((bool)array_filter($st['problems'], fn($p) => str_contains($p, '순위 확인 불가')));
    // 그 경기(4세트)를 끝장전 통계에서 제외 확정하면 다승·연승 모두 송출 가능
    match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => true], op());
    $st = type_state('win-ranking', ['race' => '', 'count' => '4']);
    assert_same([], $st['problems']);
    assert_same(['가선수', '다선수', '나선수'], array_column($st['view']['rows'], 'name'), '끝장전 승수 → 같은 승수는 패가 적은 순');
    assert_same(['1st', '2nd', '2nd'], array_column($st['view']['rows'], 'rank'));
    assert_same(['2W 1L', '', '66.7%'], [$st['view']['rows'][0]['record'], $st['view']['rows'][0]['nick'], $st['view']['rows'][0]['rate']]);
    player_info_save(['player' => '가선수', 'nickname' => 'Ga'], op());
    $st = instance_state(instance_get(channel_get('preview')['instance_id']), current_session_id());
    assert_same('Ga', $st['view']['rows'][0]['nick'], '닉네임 저장 후 AUTO 반영 (네트워크 없이)');
    assert_throws(ActionError::class, fn() => player_info_save(['player' => '없는선수', 'nickname' => 'x'], op()), 'NO_PLAYER');
    assert_same([], type_state('win-streak', ['race' => '', 'count' => '3'])['problems']);
});

test('sheet: 온라인은 자동값 없음 → 직접 입력 전 송출 불가, MOCK 선수 페이지는 막힘', function () {
    fresh_db();
    data_refresh(op());
    page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => 'jo-iljang', 'vs' => 'P'], 'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']]], op());
    setting_set('data_source', 'sheet');
    data_refresh(op(), sheet_dataset(fx_tables(), 'api'));
    cue_page(1);
    assert_true(str_contains(implode(' ', panel_state(op())['preview']['problems']), '지금 데이터에 없는 선수'));
    $st = type_state('online-h2h', ['a' => ['player' => '가선수', 'vs' => 'P'], 'b' => ['player' => '나선수', 'vs' => 'Z']]);
    assert_true(count($st['problems']) >= 4, '온라인 수치 없음');
    assert_same('가선수', $st['final']['a.name']);
    assert_same(false, panel_state(op())['source']['id'] === 'mock');
});

test('더블 찬스: A열 승자 + 상금 보정, 패 = 경기당 2회 − 승, 선수별 통계와 대조', function () {
    $ds = sheet_dataset(fx_tables(), 'api');
    // 가: 3경기 중 2024-04-06에 2회 / 나: 2024-01-06 Results로는 2회지만 상금 보정 1회 + 2024-03-02 2회 / 라: 이상 경기(4세트)도 시트처럼 센다
    assert_same(['가선수' => [2, 4], '나선수' => [3, 3], '다선수' => [3, 3], '라선수' => [1, 1]],
        array_map(static fn($d) => [$d['wins'], $d['losses']], $ds['double_chance']));
    assert_same(['가선수' => true, '나선수' => true, '다선수' => true, '라선수' => true], $ds['verify']['double']['players']);
    setup_sheet();
    $st = type_state('double-chance', ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수']]);
    assert_same([], $st['problems']);
    assert_same([['가선수', '2승 4패', '(33.3%)'], ['나선수', '3승 3패', '(50.0%)']],
        array_map(static fn($c) => [$c['name'], $c['record'], $c['rate']], $st['view']['cols']));
    // 상금 보정 탭이 없으면 나선수는 Results대로 4회 → 선수별 통계(3회)와 달라 나선수 칸만 막힘
    $ds = setup_sheet(static function (array &$t) {
        unset($t['adjust']);
    });
    assert_true(str_contains(implode(' ', $ds['check']['unavailable']), '상금 보정 탭 없음'));
    assert_same(['double', '나선수', '6회 중 3회 성공', '6회 중 4회 성공'],
        array_values(array_intersect_key($ds['check']['mismatches'][0], array_flip(['kind', 'who', 'sheet', 'calc']))));
    $st = type_state('double-chance', ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수']]);
    assert_same(1, count($st['problems']));
    assert_true(str_contains($st['problems'][0], '나선수 더블 찬스 기록이 시트 집계와 다릅니다'), $st['problems'][0]);
    $iid = channel_get('preview')['instance_id'];
    preview_save($iid, ['b.wins' => '3', 'b.losses' => '3'], op());
    take_now();
    // 선수별 통계 탭이 없으면 대조 불가 → 두 선수 모두 막힘
    setup_sheet(static function (array &$t) {
        unset($t['stats']);
    });
    $st = type_state('double-chance', ['a' => ['player' => '가선수'], 'b' => ['player' => '다선수']]);
    assert_same(2, count($st['problems']));
    assert_true(str_contains($st['problems'][0], '대조할 수 없습니다'));
    // H열 값을 읽을 수 없으면 더블 찬스 전체를 쓰지 않음 (다른 CG는 영향 없음)
    $ds = setup_sheet(static function (array &$t) {
        $t['results'][5][7] = '확인 중';
    });
    assert_same([], $ds['double_chance']);
    assert_true(str_contains(implode(' ', $ds['check']['unavailable']), 'Results 6행 Double Chance 값을 읽을 수 없음'));
    assert_true((bool)type_state('double-chance', ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수']])['problems']);
    assert_same([], type_state('head-to-head', ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수']])['problems']);
    // 금액 문자열("100,000원")도 읽는다. 상금 보정 횟수가 2를 넘으면 보정 탭 오류
    $ds = sheet_dataset(fx_tables(static function (array &$t) {
        foreach ($t['results'] as $i => $r) {
            if ($i > 0 && $r[7] > 0) {
                $t['results'][$i][7] = number_format($r[7]) . '원';
            }
        }
    }), 'api');
    assert_same(true, $ds['verify']['double']['players']['나선수']);
    $ds = sheet_dataset(fx_tables(static function (array &$t) {
        $t['adjust'][1][4] = 3;
    }), 'api');
    assert_true(str_contains(implode(' ', $ds['check']['unavailable']), '상금 보정 2행을 읽을 수 없음'));
    // 한 경기에서 더블 찬스 성공이 2회를 넘으면 그 선수는 확인 필요 (검증 실패)
    $g = static fn(string $w, string $l, bool $dc) => ['date' => '2024-05-05', 'winner' => $w, 'loser' => $l, 'dc' => $dc];
    $dcs = sheet_double_chance([$g('가', '나', true), $g('가', '나', true), $g('가', '나', true), $g('나', '가', false)],
        [['date' => '2024-05-05', 'playerA' => '가', 'playerB' => '나']], []);
    assert_same(['wins' => 2, 'losses' => 0, 'bad' => true], $dcs['가']);
    assert_same(['wins' => 0, 'losses' => 2, 'bad' => false], $dcs['나']);
});

test('검토 반영: 표시되는 칸(제목·이름)까지 막음, 예측 탭 오류 시 자리 순서 행 차단', function () {
    setup_sheet();
    // 맞대결: 다선수(이상 경기 있음)와의 맞대결은 행·승수를 입력해도 제목(몇 번째)까지 확인해야 송출
    $st = type_state('head-to-head', ['a' => ['player' => '가선수'], 'b' => ['player' => '다선수']]);
    $iid = channel_get('preview')['instance_id'];
    preview_save($iid, ['a.mw' => '1', 'b.mw' => '0', 'r1.date' => '2024-02-03', 'r1.sa' => '7', 'r1.sb' => '2'], op());
    assert_throws(ActionError::class, fn() => take_now(), 'NOT_SENDABLE');
    assert_true(str_contains(implode(' ', panel_state(op())['preview']['problems']), '제목'));
    preview_save($iid, ['title' => '가선수 vs 다선수 끝장전 두 번째 맞대결'], op());
    take_now();
    // 예측: 자리 순서 페이지를 만든 뒤 예측 탭이 깨지면 이름·기록 행 전체가 막힌다
    $r = page_add(['template' => 'prediction-ranking', 'params' => ['year' => '2026', 'seats' => ['이해설', '김중계']]], op());
    data_refresh(op(), sheet_dataset(fx_tables(function (array &$t) {
        $t['predictions'][4][8] = '보류';
    }), 'api'));
    cue_page($r['page_no']);
    $p = implode(' ', panel_state(op())['preview']['problems']);
    assert_true(str_contains($p, '승자 예측을 시트 순위표와 대조할 수 없습니다') && str_contains($p, '1행 이름'), $p);
    assert_throws(ActionError::class, fn() => take_now(), 'NOT_SENDABLE');
});

test('검토 반영: 시트 집계에만 있는 선수·종족 열 불일치·주 종족 미정·숫자 이름', function () {
    // Players 탭에만 있는 마선수 → 불일치 + 다승 순위 전체 행 차단
    $ds = setup_sheet(function (array &$t) {
        $t['players'][] = [9, '마선수', 'T', 30, 1, 'x', 10, 0, 'x', 10, 1, 'x', 10, 0, 'x'];
        $t['matches'][] = ['2024-06-01', 'Saturday', '마선수', 'T', '가선수', 'Z', 5, 4, '승']; // 끝장전 목록에만 있음
        foreach ($t['matches'] as &$row) {
            if (($row[2] ?? '') === '나선수' && ($row[0] ?? '') === '2024-03-02') {
                $row[5] = 'P'; // 상대(다선수) 종족을 틀리게
            }
        }
    });
    $who = array_map(fn($m) => $m['kind'] . ':' . $m['who'], $ds['check']['mismatches']);
    assert_same(['sets:마선수', 'matches:나선수', 'matches:마선수'], $who);
    $st = type_state('win-ranking', ['race' => '', 'count' => '2']);
    assert_true(str_contains(implode(' ', $st['problems']), '시트 집계에만 있는 마선수'));
    assert_true(str_contains(implode(' ', $st['problems']), '1행 이름'), '이름까지 막음');
    // 주 종족을 정하지 못한 선수가 있으면 종족별 순위 확인 불가
    $ds2 = sheet_dataset(fx_tables(), 'api');
    $ds2['players']['라선수']['race'] = null;
    assert_same(1, count(verify_race_known($ds2, 'T', ['r1.rank'])));
    assert_same([], verify_race_known($ds2, null, ['r1.rank']), '전체 종족 순위는 해당 없음');
    // 숫자로만 된 이름: 새로고침·연승 CG가 오류 없이 동작
    $num = static function (array &$t) {
        array_walk_recursive($t, function (&$v) {
            if (is_string($v)) {
                $v = str_replace('다선수', '1004', $v); // 연승 기록이 있는 선수
            }
        });
    };
    setup_sheet($num);
    $st = type_state('win-streak', ['race' => '', 'count' => '4']);
    $iid = channel_get('preview')['instance_id'];
    assert_true(in_array('1004', array_column(instance_get($iid)['auto'] ? [instance_get($iid)['auto']] : [], 'r1.name'), true)
        || in_array('1004', array_values(instance_get($iid)['auto']), true), '숫자 이름 선수가 연승 목록에 나옴');
    data_refresh(op(), sheet_dataset(fx_tables($num), 'api'));
    assert_same('OK', source_status()['status']);
});

test('검토 반영: 닉네임을 지우면 새로고침 뒤에도 사라짐, 마이그레이션 2는 다시 실행해도 안전', function () {
    setup_sheet();
    match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => true], op());
    player_info_save(['player' => '가선수', 'nickname' => 'Ga'], op());
    data_refresh(op(), sheet_dataset(fx_tables(), 'api'));
    player_info_save(['player' => '가선수', 'nickname' => ''], op());
    assert_same(null, dataset_or_null()['players']['가선수']['nickname']);
    $st = type_state('win-ranking', ['race' => '', 'count' => '1']);
    assert_same('', $st['view']['rows'][0]['nick']);
    foreach (migrations()[2] as $step) {
        $step instanceof Closure ? $step() : db()->exec(ddl($step));
    }
    assert_same(1, (int)db_value("SELECT COUNT(*) FROM cg_sources WHERE id = 'sheet'"));
});

test('경기 제외 확정: 9세트가 아닌 경기만, 관리자만, 새로고침 뒤에도 유지, 취소 가능', function () {
    setup_sheet(function (array &$t) {
        // 가선수 vs 나선수 2024-01-06 경기의 마지막 세트를 나선수 저그로 기록 (경기 중 종족 변경)
        foreach ($t['results'] as $i => &$row) {
            if ($i === 9) {
                $row[3] = $row[2] === '나선수' ? 'Z' : $row[3];
                $row[1] = $row[0] === '나선수' ? 'Z' : $row[1];
            }
        }
    });
    $v = data_check_view();
    assert_same(['race', 'sets'], array_values(array_unique(array_column($v['anomalies'], 'sub'))));
    assert_throws(ActionError::class, fn() => match_exclude(['match' => '2024-01-06|가선수|나선수', 'on' => true], op()), 'BAD_MATCH');
    assert_throws(ActionError::class, fn() => match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => true],
        ['name' => '운영', 'role' => 'operator', 'user_id' => 2]), 'ADMIN_ONLY');
    $v = match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => true], op());
    assert_same(1, count($v['excluded']));
    assert_same(['race'], array_column($v['anomalies'], 'sub'), '종족 변경 경기는 시트를 고쳐야 함');
    assert_same(1, panel_state(op())['data']['check']['counts']['excluded']);
    assert_same(1, panel_state(op())['data']['check']['anomalies']);
    data_refresh(op(), sheet_dataset(fx_tables(), 'api'));
    assert_same(1, count(data_check_view()['excluded']), '새로 불러와도 확정 유지');
    assert_same(true, dataset_or_null()['verify']['matches']['players']['라선수']);
    $v = match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => false], op());
    assert_same([], $v['excluded']);
    assert_same(false, dataset_or_null()['verify']['matches']['players']['라선수'], '취소하면 다시 확인 필요');
    assert_true(str_contains(implode(' ', array_column(db_all("SELECT detail FROM cg_logs WHERE action = 'MATCH_EXCLUDE'"), 'detail')), '제외 취소'));
});
