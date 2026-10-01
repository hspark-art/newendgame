<?php
declare(strict_types=1);

// v0.6: 타이틀 에디터 '항목 빼기', 오늘 매치(한 번에 추가·페이지 리스트 바꾸기), 맵 최근 순서
// 합성 시트(fx_tables): 가선수 Z, 나선수 P, 다선수 T, 라선수 Z / 맵 = 'Map 1'~'Map 9'

test('항목 빼기: 묶음을 빼면 송출 화면에서 사라지고, 필수 항목이어도 송출 가능, TAKE·UPDATE LIVE로 반영', function () {
    setup_sheet();
    match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => true], op());
    $st = type_state('match-preview', ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수'], 'map' => 'Map 1']);
    $iid = channel_get('preview')['instance_id'];
    $keys = static fn(array $st) => array_column($st['view']['rows'], 'key');
    assert_same(['match', 'set', 'race', 'form', 'h2h', 'map'], $keys($st));
    program_take(channel_get('preview')['rev'], ['effect' => 'cut'], op());

    instance_hide($iid, '최근 5경기', true, op());
    instance_hide($iid, '매치 전적', true, op()); // 필수 항목(매치 승·패)이 있는 묶음
    $st = instance_state(instance_get($iid), current_session_id());
    assert_same([], $st['problems'], '뺀 필수 항목은 송출을 막지 않음');
    assert_same(['set', 'race', 'h2h', 'map'], $keys($st));
    assert_same([null, 2], [$st['final']['a.mw'], $st['final_raw']['a.mw']], 'final은 빈 값, final_raw는 원래 값');
    $ps = panel_state(op());
    $f = array_column($ps['preview']['fields'], null, 'key');
    assert_true($f['a.form']['hidden'] && $f['a.form']['final_text'] === '빠짐' && !$f['a.name']['hidden']);
    assert_true($ps['program']['pending_live'] && $ps['program']['live_manual'], '송출 중이면 UPDATE LIVE로 뺄 수 있음');
    assert_same(['match', 'set', 'race', 'form', 'h2h', 'map'],
        array_column(channel_get('program')['snapshot']['view']['rows'], 'key'), 'PROGRAM은 그대로');

    program_update_live($iid, channel_get('program')['take_id'], channel_get('preview')['rev'], [], op());
    assert_same(['set', 'race', 'h2h', 'map'], array_column(channel_get('program')['snapshot']['view']['rows'], 'key'));
    // 다시 넣기 → UPDATE LIVE: 뺐던 줄이 송출 때 값으로 돌아온다 (덮어쓴 AUTO가 아님)
    instance_hide($iid, '매치 전적', false, op());
    program_update_live($iid, channel_get('program')['take_id'], channel_get('preview')['rev'], [], op());
    $snap = channel_get('program')['snapshot'];
    assert_same(['match', 'set', 'race', 'h2h', 'map'], array_column($snap['view']['rows'], 'key'));
    assert_same('2승 1패', $snap['view']['rows'][0]['a']);
    assert_same(['a.form', 'b.form'], $snap['hidden']);

    // 묶음이 없는 항목(제목·이름)은 뺄 수 없다
    assert_throws(ActionError::class, fn() => instance_hide($iid, '', true, op()), 'VALIDATION');
    assert_throws(ActionError::class, fn() => instance_hide($iid, '없는 묶음', true, op()), 'VALIDATION');
    // 맞대결 줄을 빼면 "첫 맞대결"로 바뀌지 않고 줄이 사라진다
    instance_hide($iid, '맞대결', true, op());
    assert_true(!in_array('h2h', $keys(instance_state(instance_get($iid), current_session_id())), true));
});

test('항목 빼기: 순위 CG의 행, 맵 상성의 종족전 줄·아래 줄, 페이지 내보내기·가져오기에도 유지', function () {
    setup_sheet();
    match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => true], op());
    type_state('win-ranking', ['race' => '', 'count' => '3']);
    $iid = channel_get('preview')['instance_id'];
    instance_hide($iid, '2행', true, op());
    $st = instance_state(instance_get($iid), current_session_id());
    assert_same(2, count($st['view']['rows']), '3명 중 2행을 뺌');
    instance_hide($iid, '1행', true, op());
    instance_hide($iid, '3행', true, op());
    assert_true(in_array('표시할 행이 없습니다. 행 값을 입력하거나 다른 조건을 고르세요.',
        instance_state(instance_get($iid), current_session_id())['problems'], true), '모두 빼면 송출 막음');

    type_state('map-matchup', ['map' => 'Map 1']);
    $mm = channel_get('preview')['instance_id'];
    instance_hide($mm, 'P vs T', true, op());
    instance_hide($mm, '총 세트·기간', true, op());
    $st = instance_state(instance_get($mm), current_session_id());
    assert_same([[], ['Z', 'T'], ''], [$st['problems'], array_column($st['view']['rows'], 'l'), $st['view']['foot']]);
    assert_true(!str_contains(cg_render($st['view']), 'mu-foot'));

    $file = rundown_export();
    assert_same(['pt.l', 'pt.r', 'pt.rate', 'sets', 'first', 'last'], array_column($file['pages'], 'hidden', 'template')['map-matchup']);
    setup_sheet();
    rundown_import($file, op());
    $row = array_column(rundown_rows(), null, 'template')['map-matchup'];
    assert_same(['pt.l', 'pt.r', 'pt.rate', 'sets', 'first', 'last'], instance_get((int)$row['instance_id'])['hidden']);
});

test('오늘 매치: 저장 → 2인 CG 한 번에 추가(상대 종족 자동·중복 건너뜀·맵 필요한 CG) → 페이지 리스트 바꾸기(송출 중 제외)', function () {
    setup_sheet();
    assert_throws(ActionError::class, fn() => match_today_save(['a' => '가선수', 'b' => '가선수'], op()), 'BAD_PARAMS');
    assert_throws(ActionError::class, fn() => match_today_save(['a' => '가선수', 'b' => '없는선수'], op()), 'BAD_PARAMS');
    $v = match_today_save(['a' => '가선수', 'b' => '나선수'], op());
    assert_same('가선수 (Z) vs 나선수 (P)', $v['text']);
    assert_same(['race-win-rate', 'head-to-head', 'online-h2h', 'double-chance', 'full-set', 'match-preview', 'map-record'],
        array_column($v['templates'], 'slug'), '2인 CG (표시 순서)');
    assert_same('가선수 (Z) vs 나선수 (P)', panel_state(op())['match']['text']);

    $r = match_pages_add(['a' => '가선수', 'b' => '나선수', 'templates' => ['race-win-rate', 'match-preview', 'map-record'], 'map' => ''], op());
    assert_same(['001 상대 종족 승률', '002 매치 프리뷰'], $r['added']);
    assert_same(['맵 전적: 이번 맵을 고르세요'], $r['skipped']);
    $rwr = json_dec(array_column(rundown_rows(), 'params_json', 'template')['race-win-rate']);
    assert_same(['a' => ['player' => '가선수', 'vs' => 'P'], 'b' => ['player' => '나선수', 'vs' => 'Z']], $rwr, '상대 종족 = 상대 선수의 종족');
    $r = match_pages_add(['a' => '가선수', 'b' => '나선수', 'templates' => ['race-win-rate', 'map-record'], 'map' => 'Map 1'], op());
    assert_same([['003 맵 전적'], ['상대 종족 승률: 이미 있음']], [$r['added'], $r['skipped']]);
    assert_throws(ActionError::class, fn() => match_pages_add(['a' => '가선수', 'b' => '나선수', 'templates' => ['win-ranking']], op()),
        'VALIDATION');

    // 1번 페이지를 송출 → 매치를 바꿔 페이지 리스트를 바꾸면 송출 중인 1번은 그대로, 나머지는 새 선수로 (맵·번호 유지)
    cue_page(1);
    program_take(channel_get('preview')['rev'], ['effect' => 'cut'], op());
    $r = match_pages_apply(['a' => '다선수', 'b' => '라선수'], op());
    assert_same(['002 매치 프리뷰', '003 맵 전적'], $r['changed']);
    assert_same(['001 상대 종족 승률: 송출 중이라 그대로 둠'], $r['skipped']);
    $p = array_map(static fn($row) => json_dec($row['params_json']), array_column(rundown_rows(), null, 'page_no'));
    assert_same('가선수', $p[1]['a']['player']);
    assert_same(['다선수', '라선수', 'Map 1'], [$p[3]['a']['player'], $p[3]['b']['player'], $p[3]['map']]);
    assert_same(['a' => '다선수', 'b' => '라선수'], match_today());
    assert_same([], match_pages_apply(['a' => '다선수', 'b' => '라선수'], op())['changed'], '이미 같으면 바꾸지 않음');
});

test('맵 고르기 순서: 최근 끝장전 20경기에서 쓴 맵을 많이 쓴 순으로 먼저, 나머지는 마지막 사용일 순', function () {
    $g = static fn(string $d, string $w, string $l, string $map) => ['date' => $d, 'winner' => $w, 'loser' => $l, 'map' => $map,
        'wrace' => 'Z', 'lrace' => 'P'];
    $games = [
        $g('2024-01-01', 'a', 'b', 'Old'), $g('2024-01-01', 'a', 'b', 'Old'), $g('2024-01-01', 'a', 'b', 'Mid'),
        $g('2025-05-01', 'c', 'd', 'Mid'), $g('2026-01-01', 'a', 'c', 'New'), $g('2026-01-01', 'a', 'c', 'Mid'),
        $g('2026-02-01', 'b', 'd', 'New'), $g('2026-02-01', 'b', 'd', 'Rare'), $g('2026-02-01', 'b', 'd', ''),
    ];
    // 최근 2경기 = 2026-02-01 b·d, 2026-01-01 a·c → New 2, Mid 1, Rare 1
    $u = stats_map_usage($games, 2);
    assert_same(['New', 'Rare', 'Mid', 'Old'], array_map('strval', array_keys($u)));
    assert_same(['sets' => 3, 'recent' => 1, 'last' => '2026-01-01'], $u['Mid']);
    assert_same(['sets' => 2, 'recent' => 0, 'last' => '2024-01-01'], $u['Old']);
    setup_sheet();
    $first = array_values(json_dec(setting_get('maps_cache')))[0];
    assert_true(isset($first['recent'], $first['last']) && $first['recent'] > 0, '패널 맵 목록에 최근 사용 정보');
});
