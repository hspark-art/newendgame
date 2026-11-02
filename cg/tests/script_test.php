<?php
declare(strict_types=1);

// v0.11.0 대본(Google 문서) → 온라인 CG 4종. 합성 Docs API 모양 JSON만 쓴다 (실제 문서 내용은 저장소에 넣지 않는다)

/** 문단: 조각은 글자 또는 [글자, 취소선] (굵게 등으로 나뉜 조각 흉내) */
function sd_p(string|array ...$runs): array
{
    $els = array_map(static fn($r) => ['textRun' => ['content' => is_array($r) ? $r[0] : $r,
        'textStyle' => is_array($r) && $r[1] ? ['strikethrough' => true, 'bold' => true] : ['bold' => true]]], $runs);
    $els[count($els) - 1]['textRun']['content'] .= "\n";
    return ['paragraph' => ['elements' => $els, 'paragraphStyle' => ['namedStyleType' => 'NORMAL_TEXT']]];
}

/** 표: 행[칸[줄]], 줄은 글자 또는 조각 목록 */
function sd_t(array $rows): array
{
    return ['table' => ['tableRows' => array_map(static fn($cells) => ['tableCells' => array_map(static fn($lines) => [
        'content' => array_map(static fn($l) => is_array($l) ? sd_p(...$l) : sd_p($l), (array)$lines)], $cells)], $rows)]];
}

function sd_h(string $t): array
{
    $p = sd_p($t);
    $p['paragraph']['paragraphStyle']['namedStyleType'] = 'HEADING_2';
    return $p;
}

/** 한 경기 구역 (A·B = 제목 순서) */
function sd_match(string $head, string $a, string $b, string $total = '231 : 178'): array
{
    return [
        sd_h($head),
        sd_t([["$a(BAN:맵하나)", '승', '세트 / 맵', '승', "$b(BAN:맵둘)"], ['', '', '1세트 맵셋', '', '']]),
        sd_p('맵: ', ['맵하나', true], ' / ', ['맵둘', true], ' / 맵셋 /  맵넷, 맵다섯'),
        sd_t([[['상대 전적', [substr($a, 0, 3), substr($a, 3) . " $total $b"], '최근 한 달 상대 전적 (9월 25일~10월 25일)', "$a 4 : 6 $b",
            '최근 한 달 상대 종족 전적', "$a vs P 23승 18패 (56.1%)  |  $b Vs 저그전 65승 55패 (54.2%)"]]]),
        sd_t([[['이야기 / 텍스트, 예시 / 무시']]]),
        sd_t([
            [['맵셋  Z 3 : 5 P (끝장전)', "$a 3 : 5 $b (온라인)", "$a vs 프로토스전 18승 21패 (46.2%)", "$b vs Z 57승 30패 (65.5%)"],
                ['맵넷', 'Z 2 : 2 P (끝장전)', "$b 2 : 1 $a", "$a vs P 10승 10패 (50.0%)", "$b vs Z 10승 5패 (99.9%)"]],
            [[[['맵하나', true]], "$a 1 : 0 $b", "$a vs P 1승 0패 (100.0%)", "$b vs Z 0승 1패 (0.0%)"], ['맵별 종족전 승률 비교 · 메모']],
        ]),
        sd_t([[['EloBoard 맵별 등록 누적 전적']], [['맵셋'], ['Z 1,527 : 1,662 P (47.9%)']], [[[['맵하나', true]]], ['Z 115 : 161 P (41.7%)']],
            [['맵넷'], ['Z 10 : 10 P (12.0%)']]]),
    ];
}

/** 탭 두 개짜리 문서: 탭1 = 예전 경기·없는 선수·최신 경기, 탭2 = 다른 경기 */
function sd_doc(): array
{
    $tab1 = array_merge([sd_p('머리말 / 아무, 글')], sd_match('0901_가선수_나선수', '가선수', '나선수', '1 : 1'),
        sd_match('0902_없는선수_가선수', '없는선수', '가선수'), sd_match('1026_가선수_나선수', '가선수', '나선수'));
    return ['documentId' => 'x', 'tabs' => [
        ['tabProperties' => ['tabId' => 't.aaa'], 'documentTab' => ['body' => ['content' => $tab1]]],
        ['tabProperties' => ['tabId' => 't.bbb'], 'documentTab' => ['body' => ['content' => sd_match('1027_다선수_라선수', '다선수', '라선수')]]],
    ]];
}

/** 대본 문서까지 읽는 가짜 Google (토큰은 시트·문서 권한을 따로) */
function sd_transport(array $key, array &$log, int $docStatus = 200): callable
{
    $main = fx_transport(fx_tabs(), $key['pub'], $log);
    return function (string $m, string $url, array $h, ?string $b) use ($main, &$log, $docStatus): array {
        if (str_ends_with((string)parse_url($url, PHP_URL_PATH), '/token')) {
            parse_str((string)$b, $form);
            $claims = json_decode((string)base64_decode(strtr(explode('.', $form['assertion'])[1], '-_', '+/')), true);
            if ($claims['scope'] === DOCS_SCOPE) {
                $log[] = ['docs-token'];
                return ['status' => 200, 'body' => '{"access_token":"doc-tok-1","expires_in":3600}'];
            }
            return $main($m, $url, $h, $b);
        }
        if (str_starts_with($url, DOCS_API_BASE)) {
            $log[] = ['docs', $url];
            assert_true(in_array('Authorization: Bearer doc-tok-1', $h, true), '문서 토큰');
            return $docStatus === 200 ? ['status' => 200, 'body' => json_encode(sd_doc())]
                : ['status' => $docStatus, 'body' => '{"error":{"message":"denied doc-tok-1"}}'];
        }
        return $main($m, $url, $h, $b);
    };
}

/** 시트 + 대본을 반영한 새 DB */
function setup_script(): array
{
    return setup_sheet(function (array &$t) {
        $t['script'] = script_parse_doc(sd_doc());
    });
}

test('대본: 주소 → 문서 ID·탭, 구역·밴(취소선)·굵게 나뉜 조각·Vs/저그전·(온라인)·같은 줄 맵·쉼표 숫자', function () {
    assert_same(['id' => 'DOCid_0123456789abcdefghij', 'tab' => 't.qczorf7u8ke2'],
        script_ref_from('https://docs.google.com/document/d/DOCid_0123456789abcdefghij/edit?tab=t.qczorf7u8ke2'));
    assert_same(['id' => 'DOCid_0123456789abcdefghij', 'tab' => ''], script_ref_from('DOCid_0123456789abcdefghij'));
    assert_same(null, script_ref_from('https://example.com/x'));

    $r = script_parse_doc(sd_doc());
    assert_same(['0901_가선수_나선수', '0902_없는선수_가선수', '1026_가선수_나선수', '1027_다선수_라선수'], array_column($r['sections'], 'key'));
    $s = $r['sections'][2];
    assert_same(['가선수', '나선수', ['맵하나', '맵둘']], [$s['a'], $s['b'], $s['bans']]);
    assert_same([['맵하나', true], ['맵둘', true], ['맵셋', false], ['맵넷', false], ['맵다섯', false]],
        array_map(fn($m) => [$m['name'], $m['banned']], $s['maps']));
    assert_same([231, 178], $s['total'], '굵게 나뉜 조각을 이어 붙임');
    assert_same(['09.25~10.25', [4, 6]], [$s['recent']['label'], $s['recent']['h2h']]);
    assert_same(['a' => ['vs' => 'P', 'w' => 23, 'l' => 18], 'b' => ['vs' => 'Z', 'w' => 65, 'l' => 55]], $s['recent']['race'], 'Vs 저그전 → Z');
    assert_same(['h2h' => [3, 5], 'a' => ['vs' => 'P', 'w' => 18, 'l' => 21], 'b' => ['vs' => 'Z', 'w' => 57, 'l' => 30]], $s['map']['맵셋'],
        '맵 이름과 같은 줄의 오프라인 전적·(온라인) 꼬리표·프로토스전');
    assert_same(['h2h' => [1, 2], 'a' => ['vs' => 'P', 'w' => 10, 'l' => 10], 'b' => null], $s['map']['맵넷'], '반대 순서 맞대결, 승률 불일치는 비움');
    assert_true(isset($s['map']['맵하나']) && !isset($s['map']['맵별 종족전 승률 비교 · 메모']), '취소선 맵 칸, 요약 칸 무시');
    assert_same(['lr' => 'Z', 'rr' => 'P', 'lw' => 1527, 'rw' => 1662], $s['cum']['맵셋'], '쉼표 숫자');
    assert_same(['lr' => 'Z', 'rr' => 'P', 'lw' => 115, 'rw' => 161], $s['cum']['맵하나']);
    assert_same(null, $s['cum']['맵넷'], '승률 불일치 → 비움');
    assert_true(in_array('대본 1026_가선수_나선수 맵 맵넷: 승률 불일치', $r['notes'], true), implode(' / ', $r['notes']));
    assert_true(in_array('대본 1026_가선수_나선수 누적 종족 전적 맵 맵넷: 승률 불일치', $r['notes'], true));
    // 탭 지정: 그 탭만, 없는 탭은 오류
    assert_same(['1027_다선수_라선수'], array_column(script_parse_doc(sd_doc(), 't.bbb')['sections'], 'key'));
    assert_throws(ProviderError::class, fn() => script_parse_doc(sd_doc(), 't.zzz'));
    // 탭 없는 예전 형식 문서
    assert_same(['1027_다선수_라선수'], array_column(script_parse_doc(['body' => ['content' => sd_match('1027_다선수_라선수', '다선수', '라선수')]])['sections'], 'key'));
    // 승률 확인: 소수 한 자리 반올림 (0.05 이내)
    assert_same([true, true, false], [script_rate_ok(23, 18, '56.1'), script_rate_ok(1, 2, '33.3'), script_rate_ok(1, 2, '33.4')]);
});

test('대본: 데이터에 없는 선수 구역은 빼고 안내, 가장 뒤(최신) 구역, 선수 순서가 반대면 값 뒤집기, 문서 글은 저장하지 않음', function () {
    $ds = setup_script();
    $sc = $ds['script'];
    assert_same(['0901_가선수_나선수', '1026_가선수_나선수', '1027_다선수_라선수'], array_column($sc['sections'], 'key'));
    assert_true(in_array('대본 0902_없는선수_가선수: 데이터에 없는 선수 이름 (없는선수) — 건너뜀', $ds['check']['script_notes'], true));
    assert_same('1026_가선수_나선수', script_pick($sc['sections'], '가선수', '나선수')['key'], '최신 구역');
    $r = script_pick($sc['sections'], '나선수', '가선수');
    assert_same(['나선수', [178, 231], [6, 4], ['vs' => 'Z', 'w' => 65, 'l' => 55], [5, 3], ['맵둘', '맵하나']],
        [$r['a'], $r['total'], $r['recent']['h2h'], $r['recent']['race']['a'], $r['map']['맵셋']['h2h'], $r['bans']]);
    assert_same(null, script_pick($sc['sections'], '가선수', '다선수'));
    $c = panel_state(op())['data']['check'];
    assert_same(3, $c['counts']['script_matches']);
    $cache = (string)setting_get('script_cache');
    assert_true(!str_contains($cache, '이야기') && !str_contains($cache, '머리말') && !str_contains(json_enc(dataset_cache_get('sheet')), '이야기'), '문서 글 없음');
});

test('대본: 새로고침 때 문서 읽기 전용 토큰으로 Docs API GET(탭 포함), 문서 실패해도 시트 새로고침은 성공하고 온라인 CG만 막힘', function () {
    fresh_db();
    $k = fx_key();
    google_key_save($k['json'], op());
    $operator = ['name' => '운영', 'role' => 'operator', 'user_id' => 2];
    data_settings_save(['source' => 'sheet', 'sheet' => 'TESTsheetID_0123456789abc',
        'script_doc' => 'https://docs.google.com/document/d/DOCid_0123456789abcdefghij/edit?tab=t.aaa'], op());
    assert_same(['DOCid_0123456789abcdefghij', 't.aaa'], [sheet_config()['script_id'], sheet_config()['script_tab']]);
    assert_same('설정됨', data_settings_view($operator)['script_doc']);
    data_settings_save(['source' => 'sheet', 'sheet' => 'TESTsheetID_0123456789abc'], op());
    assert_same('DOCid_0123456789abcdefghij', sheet_config()['script_id'], '보내지 않으면 그대로');
    assert_throws(ActionError::class, fn() => data_settings_save(['source' => 'sheet', 'script_doc' => 'https://example.com/x'], op()), 'BAD_SHEET');

    foreach ([200, 403] as $status) {
        $log = [];
        $GLOBALS['CG_HTTP'] = sd_transport($k, $log, $status);
        try {
            data_refresh(op());
        } finally {
            unset($GLOBALS['CG_HTTP']);
        }
        $docs = array_values(array_filter($log, fn($l) => $l[0] === 'docs'));
        assert_same(DOCS_API_BASE . 'DOCid_0123456789abcdefghij?includeTabsContent=true', $docs[0][1]);
        assert_true(in_array(['docs-token'], $log, true), '문서 권한 토큰을 따로');
        $c = panel_state(op())['data']['check'];
        assert_same(['OK', 40], [source_status()['status'], $c['counts']['games']], '시트 새로고침 성공');
        $u = array_values(array_filter($c['unavailable'], fn($x) => str_starts_with($x, '대본')));
        $st = type_state('online-h2h', ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수']]);
        if ($status === 200) {
            assert_same([[], 2], [$u, $c['counts']['script_matches']], '지정 탭(t.aaa)만');
            assert_same([], $st['problems']);
        } else {
            assert_same(['대본 사용 불가: 대본 문서에 접근할 권한이 없습니다. 문서를 서비스 계정 이메일과 "뷰어"로 공유했는지, Google Docs API를 사용 설정했는지 확인하세요.'], $u);
            assert_true(in_array('맞대결 A 승: 값이 없습니다.', $st['problems'], true), '온라인 CG만 막힘');
            assert_same([], type_state('race-win-rate', ['a' => ['player' => '가선수', 'vs' => 'P'], 'b' => ['player' => '나선수', 'vs' => 'Z']])['problems']);
            assert_true(!str_contains(json_enc(panel_state(op())), 'doc-tok-1'), '토큰 노출 없음');
        }
    }
});

test('대본 CG: #6 통산·최근 한 달, #20 최근 한 달 종족, 맵별 온라인 전적, 맵별 누적 종족 전적 — 화면 글자', function () {
    setup_script();
    $p = ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수']];
    $st = type_state('online-h2h', $p);
    assert_same(['온라인 전적 최근 한 달 (09.25~10.25)', '4 : 6', '4승 6패', '(40.0%)', '6승 4패'], [$st['view']['title'], $st['view']['h2h'],
        $st['view']['cols'][0]['record'], $st['view']['cols'][0]['rate'], $st['view']['cols'][1]['record']]);
    assert_true(str_contains(rundown_rows()[0]['summary'] ?? template_summary('online-h2h', instance_get(channel_get('preview')['instance_id'])['params']), '대본 1026_가선수_나선수'));
    $st = type_state('online-h2h', $p + ['basis' => 'total']);
    assert_same(['온라인 상대 전적 (통산)', '231 : 178', '(56.5%)'], [$st['view']['title'], $st['view']['h2h'], $st['view']['cols'][0]['rate']]);
    assert_throws(ActionError::class, fn() => type_state('online-h2h', $p + ['basis' => 'period']), 'BAD_PARAMS');
    // 선수 순서를 바꾼 페이지
    $st = type_state('online-h2h', ['a' => ['player' => '나선수'], 'b' => ['player' => '가선수']]);
    assert_same(['나선수', '6 : 4'], [$st['view']['cols'][0]['name'], $st['view']['h2h']]);

    $st = type_state('online-race', $p);
    assert_same([], $st['problems']);
    $html = cg_render($st['view']);
    assert_true(str_contains($html, '<small>09.25~10.25</small> 가선수 vs P · 나선수 vs Z'), $html);
    assert_same(['온라인 종족 전적 최근 한 달', '23승 18패', '(56.1%)', '65승 55패', 'Z'], [$st['view']['title'], $st['view']['cols'][0]['record'],
        $st['view']['cols'][0]['rate'], $st['view']['cols'][1]['record'], $st['view']['cols'][1]['vs']]);

    $st = type_state('online-map', $p + ['map' => '맵셋']);
    $html = cg_render($st['view']);
    assert_same(['맵셋 · 온라인 맵 전적', '3 : 5', '18승 21패', '(46.2%)', '57승 30패'], [$st['view']['title'], $st['view']['h2h'],
        $st['view']['cols'][0]['record'], $st['view']['cols'][0]['rate'], $st['view']['cols'][1]['record']]);
    assert_true(str_contains($html, '<small>온라인 맞대결</small> 가선수 <b>3 : 5</b> 나선수') && str_contains($html, '<span class="cg-vs">vs P</span>'));
    assert_throws(ActionError::class, fn() => type_state('online-map', $p + ['map' => '맵하나']), 'BAD_PARAMS'); // 밴 맵
    assert_throws(ActionError::class, fn() => type_state('online-map', $p + ['map' => '없는맵']), 'BAD_PARAMS');
    assert_throws(ActionError::class, fn() => type_state('online-map', ['a' => ['player' => '가선수'], 'b' => ['player' => '다선수'], 'map' => '맵셋']), 'BAD_PARAMS');
    $st = type_state('online-map', $p + ['map' => '맵넷']);
    assert_true(in_array('B 맵 승: 값이 없습니다.', $st['problems'], true), '승률 불일치 값은 비워 송출 불가');

    $st = type_state('online-map-cum', $p + ['map' => '맵하나']); // 밴 맵도 누적 전적은 고를 수 있음
    $html = cg_render($st['view']);
    assert_same(['맵하나 · 맵 누적 종족 전적', '저그', '115승 161패', '(41.7%)', '프로토스', '161승 115패', '(58.3%)'], [$st['view']['title'],
        $st['view']['cols'][0]['name'], $st['view']['cols'][0]['record'], $st['view']['cols'][0]['rate'],
        $st['view']['cols'][1]['name'], $st['view']['cols'][1]['record'], $st['view']['cols'][1]['rate']]);
    assert_true(str_contains($html, '<small>온라인 누적</small> 저그 vs 프로토스 전체 등록 전적'));
    // A 선수 종족(P)이 오른쪽이면 왼쪽으로
    $st = type_state('online-map-cum', ['a' => ['player' => '나선수'], 'b' => ['player' => '가선수'], 'map' => '맵셋']);
    assert_same(['프로토스', '1662승 1527패'], [$st['view']['cols'][0]['name'], $st['view']['cols'][0]['record']]);
});

test('대본 CG: 수동 수정·항목 빼기, 오늘 매치 2인 메뉴, 패널에 대본 경기 목록', function () {
    setup_script();
    $p = ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수']];
    $st = type_state('online-race', $p);
    $id = channel_get('preview')['instance_id'];
    preview_save($id, ['title' => '수동 제목', 'a.wins' => 30, 'sum.label' => '10월'], op());
    $st = instance_state(instance_get($id), current_session_id());
    assert_same(['수동 제목', '30승 18패', '(62.5%)'], [$st['view']['title'], $st['view']['cols'][0]['record'], $st['view']['cols'][0]['rate']]);
    assert_true(str_contains(cg_render($st['view']), '<small>10월</small>'));
    instance_hide($id, ['sum.label', 'b.vs'], true, op());
    $st = instance_state(instance_get($id), current_session_id());
    $html = cg_render($st['view']);
    assert_true(!str_contains($html, '<small>') && str_contains($html, '가선수 vs P · 나선수</span>'), $html);
    data_refresh(op(), sheet_dataset(fx_tables(fn(array &$t) => $t['script'] = script_parse_doc(sd_doc())), 'api'));
    assert_same('수동 제목', instance_state(instance_get($id), current_session_id())['view']['title'], '새로고침 뒤에도 수동값 유지');

    $st = type_state('online-map-cum', $p + ['map' => '맵셋']);
    instance_hide(channel_get('preview')['instance_id'], ['sum'], true, op());
    assert_same([], instance_state(instance_get(channel_get('preview')['instance_id']), current_session_id())['problems']);

    $slugs = array_column(match_today_save(['a' => '가선수', 'b' => '나선수'], op())['templates'], 'slug');
    assert_true(in_array('online-map', $slugs, true) && in_array('online-map-cum', $slugs, true));
    $ps = panel_state(op());
    assert_same(['0901_가선수_나선수', '1026_가선수_나선수', '1027_다선수_라선수'], array_column($ps['script'], 'key'));
    $tpl = array_column($ps['templates'], null, 'slug');
    assert_same([['month', '최근 한 달'], ['total', '통산']], $tpl['online-h2h']['params'][2]['options']);
    assert_same(['온라인', '온라종족', '온라맵', '맵누적'], [$tpl['online-h2h']['short'], $tpl['online-race']['short'],
        $tpl['online-map']['short'], $tpl['online-map-cum']['short']]);
});
