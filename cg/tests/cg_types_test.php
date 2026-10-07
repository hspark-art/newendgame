<?php
declare(strict_types=1);

// PHASE 7: CG 9종 — 통계·AUTO 값·표시 문자열·파라미터 검사 (MOCK 기준)

/** 새 DB + 데이터 불러오기 */
function setup_types(): void
{
    fresh_db();
    data_refresh(op());
}

/** 페이지를 추가해 PREVIEW에 큐하고 상태를 돌려준다 */
function type_state(string $slug, array $params): array
{
    $r = page_add(['template' => $slug, 'params' => $params], op());
    cue_page($r['page_no']);
    return instance_state(instance_get(channel_get('preview')['instance_id']), current_session_id());
}

test('stats: 서수·공동 순위', function () {
    assert_same(['1st', '2nd', '3rd', '4th', '11th', '12th', '13th', '21st', '22nd', '101st', '111th'],
        array_map('english_ordinal', [1, 2, 3, 4, 11, 12, 13, 21, 22, 101, 111]));
    assert_same(['첫', '두', '다섯', '열', '열한', '스무', '스물한', '서른다섯'], array_map('korean_ordinal', [1, 2, 5, 10, 11, 20, 21, 35]));
    $rows = stats_rank([['v' => 9], ['v' => 7], ['v' => 7], ['v' => 5]], fn($r) => $r['v']);
    assert_same([1, 2, 2, 4], array_column($rows, 'rank'));
});

test('stats: 풀세트는 9전만, 연승은 경기 순서대로·종료일은 마지막 경기 날짜', function () {
    $m = fn($id, $d, $a, $b, $sa, $sb, $bo = 9) => ['id' => $id, 'date' => $d, 'playerA' => $a, 'playerB' => $b,
        'raceA' => 'Z', 'raceB' => 'T', 'scoreA' => $sa, 'scoreB' => $sb, 'bestOf' => $bo];
    $matches = [
        $m('1', '2024-01-01', 'x', 'y', 5, 4), $m('2', '2024-02-01', 'y', 'x', 5, 4), $m('3', '2024-03-01', 'x', 'y', 5, 1),
        $m('4', '2024-04-01', 'x', 'y', 3, 2, 5), $m('5', '2024-05-01', 'x', 'y', 5, 0),
    ];
    assert_same(['matches' => 4, 'fs_wins' => 1, 'fs_losses' => 1], stats_full_set($matches, 'x'), '5전(3:2)은 세지 않음');
    $players = ['x' => ['name' => 'X', 'race' => 'Z'], 'y' => ['name' => 'Y', 'race' => 'T']];
    $s = stats_win_streaks($matches, $players, null, 5);
    assert_same('x', $s[0]['player']);
    assert_same([3, '2024-03-01', '2024-05-01'], [$s[0]['streak'], $s[0]['start'], $s[0]['end']], '이어지는 연승 = 마지막 출전일');
    assert_same(['y', 1, '2024-02-01'], [$s[1]['player'], $s[1]['streak'], $s[1]['end']]);
});

test('CG#1 상대 종족 승률: 세트 기준 33승 21패 (61.1%)', function () {
    setup_types();
    $st = type_state('race-win-rate', ['a' => ['player' => 'jo-iljang', 'vs' => 'P'], 'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']]);
    assert_same([], $st['problems']);
    assert_same(['33승 21패', '(61.1%)'], [$st['view']['cols'][0]['record'], $st['view']['cols'][0]['rate']]);
    assert_same(['129승 123패', '(51.2%)'], [$st['view']['cols'][1]['record'], $st['view']['cols'][1]['rate']]);
});

test('CG#2 최근 종족전: 김민철 테란전 5경기 = 레퍼런스 02, 오래된 순·승자 표시', function () {
    setup_types();
    $st = type_state('recent-race', ['player' => 'kim-minchul', 'vs' => 'T']);
    assert_same('김민철 최근 끝장전 테란전 전적', $st['view']['title']);
    $rows = array_map(fn($r) => [$r['date'], $r['a'], $r['sa'] . ':' . $r['sb'], $r['b'], $r['a_win'] ? 'A' : 'B'], $st['view']['rows']);
    assert_same([
        ['2024-05-10', '김민철', '5:4', '이재호', 'A'],
        ['2024-05-21', '김민철', '4:5', '김지성', 'B'],
        ['2024-09-28', '김민철', '4:5', '이재호', 'B'],
        ['2026-04-30', '김민철', '5:4', '황병영', 'A'],
        ['2026-05-06', '김민철', '5:4', '김지성', 'A'],
    ], $rows);
    $st = type_state('recent-race', ['player' => 'kim-minchul', 'vs' => 'T', 'count' => '2']);
    assert_same(['2026-04-30', '2026-05-06'], array_column($st['view']['rows'], 'date'), '최근 2경기');
    assert_same(null, $st['final']['r3.date'], '남는 행은 비움');
});

test('CG#3 맞대결: 조일장 vs 김지성 0 : 4, "다섯 번째 맞대결" (요약은 끝장전 승수)', function () {
    setup_types();
    $st = type_state('head-to-head', ['a' => ['player' => 'jo-iljang'], 'b' => ['player' => 'kim-jisung']]);
    assert_same('조일장 vs 김지성 끝장전 다섯 번째 맞대결', $st['view']['title']);
    assert_same('0 : 4', $st['view']['summary']);
    assert_same(['2022-08-04', '2023-07-31', '2024-07-15', '2025-10-23'], array_column($st['view']['rows'], 'date'));
    foreach ($st['view']['rows'] as $r) {
        assert_same([4, 5, false, true], [$r['sa'], $r['sb'], $r['a_win'], $r['b_win']], 'B측 기록도 A 기준으로 뒤집음');
    }
    $html = cg_render($st['view']);
    assert_true(str_contains($html, '<b>0 : 4</b>') && substr_count($html, 'class="c-b is-win"') === 4, '승자 노랑');
});

test('CG#4 다승 순위: 세트 승수, 종족 필터, 공동 순위', function () {
    setup_types();
    $st = type_state('win-ranking', ['race' => '', 'count' => '4']);
    assert_same('중계진 스타 끝장전 다승 순위', $st['view']['title']);
    assert_same(['rank' => '1st', 'name' => '장윤철', 'nick' => 'SnOw', 'record' => '17W 15L', 'rate' => '53.1%', 'top' => true], $st['view']['rows'][0], '끝장전 승패');
    assert_same(4, count($st['view']['rows']));
    $st = type_state('win-ranking', ['race' => 'Z', 'count' => '5']);
    assert_same('중계진 스타 끝장전 저그 다승 순위', $st['view']['title']);
    assert_same(['1st', '2nd', '2nd', '2nd', '2nd'], array_column($st['view']['rows'], 'rank'), '같은 승수는 공동 순위');
});

test('CG#5 승자 예측 순위: 적중률 순위, 자리 순서 표시(1→3→2)', function () {
    setup_types();
    $st = type_state('prediction-ranking', []);
    assert_same('2026 중계진 승자 예측 순위', $st['view']['title'], '연도를 비우면 최근 연도');
    assert_same([['1', '박상현', '5W 0L', '100.0%', true], ['2', '이승원', '3W 2L', '60.0%', false], ['3', '임성춘', '1W 4L', '20.0%', false]],
        array_map(fn($r) => [$r['rank'], $r['name'], $r['record'], $r['rate'], $r['top']], $st['view']['rows']));
    $st = type_state('prediction-ranking', ['year' => '2026', 'seats' => ['park-sanghyun', 'lim-sungchun', 'lee-seungwon']]);
    assert_same(['1', '3', '2'], array_column($st['view']['rows'], 'rank'), '자리 순서대로');
    assert_same(['박상현', '임성춘', '이승원'], array_column($st['view']['rows'], 'name'));
    $st = type_state('prediction-ranking', ['year' => '2025']);
    assert_same(['9W 3L', '75.0%'], [$st['view']['rows'][0]['record'], $st['view']['rows'][0]['rate']]);
});

test('CG#6 온라인 상대 전적: 게임 단위 전적 + 온라인 맞대결', function () {
    setup_types();
    $st = type_state('online-h2h', ['a' => ['player' => 'jo-iljang', 'vs' => 'P'], 'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']]);
    assert_same([], $st['problems']);
    assert_same(['37승 23패', '(61.7%)'], [$st['view']['cols'][0]['record'], $st['view']['cols'][0]['rate']]);
    assert_same(['35승 34패', '(50.7%)'], [$st['view']['cols'][1]['record'], $st['view']['cols'][1]['rate']]);
    assert_same('12 : 8', $st['view']['h2h']);
    assert_true(str_contains(cg_render($st['view']), '<b>12 : 8</b>'));
});

test('CG#7 더블 찬스: 집계표 값, 집계표에 없으면 수동 입력 전까지 송출 불가', function () {
    setup_types();
    $st = type_state('double-chance', ['a' => ['player' => 'yoo-youngjin'], 'b' => ['player' => 'jo-iljang']]);
    assert_same('유영진 vs 조일장 더블 찬스 승률', $st['view']['title']);
    assert_same(['7승 3패', '(70.0%)', '4승 5패', '(44.4%)'], [$st['view']['cols'][0]['record'], $st['view']['cols'][0]['rate'],
        $st['view']['cols'][1]['record'], $st['view']['cols'][1]['rate']]);
    $st = type_state('double-chance', ['a' => ['player' => 'yoo-youngjin'], 'b' => ['player' => 'kim-jisung']]);
    assert_true(count($st['problems']) >= 2, '김지성은 집계표에 없음');
    assert_throws(ActionError::class, fn() => take_now(), 'NOT_SENDABLE');
    preview_save(channel_get('preview')['instance_id'], ['b.wins' => '2', 'b.losses' => '0'], op());
    assert_same('(100.0%)', instance_state(instance_get(channel_get('preview')['instance_id']), current_session_id())['view']['cols'][1]['rate']);
    take_now();
});

test('CG#8 연승 순위: 기본 테란, 이어지는 연승은 마지막 출전일까지, 전체 종족 공동 순위', function () {
    setup_types();
    $st = type_state('win-streak', []);
    assert_same('끝장전 테란 연승 순위', $st['view']['title'], '종족을 보내지 않으면 기본 테란');
    assert_same(['name' => '이재호', 'streak' => '6연승', 'period' => '2024-09-28 ~ 2026-02-20'],
        array_intersect_key($st['view']['rows'][0], array_flip(['name', 'streak', 'period'])), '"진행 중" 대신 마지막 출전일');
    assert_same('2026-02-20', max(array_column(array_filter(provider_load('mock')['matches'], fn($m) => in_array('lee-jaeho', [$m['playerA'], $m['playerB']], true)), 'date')));
    assert_same(['2nd', '김지성', '2022-08-04 ~ 2025-10-23'],
        [$st['view']['rows'][1]['rank'], $st['view']['rows'][1]['name'], $st['view']['rows'][1]['period']]);
    $st = type_state('win-streak', ['race' => '', 'count' => '5']);
    assert_same('끝장전 연승 순위', $st['view']['title']);
    assert_same(['1st', '2nd', '3rd', '3rd', '5th'], array_column($st['view']['rows'], 'rank'));
    assert_same('15연승', $st['view']['rows'][0]['streak']);
});

test('CG#9 풀세트: 지난 9전 풀세트 비율, 수동 수정 시 다시 계산, 합이 경기 수보다 크면 송출 불가', function () {
    setup_types();
    $st = type_state('full-set', ['a' => ['player' => 'jo-iljang'], 'b' => ['player' => 'jang-yunchul']]);
    assert_same('풀세트 접전 확률', $st['view']['title']);
    assert_same(['50.0%', '12경기 중 6회 풀세트', '5:4 승 0 · 4:5 패 6'], array_values(array_diff_key($st['view']['cols'][0], ['name' => 1])));
    assert_same(['40.6%', '32경기 중 13회 풀세트', '5:4 승 4 · 4:5 패 9'], array_values(array_diff_key($st['view']['cols'][1], ['name' => 1])));
    assert_true(str_contains(cg_render($st['view']), '지난 풀세트 비율'), '예측이 아니라 지난 비율로 표기');
    $iid = channel_get('preview')['instance_id'];
    preview_save($iid, ['a.fsw' => '3'], op());
    assert_same('75.0%', pv_field('a.rate')['final_text'], '9/12');
    preview_save($iid, ['a.fsw' => '7'], op());
    assert_true((bool)array_filter(panel_state(op())['preview']['problems'], fn($p) => str_contains($p, 'A 풀세트 비율')));
});

test('파라미터: 같은 선수·범위·예측자·종족 검사, 기본값', function () {
    setup_types();
    $ctx = template_ctx();
    $bad = [
        ['head-to-head', ['a' => ['player' => 'jo-iljang'], 'b' => ['player' => 'jo-iljang']]],
        ['full-set', ['a' => ['player' => 'jo-iljang'], 'b' => ['player' => 'JO-ILJANG']]],
        ['recent-race', ['player' => 'kim-minchul', 'vs' => 'T', 'count' => '6']],
        ['recent-race', ['player' => 'kim-minchul', 'vs' => 'T', 'count' => '0']],
        ['recent-race', ['player' => 'kim-minchul', 'vs' => '']],
        ['win-ranking', ['race' => 'X']],
        ['prediction-ranking', ['year' => '26']],
        ['prediction-ranking', ['seats' => ['ghost']]],
        ['prediction-ranking', ['seats' => ['park-sanghyun', 'PARK-SANGHYUN']]],
        ['online-h2h', ['a' => ['player' => 'jo-iljang', 'vs' => 'P'], 'b' => ['player' => 'nobody', 'vs' => 'Z']]],
    ];
    foreach ($bad as [$slug, $in]) {
        assert_throws(ActionError::class, fn() => template_params($slug, $in, $ctx['players'], $ctx), 'BAD_PARAMS');
    }
    assert_same(['race' => 'T', 'count' => 4], template_params('win-streak', [], $ctx['players'], $ctx));
    assert_same(['race' => '', 'count' => 4], template_params('win-ranking', [], $ctx['players'], $ctx));
    assert_same(['year' => '2026', 'seats' => []], template_params('prediction-ranking', ['seats' => ['', '']], $ctx['players'], $ctx));
    // 페이지 수정으로 파라미터를 바꾸면 다른 인스턴스
    $r = page_add(['template' => 'win-ranking', 'params' => ['race' => 'T']], op());
    $before = (int)rundown_get($r['id'])['instance_id'];
    page_update($r['id'], ['params' => ['race' => 'Z', 'count' => '3']], op());
    assert_true((int)rundown_get($r['id'])['instance_id'] !== $before);
});

test('9종 렌더링: 모든 값 이스케이프, MOCK 표시, 빈 행 생략, 패널에 입력 정의 전달', function () {
    setup_types();
    $cases = [
        'race-win-rate' => ['a' => ['player' => 'jo-iljang', 'vs' => 'P'], 'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']],
        'recent-race' => ['player' => 'kim-minchul', 'vs' => 'T'],
        'head-to-head' => ['a' => ['player' => 'jo-iljang'], 'b' => ['player' => 'kim-jisung']],
        'win-ranking' => ['race' => ''],
        'prediction-ranking' => [],
        'online-h2h' => ['a' => ['player' => 'jo-iljang', 'vs' => 'P'], 'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']],
        'double-chance' => ['a' => ['player' => 'yoo-youngjin'], 'b' => ['player' => 'jo-iljang']],
        'win-streak' => [],
        'full-set' => ['a' => ['player' => 'jo-iljang'], 'b' => ['player' => 'jang-yunchul']],
    ];
    // v0.5 새 CG 4종(미션 지수·매치 프리뷰·맵 전적·맵 상성)은 시트 데이터가 필요해 sheet_cg_test.php,
    // 자유 입력은 free_text_test.php에서 확인한다
    assert_same(array_merge(array_keys($cases), ['mission-index', 'match-preview', 'map-record', 'map-matchup', 'free-text', 'match-records', 'record-detail']),
        array_keys(cg_templates()), '16종, 정해진 순서'); // 매치 기록·기록 상세는 records_test.php에서 확인
    foreach ($cases as $slug => $params) {
        $st = type_state($slug, $params);
        assert_same([], $st['problems'], "$slug 송출 가능");
        $html = cg_render($st['view']);
        assert_true(str_contains($html, 'class="cg-mock"'), "$slug MOCK 표시");
        preview_save(channel_get('preview')['instance_id'], ['title' => '<script>x</script>&'], op());
        $st = instance_state(instance_get(channel_get('preview')['instance_id']), current_session_id());
        $html = cg_render($st['view']);
        assert_true(str_contains($html, '&lt;script&gt;x&lt;/script&gt;&amp;') && !str_contains($html, '<script>'), "$slug 이스케이프");
        assert_true(!str_contains($html, 'style='), "$slug 인라인 style 없음 (CSP)");
    }
    // 목록형: 행 이름을 지우면 그 행은 표시하지 않음
    $st = type_state('win-ranking', ['race' => 'T', 'count' => '2']);
    assert_same(2, count($st['view']['rows']));
    assert_same([], $st['problems'], '빈 행(3~5행)은 송출을 막지 않음');
    $ps = panel_state(op());
    $tpl = array_column($ps['templates'], null, 'slug');
    assert_same(['key' => 'race', 'label' => '종족', 'type' => 'race_any', 'default_value' => 'T'], $tpl['win-streak']['params'][0]);
    assert_same(['2026', '2025'], $ps['years']);
    assert_same(['park-sanghyun', 'lim-sungchun', 'lee-seungwon'], array_column($ps['predictors'], 'id'));
    assert_same('1행', array_column($ps['preview']['fields'], 'group', 'key')['r1.name']);
});

test('provider: 온라인·승자 예측·더블 찬스 검증 오류', function () {
    $players = [['id' => 'a', 'name' => 'A', 'race' => 'P'], ['id' => 'b', 'name' => 'B', 'race' => 'Z']];
    $match = ['id' => 'm1', 'date' => '2024-01-01', 'playerA' => 'a', 'playerB' => 'b', 'raceA' => 'P', 'raceB' => 'Z',
        'scoreA' => 5, 'scoreB' => 3, 'bestOf' => 9];
    $game = ['id' => 'g1', 'date' => '2024-01-02', 'playerA' => 'a', 'playerB' => 'b', 'raceA' => 'P', 'raceB' => 'Z', 'winner' => 'a'];
    $raw = fn(array $extra) => ['players' => $players, 'matches' => [$match]] + $extra;
    $check = function (array $extra, string $expect) use ($raw) {
        $p = implode(' / ', dataset_validate(dataset_normalize($raw($extra), 't')));
        assert_true(str_contains($p, $expect), "'$expect' 기대, 실제: '$p'");
    };
    $ok = ['online.games' => [$game], 'predictions.predictors' => [['id' => 'x', 'name' => 'X']],
        'predictions.picks' => [['predictor' => 'x', 'match' => 'm1', 'pick' => 'a']],
        'double_chance.records' => [['player' => 'a', 'wins' => 1, 'losses' => 0]]];
    assert_same([], dataset_validate(dataset_normalize($raw($ok), 't')));
    $check(['online.games' => [array_replace($game, ['winner' => 'z'])]], '승자 오류');
    $check(['online.games' => [$game, $game]], 'id 없음 또는 중복');
    $check(['online.games' => [array_replace($game, ['date' => '2024-13-01'])]], '날짜 오류');
    $check(array_replace($ok, ['predictions.picks' => [['predictor' => 'x', 'match' => 'm9', 'pick' => 'a']]]), '없는 경기');
    $check(array_replace($ok, ['predictions.picks' => [['predictor' => 'y', 'match' => 'm1', 'pick' => 'a']]]), '알 수 없는 예측자');
    $check(array_replace($ok, ['predictions.picks' => [['predictor' => 'x', 'match' => 'm1', 'pick' => 'c']]]), '그 경기의 선수가 아닌 예측');
    $check(array_replace($ok, ['predictions.picks' => array_fill(0, 2, ['predictor' => 'x', 'match' => 'm1', 'pick' => 'a'])]), '같은 경기 중복 예측');
    $check(['double_chance.records' => [['player' => 'a', 'wins' => -1, 'losses' => 0]]], '더블 찬스 기록 오류');
    $check(['double_chance.records' => array_fill(0, 2, ['player' => 'a', 'wins' => 1, 'losses' => 0])], '더블 찬스 중복');
});

test('업데이트 호환: v0.1.1 페이지는 그대로, 새로고침 전에는 목록 준비 안 됨 표시', function () {
    fresh_db();
    setting_set('players_cache', json_enc(['jo-iljang' => ['id' => 'jo-iljang', 'name' => '조일장', 'race' => 'Z'],
        'jang-yunchul' => ['id' => 'jang-yunchul', 'name' => '장윤철', 'race' => 'P']]));
    assert_same(false, panel_state(op())['caches_ready'], '예측자·연도 목록 없음 → 패널이 새로고침');
    // v0.1.1과 같은 모양의 파라미터 → 같은 params_key (기존 수정값이 이어짐)
    $p = template_params('race-win-rate', ['a' => ['player' => 'jo-iljang', 'vs' => 'P'], 'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']], players_cache());
    assert_same(['a' => ['player' => 'jo-iljang', 'vs' => 'P'], 'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']], $p);
    data_refresh(op());
    assert_same(true, panel_state(op())['caches_ready']);
});

test('검토 반영: 예측 동률은 적중 수로, 풀세트 0경기 모순 차단, 표시할 행 없음 차단, 행 번호 표시, 행 비율 필드', function () {
    // 예측: 적중률이 같으면 적중 수가 많은 쪽이 앞 순위
    $preds = [];
    foreach (range(1, 10) as $i) {
        $preds[] = ['date' => '2026-01-01', 'predictor' => 'p1', 'correct' => $i <= 5]; // 5승 5패
    }
    $preds[] = ['date' => '2026-01-01', 'predictor' => 'p2', 'correct' => true];
    $preds[] = ['date' => '2026-01-01', 'predictor' => 'p2', 'correct' => false]; // 1승 1패
    $preds[] = ['date' => '2025-12-31', 'predictor' => 'p3', 'correct' => true]; // 다른 해
    $r = stats_prediction_ranking($preds, '2026');
    assert_same([['p1', 1], ['p2', 2]], array_map(fn($x) => [$x['predictor'], $x['rank']], $r));

    // 풀세트: 경기 수 0인데 풀세트 횟수가 있으면 송출 불가, 모두 0이면 "자료 없음"으로 송출 가능
    $fields = template_get('full-set')['fields'];
    $final = ['a.matches' => 0, 'a.fsw' => 2, 'a.fsl' => 0];
    assert_same(false, derived_empty_ok($fields['a.rate'], $final));
    assert_same(true, derived_empty_ok($fields['a.rate'], ['a.matches' => 0, 'a.fsw' => 0, 'a.fsl' => 0]));

    setup_types();
    // 예측 기록이 없는 연도 → 표시할 행 없음 → 송출 불가, 행을 직접 입력하면 가능
    $st = type_state('prediction-ranking', ['year' => '2019']);
    assert_true(in_array('표시할 행이 없습니다. 행 값을 입력하거나 다른 조건을 고르세요.', $st['problems'], true));
    assert_throws(ActionError::class, fn() => take_now(), 'NOT_SENDABLE');
    preview_save(channel_get('preview')['instance_id'], ['r1.name' => '박상현'], op());
    take_now();

    // 행 필드 오류·UPDATE LIVE 기록에 행 번호
    type_state('win-ranking', ['race' => '', 'count' => '4']);
    $iid = channel_get('preview')['instance_id'];
    $e = assert_throws(ActionError::class, fn() => preview_save($iid, ['r2.wins' => 'x'], op()), 'VALIDATION');
    assert_true(str_contains($e->getMessage(), '2행 승:'), $e->getMessage());
    take_now();
    $pg = channel_get('program');
    program_update_live($iid, $pg['take_id'], channel_get('preview')['rev'], ['r2.wins' => '200'], op());
    assert_same('변경: 2행 승, 2행 승률', db_value("SELECT detail FROM cg_logs WHERE action = 'UPDATE_LIVE' ORDER BY id DESC"));
    $log = array_values(array_filter(panel_state(op())['logs'], fn($l) => $l['action'] === 'SET'))[0];
    assert_same('2행 승: AUTO 7 → 200', $log['detail']);

    // 행 필드에 비율(share) 파생값: 행 번호가 parts·total에 모두 붙음
    $rf = row_fields(2, ['m' => ['label' => '경기', 'type' => 'int'], 'f' => ['label' => '풀세트', 'type' => 'int'],
        'r' => ['label' => '비율', 'type' => 'rate', 'derived' => ['calc' => 'share', 'parts' => ['f'], 'total' => 'm']]]);
    assert_same(['calc' => 'share', 'parts' => ['r2.f'], 'total' => 'r2.m'], $rf['r2.r']['derived']);
    $m = ov_merge($rf, ['r1.m' => 4, 'r1.f' => 1, 'r2.m' => null, 'r2.f' => null], []);
    assert_same([250, null], [$m['r1.r']['final'], $m['r2.r']['final']]);
    assert_same([], ov_sendable($rf, ov_final($m)), '빈 행의 비율은 송출을 막지 않음');
});
