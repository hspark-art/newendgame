<?php
declare(strict_types=1);

// 방송 흐름 시나리오 (DATA_AND_OVERRIDE §9)

function op(): array
{
    return ['name' => '테스트', 'role' => 'admin', 'user_id' => null];
}

/** 새 DB + 데이터 불러오기 + 조일장(vs P) / 장윤철(vs Z) 페이지 1개 (PREVIEW에 큐됨) */
function setup_rwr(): array
{
    fresh_db();
    data_refresh(op());
    $r = page_add(['template' => 'race-win-rate', 'params' => [
        'a' => ['player' => 'jo-iljang', 'vs' => 'P'], 'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']]], op());
    return $r;
}

function pv_field(string $key): array
{
    foreach (panel_state(op())['preview']['fields'] as $f) {
        if ($f['key'] === $key) {
            return $f;
        }
    }
    fail("필드 없음: $key");
}

function take_now(array $opts = []): array
{
    return program_take(channel_get('preview')['rev'], $opts + ['effect' => 'slide', 'dur_ms' => 350], op());
}

function program_json(): string
{
    return (string)db_value("SELECT snapshot_json FROM cg_channels WHERE layer = 1 AND kind = 'program'");
}

test('flow: 페이지 추가 → AUTO 계산, PREVIEW 자동 큐, 표시 문자열', function () {
    $r = setup_rwr();
    assert_same(1, $r['page_no']);
    $pv = panel_state(op())['preview'];
    assert_same(1, $pv['page_no']);
    assert_same('33', pv_field('a.wins')['final_text']);
    assert_same('61.1%', pv_field('a.rate')['final_text']);
    assert_same('51.2%', pv_field('b.rate')['final_text']);
    $view = instance_state(instance_get($pv['instance_id']), current_session_id())['view'];
    assert_same('33승 21패', $view['cols'][0]['record']);
    assert_same('(61.1%)', $view['cols'][0]['rate']);
    assert_same('(51.2%)', $view['cols'][1]['rate']);
    assert_same('조일장', $view['cols'][0]['name']);
    assert_true($view['mock'], 'MOCK 표시');
    $html = cg_render($view);
    assert_true(str_contains($html, '33승 21패') && str_contains($html, 'MOCK'), 'HTML');
});

test('시나리오1: 승패 수정 → 승률 재계산, 직접 승률이 우선', function () {
    setup_rwr();
    $iid = channel_get('preview')['instance_id'];
    preview_save($iid, ['a.wins' => '34', 'a.losses' => '20'], op());
    $rate = pv_field('a.rate');
    assert_same('63.0%', $rate['final_text']);
    assert_same('AUTO', $rate['origin'], '자동 계산 상태');
    assert_same('61.1%', $rate['auto_text']);
    preview_save($iid, ['a.rate' => '62.8'], op());
    $rate = pv_field('a.rate');
    assert_same('62.8%', $rate['final_text']);
    assert_same('MANUAL', $rate['origin']);
    assert_true($rate['differs'], '계산값과 다름 표시');
    assert_same('63.0%', $rate['calc_text']);
});

test('시나리오2: 0 허용, 빈값·잘못된 값 거부(부분 저장 없음), 0경기', function () {
    setup_rwr();
    $iid = channel_get('preview')['instance_id'];
    preview_save($iid, ['a.wins' => '0'], op());
    assert_same('0', pv_field('a.wins')['final_text']);
    assert_same('MANUAL', pv_field('a.wins')['origin']);
    foreach (['a.wins' => ['', ' ', '-1', '3.5', '1e3', 'NaN', 'abc', '100000'], 'a.rate' => ['100.1', '62.85', '-1', '.5', '']] as $key => $bads) {
        foreach ($bads as $bad) {
            $e = assert_throws(ActionError::class, fn() => preview_save($iid, ['a.losses' => '9', $key => $bad], op()), 'VALIDATION');
            assert_true(isset($e->fields[$key]), "$key='$bad' 오류 표시");
        }
    }
    assert_same('21', pv_field('a.losses')['final_text'], '함께 보낸 정상 값도 저장되지 않음');
    assert_throws(ActionError::class, fn() => preview_save($iid, ['nope' => '1'], op()), 'VALIDATION');
    // 0경기: 장윤철 vs T
    page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => 'jang-yunchul', 'vs' => 'T'],
        'b' => ['player' => 'jo-iljang', 'vs' => 'P']]], op());
    cue_page(2);
    assert_same('—', pv_field('a.rate')['final_text']);
    $st = instance_state(instance_get(channel_get('preview')['instance_id']), current_session_id());
    assert_same([], $st['problems'], '0경기는 송출 가능');
    assert_same('0승 0패', $st['view']['cols'][0]['record']);
    assert_same('(자료 없음)', $st['view']['cols'][0]['rate']);
});

test('시나리오2: 정상 데이터가 한 번도 없으면 값 없음 → TAKE 차단, 수동 입력하면 송출 가능', function () {
    fresh_db(['mock_dir' => '/nonexistent']);
    setting_set('players_cache', json_enc(['jo-iljang' => ['id' => 'jo-iljang', 'name' => '조일장', 'race' => 'Z'],
        'jang-yunchul' => ['id' => 'jang-yunchul', 'name' => '장윤철', 'race' => 'P']]));
    page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => 'jo-iljang', 'vs' => 'P'],
        'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']]], op());
    assert_true(panel_state(op())['preview']['auto_missing']);
    assert_throws(ActionError::class, fn() => take_now(), 'NOT_SENDABLE');
    $iid = channel_get('preview')['instance_id'];
    preview_save($iid, ['a.name' => '조일장', 'a.wins' => '1', 'a.losses' => '1', 'b.name' => '장윤철', 'b.wins' => '0',
        'b.losses' => '0', 'title' => '테스트'], op());
    take_now();
    assert_same(1, channel_get('program')['take_id']);
});

test('시나리오3: 자동 갱신 → 수동값 보존·변경 표시, PROGRAM 불변', function () {
    setup_rwr();
    $iid = channel_get('preview')['instance_id'];
    take_now();
    preview_save($iid, ['a.wins' => '34'], op());
    $before = program_json();
    $pgRev = channel_get('program')['rev'];
    $pvRev = channel_get('preview')['rev'];
    $ds = mock_with(fn(array &$d) => $d['matches'][0]['scoreA'] = 2); // mock-001: MOCK-P2 2:5 조일장 → 조일장 vs P 33승 20패
    $r = data_refresh(op(), $ds);
    assert_same(1, $r['changed']);
    $losses = pv_field('a.losses');
    assert_same('20', $losses['final_text'], '수정 안 한 필드는 새 AUTO');
    $wins = pv_field('a.wins');
    assert_same('34', $wins['final_text'], '수동값 보존');
    $rate = pv_field('a.rate');
    assert_same('63.0%', $rate['final_text'], '34/20 재계산');
    assert_same('62.3%', $rate['auto_text'], '새 AUTO 33/20');
    assert_same($before, program_json(), 'PROGRAM 스냅샷 그대로');
    assert_same($pgRev, channel_get('program')['rev']);
    assert_true(channel_get('preview')['rev'] > $pvRev, 'PREVIEW는 갱신 알림');
    // 수동값 저장 이후 AUTO가 바뀐 경우 표시
    $ds = mock_with(function (array &$d) {
        $d['matches'][0]['scoreA'] = 2;
        $d['matches'][1]['scoreB'] = 1; // 5:2 → 5:1
    });
    data_refresh(op(), $ds);
    assert_true(pv_field('a.wins')['auto_changed'] === false, 'wins AUTO는 그대로(33)');
    preview_save($iid, ['a.losses' => '25'], op());
    $ds = mock_with(function (array &$d) {
        $d['matches'][0]['scoreA'] = 2;
        $d['matches'][1]['scoreB'] = 1;
        $d['matches'][2]['scoreB'] = 3; // 5:2 → 5:3, 패 +1
    });
    data_refresh(op(), $ds);
    $l = pv_field('a.losses');
    assert_true($l['auto_changed'], '자동값 변경 표시');
    assert_same('19', $l['auto_at_set_text']);
    assert_same('20', $l['auto_text']);
    assert_same('25', $l['final_text']);
});

test('시나리오4: RESET → 최신 AUTO로 PREVIEW 복귀, PROGRAM 유지', function () {
    setup_rwr();
    $iid = channel_get('preview')['instance_id'];
    preview_save($iid, ['a.wins' => '40', 'b.wins' => '1'], op());
    take_now();
    $before = program_json();
    override_reset($iid, 'a.wins', op());
    assert_same('33', pv_field('a.wins')['final_text']);
    assert_same('1', pv_field('b.wins')['final_text'], '다른 필드 수정값은 유지');
    override_reset($iid, null, op());
    assert_same('129', pv_field('b.wins')['final_text']);
    assert_same(0, (int)db_value('SELECT COUNT(*) FROM cg_overrides'));
    assert_same($before, program_json());
});

test('시나리오5: PREVIEW 변경은 PROGRAM 불변, TAKE는 확인한 스냅샷 반영', function () {
    setup_rwr();
    page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => 'jang-yunchul', 'vs' => 'Z'],
        'b' => ['player' => 'jo-iljang', 'vs' => 'P']], 'label' => '역순'], op());
    $t = take_now();
    assert_same(1, $t['take_id']);
    $snap1 = program_json();
    cue_page(2);
    preview_save(channel_get('preview')['instance_id'], ['title' => '다른 제목'], op());
    assert_same($snap1, program_json(), 'PREVIEW 편집·큐 변경 후에도 PROGRAM 그대로');
    $stale = channel_get('preview')['rev'] - 1;
    assert_throws(ActionError::class, fn() => program_take($stale, [], op()), 'PREVIEW_CHANGED');
    take_now(['effect' => 'fade', 'dur_ms' => 500]);
    $pg = channel_get('program');
    assert_same(2, $pg['take_id']);
    assert_same(1, $pg['visible']);
    assert_same('다른 제목', $pg['snapshot']['view']['title']);
    assert_same('fade', $pg['snapshot']['effect']);
    assert_same(500, $pg['snapshot']['dur_ms']);
    assert_same(2, $pg['snapshot']['page_no']);
    $pv = instance_state(instance_get(channel_get('preview')['instance_id']), current_session_id())['view'];
    assert_same($pv, $pg['snapshot']['view'], 'TAKE 순간의 PREVIEW와 같음');
    take_now(['effect' => 'cut', 'dur_ms' => 900]);
    assert_same(0, channel_get('program')['snapshot']['dur_ms'], 'CUT은 0ms');
});

test('SHOW/OUT: 표시 여부만 변경, 빈 PROGRAM은 거부, 수정값 유지', function () {
    setup_rwr();
    assert_throws(ActionError::class, fn() => program_visibility(true, op()), 'PROGRAM_EMPTY');
    $iid = channel_get('preview')['instance_id'];
    preview_save($iid, ['a.wins' => '34'], op());
    take_now();
    $snap = program_json();
    program_visibility(false, op());
    assert_same(0, channel_get('program')['visible']);
    assert_same($snap, program_json());
    assert_same(false, output_payload('program')['visible']);
    program_visibility(true, op());
    assert_same(true, output_payload('program')['visible']);
    assert_same(1, (int)db_value('SELECT COUNT(*) FROM cg_overrides'));
    $rev = channel_get('program')['rev'];
    program_visibility(true, op());
    assert_same($rev, channel_get('program')['rev'], '이미 표시 중이면 변화 없음');
});

test('시나리오6: UPDATE LIVE → 같은 대상만 즉시 변경, 불일치 차단·기록', function () {
    setup_rwr();
    page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => 'jang-yunchul', 'vs' => 'Z'],
        'b' => ['player' => 'jo-iljang', 'vs' => 'P']]], op());
    $iid = channel_get('preview')['instance_id'];
    take_now();
    program_visibility(false, op());
    $pg = channel_get('program');
    $r = program_update_live($iid, $pg['take_id'], channel_get('preview')['rev'], ['a.wins' => '34', 'a.losses' => '20'], op());
    assert_same(['a.wins', 'a.losses', 'a.rate'], $r['changed']);
    $after = channel_get('program');
    assert_same($pg['take_id'], $after['take_id'], 'take_id 유지');
    assert_same(0, $after['visible'], '표시 상태 유지');
    assert_same('34승 20패', $after['snapshot']['view']['cols'][0]['record']);
    assert_same('(63.0%)', $after['snapshot']['view']['cols'][0]['rate']);
    assert_same('34', pv_field('a.wins')['final_text'], '수정값이 PREVIEW에도 저장됨');
    assert_same(1, (int)db_value("SELECT COUNT(*) FROM cg_logs WHERE action = 'UPDATE_LIVE'"));
    assert_same(2, (int)db_value("SELECT COUNT(*) FROM cg_logs WHERE action = 'SET'"));
    assert_throws(ActionError::class, fn() => program_update_live($iid, $pg['take_id'], channel_get('preview')['rev'], [], op()), 'NO_CHANGE');
    // 다른 CG를 PREVIEW에 큐한 상태: 대상 불일치
    cue_page(2);
    $other = channel_get('preview')['instance_id'];
    $snap = program_json();
    assert_throws(ActionError::class, fn() => program_update_live($other, $pg['take_id'], channel_get('preview')['rev'], ['a.wins' => '1'], op()), 'TARGET_MISMATCH');
    assert_same($snap, program_json());
    assert_same(1, (int)db_value("SELECT COUNT(*) FROM cg_logs WHERE action = 'UPDATE_LIVE_REJECTED'"));
    assert_same(0, (int)db_value('SELECT COUNT(*) FROM cg_overrides WHERE instance_id = ?', [$other]), '거부 시 저장 안 함');
    // 오래된 take_id
    cue_page(1);
    assert_throws(ActionError::class, fn() => program_update_live($iid, $pg['take_id'] - 1, channel_get('preview')['rev'], ['a.wins' => '35'], op()), 'PROGRAM_CHANGED');
    assert_throws(ActionError::class, fn() => program_update_live($iid, $pg['take_id'], channel_get('preview')['rev'], ['a.wins' => 'x'], op()), 'VALIDATION');
    assert_same($snap, program_json());
});

test('UPDATE LIVE: 자동 갱신으로 바뀐 AUTO 값은 내보내지 않고 수정값만 반영', function () {
    setup_rwr();
    $iid = channel_get('preview')['instance_id'];
    take_now();
    $ds = mock_with(fn(array &$d) => $d['matches'][0]['scoreA'] = 2); // 조일장 vs P: 33승 20패 (AUTO 변경)
    data_refresh(op(), $ds);
    assert_same('20', pv_field('a.losses')['final_text'], 'PREVIEW에는 새 AUTO');
    assert_true(panel_state(op())['program']['pending_live'], '송출값과 다름 표시');
    assert_true(!panel_state(op())['program']['live_manual'], '보낼 수정값은 없음');
    assert_throws(ActionError::class, fn() => program_update_live($iid, 1, channel_get('preview')['rev'], [], op()), 'NO_CHANGE');
    program_update_live($iid, 1, channel_get('preview')['rev'], ['title' => '긴급 제목'], op());
    $snap = channel_get('program')['snapshot'];
    assert_same('긴급 제목', $snap['view']['title']);
    assert_same(21, $snap['final']['a.losses'], 'AUTO 변경(20)은 송출에 반영 안 됨');
    assert_same('33승 21패', $snap['view']['cols'][0]['record']);
    // 저장만 해 둔 수정값은 다음 UPDATE LIVE에서 반영, 승률은 송출 스냅샷 기준으로 재계산
    preview_save($iid, ['a.wins' => '40'], op());
    assert_true(panel_state(op())['program']['live_manual']);
    $r = program_update_live($iid, 1, channel_get('preview')['rev'], [], op());
    assert_same(['a.wins', 'a.rate'], $r['changed']);
    $snap = channel_get('program')['snapshot'];
    assert_same('40승 21패', $snap['view']['cols'][0]['record']);
    assert_same(656, $snap['final']['a.rate'], '40/61 = 65.6% (송출 중인 패 21 기준)');
    // 직접 입력한 승률은 재계산하지 않음
    program_update_live($iid, 1, channel_get('preview')['rev'], ['a.rate' => '70.0'], op());
    assert_same('(70.0%)', channel_get('program')['snapshot']['view']['cols'][0]['rate']);
    program_update_live($iid, 1, channel_get('preview')['rev'], ['a.wins' => '41'], op());
    assert_same(700, channel_get('program')['snapshot']['final']['a.rate']);
});

test('시나리오9: 새 세션 → AUTO, KEEP만 유지, PROGRAM 불변, 재시작 복원', function () {
    setup_rwr();
    $iid = channel_get('preview')['instance_id'];
    preview_save($iid, ['a.wins' => '34', 'title' => '결승 특집'], op());
    override_keep($iid, 'title', true, op());
    assert_throws(ActionError::class, fn() => override_keep($iid, 'b.wins', true, op()), 'NO_OVERRIDE');
    take_now();
    $snap = program_json();
    $r = session_start_new('2부 방송', op());
    assert_same(1, $r['carried']);
    assert_same('33', pv_field('a.wins')['final_text'], 'KEEP 없는 수정값은 새 세션에서 AUTO');
    assert_same('결승 특집', pv_field('title')['final_text'], 'KEEP 수정값 유지');
    assert_same($snap, program_json());
    assert_throws(ActionError::class, fn() => session_start_new('  ', op()), 'VALIDATION');
    // 재시작: 연결을 끊고 같은 파일로 다시 연다
    reopen_db();
    assert_same($r['session_id'], current_session_id());
    assert_same('결승 특집', pv_field('title')['final_text']);
    assert_same($snap, program_json());
    assert_same(1, channel_get('program')['visible']);
    assert_same($iid, channel_get('preview')['instance_id']);
});

test('시나리오10: 파라미터가 다르면 수정값이 섞이지 않음', function () {
    setup_rwr();
    $players = players_cache();
    $p1 = template_params('race-win-rate', ['b' => ['vs' => 'z', 'player' => 'JANG-YUNCHUL'], 'a' => ['vs' => 'P', 'player' => 'jo-iljang']], $players);
    $p2 = template_params('race-win-rate', ['a' => ['player' => 'jo-iljang', 'vs' => 'P'], 'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']], $players);
    assert_same(params_key($p1), params_key($p2), '입력 순서·대소문자와 무관하게 같은 대상');
    $iid = channel_get('preview')['instance_id'];
    preview_save($iid, ['a.wins' => '99'], op());
    page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => 'jo-iljang', 'vs' => 'T'],
        'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']]], op());
    page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => 'jang-yunchul', 'vs' => 'Z'],
        'b' => ['player' => 'jo-iljang', 'vs' => 'P']]], op());
    page_add(['template' => 'race-win-rate', 'params' => $p1], op());
    $ids = array_map(fn($r) => (int)$r['instance_id'], rundown_rows());
    assert_same($ids[0], $ids[3], '같은 대상은 같은 인스턴스');
    assert_true(count(array_unique($ids)) === 3, '종족·순서가 다르면 다른 인스턴스');
    cue_page(2);
    assert_same('16', pv_field('a.wins')['final_text'], '종족이 다른 CG에 수정값이 따라오지 않음 (조일장 vs T AUTO)');
    cue_page(4);
    assert_same('99', pv_field('a.wins')['final_text'], '같은 대상이면 같은 수정값');
    assert_throws(ActionError::class, fn() => template_params('race-win-rate', ['a' => ['player' => 'nobody', 'vs' => 'P'], 'b' => ['player' => 'jo-iljang', 'vs' => 'P']], $players), 'BAD_PARAMS');
    assert_throws(ActionError::class, fn() => template_params('race-win-rate', ['a' => ['player' => 'jo-iljang', 'vs' => 'X'], 'b' => ['player' => 'jo-iljang', 'vs' => 'P']], $players), 'BAD_PARAMS');
});

test('로그: 수정 기록에 운영자·세션·대상·필드·당시 AUTO·이전·새 값', function () {
    setup_rwr();
    $iid = channel_get('preview')['instance_id'];
    preview_save($iid, ['a.wins' => '34'], op());
    preview_save($iid, ['a.wins' => '0'], op());
    $log = db_one("SELECT * FROM cg_logs WHERE action = 'SET' ORDER BY id DESC");
    assert_same('테스트', $log['operator']);
    assert_same(current_session_id(), (int)$log['session_id']);
    assert_same($iid, (int)$log['instance_id']);
    assert_same('race-win-rate', $log['template']);
    assert_same('a.wins', $log['field']);
    assert_same('33', $log['auto_json']);
    assert_same('34', $log['prev_json']);
    assert_same('0', $log['new_json']);
    override_reset($iid, 'a.wins', op());
    $reset = db_one("SELECT * FROM cg_logs WHERE action = 'RESET'");
    assert_same('0', $reset['prev_json']);
    assert_same('null', $reset['new_json']);
    take_now();
    program_visibility(false, op());
    foreach (['PAGE_ADD', 'TAKE', 'OUT', 'REFRESH'] as $a) {
        assert_same(1, (int)db_value('SELECT COUNT(*) FROM cg_logs WHERE action = ?', [$a]), $a);
    }
});

test('소스 실패: 마지막 정상 AUTO 유지, ERROR·STALE 표시, 오류 기록', function () {
    setup_rwr();
    $auto = db_value('SELECT auto_json FROM cg_instances');
    take_now();
    $snap = program_json();
    $GLOBALS['CG_CONFIG']['mock_dir'] = '/nonexistent';
    assert_throws(ActionError::class, fn() => data_refresh(op()), 'SOURCE_ERROR');
    assert_same($auto, db_value('SELECT auto_json FROM cg_instances'));
    $src = source_status();
    assert_same('ERROR', $src['status']);
    assert_true($src['stale'], 'STALE');
    assert_true($src['last_success_at'] !== null);
    assert_same(1, (int)db_value("SELECT COUNT(*) FROM cg_logs WHERE action = 'REFRESH_FAIL' AND type = 'error'"));
    assert_same($snap, program_json());
    assert_same('61.1%', pv_field('a.rate')['final_text']);
});

test('페이지 리스트: 번호 큐·NEXT·PREV·이동·삭제·번호 중복', function () {
    setup_rwr();
    foreach (['T', 'Z'] as $vs) {
        page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => 'jo-iljang', 'vs' => $vs],
            'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']], 'page_no' => $vs === 'Z' ? '10' : null], op());
    }
    assert_same([1, 2, 10], array_map(fn($r) => (int)$r['page_no'], rundown_rows()));
    assert_throws(ActionError::class, fn() => page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => 'jo-iljang', 'vs' => 'P'],
        'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']], 'page_no' => 10], op()), 'PAGE_NO_TAKEN');
    assert_throws(ActionError::class, fn() => cue_page(7), 'NO_PAGE');
    assert_same(['page_no' => 2], cue_step(1));
    assert_same(['page_no' => 10], cue_step(1));
    assert_same(null, cue_step(1), '끝이면 그대로');
    assert_same(['page_no' => 2], cue_step(-1));
    $rows = rundown_rows();
    page_move((int)$rows[2]['id'], -1);
    assert_same([1, 10, 2], array_map(fn($r) => (int)$r['page_no'], rundown_rows()));
    take_now(['auto_next' => true]);
    assert_same(2, channel_get('program')['snapshot']['page_no']);
    assert_true(channel_get('preview')['rundown_id'] !== null, '자동 NEXT 후에도 큐 유지');
    cue_page(2);
    page_remove((int)channel_get('preview')['rundown_id'], op());
    assert_same(null, channel_get('preview')['instance_id'], '큐된 페이지 삭제 시 PREVIEW 비움');
    assert_same(2, channel_get('program')['snapshot']['page_no'], 'PROGRAM은 그대로');
    $id = (int)rundown_rows()[0]['id'];
    page_update($id, ['params' => ['a' => ['player' => 'jo-iljang', 'vs' => 'Z'], 'b' => ['player' => 'jang-yunchul', 'vs' => 'P']],
        'label' => '메모', 'page_no' => 5], op());
    $row = rundown_get($id);
    assert_same(5, (int)$row['page_no']);
    assert_same('메모', $row['label']);
    $c = page_copy($id, op());
    assert_same(11, $c['page_no']);
});

test('자동 NEXT: TAKE 후 다음 페이지가 PREVIEW에 큐됨', function () {
    setup_rwr();
    page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => 'jo-iljang', 'vs' => 'T'],
        'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']]], op());
    take_now(['auto_next' => true]);
    assert_same(1, channel_get('program')['snapshot']['page_no']);
    $pvRow = rundown_get((int)channel_get('preview')['rundown_id']);
    assert_same(2, (int)$pvRow['page_no']);
});

test('내보내기·가져오기: 뒤에 추가, 번호 충돌 시 빈 번호', function () {
    setup_rwr();
    page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => 'jo-iljang', 'vs' => 'T'],
        'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']], 'label' => 'B'], op());
    $data = rundown_export();
    assert_same(2, count($data['pages']));
    $r = rundown_import(json_decode(json_encode($data), true), op());
    assert_same(2, $r['added']);
    assert_same([1, 2, 3, 4], array_map(fn($x) => (int)$x['page_no'], rundown_rows()));
    assert_throws(ActionError::class, fn() => rundown_import(['format' => 'x'], op()), 'BAD_FILE');
    $bad = $data;
    $bad['pages'][1]['params']['a']['player'] = 'ghost';
    assert_throws(ActionError::class, fn() => rundown_import($bad, op()), 'BAD_FILE');
    assert_same(4, count(rundown_rows()), '하나라도 틀리면 아무것도 추가하지 않음');
});

test('송출 상태 API: since 비교, 표시 문자열, 하트비트', function () {
    setup_rwr();
    $p = output_payload('program');
    assert_same(false, $p['visible']);
    assert_same('', $p['html']);
    take_now();
    $p = output_payload('program');
    assert_true($p['visible'] && str_contains($p['html'], '33승 21패') && str_contains($p['html'], '(61.1%)'));
    assert_same(['ok' => true, 'rev' => $p['rev'], 'same' => true], output_payload('program', 1, $p['rev']));
    $pv = output_payload('preview');
    assert_true(str_contains($pv['html'], '장윤철'));
    assert_same(0, output_seen()['count']);
    output_heartbeat('abc12345');
    output_heartbeat('abc12345');
    output_heartbeat('zzz99999');
    output_heartbeat('BAD!');
    assert_same(2, output_seen()['count']);
    assert_throws(ActionError::class, fn() => output_payload('program', 2), 'BAD_LAYER');
});

test('표시 위치: 범위 검증, PREVIEW만 즉시 반영', function () {
    setup_rwr();
    take_now();
    preview_display(['right' => '20', 'bottom' => '10', 'scale_pct' => '120'], op());
    assert_same(['right' => 20, 'bottom' => 10, 'scale_pct' => 120], channel_get('preview')['display']);
    assert_same(['right' => 0, 'bottom' => 4, 'scale_pct' => 100], channel_get('program')['snapshot']['display']);
    assert_throws(ActionError::class, fn() => preview_display(['right' => 'a', 'bottom' => 0, 'scale_pct' => 100], op()), 'BAD_DISPLAY');
    assert_throws(ActionError::class, fn() => preview_display(['right' => 0, 'bottom' => 0, 'scale_pct' => 300], op()), 'BAD_DISPLAY');
    take_now();
    assert_same(120, channel_get('program')['snapshot']['display']['scale_pct']);
});
