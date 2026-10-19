<?php
declare(strict_types=1);

// v0.9.0 총상금 순위·ASL 기록 (합성 데이터. 실제 시트 내용은 저장소에 넣지 않는다)

/** 합성 ASL '보기용' 탭: 시즌 1(가선수 우승, 다선수 준우승), 대국민 스타리그(나선수 우승, 가선수 준우승), 시즌 2(결승전 없음 = 우승자 미정) */
function fx_asl(): array
{
    $r = static fn($no, $cup, $round, $w, $wr, $lr, $l) => [$no, $cup, $round, $w, $wr, '맵' . ($no === 6 ? 1 : $no), $lr, $l];
    return [
        ['경기번호', '대회', '라운드', '승자', '종족', '맵', '종족', '패자'],
        $r(1, 'ASL Season 1', '16강', '가선수', 'Z', 'T', '다선수'),
        $r(2, 'ASL Season 1', '4강', '가선수', 'Z', 'P', '나선수'),
        $r(3, 'ASL Season 1', '결승전', '가선수', 'Z', 'T', '다선수'),
        $r(4, 'ASL Season 1', '결승전', '다선수', 'T', 'Z', '가선수'),
        $r(5, 'ASL Season 1', '결승전', '가선수', 'Z', 'T', '다선수'),
        $r(6, 'ASL Season 1', '16강', '가선수', 'Z', 'T', '다선수'), // 1번과 경기번호만 다름 (중복 의심)
        [],
        $r(7, '대국민 스타리그', '8강', '나선수', 'P', 'T', '다선수'),
        $r(8, '대국민 스타리그', '결승전', '나선수', 'P', 'Z', '가선수'),
        $r(9, '대국민 스타리그', '결승전', '나선수', 'P', 'Z', '가선수'),
        $r(10, 'ASL Season 2', '4강', '다선수', 'T', 'P', '나선수'),
    ];
}

/** 시트 + ASL을 반영한 새 DB */
function setup_asl(?callable $tamper = null): array
{
    return setup_sheet(function (array &$t) use ($tamper) {
        $t['asl'] = fx_asl();
        $tamper && $tamper($t);
    });
}

test('총상금: 이긴 세트의 Prize + Double Chance 금액, 상금 보정 행은 그 날짜 값을 대신, 선수별 통계 J~L과 대조', function () {
    $ds = sheet_dataset(fx_tables(), 'api');
    // 나선수 2024-01-06: Results로는 기본 400,000·더블 200,000이지만 보정 행(500,000 / 0)이 대신한다
    assert_same(['가선수' => [1600000, 200000, 1800000], '나선수' => [1300000, 200000, 1500000],
        '다선수' => [1100000, 300000, 1400000], '라선수' => [100000, 100000, 200000]], $ds['prize']);
    assert_same(['available' => true, 'players' => ['가선수' => true, '나선수' => true, '다선수' => true, '라선수' => true], 'extra' => []],
        $ds['verify']['prize']);
    $noAdj = sheet_prize($ds['games'], [], []);
    assert_same([1200000, 400000, 1600000], $noAdj['나선수'], '보정 없이 센 값');
    // 헤이딜러처럼 한 세트 상금이 200,000인 경기: 시트 집계도 같은 값이면 일치
    $ds = sheet_dataset(fx_tables(function (array &$t) {
        $t['results'][1][6] = '200,000원';
        foreach ($t['stats'] as &$r) {
            if (($r[1] ?? '') === '가선수') {
                $r[9] += 100000;
                $r[11] += 100000;
            }
        }
    }), 'api');
    assert_same([1700000, 200000, 1900000], $ds['prize']['가선수']);
    assert_same(true, $ds['verify']['prize']['players']['가선수']);
    assert_same([], $ds['check']['mismatches']);
});

test('총상금: 읽을 수 없는 금액·보정 칸·머리글 → 사용 불가(행 번호), 다른 데이터는 그대로', function () {
    foreach ([
        [fn(array &$t) => $t['results'][3][6] = '십만', 'Results 4행 Prize'],
        [fn(array &$t) => $t['results'][3][6] = -100000, 'Results 4행 Prize'],
        [fn(array &$t) => $t['results'][2][7] = 0.5, 'Results 3행 Double Chance 금액'],
        [fn(array &$t) => $t['adjust'][1][2] = '', '상금 보정 2행의 상금'],
        [fn(array &$t) => $t['adjust'][0][3] = '더블', 'C·D열 머리글'],
    ] as [$tamper, $msg]) {
        $ds = sheet_dataset(fx_tables($tamper), 'api');
        assert_same([], $ds['prize']);
        assert_same(false, $ds['verify']['prize']['available']);
        $u = implode(' / ', $ds['check']['unavailable']);
        assert_true(str_contains($u, '총상금 사용 불가') && str_contains($u, $msg), $u);
        assert_same(40, count($ds['games']), '세트 기록은 그대로');
    }
    // 선수별 통계에 상금 열이 없으면 계산은 하고 검증만 불가
    $ds = sheet_dataset(fx_tables(fn(array &$t) => $t['stats'][3][11] = '합계'), 'api');
    assert_same(false, $ds['verify']['prize']['available']);
    assert_true(in_array('총상금 검증 불가: 선수별 통계 탭의 상금 머리글(기본 상금, 더블 찬스 상금, 합계 상금)을 찾을 수 없음', $ds['check']['unavailable'], true));
    assert_same(true, $ds['verify']['double']['available'], '더블 찬스 검증은 그대로');
});

test('총상금: 진행 중 경기는 CG에서 빼고, 시트가 진행 중 경기를 셌어도 일치(결과 대기 안내)', function () {
    $live = function (array &$t) {
        foreach ([['가선수', '나선수'], ['가선수', '나선수'], ['나선수', '가선수']] as [$w, $l]) {
            $t['results'][] = [$w, $w === '가선수' ? 'Z' : 'P', $l, $l === '가선수' ? 'Z' : 'P', 'Map 1', '2026-02-01', 100000, 0];
        }
        for ($i = 0; $i < 6; $i++) {
            $t['results'][] = ['', '', '', '', 'Map 2', '2026-02-01', '', ''];
        }
    };
    $ds = sheet_dataset(fx_tables($live), 'api');
    assert_same(1, count($ds['check']['live']));
    assert_same(1800000, $ds['prize']['가선수'][2], '진행 중 경기 상금은 넣지 않음');
    assert_same(true, $ds['verify']['prize']['players']['가선수'], '시트도 끝난 경기 기준');
    $sheetAll = function (array &$t) use ($live) {
        $live($t);
        foreach ($t['stats'] as &$r) {
            $add = ['가선수' => 200000, '나선수' => 100000][$r[1] ?? ''] ?? 0;
            if ($add) {
                $r[9] += $add;
                $r[11] += $add;
            }
        }
    };
    $ds = sheet_dataset(fx_tables($sheetAll), 'api');
    assert_same(1800000, $ds['prize']['가선수'][2], 'CG는 끝난 경기 기준 그대로');
    assert_same([true, true], [$ds['verify']['prize']['players']['가선수'], $ds['verify']['prize']['players']['나선수']]);
    assert_true((bool)array_filter($ds['check']['waiting'], fn($w) => str_starts_with($w, '총상금 가선수')), '결과 대기 안내');
    $ds = sheet_dataset(fx_tables(function (array &$t) use ($live) {
        $live($t);
        $t['stats'][4][9] += 50000;
    }), 'api');
    $pm = array_values(array_filter($ds['check']['mismatches'], fn($m) => $m['kind'] === 'prize'));
    assert_same([['가선수', '총상금']], array_map(fn($m) => [$m['who'], $m['item']], $pm));
    assert_same(false, $ds['verify']['prize']['players']['가선수']);
});

test('CG#17 총상금 순위: 공동 순위·금액 표시·다승 순위와 같은 화면, 불일치는 송출 차단 → 수동 입력하면 송출', function () {
    $rows = stats_prize_ranking(['x' => [0, 0, 300], 'w' => [0, 0, 300], 'y' => [0, 0, 100], 'z' => [0, 0, 500]], [], 3);
    assert_same([['z', 1], ['w', 2], ['x', 2]], array_map(fn($r) => [$r['player'], $r['rank']], $rows), '같은 금액은 공동 순위, 이름순');

    setup_sheet();
    $st = type_state('prize-ranking', ['count' => '3']);
    assert_same([], $st['problems']);
    assert_same('중계진 스타 끝장전 총상금 순위', $st['view']['title']);
    assert_same([['1st', '가선수', '1,800,000원', ''], ['2nd', '나선수', '1,500,000원', ''], ['3rd', '다선수', '1,400,000원', '']],
        array_map(fn($r) => [$r['rank'], $r['name'], $r['record'], $r['rate']], $st['view']['rows']));
    assert_true(str_contains(cg_render($st['view']), 'cg-box cg-list cg-ranking cg-prize cg-n3'), '다승 순위와 같은 줄 모양');
    assert_same(5, template_params('prize-ranking', [], players_cache())['count'], '기본 5명');

    // 나선수 상금이 시트와 다름 → 순위 전체 차단, 확인한 값을 직접 넣으면 송출
    setup_sheet(fn(array &$t) => $t['stats'][5][11] = 1400000);
    $st = type_state('prize-ranking', ['count' => '2']);
    assert_true((bool)array_filter($st['problems'], fn($p) => str_contains($p, '순위 확인 불가: 나선수')), implode(' / ', $st['problems']));
    $iid = channel_get('preview')['instance_id'];
    $manual = [];
    foreach ([1, 2] as $n) {
        foreach (['rank', 'name', 'nick', 'total'] as $k) {
            $manual["r$n.$k"] = (string)($st['final']["r$n.$k"] ?? '-');
        }
    }
    $manual['r2.total'] = '1,400,000';
    preview_save($iid, $manual, op());
    $st = instance_state(instance_get($iid), current_session_id());
    assert_same([], $st['problems']);
    assert_same('1,400,000원', $st['view']['rows'][1]['record']);
    assert_same([1400000], array_values(array_filter([field_parse(template_get('prize-ranking')['fields']['r1.total'], '1,400,000')['value'] ?? null])));
    assert_same(false, field_parse(['type' => 'int'], '1,000')['ok'], '다른 정수 칸은 그대로 (쉼표 안 됨, 5자리)');
});

test('ASL: 형식 검사 — 머리글·대회·라운드·종족·이름 오류는 행 번호와 함께 ASL만 사용 불가', function () {
    $ok = asl_parse(fx_asl());
    assert_same([true, 10], [$ok['available'], count($ok['games'])], '빈 행은 건너뜀');
    assert_same([0, 1, 2], array_keys($ok['seasons']), '시즌 순서: 대국민 스타리그 = 0');
    assert_same([0, 1, 22, null, null, null], array_map('asl_season_no', ['대국민 스타리그', 'ASL Season 1', ' ASL  Season 22 ', 'KSL Season 1', 'ASL Season 0', '']));
    foreach ([
        [[4 => 'X'], '3행 종족(X/P)'], [[2 => '64강'], '3행 라운드(64강)'], [[1 => 'KSL Season 1'], '3행 대회(KSL Season 1)'],
        [[7 => '가선수'], '3행 같은 선수'], [[3 => ''], '3행 선수 이름'],
    ] as [$patch, $msg]) {
        $rows = fx_asl();
        $rows[2] = array_replace($rows[2], $patch);
        $r = asl_parse($rows);
        assert_true(!$r['available'] && str_contains((string)$r['error'], $msg), (string)$r['error']);
    }
    $rows = fx_asl();
    $rows[0][2] = '단계';
    assert_true(str_contains((string)asl_parse($rows)['error'], '머리글'));
    // 한 행만 틀려도 ASL 전체를 쓰지 않는다 → 끝장전 데이터는 그대로, ASL CG는 차단
    setup_asl(fn(array &$t) => $t['asl'][4][2] = '64강');
    $c = panel_state(op())['data']['check'];
    assert_true((bool)array_filter($c['unavailable'], fn($u) => str_starts_with($u, 'ASL 기록 사용 불가') && str_contains($u, '5행')));
    $st = type_state('asl-record', ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수']]);
    assert_same([null, '가선수'], [$st['final']['a.titles'], $st['final']['a.name']], '수치는 비우고 이름만');
    assert_true($st['problems'] !== []);
    assert_true(str_contains(instance_get(channel_get('preview')['instance_id'])['issues'][0]['msg'], 'ASL 기록을 쓸 수 없습니다'));
    assert_same(40, $c['counts']['games']);
});

test('ASL: 우승·준우승·4강 이상, 결승전 없는 시즌은 우승자 미정 → 그 시즌 4강 선수의 우승·준우승만 차단, 중복 의심은 안내만', function () {
    $asl = asl_parse(fx_asl());
    assert_same(['가선수', '다선수', null], [$asl['seasons'][1]['champion'], $asl['seasons'][1]['runner'], $asl['seasons'][1]['undetermined']]);
    assert_same(['나선수', '가선수'], [$asl['seasons'][0]['champion'], $asl['seasons'][0]['runner']]);
    assert_same([null, '결승전 기록 없음'], [$asl['seasons'][2]['champion'], $asl['seasons'][2]['undetermined']]);
    assert_same(['경기번호 1·6'], $asl['dupes']);
    assert_same(['games' => 8, 'titles' => 1, 'runner' => 1, 'semis' => 2, 'wins' => 5, 'losses' => 3, 'unsure' => []], stats_asl_record($asl, '가선수'));
    assert_same(['games' => 5, 'titles' => 1, 'runner' => 0, 'semis' => 3, 'wins' => 3, 'losses' => 2, 'unsure' => ['ASL Season 2']],
        stats_asl_record($asl, '나선수'));
    // 결승전 동점·3명 이상도 미정
    $tie = fx_asl();
    unset($tie[5]);
    assert_same('결승전 1:1 동점', asl_parse(array_values($tie))['seasons'][1]['undetermined']);

    setup_asl();
    $c = panel_state(op())['data']['check'];
    assert_same(10, $c['counts']['asl_games']);
    assert_true(str_contains(implode(' / ', $c['asl_notes']), 'ASL Season 2: 우승자를 정할 수 없음'));
    assert_true(str_contains(implode(' / ', $c['asl_notes']), '중복 의심 1쌍') && str_contains(implode(' / ', $c['asl_notes']), '경기번호 1·6'));
    $st = type_state('asl-record', ['a' => ['player' => '가선수'], 'b' => ['player' => '다선수']]);
    assert_same([1, 1, 2, 5, 3, 0, 1, 2], array_map(fn($k) => $st['final'][$k], ['a.titles', 'a.runner', 'a.semis', 'a.wins', 'a.losses',
        'b.titles', 'b.runner', 'b.semis']));
    $p = implode(' / ', $st['problems']);
    assert_true(str_contains($p, '다선수: ASL Season 2 우승자를 정할 수 없어') && str_contains($p, 'B 우승') && !str_contains($p, 'B 4강'), $p);
    assert_true(!str_contains($p, '가선수'), '가선수는 막지 않음');
    $iid = channel_get('preview')['instance_id'];
    preview_save($iid, ['b.titles' => '0', 'b.runner' => '1'], op());
    $st = instance_state(instance_get($iid), current_session_id());
    assert_same([], $st['problems'], '확인한 값을 직접 넣으면 송출');
    assert_same(['우승 1회', '준우승 1회 · 4강 이상 2회', '세트 5승 3패 (62.5%)'],
        [$st['view']['cols'][0]['rate'], $st['view']['cols'][0]['count'], $st['view']['cols'][0]['detail']]);
    assert_same(['ASL 선수 기록', '세트 2승 5패 (28.6%)'], [$st['view']['title'], $st['view']['cols'][1]['detail']]);
    assert_true(str_contains(cg_render($st['view']), 'cg-box cg-rwr cg-full-set cg-asl'), '풀세트 접전 확률과 같은 칸 모양');
    assert_throws(ActionError::class, fn() => page_add(['template' => 'asl-record', 'params' => ['a' => ['player' => '가선수'], 'b' => ['player' => '가선수']]], op()), 'BAD_PARAMS');
});

test('ASL: 기록에 없는 선수·ASL 미설정은 차단, ASL 상대 종족 승률 = 그 세트의 상대 종족', function () {
    setup_asl();
    $st = type_state('asl-race', ['a' => ['player' => '가선수', 'vs' => 'T'], 'b' => ['player' => '나선수', 'vs' => 'Z']]);
    assert_same([], $st['problems']);
    assert_same(['4승 1패', '(80.0%)'], [$st['view']['cols'][0]['record'], $st['view']['cols'][0]['rate']]);
    assert_same(['2승 1패', '(66.7%)'], [$st['view']['cols'][1]['record'], $st['view']['cols'][1]['rate']], '나선수 vs Z');
    assert_same(['ASL 상대 종족 승률', '나선수', 'Z'], [$st['view']['title'], $st['view']['cols'][1]['name'], $st['view']['cols'][1]['vs']]);
    assert_true(str_contains(cg_render($st['view']), 'cg-box cg-rwr'), '상대 종족 승률과 같은 화면');
    $st = type_state('asl-race', ['a' => ['player' => '가선수', 'vs' => 'T'], 'b' => ['player' => '라선수', 'vs' => 'Z']]);
    assert_same([0, 0], [$st['final']['b.wins'], $st['final']['b.losses']]);
    assert_true((bool)array_filter($st['problems'], fn($p) => str_contains($p, '라선수: ASL 기록에 없는 선수입니다')));
    assert_same(['wins' => 1, 'losses' => 2], stats_asl_race(asl_parse(fx_asl()), '가선수', 'P'));
    $st = type_state('asl-record', ['a' => ['player' => '라선수'], 'b' => ['player' => '가선수']]);
    assert_true((bool)array_filter($st['problems'], fn($p) => str_contains($p, '라선수: ASL 기록에 없는 선수입니다')));
    $iid = channel_get('preview')['instance_id'];
    preview_save($iid, ['a.titles' => '0', 'a.runner' => '0', 'a.semis' => '0', 'a.wins' => '0', 'a.losses' => '0'], op());
    assert_same([], instance_state(instance_get($iid), current_session_id())['problems'], '수동 입력 허용');

    setup_sheet(); // ASL 시트 미설정
    $st = type_state('asl-race', ['a' => ['player' => '가선수', 'vs' => 'T'], 'b' => ['player' => '나선수', 'vs' => 'Z']]);
    assert_true(str_contains(instance_get(channel_get('preview')['instance_id'])['issues'][0]['msg'], 'ASL 시트 주소가 설정되지 않았습니다'));
    assert_same([null, '가선수'], [$st['final']['a.wins'], $st['final']['a.name']]);
    assert_true($st['problems'] !== [], '수치가 비어 송출 불가');
});

test('ASL: 설정 저장(주소→ID, 운영자에게는 설정됨만), 새로고침 때 같은 키로 보기용 탭 읽기, ASL 실패해도 끝장전 새로고침은 성공', function () {
    fresh_db();
    $k = fx_key();
    google_key_save($k['json'], op());
    $operator = ['name' => '운영', 'role' => 'operator', 'user_id' => 2];
    data_settings_save(['source' => 'sheet', 'sheet' => 'TESTsheetID_0123456789abc',
        'asl_sheet' => 'https://docs.google.com/spreadsheets/d/ASLsheetID_0123456789abcd/edit#gid=1'], op());
    assert_same('ASLsheetID_0123456789abcd', data_settings_view(op())['asl_sheet_id']);
    assert_same('설정됨', data_settings_view($operator)['asl_sheet_id']);
    data_settings_save(['source' => 'sheet', 'sheet' => 'TESTsheetID_0123456789abc'], op());
    assert_same('ASLsheetID_0123456789abcd', sheet_config()['asl_id'], '보내지 않으면 그대로');
    assert_throws(ActionError::class, fn() => data_settings_save(['source' => 'sheet', 'asl_sheet' => 'https://example.com/x'], op()), 'BAD_SHEET');

    $log = [];
    $main = fx_transport(fx_tabs(), $k['pub'], $log);
    foreach ([200, 403] as $status) {
        $GLOBALS['CG_HTTP'] = function (string $m, string $url, array $h, ?string $b) use ($main, $status, &$log): array {
            if (!str_contains($url, 'ASLsheetID_0123456789abcd')) {
                return $main($m, $url, $h, $b);
            }
            $log[] = [$m, $url];
            assert_true(in_array('Authorization: Bearer tok-secret-123', $h, true), '같은 토큰');
            assert_true(str_contains(rawurldecode($url), "ranges='보기용'!A:H"), rawurldecode($url));
            return $status === 200 ? ['status' => 200, 'body' => json_encode(['valueRanges' => [['values' => fx_asl()]]])]
                : ['status' => 403, 'body' => '{"error":{"message":"denied tok-secret-123"}}'];
        };
        try {
            data_refresh(op());
        } finally {
            unset($GLOBALS['CG_HTTP']);
        }
        $c = panel_state(op())['data']['check'];
        assert_same(['OK', 40], [source_status()['status'], $c['counts']['games']], '끝장전 새로고침 성공');
        $u = array_values(array_filter($c['unavailable'], fn($x) => str_starts_with($x, 'ASL')));
        if ($status === 200) {
            assert_same([[], 10], [$u, $c['counts']['asl_games']]);
        } else {
            assert_same(['ASL 기록 사용 불가: 시트에 접근할 권한이 없습니다. 시트를 서비스 계정 이메일과 "뷰어"로 공유했는지 확인하세요.'], $u);
            assert_same(0, $c['counts']['asl_games']);
            type_state('asl-record', ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수']]);
            assert_true(str_contains(instance_get(channel_get('preview')['instance_id'])['issues'][0]['msg'], 'ASL 기록을 쓸 수 없습니다'));
        }
    }
    // xlsx 가져오기는 ASL을 읽지 않는다 (안내)
    data_import_xlsx(['file' => base64_encode(fx_xlsx(fx_tabs()))], op());
    assert_true((bool)array_filter(panel_state(op())['data']['check']['unavailable'], fn($x) => str_contains($x, 'xlsx 가져오기는 ASL 시트를 읽지 않습니다')));
});
