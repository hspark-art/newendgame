<?php
declare(strict_types=1);

// v0.4.1: MOCK 데이터 제거 — 배포 설정(mock_dir 없음)에서는 Google 시트만, 기존 DB의 MOCK 데이터는 마이그레이션 4가 지운다

test('배포본: MOCK 없음 — 소스는 Google 시트뿐, 시트 연결 전에는 데이터 없음·자동 새로고침 안 함', function () {
    fresh_db(['mock_dir' => null]); // 배포 설정
    assert_same(['sheet' => 'Google 시트'], data_sources());
    assert_same('sheet', data_source());
    setting_set('data_source', 'mock'); // 이전 버전에서 남은 설정
    assert_same('sheet', data_source());
    $st = panel_state(op());
    assert_same(['sheet', 'NEVER', false, []], [$st['source']['id'], $st['source']['status'], $st['data']['ready'], $st['players']]);
    assert_same(['sheet'], array_keys(data_settings_view(op())['sources']));
    assert_throws(ActionError::class, fn() => data_settings_save(['source' => 'mock'], op()), 'BAD_SOURCE');
    $e = assert_throws(ProviderError::class, fn() => provider_load('mock'));
    assert_true(str_contains($e->getMessage(), '배포본에 없습니다'));
    // 시트 주소와 키를 모두 등록해야 자동 새로고침 준비됨
    data_settings_save(['source' => 'sheet', 'sheet' => 'TESTsheetID_0123456789abc'], op());
    assert_same(false, data_ready(), '키 없음');
    google_key_save(fx_key()['json'], op());
    assert_same(true, panel_state(op())['data']['ready']);
    google_key_remove(op());
    // xlsx 가져오기는 그대로: 시트의 선수 전원이 목록에 나온다
    data_import_xlsx(['file' => base64_encode(fx_xlsx(fx_tabs()))], op());
    assert_same(['가선수', '나선수', '다선수', '라선수'], array_column(panel_state(op())['players'], 'name'));
    assert_same(false, panel_state(op())['data']['ready'], '파일만 쓰면 자동 새로고침 안 함 (알림·오류 반복 방지)');
});

test('마이그레이션 4: 기존 DB의 MOCK 페이지·수정값·닉네임·캐시 삭제, 송출 중이던 MOCK 화면 내림, 다시 실행해도 같음', function () {
    // v0.4.0 이전 DB 흉내: MOCK으로 페이지를 만들고 송출·수정·닉네임 입력
    fresh_db();
    data_refresh(op());
    page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => 'jo-iljang', 'vs' => 'P'], 'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']]], op());
    preview_save(channel_get('preview')['instance_id'], ['a.wins' => '40'], op());
    take_now();
    $keep = page_add(['template' => 'win-ranking', 'params' => ['race' => '', 'count' => '4']], op()); // 선수와 무관한 페이지
    page_add(['template' => 'prediction-ranking', 'params' => ['seats' => ['park-sanghyun', 'lim-sungchun']]], op());
    player_info_save(['player' => 'jang-yunchul', 'nickname' => 'SnowFlake'], op());
    setting_set('data_source', 'mock');
    assert_same(3, count(rundown_rows()));
    // v0.4.1로 업데이트: 배포 설정에는 MOCK이 없고, 마이그레이션 4 실행
    $GLOBALS['CG_CONFIG']['mock_dir'] = null;
    setting_set('schema_version', '3');
    run_migrations();
    assert_same(4, schema_version());
    assert_same([$keep['page_no']], array_map('intval', array_column(rundown_rows(), 'page_no')), 'MOCK 선수·중계진 페이지 삭제');
    assert_same([0, 0, 0], [(int)db_value('SELECT COUNT(*) FROM cg_overrides'), (int)db_value('SELECT COUNT(*) FROM cg_player_info'),
        (int)db_value("SELECT COUNT(*) FROM cg_dataset_cache WHERE source = 'mock'")]);
    assert_same(null, instance_get((int)rundown_rows()[0]['instance_id'])['auto'], '남은 페이지의 MOCK AUTO는 비움');
    $pg = channel_get('program');
    assert_same([null, 0], [$pg['snapshot'], $pg['visible']], '송출 중이던 MOCK 화면 내림');
    assert_same(null, channel_get('preview')['instance_id']);
    foreach (['data_source', 'data_check', 'players_cache', 'predictors_cache', 'years_cache'] as $k) {
        assert_same(null, setting_get($k), "$k 초기화");
    }
    $st = panel_state(op());
    assert_same(['sheet', [], false], [$st['source']['id'], $st['players'], $st['data']['ready']]);
    assert_true(str_contains((string)db_value("SELECT detail FROM cg_logs WHERE action = 'MOCK_PURGE'"), '페이지 2개'));
    $out = json_encode(output_payload('program'), JSON_UNESCAPED_UNICODE);
    assert_true(!str_contains($out, '조일장') && !str_contains($out, 'MOCK'), '송출 화면에 MOCK 수치 없음');
    // 다시 실행해도 같은 결과, 기록도 한 번만
    mock_purge();
    assert_same(1, count(rundown_rows()));
    assert_same(1, (int)db_value("SELECT COUNT(*) FROM cg_logs WHERE action = 'MOCK_PURGE'"));
    // 시트를 불러오면 남은 페이지가 시트 수치로 다시 계산된다
    data_import_xlsx(['file' => base64_encode(fx_xlsx(fx_tabs()))], op());
    assert_same('sheet', instance_get((int)rundown_rows()[0]['instance_id'])['auto_source']);
});
