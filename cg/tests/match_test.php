<?php
declare(strict_types=1);

// v0.6: 타이틀 에디터 '항목 빼기', 오늘 매치(한 번에 추가·페이지 리스트 바꾸기), 맵 최근 순서
// v0.6.1: 항목 빼기는 항목마다(빨간 −). 묶음 전체는 패널에서 Shift+클릭 = 묶음의 키를 모두 보냄 (hide_group)
// 합성 시트(fx_tables): 가선수 Z, 나선수 P, 다선수 T, 라선수 Z / 맵 = 'Map 1'~'Map 9'

/** 필드마다 그럴듯한 값 (14종 그리기 확인용) */
function sample_final(string $slug): array
{
    return array_map(static fn(array $def) => match ($def['type']) {
        'text' => ($def['max'] ?? 9) === 1 ? 'Z' : (($def['max'] ?? 9) === 5 ? 'WWLWL' : '값'),
        'date' => '2026-01-02', 'rate' => 612, 'srate' => -35, 'sint' => -1200, default => 3,
    }, template_get($slug)['fields']);
}

/** 패널의 Shift+클릭과 같음: 묶음(group)의 항목을 모두 빼기/다시 넣기 */
function hide_group(int $iid, string $group, bool $hide): array
{
    $fields = template_get(instance_get($iid)['template'])['fields'];
    return instance_hide($iid, array_keys(array_filter($fields, static fn($d) => ($d['group'] ?? '') === $group)), $hide, op());
}

test('항목 빼기: 묶음을 빼면 송출 화면에서 사라지고, 필수 항목이어도 송출 가능, TAKE·UPDATE LIVE로 반영', function () {
    setup_sheet();
    match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => true], op());
    $st = type_state('match-preview', ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수'], 'map' => 'Map 1']);
    $iid = channel_get('preview')['instance_id'];
    $keys = static fn(array $st) => array_column($st['view']['rows'], 'key');
    assert_same(['match', 'set', 'race', 'form', 'h2h', 'map'], $keys($st));
    program_take(channel_get('preview')['rev'], ['effect' => 'cut'], op());

    hide_group($iid, '최근 5경기', true);
    hide_group($iid, '매치 전적', true); // 필수 항목(매치 승·패)이 있는 묶음
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
    hide_group($iid, '매치 전적', false);
    program_update_live($iid, channel_get('program')['take_id'], channel_get('preview')['rev'], [], op());
    $snap = channel_get('program')['snapshot'];
    assert_same(['match', 'set', 'race', 'h2h', 'map'], array_column($snap['view']['rows'], 'key'));
    assert_same('2승 1패', $snap['view']['rows'][0]['a']);
    assert_same(['a.form', 'b.form'], $snap['hidden']);

    assert_throws(ActionError::class, fn() => instance_hide($iid, [], true, op()), 'VALIDATION');
    assert_throws(ActionError::class, fn() => instance_hide($iid, ['a.mw', '없는 항목'], true, op()), 'VALIDATION');
    // 맞대결 줄을 빼면 "첫 맞대결"로 바뀌지 않고 줄이 사라진다
    hide_group($iid, '맞대결', true);
    assert_true(!in_array('h2h', $keys(instance_state(instance_get($iid), current_session_id())), true));
});

test('항목 빼기(v0.6.1): 항목 하나만 빼면 그 자리만 비고 줄은 남는다 — 제목·이름·승·승률·맞대결·맵 이름', function () {
    setup_sheet();
    match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => true], op());
    $st = type_state('match-preview', ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수'], 'map' => 'Map 1']);
    $iid = channel_get('preview')['instance_id'];
    $row = static fn(array $st, string $k) => array_column($st['view']['rows'], null, 'key')[$k] ?? null;
    $match = $row($st, 'match');
    assert_same('2승 1패', $match['a']);
    $leadBefore = $match['lead'];
    $state = static fn() => instance_state(instance_get($iid), current_session_id());

    // 제목 → 제목 줄(띠 포함)이 송출 화면에서 사라짐. 필수 항목이어도 송출 가능
    instance_hide($iid, ['title'], true, op());
    $st = $state();
    assert_same(['', []], [$st['view']['title'], $st['problems']]);
    assert_true(!str_contains(cg_render($st['view']), 'cg-title') && !str_contains(cg_render($st['view']), 'cg-band'));
    // A 이름만 → A 이름 칸만 비고 B는 그대로
    instance_hide($iid, ['a.name'], true, op());
    $st = $state();
    assert_same(['', '나선수', []], [$st['view']['a']['name'], $st['view']['b']['name'], $st['problems']]);
    // A 매치 승만 → "1패", 줄은 남음. A 승률만 → 작은 글씨 비움, 강조(더 좋은 쪽)는 승·패로 계산해 그대로
    instance_hide($iid, ['a.mw'], true, op());
    instance_hide($iid, ['a.mrate', 'b.mrate'], true, op());
    $m = $row($state(), 'match');
    assert_same(['1패', '', ''], [$m['a'], $m['a_sub'], $m['b_sub']]);
    instance_hide($iid, ['a.mw'], false, op());
    assert_same($leadBefore, $row($state(), 'match')['lead'], '승률을 빼도 강조는 유지');
    // 맞대결: 승수만 빼면 줄은 남고 세트만 표시, 넷 다 빼야 줄이 사라짐
    instance_hide($iid, ['h.a', 'h.b'], true, op());
    $h = $row($state(), 'h2h');
    assert_true($h !== null && $h['a'] === '' && $h['b'] === '' && str_starts_with($h['a_sub'], '세트 '));
    // 맵 이름만 → 가운데 칸만 비고 맵 줄은 남음
    instance_hide($iid, ['map.name'], true, op());
    $mp = $row($state(), 'map');
    assert_true($mp !== null && $mp['label'] === '' && $mp['a'] !== '');
    // 상대 종족만 → "vs Z"만 빠짐
    instance_hide($iid, ['a.vs'], true, op());
    assert_true(!str_contains($row($state(), 'race')['a_sub'], 'vs'));
    // 줄의 항목을 모두 빼면 줄이 사라짐 (항목 하나씩 빼서)
    foreach (['a.sw', 'a.sl', 'a.srate', 'b.sw', 'b.sl'] as $k) {
        instance_hide($iid, [$k], true, op());
        assert_true($row($state(), 'set') !== null, "{$k}까지 빼도 줄은 남음");
    }
    instance_hide($iid, ['b.srate'], true, op());
    assert_same(null, $row($state(), 'set'));

    // 순위 CG: 1행 이름만 빼도 1행·1위 강조는 그대로
    type_state('win-ranking', ['race' => '', 'count' => '3']);
    $wr = channel_get('preview')['instance_id'];
    instance_hide($wr, ['r1.name'], true, op());
    $v = instance_state(instance_get($wr), current_session_id())['view'];
    assert_same([3, '', true], [count($v['rows']), $v['rows'][0]['name'], $v['rows'][0]['top']]);
    instance_hide($wr, ['r1.losses'], true, op());
    assert_true(!str_contains(instance_state(instance_get($wr), current_session_id())['view']['rows'][0]['record'], 'L'));
    // 다시 넣기 = 같은 키로 hide=false (패널의 '모두 다시 넣기'는 뺀 키를 모두 보냄)
    instance_hide($wr, ['r1.name', 'r1.losses'], false, op());
    assert_same([], instance_get($wr)['hidden']);
});

test('항목 빼기(v0.6.1): CG 14종 모두 — 어떤 항목 하나를 빼도, 모두 빼도 오류 없이 그려진다', function () {
    $p = ['a' => ['player' => '가', 'vs' => 'P'], 'b' => ['player' => '나', 'vs' => 'T'], 'map' => 'Map 1', 'year' => 2026,
        'race' => '', 'count' => 3, 'seats' => []];
    foreach (cg_templates() as $slug => $tpl) {
        $final = sample_final($slug);
        $keys = array_keys($tpl['fields']);
        foreach (array_merge([[]], array_map(static fn($k) => [$k], $keys), [$keys]) as $hidden) {
            $view = template_present($slug, $final, $p, false, $hidden);
            $html = cg_render($view);
            assert_true($html !== '', "$slug 빼기 " . implode(',', $hidden));
            if (in_array('title', $hidden, true)) {
                assert_true(!str_contains($html, 'class="cg-title"'), "$slug 제목을 빼면 제목 줄 없음");
            }
        }
    }
});

test('항목 빼기: 순위 CG의 행, 맵 상성의 종족전 줄·아래 줄, 페이지 내보내기·가져오기에도 유지', function () {
    setup_sheet();
    match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => true], op());
    type_state('win-ranking', ['race' => '', 'count' => '3']);
    $iid = channel_get('preview')['instance_id'];
    hide_group($iid, '2행', true);
    $st = instance_state(instance_get($iid), current_session_id());
    assert_same(2, count($st['view']['rows']), '3명 중 2행을 뺌');
    hide_group($iid, '1행', true);
    hide_group($iid, '3행', true);
    assert_true(in_array('표시할 행이 없습니다. 행 값을 입력하거나 다른 조건을 고르세요.',
        instance_state(instance_get($iid), current_session_id())['problems'], true), '모두 빼면 송출 막음');

    type_state('map-matchup', ['map' => 'Map 1']);
    $mm = channel_get('preview')['instance_id'];
    hide_group($mm, 'P vs T', true);
    hide_group($mm, '총 세트·기간', true);
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

test('리뷰 수정 (v0.6): 같은 매치로 바꾸면 고른 종족 유지, 매치 바꾸면 뺀 항목 유지, 빈 최근 경기, 송출값 비교는 키 순서 무관', function () {
    setup_sheet();
    match_exclude(['match' => '2025-01-04|다선수|라선수', 'on' => true], op());
    // 운영자가 상대 종족을 직접 T로 고른 페이지 → 같은 매치로 '페이지 리스트 바꾸기'를 해도 그대로
    page_add(['template' => 'race-win-rate', 'params' => ['a' => ['player' => '가선수', 'vs' => 'T'], 'b' => ['player' => '나선수', 'vs' => 'Z']]], op());
    $r = match_pages_apply(['a' => '가선수', 'b' => '나선수'], op());
    assert_same([], $r['changed']);
    assert_same('T', json_dec(rundown_rows()[0]['params_json'])['a']['vs']);
    // 다른 매치로 바꾸면 바뀐 선수를 상대로 하는 종족만 새로 (B 그대로 → A의 상대 종족 T 유지, A가 다선수 → B의 상대 종족 T),
    // 그 페이지에서 뺀 항목은 새 CG에도 그대로
    $r = page_add(['template' => 'match-preview', 'params' => ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수'], 'map' => '']], op());
    hide_group((int)rundown_get($r['id'])['instance_id'], '최근 5경기', true);
    match_pages_apply(['a' => '다선수', 'b' => '나선수'], op());
    assert_same(['a.form', 'b.form'], instance_get((int)rundown_get($r['id'])['instance_id'])['hidden']);
    $p = json_dec(rundown_rows()[0]['params_json']);
    assert_same([['player' => '다선수', 'vs' => 'T'], ['player' => '나선수', 'vs' => 'T']], [$p['a'], $p['b']]);
    // 최근 경기가 없는 쪽은 빈 칸 (빈 L 칩이 생기지 않음 — PHP 8.1 str_split(''))
    $st = type_state('match-preview', ['a' => ['player' => '가선수'], 'b' => ['player' => '라선수'], 'map' => '']);
    $form = array_column($st['view']['rows'], null, 'key')['form'];
    assert_same([['W', 'W', 'L'], []], [$form['a'], $form['b']]);
    assert_true(!str_contains(cg_render($st['view']), '<i class="is-l"></i>'));
    assert_true(finals_same(['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]) && !finals_same(['a' => 1], ['a' => 2]) && finals_same(null, null));
});

test('리뷰 수정 (v0.6): 맵 칸이 빈 세트는 선수 맵 전적 불일치로 잡지 않음, 수익률 칸 하나가 이상하면 그 중계진만 대조 불가', function () {
    $ds = sheet_dataset(fx_tables(static function (array &$t) {
        $t['results'][1][4] = ''; // 맵 입력 전 세트
    }), 'api');
    // 빈 맵 이름(' 맵 전적: MAP 선수별 전적에 없음') 불일치가 생기지 않음
    $mis = array_filter($ds['check']['mismatches'], static fn($m) => $m['kind'] === 'mapsets' && $m['item'] === ' 맵 전적');
    assert_same([], array_values($mis), '빈 맵은 대조하지 않음');
    $ds = sheet_dataset(fx_tables(static function (array &$t) {
        $t['predictions'][3][16] = '#DIV/0!'; // 이해설 수익률 칸
    }), 'api');
    assert_true($ds['verify']['mission']['available'], '미션 대조 자체는 가능');
    assert_same(['김중계' => true, '이해설' => false], $ds['verify']['mission']['predictors']);
});

test('항목 빼기(v0.6.1 리뷰): 빈 승률 허용·1위/이긴 쪽 강조·첫 맞대결은 빼기 전 값으로, 빈 값 빼기도 UPDATE LIVE', function () {
    setup_sheet();
    $p2 = ['a' => ['player' => '가선수', 'vs' => 'P'], 'b' => ['player' => '나선수', 'vs' => 'T'], 'map' => 'Map 1'];
    // 0승 0패(빈 승률 허용)에서 승만 빼도 송출이 막히지 않음
    $raw = ['title' => '제목', 'a.name' => '가선수', 'a.wins' => 0, 'a.losses' => 0, 'a.rate' => null,
        'b.name' => '나선수', 'b.wins' => 3, 'b.losses' => 1, 'b.rate' => 750];
    assert_same([], template_problems('race-win-rate', $raw, $p2));
    assert_same([], template_problems('race-win-rate', $raw, $p2, [], [], ['a.wins']));
    // 자리 순서로 1행 = 3위, 2행 = 1위: 어느 순위를 빼도 1위 강조는 2행만
    foreach (['mission-index', 'prediction-ranking', 'win-ranking', 'win-streak'] as $slug) {
        $f = sample_final($slug);
        [$f['r1.rank'], $f['r2.rank']] = [3, 1];
        foreach ([[], ['r1.rank'], ['r2.rank']] as $h) {
            $tops = array_column(template_present($slug, $f, ['year' => 2026, 'race' => '', 'count' => 3, 'seats' => []], false, $h)['rows'], 'top');
            assert_same([false, true], array_slice($tops, 0, 2), "$slug 1위 강조 (뺀 항목 " . implode(',', $h) . ')');
        }
    }
    // 맞대결 기록: 점수를 빼도 이긴 쪽 강조 그대로
    foreach (['head-to-head', 'recent-race'] as $slug) {
        $f = sample_final($slug);
        [$f['r1.sa'], $f['r1.sb']] = [5, 4];
        $r = template_present($slug, $f, ['count' => 3], false, ['r1.sa'])['rows'][0];
        assert_same([true, false, ''], [$r['a_win'], $r['b_win'], $r['score']], "$slug 이긴 쪽 강조");
    }
    // 매치 프리뷰 맞대결: 2:2 동률이면 세트가 달라도 강조 없음, 0:0은 승 하나를 빼도 '첫 맞대결'
    $mp = sample_final('match-preview');
    [$mp['h.a'], $mp['h.b'], $mp['h.sa'], $mp['h.sb']] = [2, 2, 9, 8];
    $row = static fn(array $v, string $k) => array_column($v['rows'], null, 'key')[$k] ?? null;
    assert_same('', $row(template_present('match-preview', $mp, $p2, false), 'h2h')['lead']);
    [$mp['h.a'], $mp['h.b'], $mp['h.sa'], $mp['h.sb']] = [0, 0, 0, 0];
    assert_same('note', $row(template_present('match-preview', $mp, $p2, false, ['h.a']), 'h2h')['kind']);
    // 맵 전적: 상대 종족전 기록이 원래 없으면(null) 승을 빼도 아래 줄은 빈칸 ("-패"가 아님)
    $mr = sample_final('map-record');
    $mr['a.vs'] = $mr['a.vw'] = $mr['a.vl'] = null;
    assert_same('', template_present('map-record', $mr, $p2, false, ['a.vw'])['cols'][0]['detail']);

    // 송출 중 CG에서 원래 비어 있던 항목(닉네임 없음)을 빼도 '송출값과 다름' → UPDATE LIVE로 반영
    type_state('match-preview', ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수'], 'map' => '']);
    $iid = channel_get('preview')['instance_id'];
    assert_same(null, instance_state(instance_get($iid), current_session_id())['final']['a.nick']);
    program_take(channel_get('preview')['rev'], ['effect' => 'cut'], op());
    instance_hide($iid, ['a.nick'], true, op());
    $ps = panel_state(op());
    $f = array_column($ps['preview']['fields'], null, 'key');
    assert_true($ps['program']['pending_live'] && $ps['program']['live_manual'] && $f['a.nick']['live_differs']);
    $r = program_update_live($iid, channel_get('program')['take_id'], channel_get('preview')['rev'], [], op());
    assert_same(['a.nick'], $r['changed']);
    assert_same(['a.nick'], channel_get('program')['snapshot']['hidden']);
    assert_true(!panel_state(op())['program']['pending_live']);
});

