<?php
declare(strict_types=1);

// v0.6.2: 자동 새로고침 — 패널이 여러 개여도 서버 전체에서 5분에 한 번, 변경 0건은 송출 로그에 남기지 않음,
// 한 번 실패하면 1분 뒤 다시 시도, 연속 2회 실패부터 관리자 알림

/** 마지막 새로고침 시도를 $sec초 전으로 (시간이 지난 것처럼) */
function fx_attempt_ago(int $sec): void
{
    db_exec('UPDATE cg_sources SET last_attempt_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - $sec), data_source()]);
}

/** 송출 로그의 새로고침 기록 [action, detail] */
function fx_refresh_logs(): array
{
    return array_map(static fn($r) => [$r['action'], $r['detail']],
        db_all("SELECT action, detail FROM cg_logs WHERE action IN ('REFRESH', 'REFRESH_FAIL') ORDER BY id"));
}

/** 해결되지 않은 새로고침 실패 알림 수 */
function fx_refresh_alerts(): int
{
    return (int)db_value("SELECT COUNT(*) FROM cg_alerts WHERE kind = 'refresh' AND resolved_at IS NULL");
}

test('자동 새로고침: 패널이 여러 개여도 5분에 한 번만 불러오고, 변경 0건은 송출 로그에 남기지 않음, 직접 누르면 바로', function () {
    setup_sheet();
    page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => '가선수', 'vs' => 'P'], 'b' => ['player' => '나선수', 'vs' => 'Z']]], op());
    $k = fx_key();
    $log = [];
    google_key_save($k['json'], op());
    data_settings_save(['source' => 'sheet', 'sheet' => 'TESTsheetID_0123456789abc'], op());
    try {
        $GLOBALS['CG_HTTP'] = fx_transport(fx_tabs(), $k['pub'], $log);
        db_exec('DELETE FROM cg_logs');
        fx_attempt_ago(300);
        $r = data_refresh_auto(op());
        assert_true(!isset($r['skipped']), '첫 패널은 불러옴');
        $calls = count($log);
        assert_true($calls > 0, 'Google에 요청함');
        // 같은 5분 안에 다른 패널 3개가 요청 → 건너뜀, Google 요청 없음
        for ($i = 0; $i < 3; $i++) {
            assert_same(['changed' => 0, 'skipped' => true], data_refresh_auto(op()));
        }
        fx_attempt_ago(200);
        assert_same(true, data_refresh_auto(op())['skipped'] ?? null, '290초가 지나기 전에는 건너뜀');
        assert_same($calls, count($log), '건너뛴 요청은 Google에 가지 않음');
        fx_attempt_ago(291);
        assert_true(!isset(data_refresh_auto(op())['skipped']), '290초가 지나면 다시 불러옴');
        assert_same([], fx_refresh_logs(), '자동값 변경 0건인 자동 새로고침은 송출 로그에 없음');
        assert_same('OK', source_status()['status']);

        // 직접 누른 새로고침(F5·버튼): 간격과 관계없이 바로, 송출 로그에 남김
        data_refresh(op());
        $l = fx_refresh_logs();
        assert_true(count($l) === 1 && $l[0][0] === 'REFRESH' && str_starts_with($l[0][1], 'AUTO 변경 0건 · 세트 40'),
            json_encode($l, JSON_UNESCAPED_UNICODE));
        // 자동값(검증 사유)이 바뀐 자동 새로고침은 '(자동)'으로 남김
        db_exec('DELETE FROM cg_logs');
        $r = data_refresh(op(), sheet_dataset(fx_tables(fx_alert_tamper(99)), 'api'), true);
        assert_true($r['changed'] > 0);
        $l = fx_refresh_logs();
        assert_true(count($l) === 1 && str_starts_with($l[0][1], '(자동) AUTO 변경 '), json_encode($l, JSON_UNESCAPED_UNICODE));
    } finally {
        unset($GLOBALS['CG_HTTP']);
    }
});

test('자동 새로고침: 한 번 실패는 알림 없이 1분 뒤 다시 시도, 연속 2회 실패부터 관리자 알림, 성공하면 해결·처음부터', function () {
    setup_sheet();
    // 시트 주소·키가 없으면(파일 가져오기만 쓰는 경우) 자동 새로고침은 하지 않는다 — 실패로 기록하지도 않음
    fx_attempt_ago(300);
    assert_same(['changed' => 0, 'skipped' => true], data_refresh_auto(op()));
    assert_same([0, 'OK'], [refresh_fail_streak(), source_status()['status']]);

    $k = fx_key();
    $log = [];
    google_key_save($k['json'], op());
    data_settings_save(['source' => 'sheet', 'sheet' => 'TESTsheetID_0123456789abc'], op());
    try {
        $GLOBALS['CG_HTTP'] = fx_transport(fx_tabs(), $k['pub'], $log, ['sheets' => 403]); // 시트 읽기가 계속 실패
        db_exec('DELETE FROM cg_logs');
        $n = alerts_new_count();
        assert_throws(ActionError::class, fn() => data_refresh(op()), 'SOURCE_ERROR');
        assert_same([0, $n, 1], [fx_refresh_alerts(), alerts_new_count(), refresh_fail_streak()], '첫 실패는 알림 없음');
        assert_same('ERROR', source_status()['status'], '상태는 실패로 표시 (마지막 정상 데이터 유지)');
        assert_same(['REFRESH_FAIL'], array_column(fx_refresh_logs(), 0), '실패는 송출 로그에 남김');

        // 실패 직후: 다시 시도는 55초 뒤부터
        assert_same(true, data_refresh_auto(op())['skipped'] ?? null);
        fx_attempt_ago(56);
        assert_throws(ActionError::class, fn() => data_refresh_auto(op()), 'SOURCE_ERROR');
        assert_same([1, $n + 1, 2], [fx_refresh_alerts(), alerts_new_count(), refresh_fail_streak()], '연속 2회 실패 → 알림');
        // 2회째부터는 다시 5분 간격 (장애가 길어도 1분마다 오류 기록이 쌓이지 않게)
        fx_attempt_ago(60);
        assert_same(true, data_refresh_auto(op())['skipped'] ?? null);
        fx_attempt_ago(291);
        assert_throws(ActionError::class, fn() => data_refresh_auto(op()), 'SOURCE_ERROR');
        assert_same([1, 3], [fx_refresh_alerts(), refresh_fail_streak()], '같은 실패는 알림 한 줄');

        // 성공 → 알림 해결, 연속 실패 횟수 0. 다음 한 번 실패는 다시 알림 없음
        $GLOBALS['CG_HTTP'] = fx_transport(fx_tabs(), $k['pub'], $log);
        data_refresh(op());
        assert_same([0, 0], [fx_refresh_alerts(), refresh_fail_streak()]);
        $GLOBALS['CG_HTTP'] = fx_transport(fx_tabs(), $k['pub'], $log, ['sheets' => 403]);
        assert_throws(ActionError::class, fn() => data_refresh(op()), 'SOURCE_ERROR');
        assert_same([0, 1], [fx_refresh_alerts(), refresh_fail_streak()]);
    } finally {
        unset($GLOBALS['CG_HTTP']);
    }
});
