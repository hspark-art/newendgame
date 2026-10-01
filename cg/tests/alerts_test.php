<?php
declare(strict_types=1);

// 관리자 알림: 데이터 오류·새로고침 실패 → 새 알림 / 확인함 / 해결됨, 시트 입력 점검(행 번호 안내)

/** 알림 목록을 [종류 => 제목 목록]으로 */
function alert_titles(array $list): array
{
    $out = [];
    foreach ($list as $a) {
        $out[$a['kind']][] = $a['title'];
    }
    ksort($out);
    return $out;
}

/** Players 탭 가선수 vs P 승을 $wins로 틀리게 + Results 3행 종족 칸에 공백 */
function fx_alert_tamper(int $wins): callable
{
    return static function (array &$t) use ($wins) {
        foreach ($t['players'] as &$row) {
            if (($row[1] ?? '') === '가선수') {
                $row[12] = $wins;
            }
        }
        $t['results'][2][1] = ' ' . $t['results'][2][1];
    };
}

test('시트 입력 점검: 이름·종족 칸 공백, 경기 중 종족 변경은 행 번호로 안내', function () {
    $ds = sheet_dataset(fx_tables(static function (array &$t) {
        $t['results'][2][1] = ' P';        // 3행 승자 종족 앞 공백
        $t['results'][1][2] = "나선수\u{200B}"; // 2행 패자 이름 뒤 보이지 않는 문자
        $t['results'][3][1] = 'P';         // 4행: 가선수가 이 세트만 P
    }), 'api');
    $lint = array_column($ds['check']['lint'], 'text', 'row');
    assert_same([2, 3], array_keys($lint));
    assert_true(str_contains($lint[2], 'Results 2행 패자 이름 칸에 공백·보이지 않는 문자'), $lint[2]);
    assert_true(str_contains($lint[3], "Results 3행 승자 종족 칸 ' P'"), $lint[3]);
    assert_same('나선수', $ds['games'][0]['loser'], '읽을 때는 정리해서 같은 선수로 센다');
    $race = array_values(array_filter($ds['check']['anomalies'], static fn($a) => $a['sub'] === 'race'));
    assert_same(1, count($race));
    assert_true(str_contains($race[0]['text'], 'Results 4행에서 가선수 P (나머지 세트는 Z)'), $race[0]['text']);
    // 점검 창에도 나온다
    setup_sheet(fx_alert_tamper(9));
    assert_same(1, count(data_check_view()['lint']));
    assert_same(1, json_dec(setting_get('data_check'))['lint']);
});

test('알림: 데이터 오류 → 새 알림, 확인 → 확인함, 고치면 해결됨, 다시 생기면 새 알림', function () {
    setup_sheet(fx_alert_tamper(99));
    $v = alerts_view(op());
    assert_same(['anomaly', 'lint', 'mismatch'], array_keys(alert_titles($v['new'])));
    assert_same(['가선수 세트 전적 vs P: 시트 99승 9패 / 계산 9승 9패'], alert_titles($v['new'])['mismatch']);
    assert_true(str_contains(alert_titles($v['new'])['anomaly'][0], '세트 수 4개'));
    assert_true(str_contains(alert_titles($v['new'])['lint'][0], 'Results 3행'));
    assert_same('mismatch', $v['new'][0]['kind'], '불일치(송출 차단)를 먼저');
    assert_same(3, panel_state(op())['alerts_new']);
    // 운영자에게는 보이지 않음
    $operator = ['name' => '운영', 'role' => 'operator', 'user_id' => 2];
    assert_same(null, panel_state($operator)['alerts_new']);
    assert_throws(ActionError::class, fn() => alerts_view($operator), 'ADMIN_ONLY');
    assert_throws(ActionError::class, fn() => alert_ack(['all' => true], $operator), 'ADMIN_ONLY');
    // 하나 확인 → 확인함으로, 같은 데이터로 새로고침해도 다시 새 알림이 되지 않음
    $rev = (int)setting_get('state_rev');
    $v = alert_ack(['id' => $v['new'][0]['id']], op());
    assert_true((int)setting_get('state_rev') > $rev, '패널 배지 갱신');
    assert_same(['mismatch'], array_keys(alert_titles($v['acked'])));
    assert_same('테스트', $v['acked'][0]['acked_by']);
    data_refresh(op(), sheet_dataset(fx_tables(fx_alert_tamper(99)), 'api'));
    assert_same(2, alerts_new_count());
    // 값이 바뀌면 (99 → 98) 다시 새 알림
    data_refresh(op(), sheet_dataset(fx_tables(fx_alert_tamper(98)), 'api'));
    assert_same(['가선수 세트 전적 vs P: 시트 98승 9패 / 계산 9승 9패'], alert_titles(alerts_view(op())['new'])['mismatch']);
    assert_same(1, db_value('SELECT COUNT(*) FROM cg_alerts WHERE kind = ?', ['mismatch']), '같은 문제는 한 줄');
    // 모두 확인
    $v = alert_ack(['all' => true], op());
    assert_same([[], 3], [$v['new'], count($v['acked'])]);
    assert_same(0, panel_state(op())['alerts_new']);
    // 시트를 고치면 해결됨 (이상 경기는 그대로), 경기 제외 확정하면 이상 경기도 해결됨
    data_refresh(op(), sheet_dataset(fx_tables(), 'api'));
    $v = alerts_view(op());
    assert_same(['anomaly'], array_keys(alert_titles($v['acked'])));
    assert_same(['lint', 'mismatch'], array_keys(alert_titles($v['resolved'])));
    match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => true], op());
    assert_same([[], []], [alerts_view(op())['new'], alerts_view(op())['acked']]);
    // 다시 생기면 새 알림
    data_refresh(op(), sheet_dataset(fx_tables(fx_alert_tamper(98)), 'api'));
    assert_same(['lint', 'mismatch'], array_keys(alert_titles(alerts_view(op())['new'])));
    // MOCK으로 바꾸면 시트 데이터 알림은 모두 해결됨
    setting_set('data_source', 'mock');
    data_refresh(op());
    assert_same(0, alerts_new_count());
    // 해결된 지 30일이 지난 알림은 지운다
    db_exec('UPDATE cg_alerts SET resolved_at = ?', ['2000-01-01 00:00:00']);
    data_refresh(op());
    assert_same(0, (int)db_value('SELECT COUNT(*) FROM cg_alerts'));
    assert_throws(ActionError::class, fn() => alert_ack(['id' => 'x'], op()), 'BAD_REQUEST');
});

test('알림: 새로고침 실패는 한 줄로 모으고, 성공하면 해결됨. 내용은 화면 표시용 문자열만', function () {
    setup_sheet();
    $n = alerts_new_count();
    for ($i = 0; $i < 3; $i++) {
        assert_throws(ActionError::class, fn() => data_refresh(op(), null), 'SOURCE_ERROR'); // 키·주소 없음
    }
    $new = array_values(array_filter(alerts_view(op())['new'], static fn($a) => $a['kind'] === 'refresh'));
    assert_same(1, count($new), '같은 실패는 한 줄');
    assert_same('데이터 새로고침 실패 — Google 시트', $new[0]['title']);
    assert_true(str_contains($new[0]['detail'], '마지막 정상 데이터와 송출 중인 CG는 그대로 유지'), $new[0]['detail']);
    assert_same($n + 1, panel_state(op())['alerts_new']);
    // 형식 오류(행 번호)도 알림 내용에 나온다. 토큰·키는 알림에 들어가지 않는다
    $k = fx_key();
    $log = [];
    google_key_save($k['json'], op());
    data_settings_save(['source' => 'sheet', 'sheet' => 'TESTsheetID_0123456789abc'], op());
    try {
        $GLOBALS['CG_HTTP'] = fx_transport(fx_tabs(static function (array &$t) {
            $t['results'][3][1] = 'X';
        }), $k['pub'], $log);
        assert_throws(ActionError::class, fn() => data_refresh(op()), 'SOURCE_ERROR');
        $a = array_values(array_filter(alerts_view(op())['new'], static fn($a) => $a['kind'] === 'refresh'));
        assert_same(1, count($a), '내용이 바뀐 실패는 같은 줄을 다시 새 알림으로');
        assert_true(str_contains($a[0]['detail'], 'Results 4행'), $a[0]['detail']);
        $GLOBALS['CG_HTTP'] = fx_transport(fx_tabs(), $k['pub'], $log, ['sheets' => 403]);
        assert_throws(ActionError::class, fn() => data_refresh(op()), 'SOURCE_ERROR');
        $all = json_encode(db_all('SELECT * FROM cg_alerts'), JSON_UNESCAPED_UNICODE);
        assert_true(str_contains($all, '시트에 접근할 권한이 없습니다') && !str_contains($all, 'tok-secret') && !str_contains($all, 'PRIVATE KEY'));
        $GLOBALS['CG_HTTP'] = fx_transport(fx_tabs(), $k['pub'], $log);
        data_refresh(op());
    } finally {
        unset($GLOBALS['CG_HTTP']);
    }
    $v = alerts_view(op());
    assert_same([], array_values(array_filter($v['new'], static fn($a) => $a['kind'] === 'refresh')));
    assert_same('refresh', $v['resolved'][0]['kind']);
});

test('알림: 마이그레이션 3 (v0.3.x DB 업그레이드) — 다시 실행해도 안전, 다음 새로고침에서 현재 문제를 새 알림으로', function () {
    setup_sheet();
    $latest = max(array_keys(migrations()));
    assert_same($latest, schema_version());
    db()->exec('DROP TABLE cg_alerts');
    setting_set('schema_version', '2'); // v0.3.x DB
    run_migrations();
    assert_same($latest, schema_version());
    foreach (migrations()[3] as $step) {
        db()->exec(ddl($step));
    }
    assert_same(0, alerts_new_count(), '반영 전에는 알림 없음');
    data_refresh(op(), sheet_dataset(fx_tables(), 'api'));
    assert_same(1, alerts_new_count(), '확인 안 된 이상 경기 1건');
});
