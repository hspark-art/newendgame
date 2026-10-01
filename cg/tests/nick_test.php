<?php
declare(strict_types=1);

// v0.4.2: 시트 '닉네임' 탭 + 프로그램 입력 우선, 위치·크기 [송출에도 바로 적용]

test('닉네임: 시트 탭 값이 CG에 나오고, 프로그램에서 입력하면 그 값이 우선, 비우면 시트 값으로', function () {
    setup_sheet();
    match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => true], op());
    $nick = static function (string $name): string {
        $st = type_state('win-ranking', ['race' => '', 'count' => '4']);
        foreach ($st['view']['rows'] as $r) {
            if ($r['name'] === $name) {
                return $r['nick'];
            }
        }
        return '(없음)';
    };
    assert_same('Na', $nick('나선수'), '시트 닉네임 탭');
    assert_same('', $nick('다선수'), '닉네임 칸이 비면 표시 안 함');
    $info = array_column(player_info_view(), null, 'id');
    assert_same(['', 'Na'], [$info['나선수']['nickname'], $info['나선수']['sheet_nick']]);
    player_info_save(['player' => '나선수', 'nickname' => 'Light'], op());
    assert_same('Light', $nick('나선수'), '프로그램 입력이 우선');
    data_refresh(op(), sheet_dataset(fx_tables(), 'api'));
    assert_same('Light', $nick('나선수'), '새로고침 뒤에도 프로그램 입력 유지');
    player_info_save(['player' => '나선수', 'nickname' => ''], op());
    assert_same('Na', $nick('나선수'), '비우면 시트 값');
    assert_same(1, json_dec(setting_get('data_check'))['counts']['nicknames']);
});

test('닉네임 탭 점검: 없는 선수·긴 닉네임·중복·머리글 없음은 행 번호로 안내하고 나머지는 그대로 사용, 탭이 없어도 정상', function () {
    $ds = sheet_dataset(fx_tables(static function (array &$t) {
        $t['nicks'][0][0] = "\u{FEFF}선수명"; // CSV를 가져오면 첫 칸에 BOM이 붙을 수 있음
        $t['nicks'][] = ['가선수 ', 'Ga'];
        $t['nicks'][] = ['나선수', 'Dup'];
        $t['nicks'][] = ['없는선수', 'x'];
        $t['nicks'][] = ['라선수', str_repeat('가', 21)];
    }), 'api');
    assert_same(['Ga', 'Na', null, null], array_column(array_values($ds['players']), 'nickname'));
    $lint = array_column($ds['check']['lint'], 'text');
    assert_same(3, count($lint));
    assert_true(str_contains($lint[0], "닉네임 5행 '나선수'이(가) 위(2행)에 이미 있습니다"), $lint[0]);
    assert_true(str_contains($lint[1], "닉네임 7행 '라선수'의 닉네임은 20자 이내"), $lint[1]);
    assert_true(str_contains($lint[2], "닉네임 6행 '없는선수'은(는) Results에 없는 선수"), $lint[2]);
    $ds = sheet_dataset(fx_tables(static function (array &$t) {
        $t['nicks'] = [['이름', '별명'], ['가선수', 'Ga']];
    }), 'api');
    assert_true(str_contains($ds['check']['lint'][0]['text'], '닉네임 탭의 머리글'));
    assert_same(null, $ds['players']['가선수']['nickname']);
    $ds = sheet_dataset(fx_tables(static function (array &$t) {
        unset($t['nicks']);
    }), 'api');
    assert_same([[], []], [$ds['check']['lint'], $ds['check']['unavailable']], '닉네임 탭은 선택 — 없어도 오류·대조 불가 아님');
});

test('위치·크기: [적용]은 PREVIEW만, [송출에도 바로 적용]은 TAKE 없이 송출 화면까지 (수치는 그대로)', function () {
    setup_rwr();
    take_now();
    $before = output_payload('program');
    assert_same(['live' => false], preview_display(['right' => '10', 'bottom' => '20', 'scale_pct' => '80'], op()));
    assert_same(100, output_payload('program')['display']['scale_pct'], 'PROGRAM은 다음 TAKE부터');
    assert_same(80, output_payload('preview')['display']['scale_pct']);
    $rev = channel_get('program')['rev'];
    assert_same(['live' => true], preview_display(['right' => '10', 'bottom' => '20', 'scale_pct' => '80', 'live' => true], op()));
    $after = output_payload('program');
    assert_same(['right' => 10, 'bottom' => 20, 'scale_pct' => 80], $after['display']);
    assert_true(channel_get('program')['rev'] > $rev, '송출 화면이 변경을 받음');
    assert_same([$before['html'], $before['take_id'], $before['visible']], [$after['html'], $after['take_id'], $after['visible']],
        'CG 내용·TAKE 번호·표시 상태는 그대로');
    assert_same('DISPLAY_LIVE', db_value("SELECT action FROM cg_logs WHERE action LIKE 'DISPLAY%' ORDER BY id DESC LIMIT 1"));
    assert_throws(ActionError::class, fn() => preview_display(['scale_pct' => '300', 'live' => true], op()), 'BAD_DISPLAY');
    // 송출 중인 CG가 없으면 PREVIEW에만
    fresh_db();
    assert_same(['live' => false], preview_display(['scale_pct' => '90', 'live' => true], op()));
});
