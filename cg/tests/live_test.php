<?php
declare(strict_types=1);

// v0.8.1~0.8.2: 경기 중 실시간 수기 입력 — 결과 대기(사전 입력)·입력 중·실제 오류를 구분하고, 시트 집계와는 같은 기준으로 대조

/**
 * 가선수(Z) vs 나선수(P) 경기: 세트 $planned개 행을 미리 만들고(결과 대기 = 승자·패자·종족 빈 칸) 그중 $done개 승자를 입력.
 * Players 탭(세트 전적)·선수별 통계(더블 시도 = 경기 × 2)도 시트 수식처럼 완료 세트만큼 바로 센다
 */
function fx_live(int $done, int $planned = 9, ?string $date = null): callable
{
    $date ??= date('Y-m-d');
    return static function (array &$t) use ($done, $planned, $date) {
        $w = ['가' => 0, '나' => 0];
        for ($i = 0; $i < $planned; $i++) {
            if ($i >= $done) {
                $t['results'][] = ['', '', '', '', 'Map ' . ($i + 1), $date, 100000, 0]; // 결과 대기 (사전 입력)
                continue;
            }
            $t['results'][] = $i % 3 === 2 ? ['나선수', 'P', '가선수', 'Z', '', $date, 0, 0] : ['가선수', 'Z', '나선수', 'P', '', $date, 0, 0];
            $i % 3 === 2 ? $w['나']++ : $w['가']++;
        }
        foreach ($t['players'] as &$r) {
            if (($r[1] ?? '') === '가선수') {
                [$r[3], $r[4], $r[12], $r[13]] = [$r[3] + $w['가'], $r[4] + $w['나'], $r[12] + $w['가'], $r[13] + $w['나']];
            } elseif (($r[1] ?? '') === '나선수') {
                [$r[3], $r[4], $r[6], $r[7]] = [$r[3] + $w['나'], $r[4] + $w['가'], $r[6] + $w['나'], $r[7] + $w['가']];
            }
        }
        unset($r);
        foreach ($t['stats'] as &$r) {
            if ($done > 0 && in_array($r[1] ?? '', ['가선수', '나선수'], true)) {
                $r[13] += 2;
            }
        }
        unset($r);
    };
}

/** 예측 탭 순위표를 실제 시트 수식 기준으로: 전체 = COUNTIF(중계진)(결과 대기 포함), 수익률 = 지수 ÷ SUMIF(중계진, 갯수)(결과 대기 갯수 포함) */
function fx_pred_sheet_formula(array &$t): void
{
    // 합성 예측 탭: 김중계 완료 4(3승, 지수 +200, 갯수 400) + 결과 대기 1행(10행, 갯수 100)
    $t['predictions'][2][12] = 5;
    $t['predictions'][2][16] = 200 / 500;
}

test('결과 대기(예측): 결과 칸이 빈 미리 입력한 예측은 완료 집계에서 빼고, 시트 전체(COUNTIF)와는 같은 기준으로 대조 — 거짓 불일치·송출 차단 없음', function () {
    $ds = sheet_dataset(fx_tables('fx_pred_sheet_formula'), 'api');
    assert_same([], array_filter($ds['check']['mismatches'], static fn($m) => in_array($m['kind'], ['predictions', 'mission'], true)));
    assert_same(['김중계' => true, '이해설' => true], $ds['verify']['predictions']['predictors']);
    assert_same(['김중계' => true, '이해설' => true], $ds['verify']['mission']['predictors']);
    $r = array_column(stats_prediction_ranking($ds['predictions'], '2026'), null, 'predictor')['김중계'];
    assert_same([3, 1], [$r['correct'], $r['wrong']], 'CG 집계 = 완료 4회 중 3회 (결과 대기 제외)');
    $m = array_column(stats_mission_ranking($ds['predictions'], '2026'), null, 'predictor')['김중계'];
    assert_same([200, 400, 500], [$m['index'], $m['staked'], $m['roi']], '미션: 지수·분모 모두 완료 행 (+200 ÷ 400 = 50.0%)');
    assert_same(['승자 예측 김중계: 전체 입력 5 / 완료 4 / 결과 대기·입력 중 1 (시트 \'전체\'는 결과 대기 행도 셉니다 — CG는 완료 기준)',
        '미션 지수 김중계: 완료 기준 +200 · 50.00% (CG) / 시트 +200 · 40.00% — 시트 분모에 결과 대기 갯수 100 포함'], $ds['check']['waiting']);
    // 시트가 완료 행만 세도록 바뀌어도(기존 합성 시트) 일치
    $old = sheet_dataset(fx_tables(), 'api');
    assert_same([true, true], [$old['verify']['predictions']['predictors']['김중계'], $old['verify']['mission']['predictors']['김중계']]);
    // 같은 기준으로 비교해도 다르면 실제 불일치 (전체 6 = 완료 4도, 입력 전체 5도 아님)
    $bad = sheet_dataset(fx_tables(static function (array &$t) {
        fx_pred_sheet_formula($t);
        $t['predictions'][2][12] = 6;
    }), 'api');
    $mis = array_values(array_filter($bad['check']['mismatches'], static fn($m) => $m['kind'] === 'predictions'));
    assert_same([['김중계', '6회 중 3회', '4회 중 3회 (결과 대기·입력 중 포함 시 5회 중 3회)']], array_map(static fn($m) => [$m['who'], $m['sheet'], $m['calc']], $mis));

    // 송출: 승자 예측·미션 지수 CG가 막히지 않음
    setup_sheet('fx_pred_sheet_formula');
    foreach (['prediction-ranking', 'mission-index'] as $slug) {
        $st = type_state($slug, ['year' => '2026', 'seats' => []]);
        assert_same([], $st['problems'], "$slug: " . implode(' / ', $st['problems']));
    }
    assert_same(2, count(json_dec(setting_get('data_check'))['waiting']), '패널·서버 점검에 결과 대기 설명');
});

test('결과 대기(예측): 대기 예측에 결과를 입력하고 새로고침하면 전체·적중·적중률·지수·수익률이 바로 갱신, 이전 캐시 값이 남지 않음', function () {
    setup_sheet('fx_pred_sheet_formula');
    $page = page_add(['template' => 'mission-index', 'params' => ['year' => '2026', 'seats' => []]], op());
    $pr = page_add(['template' => 'prediction-ranking', 'params' => ['year' => '2026', 'seats' => []]], op());
    // 시트에서 10행(김중계, 갯수 100) 결과 '성공' 입력 → 시트 집계: 전체 5, 승 4, 지수 +300, 수익률 300/500
    $done = static function (array &$t) {
        $t['predictions'][9][8] = '성공';
        [$t['predictions'][2][12], $t['predictions'][2][13], $t['predictions'][2][15], $t['predictions'][2][16]] = [5, 4, 300, 300 / 500];
    };
    data_refresh(op(), sheet_dataset(fx_tables($done), 'api'));
    $ds = dataset_or_null();
    $r = array_column(stats_prediction_ranking($ds['predictions'], '2026'), null, 'predictor')['김중계'];
    $m = array_column(stats_mission_ranking($ds['predictions'], '2026'), null, 'predictor')['김중계'];
    assert_same([4, 1, 800, 300, 500, 600], [$r['correct'], $r['wrong'], $r['rate'], $m['index'], $m['staked'], $m['roi']]);
    assert_same([], json_dec(setting_get('data_check'))['waiting'], '결과 대기 설명이 남지 않음');
    assert_same([], $ds['check']['mismatches']);
    foreach ([$page, $pr] as $p) {
        cue_page($p['page_no']);
        $st = instance_state(instance_get(channel_get('preview')['instance_id']), current_session_id());
        assert_same([], $st['problems']);
        assert_true(str_contains(json_encode($st['view'], JSON_UNESCAPED_UNICODE), $p === $page ? '+300' : '80'), json_encode($st['view'], JSON_UNESCAPED_UNICODE));
    }
});

test('결과 대기와 실제 값 구분: 실패·갯수 0은 완료로 집계, 빈 결과는 대기, 참/거짓·두 값·다른 글자는 실제 오류, 갯수만 빈 맨 아래 행은 미션에서만 뺌', function () {
    $row = static fn(string $who, $amt, $res) => ['2026-01-20', '가선수(Z)', '나선수(P)', 'SET 1', 'Map', $amt, $who, '가선수(Z)', $res];
    // 갯수 0 + 실패 = 유효한 완료 기록 (누락하지 않음)
    $ds = sheet_dataset(fx_tables(static function (array &$t) use ($row) {
        $t['predictions'][] = $row('이해설 해설', 0, '실패');
        $t['predictions'][3][12] = 4; // 시트 전체·지수·수익률도 같이: 이해설 4회 중 1회, 지수 −100, 분모 300
    }), 'api');
    $m = array_column(stats_mission_ranking($ds['predictions'], '2026'), null, 'predictor')['이해설'];
    assert_same([4, -100, 300], [count(array_filter($ds['predictions'], static fn($p) => $p['predictor'] === '이해설')), $m['index'], $m['staked']]);
    assert_same(true, $ds['verify']['predictions']['predictors']['이해설']);
    // 허용되지 않은 결과값 → 결과 대기가 아니라 실제 오류 (위치와 관계없이)
    foreach ([false, true, '성공/실패', '적중', 0] as $v) {
        $e = sheet_dataset(fx_tables(static function (array &$t) use ($row, $v) {
            $t['predictions'][] = $row('이해설 해설', 100, $v);
        }), 'api');
        assert_true((bool)array_filter($e['check']['unavailable'], static fn($u) => str_contains($u, '성공/실패 칸 값')),
            var_export($v, true) . ': ' . implode(' / ', $e['check']['unavailable']));
    }
    // 결과는 있고 갯수만 빈 맨 아래 행: 승자 예측에는 넣고 미션 지수에서만 뺀다
    $ds = sheet_dataset(fx_tables(static function (array &$t) use ($row) {
        $t['predictions'][] = $row('이해설 해설', '', '성공');
        [$t['predictions'][3][12], $t['predictions'][3][13]] = [4, 2]; // 시트: 전체 4, 승 2 (지수·분모는 빈 갯수 = 0)
    }), 'api');
    assert_same([true, true], [$ds['verify']['predictions']['predictors']['이해설'], $ds['verify']['mission']['predictors']['이해설']]);
    assert_same([2, 2], array_values(array_intersect_key(array_column(stats_prediction_ranking($ds['predictions'], '2026'), null, 'predictor')['이해설'], ['correct' => 1, 'wrong' => 1])));
    assert_true((bool)array_filter($ds['check']['pending'], static fn($p) => str_contains($p['text'], '갯수 입력 전 — 미션 지수 계산에서만 뺐습니다')));
    // 결과가 있는데 중계진이 빈 맨 아래 행 = 입력 중 (그 행만 뺌), 중간 행이면 이전처럼 예측 CG 사용 불가
    $ds = sheet_dataset(fx_tables(static function (array &$t) use ($row) {
        $t['predictions'][] = $row('', 100, '성공');
    }), 'api');
    assert_same(true, $ds['verify']['predictions']['available']);
    $mid = sheet_dataset(fx_tables(static function (array &$t) {
        $t['predictions'][4][6] = '';
    }), 'api');
    assert_true((bool)array_filter($mid['check']['unavailable'], static fn($u) => str_contains($u, '승자 예측 사용 불가: 예측 탭 5행')));
});

test('진행 중 경기: 9세트를 미리 만들고 7세트 승자만 입력 → 결과 대기 2행, 세트 수 부족 오류·통계 제외 확정·CG 차단 없음, 끝난 경기 전적·연승에는 넣지 않음', function () {
    $ds = dataset_finalize(sheet_dataset(fx_tables(fx_live(7)), 'api'), []);
    assert_same(1, count($ds['check']['anomalies']), '기존 이상 경기(다선수·라선수)만');
    assert_true(str_contains($ds['check']['live'][0]['text'], '가선수 vs 나선수 5:2 — 진행 중 (완료 7세트 · 결과 대기 2행)'), $ds['check']['live'][0]['text'] ?? '');
    assert_same(['wait', 'wait'], array_column($ds['check']['pending'], 'kind'));
    assert_true(str_contains($ds['check']['pending'][0]['text'], '결과 대기 (승자 입력 전)'));
    assert_same([], $ds['check']['mismatches']);
    assert_same([true, true], [$ds['verify']['matches']['players']['가선수'], $ds['verify']['matches']['players']['나선수']]);
    assert_same(4, count($ds['matches']), '끝난 경기 전적에는 넣지 않음');
    assert_same(['wins' => 14, 'losses' => 11], stats_race_sets($ds['games'], '가선수', 'P'), '완료 세트는 세트 통계에 바로');
    assert_same([], array_filter(alert_items($ds), static fn($a) => str_contains($a[2], '가선수 vs 나선수')), '알림 없음');
    // 매치 기록: 진행 중 경기는 날짜와 관계없이 빼고 알림 (연승·연패·맞대결에 넣지 않음)
    $r = stats_match_records($ds, '가선수', '나선수', date('Y-m-d', strtotime('+1 day')));
    assert_true((bool)array_filter($r['notes'], static fn($n) => str_contains($n, '진행 중인 가선수 vs 나선수 경기')), implode(' / ', $r['notes']));
    assert_same('2024.04.06', $r['items']['h.gap']['detail']['facts'][0][1] === '' ? '' : substr($r['items']['h.gap']['detail']['facts'][0][1], 0, 10), '마지막 맞대결 = 끝난 경기');
    // 송출·운영
    setup_sheet(fx_live(7));
    page_add(['template' => 'head-to-head', 'params' => ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수']]], op());
    cue_page(1);
    assert_same([], instance_state(instance_get(channel_get('preview')['instance_id']), current_session_id())['problems']);
    assert_throws(ActionError::class, fn() => match_exclude(['match' => date('Y-m-d') . '|가선수|나선수', 'on' => true], op()), 'BAD_MATCH');
    // 8세트째 입력 중(승자만) → 입력 중 1 + 결과 대기 1, 여전히 진행 중
    $ds = sheet_dataset(fx_tables(static function (array &$t) {
        (fx_live(7))($t);
        $t['results'][count($t['results']) - 2] = ['가선수', 'Z', '', '', 'Map 8', date('Y-m-d'), 100000, 0];
    }), 'api');
    assert_same(['typing', 'wait'], array_column($ds['check']['pending'], 'kind'));
    assert_same(1, count($ds['check']['live']));
});

test('진행 중 경기: 자정을 넘겨도(경기 날짜가 어제·며칠 전) 같은 날짜의 사전 입력 행이 남아 있으면 진행 중 — 날짜·시각으로 판정하지 않음', function () {
    foreach (['-1 day', '-3 day'] as $ago) {
        $ds = dataset_finalize(sheet_dataset(fx_tables(fx_live(7, 9, date('Y-m-d', strtotime($ago)))), 'api'), []);
        assert_same([1, 1, true], [count($ds['check']['live']), count($ds['check']['anomalies']), $ds['verify']['matches']['players']['가선수']], $ago);
    }
    // 9세트를 모두 입력하면 끝난 경기 → 끝장전 통계에
    $ds = dataset_finalize(sheet_dataset(fx_tables(fx_live(9, 9, date('Y-m-d', strtotime('-1 day')))), 'api'), []);
    assert_same([[], 5], [$ds['check']['live'], count($ds['matches'])]);
});

test('실제 오류·과거 확인 필요는 계속 발견: 사전 입력 없는 7세트, 다른 날짜의 사전 입력, 진행 중 종족 변경, 채운 칸의 잘못된 값, 완료 세트 사이 빈 행', function () {
    // 사전 입력 행 없이 7세트 → 이전처럼 세트 수 이상 (확인 필요, 선수 CG 차단)
    $ds = dataset_finalize(sheet_dataset(fx_tables(fx_live(7, 7)), 'api'), []);
    assert_same([[], false], [$ds['check']['live'], $ds['verify']['matches']['players']['가선수']]);
    assert_true((bool)array_filter($ds['check']['anomalies'], static fn($a) => str_contains($a['text'], '가선수 vs 나선수 5:2 — 세트 수 7개')));
    // 과거 미완료 경기(다선수·라선수 4세트) 아래에 다른 날짜(다음 방송)의 사전 입력 행 → 과거 경기는 그대로 이상 경기
    $ds = sheet_dataset(fx_tables(static function (array &$t) {
        for ($i = 0; $i < 9; $i++) {
            $t['results'][] = ['', '', '', '', '', date('Y-m-d'), 0, 0];
        }
    }), 'api');
    assert_same([[], 1, 9], [$ds['check']['live'], count($ds['check']['anomalies']), count($ds['check']['pending'])]);
    // 진행 중 경기 안에서 종족이 바뀜 → 실제 오류(종족 변경)
    $ds = sheet_dataset(fx_tables(static function (array &$t) {
        (fx_live(7))($t);
        $t['results'][count($t['results']) - 3][1] = 'T'; // 7세트 승자 가선수 종족을 T로
    }), 'api');
    assert_true($ds['check']['live'] === [] && (bool)array_filter($ds['check']['anomalies'], static fn($a) => str_contains($a['text'], '경기 중 종족 변경')));
    // 채운 칸의 잘못된 값(종족 X·같은 선수·날짜 형식)은 맨 아래여도 실제 오류 → 새로고침 실패, 마지막 정상 데이터 유지
    foreach ([['가선수', 'X', '나선수', 'P'], ['가선수', 'Z', '가선수', 'Z'], ['가선수', 'Z', '', '', '', '2026/13/40']] as $bad) {
        $e = assert_throws(ProviderError::class, fn() => sheet_dataset(fx_tables(static function (array &$t) use ($bad) {
            (fx_live(7))($t);
            $t['results'][] = $bad + [4 => '', 5 => date('Y-m-d')];
        }), 'api'));
        assert_true(str_contains(implode(' ', $e->problems), '오류'), implode(' ', $e->problems));
    }
    // 완료 세트 사이의 빈 행(지난 기록이 빠짐) → 실패
    $e = assert_throws(ProviderError::class, fn() => sheet_dataset(fx_tables(static function (array &$t) {
        $t['results'][] = ['', '', '', '', '', date('Y-m-d'), 0, 0];
        $t['results'][] = ['가선수', 'Z', '나선수', 'P', '', date('Y-m-d'), 0, 0];
    }), 'api'));
    assert_same(['Results 42행: 결과 대기 (승자 입력 전) — 완료된 세트 사이의 빈 행'], $e->problems);
    // 완료된 정상 경기는 그대로
    $ds = dataset_finalize(sheet_dataset(fx_tables(), 'api'), []);
    assert_same([4, 1, [], []], [count($ds['matches']), count($ds['check']['anomalies']), $ds['check']['live'], $ds['check']['pending']]);
});

test('새로고침 반영: 진행 중 → 세트 입력 → 경기 끝 순서로 새로고침하면 CG·점검 화면이 같은 계산, 이전 값(결과 대기·진행 중 안내)이 남지 않음', function () {
    setup_sheet(fx_live(7));
    page_add(['template' => 'head-to-head', 'params' => ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수']]], op());
    $iid = (int)rundown_rows()[0]['instance_id'];
    $h2h = static fn() => json_encode(instance_state(instance_get($iid), current_session_id())['view'], JSON_UNESCAPED_UNICODE);
    $before = $h2h();
    $chk = json_dec(setting_get('data_check'));
    assert_same([1, 2], [count($chk['live']), count($chk['pending'])]);
    data_refresh(op(), sheet_dataset(fx_tables(fx_live(9)), 'api')); // 9세트 끝
    $chk = json_dec(setting_get('data_check'));
    assert_same([[], []], [$chk['live'], $chk['pending']], '진행 중·결과 대기 안내가 남지 않음');
    assert_same(5, dataset_or_null()['check']['counts']['valid_matches']);
    assert_true($h2h() !== $before, '맞대결 CG에 끝난 경기가 들어감 (같은 데이터로 다시 계산)');
    assert_same('OK', source_status()['status']);
});

test('리뷰 반영(v0.8.2): 더블 찬스는 진행 중 경기를 세지 않음(시트가 세든 안 세든 일치), 완료 갯수 0 + 결과 대기 갯수, 날짜 입력 전 완료 예측 행', function () {
    // 더블 찬스: 진행 중 경기 1세트 입력 → CG 값(끝난 경기)은 그대로. 시트(선수별 통계)가 진행 중 경기를 세어도·안 세어도 일치
    $base = sheet_dataset(fx_tables(), 'api')['double_chance']['가선수'];
    foreach ([true, false] as $sheetCounts) {
        $ds = sheet_dataset(fx_tables(static function (array &$t) use ($sheetCounts) {
            (fx_live(1))($t);
            if (!$sheetCounts) {
                foreach ($t['stats'] as &$r) {
                    if (in_array($r[1] ?? '', ['가선수', '나선수'], true)) {
                        $r[13] -= 2; // fx_live가 더한 시도 2회를 되돌림 = 시트가 진행 중 경기를 아직 세지 않음
                    }
                }
                unset($r);
            }
        }), 'api');
        assert_same($base, $ds['double_chance']['가선수'], '진행 중 경기는 더블 찬스 시도·성공에 넣지 않음');
        assert_same([true, true], [$ds['verify']['double']['players']['가선수'], $ds['verify']['double']['players']['나선수']]);
        assert_same($sheetCounts, (bool)array_filter($ds['check']['waiting'], static fn($w) => str_contains($w, '더블 찬스 가선수')));
    }
    // 완료 갯수 합이 0인 중계진 + 결과 대기 갯수: 시트 +0 · 0.0% = 0 ÷ (0+100) → 일치 (0으로 나누지 않음)
    $row = static fn(string $who, $amt, $res, $date = '2026-01-20') => [$date, '가선수(Z)', '나선수(P)', 'SET 1', 'Map', $amt, $who, '가선수(Z)', $res];
    $ds = sheet_dataset(fx_tables(static function (array &$t) use ($row) {
        $t['predictions'][] = $row('박위원 해설', 0, '실패');
        $t['predictions'][] = $row('박위원 해설', 100, '');
        $t['predictions'][4] = array_replace($t['predictions'][4], [10 => 3, 11 => '박위원', 12 => 2, 13 => 0, 14 => 0, 15 => 0, 16 => 0.0]);
    }), 'api');
    assert_same([true, true], [$ds['verify']['predictions']['predictors']['박위원'], $ds['verify']['mission']['predictors']['박위원']]);
    // 결과는 입력했고 날짜만 입력 전인 맨 아래 행: CG에는 넣지 않고(연도를 모름), 시트 수식 기준으로는 세어 대조 → 차단 없음
    $ds = sheet_dataset(fx_tables(static function (array &$t) use ($row) {
        $t['predictions'][] = $row('이해설 해설', 100, '성공', '');
        [$t['predictions'][3][12], $t['predictions'][3][13], $t['predictions'][3][15], $t['predictions'][3][16]] = [4, 2, 0, 0.0];
    }), 'api');
    assert_same(3, count(array_filter($ds['predictions'], static fn($p) => $p['predictor'] === '이해설')));
    assert_same([true, true], [$ds['verify']['predictions']['predictors']['이해설'], $ds['verify']['mission']['predictors']['이해설']]);
});

test('리뷰 반영(v0.8.2): 사전 입력 행 연결은 같은 날짜·같은 두 선수·9세트 단위만 — 세트가 빠진 끝난 경기는 이상 경기로 남음, 표시 수는 전체', function () {
    // 8세트로 끝난 경기(세트 하나 누락) 아래에 같은 날 다음 경기 9세트 사전 입력 → 8 + 9 = 17 → 진행 중 아님, 이상 경기
    $ds = sheet_dataset(fx_tables(fx_live(8, 17)), 'api');
    assert_same([], $ds['check']['live']);
    assert_true((bool)array_filter($ds['check']['anomalies'], static fn($a) => str_contains($a['text'], '세트 수 8개 (9세트가 아님) · 결과 대기 9행 — 예정 행 17개')),
        json_encode(array_column($ds['check']['anomalies'], 'text'), JSON_UNESCAPED_UNICODE));
    // 진행 중 7세트 + 같은 날 다음 경기 9세트 사전 입력 → 7 + 2 + 9 = 18 → 진행 중
    assert_same(1, count(sheet_dataset(fx_tables(fx_live(7, 18)), 'api')['check']['live']));
    // 다른 두 선수 이름을 적기 시작한 입력 중 행은 이 경기에 연결하지 않음
    $ds = sheet_dataset(fx_tables(static function (array &$t) {
        (fx_live(7, 7))($t);
        $t['results'][] = ['다선수', 'T', '', '', '', date('Y-m-d'), 0, 0];
        $t['results'][] = ['', '', '', '', '', date('Y-m-d'), 0, 0];
    }), 'api');
    assert_same([], $ds['check']['live'], '다선수 행은 연결 안 됨 → 7 + 1 = 8 → 이상 경기');
    // 표시: 결과 대기 행이 많아도 수는 전체, 목록은 탭마다
    setup_sheet(static function (array &$t) {
        (fx_live(7, 25))($t);
        $t['predictions'][] = ['2026-01-20', '가선수(Z)', '나선수(P)', 'SET 1', 'Map', '', '이해설 해설', '가선수(Z)', '실패'];
    });
    $c = json_dec(setting_get('data_check'));
    assert_same(19, $c['pending_count']);
    assert_true(count($c['pending']) === 11 && str_contains(end($c['pending']), '예측 탭'), json_encode($c['pending'], JSON_UNESCAPED_UNICODE));
});

test('리뷰 반영(v0.8.2): MAP 통계(시트 스크립트 값)가 사전 입력 행을 세트 수에 넣어도 거짓 불일치 없음, 안 넣어도 일치', function () {
    $pre = static function (bool $sheetCounts) {
        return static function (array &$t) use ($sheetCounts) {
            $t['results'][] = ['', '', '', '', 'Map 1', date('Y-m-d'), 0, 0]; // 다음 경기 사전 입력 (맵·날짜만)
            $t['results'][] = ['', '', '', '', 'New Map', date('Y-m-d'), 0, 0];
            if ($sheetCounts) {
                foreach ($t['mapstats'] as &$r) {
                    if (($r[1] ?? '') === 'Map 1') {
                        [$r[2], $r[13]] = [$r[2] + 1, date('Y-m-d')];
                    }
                }
                unset($r);
                $t['mapstats'][] = [99, 'New Map', 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, date('Y-m-d'), date('Y-m-d'), 1];
            }
        };
    };
    foreach ([true, false] as $sheetCounts) {
        $ds = sheet_dataset(fx_tables($pre($sheetCounts)), 'api');
        assert_same([], array_filter($ds['check']['mismatches'], static fn($m) => $m['kind'] === 'maps'), $sheetCounts ? '시트가 셈' : '시트가 안 셈');
        assert_same($sheetCounts ? 2 : 0, count(array_filter($ds['check']['waiting'], static fn($w) => str_starts_with($w, '맵 '))));
    }
});
