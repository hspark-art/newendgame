<?php
declare(strict_types=1);

// v0.8.1: 경기 중 실시간 수기 입력 — 입력 중인 맨 아래 행·진행 중 경기 때문에 새로고침 전체가 실패하거나 CG가 막히지 않게

/** 오늘(한국 시간) 가선수(Z) vs 나선수(P) 진행 중 경기 $sets세트(가선수 먼저 승)를 Results 맨 아래에 추가 — 시트 자체 집계 탭도 같이 센다 */
function fx_live(int $sets, ?string $date = null): callable
{
    $date ??= date('Y-m-d');
    return static function (array &$t) use ($sets, $date) {
        $w = ['가' => 0, '나' => 0];
        for ($i = 0; $i < $sets; $i++) {
            $t['results'][] = $i % 3 === 2 ? ['나선수', 'P', '가선수', 'Z', '', $date, 0, 0] : ['가선수', 'Z', '나선수', 'P', '', $date, 0, 0];
            $i % 3 === 2 ? $w['나']++ : $w['가']++;
        }
        foreach ($t['players'] as &$r) { // Players 탭(세트 전적)도 시트 수식처럼 바로 센다
            if (($r[1] ?? '') === '가선수') {
                [$r[3], $r[4], $r[12], $r[13]] = [$r[3] + $w['가'], $r[4] + $w['나'], $r[12] + $w['가'], $r[13] + $w['나']];
            } elseif (($r[1] ?? '') === '나선수') {
                [$r[3], $r[4], $r[6], $r[7]] = [$r[3] + $w['나'], $r[4] + $w['가'], $r[6] + $w['나'], $r[7] + $w['가']];
            }
        }
        unset($r);
        foreach ($t['stats'] as &$r) { // 선수별 통계: 더블 시도 = 경기 수 × 2 (입력 중인 경기도 센다)
            if (in_array($r[1] ?? '', ['가선수', '나선수'], true)) {
                $r[13] += 2;
            }
        }
        unset($r);
    };
}

test('경기 중 입력: Results 맨 아래 입력 중인 행(날짜만·승자만)은 그 행만 빼고 새로고침 성공, 중간 행 오류는 그대로 실패', function () {
    $today = date('Y-m-d');
    $tamper = static function (array &$t) use ($today) {
        $t['results'][] = ['가선수', 'Z', '', '', 'Map 1', $today, '', ''];  // 승자만 입력
        $t['results'][] = ['', '', '', '', '', $today, '', ''];             // 다음 세트 날짜를 미리 입력
    };
    $ds = sheet_dataset(fx_tables($tamper), 'api');
    assert_same(40, count($ds['games']), '정상 세트는 모두 읽음');
    assert_same([42, 43], array_column($ds['check']['pending'], 'row'));
    assert_true(str_contains($ds['check']['pending'][0]['text'], 'Results 42행: 선수 이름, 종족(Z/) 오류 — 입력 중으로 보고 계산에서 뺐습니다'),
        $ds['check']['pending'][0]['text']);
    assert_same([], $ds['check']['mismatches']);
    assert_same([], array_filter(alert_items($ds), static fn($a) => str_contains($a[2], '42행')), '입력 중인 행은 알림 없음');

    // 입력 중인 행 아래에 정상 행이 생기면 그 행은 '중간 행' → 이전처럼 새로고침 실패 (지난 기록의 오류를 덮지 않는다)
    $e = assert_throws(ProviderError::class, fn() => sheet_dataset(fx_tables(static function (array &$t) use ($today) {
        $t['results'][] = ['', '', '', '', '', $today, '', ''];
        $t['results'][] = ['가선수', 'Z', '나선수', 'P', '', $today, 0, 0];
    }), 'api'));
    assert_same(['Results 42행: 선수 이름, 종족(/) 오류'], $e->problems);

    // 새로고침·패널·서버 점검에 '입력 중' 안내 (CG는 막지 않음)
    setup_sheet($tamper);
    $sum = json_dec(setting_get('data_check'));
    assert_same(2, count($sum['pending']));
    assert_same('OK', source_status()['status']);
    page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => '가선수', 'vs' => 'P'], 'b' => ['player' => '나선수', 'vs' => 'Z']]], op());
    cue_page(1);
    assert_same([], instance_state(instance_get(channel_get('preview')['instance_id']), current_session_id())['problems']);
});

test('경기 중 입력: 진행 중 경기(오늘·마지막 입력·9세트 미만)는 이상 경기가 아님 — 끝장전 통계에서만 빼고 CG를 막지 않으며 알림 없음, 세트 통계는 바로 반영', function () {
    $ds = dataset_finalize(sheet_dataset(fx_tables(fx_live(3)), 'api'), []);
    assert_same(1, count($ds['check']['anomalies']), '기존 이상 경기(다선수·라선수)만');
    assert_true(count($ds['check']['live']) === 1 && str_contains($ds['check']['live'][0]['text'], '가선수 vs 나선수 2:1 — 진행 중 (3세트 입력)'),
        json_encode($ds['check']['live'], JSON_UNESCAPED_UNICODE));
    assert_same([], $ds['check']['mismatches'], '시트 집계(끝장전 목록)와 대조할 때 진행 중 경기는 양쪽에서 뺀다');
    assert_same([true, true], [$ds['verify']['matches']['players']['가선수'], $ds['verify']['matches']['players']['나선수']]);
    assert_same(4, count($ds['matches']), '끝장전 통계는 끝난 경기만');
    assert_same(['wins' => 11, 'losses' => 10], stats_race_sets($ds['games'], '가선수', 'P'), '세트 통계는 입력되는 대로');
    assert_same([], array_filter(alert_items($ds), static fn($a) => str_contains($a[2], '진행 중')), '알림 없음');
    // 시트 집계 탭이 입력 중인 경기를 목록에 보여 줘도 대조에서 뺀다
    $ds2 = sheet_dataset(fx_tables(static function (array &$t) {
        (fx_live(3))($t);
        $t['matches'][] = [date('Y-m-d'), 'Saturday', '가선수', 'Z', '나선수', 'P', 2, 1, '승'];
    }), 'api');
    assert_same([], array_filter($ds2['check']['mismatches'], static fn($m) => $m['kind'] === 'matches'));

    // 송출: 두 선수의 끝장전 CG(맞대결)가 막히지 않고, 진행 중 경기는 맞대결 전적에 없음
    setup_sheet(fx_live(3));
    page_add(['template' => 'head-to-head', 'params' => ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수']]], op());
    cue_page(1);
    $st = instance_state(instance_get(channel_get('preview')['instance_id']), current_session_id());
    assert_same([], $st['problems'], implode(' / ', $st['problems']));
    assert_same(1, count(json_dec(setting_get('data_check'))['live']), '패널·서버 점검에 진행 중 경기 안내');
    // 진행 중 경기는 '통계 제외 확정' 대상이 아님
    assert_throws(ActionError::class, fn() => match_exclude(['match' => date('Y-m-d') . '|가선수|나선수', 'on' => true], op()), 'BAD_MATCH');
    // 매치 기록: 경기일 = 오늘이면 진행 중 경기는 '경기일 당일 입력'으로 빼고 알림
    $r = stats_match_records(dataset_or_null(), '가선수', '나선수', date('Y-m-d'));
    assert_true(str_contains($r['notes'][0], '진행 중이거나 입력 중'), $r['notes'][0] ?? '');
});

test('경기 중 입력: 진행 중으로 보지 않는 경우 — 9세트가 되면 끝난 경기, 이틀 지난 미완료 경기·마지막이 아닌 경기는 이전처럼 이상 경기', function () {
    $ds = dataset_finalize(sheet_dataset(fx_tables(fx_live(9)), 'api'), []);
    assert_same([[], 5], [$ds['check']['live'], count($ds['matches'])], '9세트 = 끝난 경기, 끝장전 통계에 들어감');
    // 자정을 넘긴 방송(어제 날짜)은 진행 중
    $y = date('Y-m-d', strtotime('-1 day'));
    assert_same(1, count(sheet_dataset(fx_tables(fx_live(3, $y)), 'api')['check']['live']));
    // 이틀 전 날짜로 9세트가 안 된 경기 → 이상 경기 (확인 필요)
    $old = dataset_finalize(sheet_dataset(fx_tables(fx_live(3, date('Y-m-d', strtotime('-2 day')))), 'api'), []);
    assert_same([[], 2, false], [$old['check']['live'], count($old['check']['anomalies']), $old['verify']['matches']['players']['가선수']]);
    // 진행 중 경기 뒤에 다른 경기 세트가 입력됨 → 앞 경기는 마지막 입력이 아니므로 이상 경기
    $ds = sheet_dataset(fx_tables(static function (array &$t) {
        (fx_live(3))($t);
        $t['results'][] = ['다선수', 'T', '라선수', 'Z', '', date('Y-m-d'), 0, 0];
    }), 'api');
    assert_same(1, count($ds['check']['live']), '마지막 경기만 진행 중');
    assert_true(str_contains($ds['check']['live'][0]['text'], '다선수 vs 라선수'));
    assert_true((bool)array_filter($ds['check']['anomalies'], static fn($a) => str_contains($a['text'], '가선수 vs 나선수 2:1 — 세트 수 3개')));
});

test('경기 중 입력: 예측 탭 맨 아래 입력 중인 행(중계진·갯수 입력 전)은 그 행만 빼고 예측·미션 지수 그대로, 중간 행 오류는 이전처럼', function () {
    $row = static fn(string $who, $amt, string $res) => ['2026-01-20', '가선수(Z)', '나선수(P)', 'SET 1', 'Map', $amt, $who, '가선수(Z)', $res];
    $ds = sheet_dataset(fx_tables(static function (array &$t) use ($row) {
        $t['predictions'][] = $row('', 100, '성공');               // 결과를 먼저 적고 중계진은 아직
        $t['predictions'][] = $row('김중계 캐스터', '', '실패');   // 갯수 입력 전
    }), 'api');
    assert_same(7, count($ds['predictions']));
    assert_same([true, true], [$ds['verify']['predictions']['available'], $ds['verify']['mission']['available']], '예측·미션 지수 모두 사용 가능');
    assert_same(['김중계' => true, '이해설' => true], $ds['verify']['mission']['predictors']);
    assert_same([13, 14], array_column(array_filter($ds['check']['pending'], static fn($p) => str_contains($p['text'], '예측 탭')), 'row'));
    // 중간 행: 이전과 같이 예측 CG 사용 불가 / 갯수만 비면 미션 지수만 사용 불가
    $mid = sheet_dataset(fx_tables(static function (array &$t) {
        $t['predictions'][4][6] = '';
    }), 'api');
    assert_true((bool)array_filter($mid['check']['unavailable'], static fn($u) => str_contains($u, '승자 예측 사용 불가: 예측 탭 5행')));
    $amt = sheet_dataset(fx_tables(static function (array &$t) {
        $t['predictions'][4][5] = '';
    }), 'api');
    assert_same([true, false], [$amt['verify']['predictions']['available'], $amt['verify']['mission']['available']]);
    assert_true((bool)array_filter($amt['check']['unavailable'], static fn($u) => str_contains($u, '미션 지수 사용 불가: 예측 탭 5행의 갯수')));
});
