<?php
declare(strict_types=1);

// v0.5 새 CG 4종 (미션 지수·매치 프리뷰·맵 전적·맵 종족 상성) — 합성 시트(fx_tables) 기준.
// fx: Map 1 = 경기마다 첫 세트. 가선수 Z, 나선수 P, 다선수 T, 라선수 Z. 예측 갯수는 모두 100.

test('미션 성공 지수: 성공 갯수 − 실패 갯수, 수익률 = 지수 ÷ 건 갯수, + 빨강 / − 파랑, 시트 지수표와 대조', function () {
    setup_sheet();
    $st = type_state('mission-index', ['year' => '2026']);
    assert_same([], $st['problems']);
    assert_same('2026 중계진 미션 성공 지수 순위', $st['view']['title']);
    assert_same([['1', '김중계', '+200개', '+50.0%', 'plus'], ['2', '이해설', '-100개', '-33.3%', 'minus']],
        array_map(static fn($r) => [$r['rank'], $r['name'], $r['index'], $r['roi'], $r['roi_sign']], $st['view']['rows']));
    $html = cg_render($st['view']);
    assert_true(str_contains($html, 'class="c-roi is-plus"') && str_contains($html, 'class="c-roi is-minus"'));
    // 시트 지수표 값이 다르면 송출 차단, 직접 입력하면 풀림
    setup_sheet(static function (array &$t) {
        $t['predictions'][2][15] = 999;
    });
    $st = type_state('mission-index', ['year' => '2026']);
    assert_true(str_contains(implode(' ', $st['problems']), '순위 확인 불가'), implode(' / ', $st['problems']));
    // 갯수 열이 비면 미션 지수만 쓸 수 없다 (승자 예측은 그대로)
    $ds = sheet_dataset(fx_tables(static function (array &$t) {
        $t['predictions'][3][5] = '';
    }), 'api');
    assert_true(str_contains(implode(' ', $ds['check']['unavailable']), '미션 지수 사용 불가'));
    assert_same([], stats_mission_ranking($ds['predictions'], '2026'));
    assert_true($ds['verify']['predictions']['available']);
});

test('맵 종족 상성·맵 전적: MAP 통계·MAP 선수별 전적 탭과 대조, 한글 이름은 맵 이름 탭 → 프로그램 입력 우선', function () {
    setup_sheet();
    match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => true], op());
    $st = type_state('map-matchup', ['map' => 'Map 1']);
    assert_same([], $st['problems']);
    assert_same('맵 하나 종족 상성', $st['view']['title'], '시트 맵 이름 탭');
    assert_same([['Z', '1', '50.0%', 'P', '1'], ['T', '1', '50.0%', 'Z', '1'], ['P', '0', '0.0%', 'T', '1']],
        array_map(static fn($r) => [$r['l'], $r['lw'], $r['lrate'], $r['r'], $r['rw']], $st['view']['rows']));
    assert_same('총 5세트 · 2024-01-06 ~ 2025-01-04', $st['view']['foot']);
    $html = cg_render($st['view']);
    assert_true(str_contains($html, '<rect class="mu-fill-l" x="0" y="0" width="0"') && !str_contains($html, 'style='), '막대는 SVG 속성 (CSP)');

    $st = type_state('map-record', ['map' => 'Map 1', 'a' => ['player' => '가선수'], 'b' => ['player' => '나선수']]);
    assert_same([], $st['problems']);
    assert_same([['가선수', 'Z', '2승 1패', '(66.7%)', 'vs P 1승 1패'], ['나선수', 'P', '1승 2패', '(33.3%)', 'vs Z 1승 1패']],
        array_map(static fn($c) => array_values($c), $st['view']['cols']));

    // 프로그램에서 한글 이름 입력 → 시트 탭보다 우선, 비우면 시트 값
    map_info_save(['map' => 'Map 1', 'name_ko' => '녹아웃'], op());
    assert_same('녹아웃 종족 상성', type_state('map-matchup', ['map' => 'Map 1'])['view']['title']);
    assert_same(['녹아웃', '맵 하나'], [array_column(map_info_view(), null, 'id')['Map 1']['name_ko'],
        array_column(map_info_view(), null, 'id')['Map 1']['sheet_name']]);
    map_info_save(['map' => 'Map 1', 'name_ko' => ''], op());
    assert_same('맵 하나 종족 상성', type_state('map-matchup', ['map' => 'Map 1'])['view']['title']);
    assert_throws(ActionError::class, fn() => map_info_save(['map' => '없는 맵', 'name_ko' => 'x'], op()), 'NO_MAP');
    assert_throws(ActionError::class, fn() => template_params('map-matchup', ['map' => 'Nowhere'], players_cache(), template_ctx()), 'BAD_PARAMS');

    // 시트 집계가 다르면 그 맵·그 선수만 송출 차단
    setup_sheet(static function (array &$t) {
        $t['mapstats'][4][3]++;          // 첫 맵의 Z 승
        $t['mapplayers'][2][4]++;        // 첫 줄 선수의 승
    });
    $first = fx_tables()['mapstats'][4][1];
    assert_true(str_contains(implode(' ', type_state('map-matchup', ['map' => $first])['problems']), '맵 상성이 시트 집계와 다릅니다'));
    $mis = array_column(data_check_view()['mismatches'], 'kind');
    assert_true(in_array('maps', $mis, true) && in_array('mapsets', $mis, true));
});

test('매치 프리뷰: 매치·세트·상대 종족전·최근 5경기·맞대결·이번 맵, 더 좋은 쪽 강조, 첫 맞대결 표시', function () {
    setup_sheet();
    match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => true], op());
    $st = type_state('match-preview', ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수'], 'map' => 'Map 1']);
    assert_same([], $st['problems']);
    $v = $st['view'];
    assert_same(['가선수', 'Z', '나선수', 'P'], [$v['a']['name'], $v['a']['race'], $v['b']['name'], $v['b']['race']]);
    assert_same('Na', $v['b']['nick'], '닉네임 탭');
    $rows = array_column($v['rows'], null, 'key');
    assert_same(['2승 1패', '66.7%', '1승 2패', '33.3%', 'a'], [$rows['match']['a'], $rows['match']['a_sub'], $rows['match']['b'],
        $rows['match']['b_sub'], $rows['match']['lead']]);
    assert_same(['16승 11패', '12승 15패'], [$rows['set']['a'], $rows['set']['b']]);
    assert_same(['9승 9패', 'vs P · 50.0%', ''], [$rows['race']['a'], $rows['race']['a_sub'], $rows['race']['lead']]);
    assert_same([['W', 'W', 'L'], ['L', 'L', 'W']], [$rows['form']['a'], $rows['form']['b']]);
    assert_same(['1승', '세트 9', '1승', ''], [$rows['h2h']['a'], $rows['h2h']['a_sub'], $rows['h2h']['b'], $rows['h2h']['lead']]);
    assert_same(['맵 하나', '2승 1패', '1승 2패'], [$rows['map']['label'], $rows['map']['a'], $rows['map']['b']]);
    $html = cg_render($v);
    assert_true(str_contains($html, '<i class="is-w">W</i>') && str_contains($html, 'pv-side pv-a is-lead'));
    // 맵을 고르지 않으면 맵 줄 없음, 처음 만나는 두 선수는 "첫 맞대결"
    $st = type_state('match-preview', ['a' => ['player' => '가선수'], 'b' => ['player' => '라선수'], 'map' => '']);
    $rows = array_column($st['view']['rows'], null, 'key');
    assert_true(!isset($rows['map']));
    assert_same(['기록 없음', 'vs Z'], [$rows['race']['a'], $rows['race']['a_sub']], '가선수(Z)는 저그전 세트가 없음');
    assert_same(['note', '첫 맞대결'], [$rows['h2h']['kind'], $rows['h2h']['text']]);
    // 이상 경기(확인 전)가 있는 선수는 매치 줄이 막힌다
    match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => false], op());
    $st = type_state('match-preview', ['a' => ['player' => '다선수'], 'b' => ['player' => '나선수'], 'map' => '']);
    assert_true(str_contains(implode(' ', $st['problems']), '다선수 끝장전 기록'));
});

test('부호 있는 값(지수·수익률) 입력: +/−·쉼표 허용, 패널 표시는 부호 그대로', function () {
    assert_same(['ok' => true, 'value' => -4031], field_parse(['type' => 'sint'], '-4,031'));
    assert_same(['ok' => true, 'value' => 12609], field_parse(['type' => 'sint'], '+12609'));
    assert_same(['ok' => true, 'value' => -16], field_parse(['type' => 'srate'], '-1.6'));
    assert_same(['ok' => true, 'value' => 86], field_parse(['type' => 'srate'], '8.6'));
    assert_true(!field_parse(['type' => 'sint'], '1.5')['ok'] && !field_parse(['type' => 'srate'], '8.65')['ok']);
    assert_same(['-1.6', '0.5', '-0.5'], [fmt_srate(-16), fmt_srate(5), fmt_srate(-5)]);
    assert_same(['-1.6%', '-4,031'], [fmt_field(['type' => 'srate'], -16), fmt_field(['type' => 'sint'], -4031)]);
});
