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
    $results = [['Winner', 'Race', 'Loser', 'Race', 'Map', 'Date']];
    $sets = [];      // 선수 => [all, P, T, Z] 각 [W, L]
    $lists = [];     // 선수 => 끝장전 목록
    $race = [];
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
            $results[] = [$w, $race[$w], $l, $race[$l], 'Map ' . ($i + 1), $d];
            foreach ([[$w, 0, $race[$l]], [$l, 1, $race[$w]]] as [$p, $k, $vs]) {
                $sets[$p]['all'][$k] = ($sets[$p]['all'][$k] ?? 0) + 1;
                $sets[$p][$vs][$k] = ($sets[$p][$vs][$k] ?? 0) + 1;
            }
            $turn = $turn === $a ? $b : $a;
        }
        $lists[$a][] = [$d, $a, $ar, $b, $br, $aw, $bw];
        $lists[$b][] = [$d, $b, $br, $a, $ar, $bw, $aw];
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
    $t = ['results' => $results, 'players' => $players, 'matches' => $matchList, 'predictions' => $pred];
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
    // 다승 순위는 전체 세트 전적(일치)을 쓰므로 막지 않는다
    $st = type_state('win-ranking', ['race' => '', 'count' => '3']);
    assert_same([], $st['problems']);
    // 전체 세트 전적이 다르면 그 선수 행과 순위 칸을 막는다
    setup_sheet(function (array &$t) {
        foreach ($t['players'] as &$row) {
            if (($row[1] ?? '') === '라선수') {
                $row[3] = 2;
            }
        }
    });
    $st = type_state('win-ranking', ['race' => '', 'count' => '3']);
    assert_same(1, count($st['problems']), '라선수는 3위 밖이라 행 칸은 해당 없음, 순위 칸만');
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
    $st = type_state('win-ranking', ['race' => '', 'count' => '4']);
    assert_same([], $st['problems']);
    assert_same(['가선수', '나선수', '다선수', '라선수'], array_column($st['view']['rows'], 'name'));
    assert_same(['16W 11L', ''], [$st['view']['rows'][0]['record'], $st['view']['rows'][0]['nick']]);
    player_info_save(['player' => '가선수', 'nickname' => 'Ga'], op());
    $st = instance_state(instance_get(channel_get('preview')['instance_id']), current_session_id());
    assert_same('Ga', $st['view']['rows'][0]['nick'], '닉네임 저장 후 AUTO 반영 (네트워크 없이)');
    assert_throws(ActionError::class, fn() => player_info_save(['player' => '없는선수', 'nickname' => 'x'], op()), 'NO_PLAYER');
    // 연승: 다선수는 이상 경기가 있어 순위·기록 칸이 막힌다
    $st = type_state('win-streak', ['race' => '', 'count' => '3']);
    assert_true((bool)array_filter($st['problems'], fn($p) => str_contains($p, '순위 확인 불가')));
});

test('sheet: 온라인·더블 찬스는 자동값 없음 → 직접 입력 전 송출 불가, MOCK 선수 페이지는 막힘', function () {
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
    $st = type_state('double-chance', ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수']]);
    assert_true((bool)$st['problems']);
    assert_same(false, panel_state(op())['source']['id'] === 'mock');
});
