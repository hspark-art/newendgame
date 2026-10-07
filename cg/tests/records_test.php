<?php
declare(strict_types=1);

// v0.7: 매치 기록 (#15) — stats_match_records 계산 기준, '매치 기록' CG, 오늘 매치 연결
// 합성 경기만 쓴다 (운영 데이터 없음). 날짜·선수 이름은 계산 확인용 가짜 값

/** 합성 끝장전 한 경기 (sheet_matches 모양) */
function fx_rm(string $date, string $a, string $b, int $sa, int $sb, ?string $anomaly = null, bool $excluded = false): array
{
    return ['id' => "$date|$a|$b", 'date' => $date, 'playerA' => $a, 'playerB' => $b, 'raceA' => 'Z', 'raceB' => 'P',
        'scoreA' => $sa, 'scoreB' => $sb, 'sets' => $sa + $sb, 'bestOf' => $sa + $sb === 9 ? 9 : null, 'rows' => [1, $sa + $sb],
        'anomaly' => $anomaly, 'anomaly_kind' => $anomaly === null ? null : 'sets', 'excluded' => $excluded, 'source' => 'sheet'];
}

function fx_rds(array $all): array
{
    $players = [];
    foreach ($all as $m) {
        $players[$m['playerA']] = $players[$m['playerB']] = null;
    }
    return ['matches_all' => $all, 'matches' => array_values(array_filter($all, static fn($m) => $m['anomaly'] === null)),
        'players' => array_map(static fn() => [], $players) + ['병' => [], '무' => []]];
}

/** 기록 하나: [상태, 값, 추천] */
function fx_rec(array $ds, string $a, string $b, string $date, string $key): array
{
    $it = stats_match_records($ds, $a, $b, $date)['items'][$key];
    return [$it['status'], $it['value'], $it['recommended']];
}

test('매치 기록: 매치 연승·연패 — 최근 경기부터 같은 결과, 패배에서 끊김, 이상 경기가 끼면 확인 필요, 제외 확정 경기는 보지 않음', function () {
    $base = [
        fx_rm('2020-01-01', '갑', '을', 4, 5),      // 갑 패 — 연승이 여기서 끊긴다
        fx_rm('2021-03-01', '갑', '병', 5, 4),
        fx_rm('2021-06-01', '정', '갑', 2, 7),      // 갑이 B 자리여도 승
        fx_rm('2022-01-01', '갑', '을', 6, 3),
    ];
    $r = stats_match_records(fx_rds($base), '갑', '을', '2022-06-01');
    assert_same(['ok', 3, true], [$r['items']['a.win_streak']['status'], $r['items']['a.win_streak']['value'], $r['items']['a.win_streak']['recommended']]);
    assert_same('현재 끝장전 매치', $r['items']['a.win_streak']['desc']);
    assert_true(str_starts_with(end($r['items']['a.win_streak']['basis']), '끊긴 경기: 2020-01-01'), '끊긴 경기를 근거로');
    assert_same(['none', null, false], fx_rec(fx_rds($base), '갑', '을', '2022-06-01', 'a.loss_streak'));
    // 을: 2020 승 · 2022 패 → 1연패 (후보 아님)
    assert_same(['none', null, false], fx_rec(fx_rds($base), '갑', '을', '2022-06-01', 'b.loss_streak'));
    // 2연승은 후보(추천 아님), 3연승부터 추천
    assert_same(['ok', 2, false], fx_rec(fx_rds($base), '갑', '을', '2021-12-31', 'a.win_streak'));

    // 연승 사이에 확인 필요한 이상 경기 → 확정하지 않음
    $odd = array_merge($base, [fx_rm('2021-09-01', '갑', '무', 3, 1, '세트 수 4개 (9세트가 아님)')]);
    $it = stats_match_records(fx_rds($odd), '갑', '을', '2022-06-01')['items']['a.win_streak'];
    assert_same(['hold', false], [$it['status'], $it['recommended']]);
    assert_true(str_contains($it['reason'], '이상 경기(2021-09-01)'), $it['reason']);
    // 운영자가 '통계 제외'를 확정한 경기는 끝장전이 아니므로 연승 계산에 영향 없음
    $ex = array_merge($base, [fx_rm('2021-09-01', '갑', '무', 3, 1, '세트 수 4개 (9세트가 아님)', true)]);
    assert_same(['ok', 3, true], fx_rec(fx_rds($ex), '갑', '을', '2022-06-01', 'a.win_streak'));
    // 가장 최근 경기가 이상 경기 → 연승·연패 모두 확인 필요
    $last = array_merge($base, [fx_rm('2022-03-01', '갑', '무', 2, 1, '세트 수 3개 (9세트가 아님)')]);
    assert_same('hold', fx_rec(fx_rds($last), '갑', '을', '2022-06-01', 'a.win_streak')[0]);
    assert_same('hold', fx_rec(fx_rds($last), '갑', '을', '2022-06-01', 'a.loss_streak')[0]);
});

test('매치 기록: 시트 첫 기록부터 이어지는 연승은 확인 필요(시트 이전 기록을 모름), 1경기뿐이면 후보 아님', function () {
    $ds = fx_rds([fx_rm('2023-01-01', '병', '정', 5, 4), fx_rm('2023-05-01', '병', '무', 6, 3)]);
    $it = stats_match_records($ds, '병', '정', '2024-01-01')['items']['a.win_streak'];
    assert_same(['hold', 2, false], [$it['status'], $it['value'], $it['recommended']]);
    assert_true(str_contains($it['reason'], '시트 첫 기록(2023-01-01)부터'), $it['reason']);
    $one = fx_rds([fx_rm('2023-01-01', '병', '정', 5, 4)]);
    assert_same(['none', null, false], fx_rec($one, '병', '정', '2024-01-01', 'a.win_streak'));
});

test('매치 기록: 출전·맞대결 간격은 경기일 - 마지막 경기일의 달력 날짜 차이 (윤년·월말·연말), 기록 없으면 "첫" 표현 없이 해당 없음', function () {
    $d = static fn(string $from, string $to) => stats_match_records(fx_rds([fx_rm($from, '갑', '을', 5, 4)]), '갑', '을', $to)['items'];
    assert_same(2, $d('2024-02-28', '2024-03-01')['a.gap']['value'], '윤년 2월 29일 포함');
    assert_same(1, $d('2023-02-28', '2023-03-01')['a.gap']['value']);
    assert_same(1, $d('2019-12-31', '2020-01-01')['h.gap']['value'], '연말');
    assert_same(31, $d('2024-01-31', '2024-03-02')['b.gap']['value']);
    $long = $d('2019-05-14', '2024-10-07');
    assert_same([1973, true, '만에 끝장전 출전'], [$long['a.gap']['value'], $long['a.gap']['recommended'], $long['a.gap']['desc']]);
    assert_same('경기일 2024-10-07 → 1,973일 (5년 4개월 23일)', $long['a.gap']['basis'][1]);
    assert_same([1973, true, '만에 펼쳐지는 맞대결'], [$long['h.gap']['value'], $long['h.gap']['recommended'], $long['h.gap']['desc']]);
    assert_same(false, $d('2024-01-01', '2024-12-30')['a.gap']['recommended'], '364일은 추천 아님');
    assert_same(true, $d('2024-01-01', '2024-12-31')['a.gap']['recommended'], '365일부터 추천');
    // 이전 출전·맞대결이 없으면 '첫 출전'이라고 하지 않고 해당 없음
    $none = stats_match_records(fx_rds([fx_rm('2024-01-01', '갑', '을', 5, 4)]), '병', '정', '2024-06-01')['items'];
    assert_same(['none', null], [$none['a.gap']['status'], $none['a.gap']['value']]);
    foreach ($none as $it) {
        assert_true(!preg_match('/첫 출전|최초|역대|최장|통산/u', $it['text'] . $it['reason'] . $it['desc']), $it['text'] . ' ' . $it['reason']);
    }
    assert_same('none', $none['h.gap']['status']);
    // 마지막 출전이 확인 필요한 이상 경기 → 간격 확정 안 함
    $odd = fx_rds([fx_rm('2023-01-01', '갑', '을', 5, 4), fx_rm('2023-06-01', '갑', '무', 2, 1, '세트 수 3개 (9세트가 아님)')]);
    assert_same('hold', fx_rec($odd, '갑', '을', '2024-01-01', 'a.gap')[0]);
    assert_same(['ok', 365], array_slice(fx_rec($odd, '갑', '을', '2024-01-01', 'h.gap'), 0, 2), '맞대결 간격은 두 선수 경기만');
});

test('매치 기록: 경기일 당일(진행 중·입력 중)·이후 경기는 계산에서 빼고 알림, 같은 매치의 여러 세트·중복 입력은 한 번의 출전', function () {
    $all = [
        fx_rm('2023-06-01', '갑', '병', 4, 5),                               // 갑 패 (연승이 여기서 끊김)
        fx_rm('2024-01-01', '갑', '을', 5, 4),
        fx_rm('2024-03-01', '갑', '을', 6, 3),
        fx_rm('2024-05-01', '갑', '을', 3, 1, '세트 수 4개 (9세트가 아님)'), // 경기일 당일: 입력 중
        fx_rm('2024-07-01', '갑', '병', 5, 4),                               // 경기일 이후
    ];
    $r = stats_match_records(fx_rds($all), '갑', '을', '2024-05-01');
    assert_same(2, count($r['notes']));
    assert_true(str_contains($r['notes'][0], '경기일 2024-05-01에 이미 입력된 갑 vs 을 세트 4개(3:1)') && str_contains($r['notes'][1], '이후 경기 1개'),
        implode(' / ', $r['notes']));
    assert_same(['ok', 2], array_slice(fx_rec(fx_rds($all), '갑', '을', '2024-05-01', 'a.win_streak'), 0, 2), '당일 경기는 연승에 넣지 않음');
    assert_same(61, $r['items']['a.gap']['value'], '마지막 출전 = 경기일 이전 경기 (2024-03-01)');
    // 두 선수 맞대결 2경기 모두 갑 승 = 시트 첫 맞대결부터 이어지는 연승 → 확인 필요
    assert_same(['hold', 2], array_slice(fx_rec(fx_rds($all), '갑', '을', '2024-05-01', 'h.streak'), 0, 2));

    // 같은 날 같은 두 선수의 세트를 두 번 입력 → 한 경기(18세트, 이상 경기)로 묶임. 출전·연승이 두 번 세어지지 않는다
    $games = [];
    foreach ([1, 2] as $copy) {
        for ($i = 0; $i < 9; $i++) {
            $games[] = ['row' => count($games) + 2, 'date' => '2024-02-01', 'winner' => $i < 5 ? '갑' : '을', 'wrace' => 'Z',
                'loser' => $i < 5 ? '을' : '갑', 'lrace' => 'P', 'map' => 'M', 'dc' => false];
        }
    }
    [$matches] = sheet_matches($games);
    assert_same([1, 18, true], [count($matches), $matches[0]['sets'], $matches[0]['anomaly'] !== null]);
    $dup = fx_rds(array_merge([fx_rm('2024-01-01', '갑', '을', 5, 4)], array_map(static fn($m) => $m + ['excluded' => false], $matches)));
    assert_same('hold', fx_rec($dup, '갑', '을', '2024-03-01', 'a.win_streak')[0], '중복 입력 경기는 확인 필요');
});

test('매치 기록: 맞대결 연승은 이긴 선수 이름으로, 상대 이름을 설명에', function () {
    $all = [fx_rm('2020-01-01', '갑', '을', 5, 4), fx_rm('2021-01-01', '을', '갑', 5, 4), fx_rm('2022-01-01', '갑', '을', 3, 6),
        fx_rm('2023-01-01', '을', '갑', 7, 2)];
    $it = stats_match_records(fx_rds($all), '갑', '을', '2024-01-01')['items']['h.streak'];
    assert_same(['ok', 3, true, '을', '갑 상대 맞대결'], [$it['status'], $it['value'], $it['recommended'], $it['name'], $it['desc']]);
});

test('매치 기록 CG: 오늘 매치 창의 후보 → 고른 기록으로 페이지 추가 → 송출 가능, 확정할 수 없는 기록은 이유와 함께 송출 차단', function () {
    setup_sheet(); // 합성 시트: 가선수 Z, 나선수 P, 다선수 T, 라선수 Z (2025-01-04 다선수 vs 라선수는 이상 경기)
    // 오늘 매치에 경기일 저장. 매치 기록은 기록을 골라야 해서 '한 번에 추가'·'페이지 리스트 바꾸기' 대상이 아님
    $v = match_today_save(['a' => '가선수', 'b' => '나선수', 'date' => '2026-01-01'], op());
    assert_same(['2026-01-01', date('Y-m-d')], [$v['date'], $v['today']]);
    assert_true(!in_array('match-records', match_templates(), true));
    assert_throws(ActionError::class, fn() => match_today_save(['a' => '가선수', 'b' => '나선수', 'date' => '2026-02-30'], op()), 'BAD_PARAMS');

    $r = match_records_view(['a' => '가선수', 'b' => '나선수', 'date' => '2026-01-01']);
    $items = array_column($r['items'], null, 'key');
    assert_same(array_keys(MATCH_RECORD_KINDS), array_keys($items), '정해진 순서');
    // 가선수 마지막 출전 2024-04-06 → 2026-01-01 = 635일, 두 선수 마지막 맞대결도 같은 날
    assert_same(['ok', 635, true], [$items['a.gap']['status'], $items['a.gap']['value'], $items['a.gap']['recommended']]);
    assert_same(['ok', 635], [$items['h.gap']['status'], $items['h.gap']['value']]);
    assert_same('none', $items['a.win_streak']['status'], '최근 경기 패');

    $page = page_add(['template' => 'match-records', 'params' => ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수'],
        'date' => '2026-01-01', 'records' => ['h.gap', 'a.gap']]], op());
    cue_page($page['page_no']);
    $st = instance_state(instance_get(channel_get('preview')['instance_id']), current_session_id());
    assert_same([], $st['problems']);
    assert_same([['name' => '가선수 vs 나선수', 'num' => '635', 'unit' => '일', 'desc' => '만에 펼쳐지는 맞대결'],
        ['name' => '가선수', 'num' => '635', 'unit' => '일', 'desc' => '만에 끝장전 출전']], $st['view']['rows'], '고른 순서대로');
    $html = cg_render($st['view']);
    assert_true(str_contains($html, 'rec-rows') && !str_contains($html, 'rec-big') && str_contains($html, '<b>635</b><small>일</small>'));
    assert_same('가선수 vs 나선수 · 2026-01-01 · 맞대결 간격, A 출전 간격', template_summary('match-records', json_dec(rundown_rows()[0]['params_json'])));

    // 1개만 고르면 크게 (rec-big)
    page_add(['template' => 'match-records', 'params' => ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수'],
        'date' => '2026-01-01', 'records' => ['a.gap']]], op());
    $one = type_state('match-records', ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수'], 'date' => '2026-01-01', 'records' => ['a.gap']]);
    assert_true(str_contains(cg_render($one['view']), 'rec-big'));

    // 해당 없는 기록(현재 연승 아님)을 고른 페이지 → 줄이 조용히 사라지지 않고 이유와 함께 송출 차단 → 그 줄을 빼면 다른 줄로 송출
    $bad = type_state('match-records', ['a' => ['player' => '가선수'], 'b' => ['player' => '나선수'], 'date' => '2026-01-01',
        'records' => ['a.gap', 'a.win_streak']]);
    assert_true((bool)array_filter($bad['problems'], static fn($p) => str_contains($p, '가선수 매치 연승 — 기록 없음: 현재 연승 중이 아닙니다')),
        implode(' / ', $bad['problems']));
    $iid = channel_get('preview')['instance_id'];
    instance_hide($iid, ['r2.name', 'r2.num', 'r2.unit', 'r2.desc'], true, op());
    $ok = instance_state(instance_get($iid), current_session_id());
    assert_same([[], 1], [$ok['problems'], count($ok['view']['rows'])]);

    // 이상 경기가 있는 선수(다선수: 2025-01-04 이상 경기) → 출전 간격 확인 필요 + 다른 CG와 같은 검증 사유로 차단
    $hold = match_records_view(['a' => '다선수', 'b' => '라선수', 'date' => '2026-01-01']);
    $h = array_column($hold['items'], null, 'key');
    assert_same(['hold', 'hold'], [$h['a.gap']['status'], $h['b.win_streak']['status']], '라선수의 유일한 경기가 이상 경기');
    $blocked = type_state('match-records', ['a' => ['player' => '다선수'], 'b' => ['player' => '라선수'], 'date' => '2026-01-01', 'records' => ['a.gap']]);
    assert_true((bool)array_filter($blocked['problems'], static fn($p) => str_contains($p, '확인 필요: 마지막 출전 경기(2025-01-04)가 이상 경기')),
        implode(' / ', $blocked['problems']));
    // 경기일 = 이상 경기 당일 → 그 경기는 진행 중·입력 중으로 보고 빼고 알림
    $same = match_records_view(['a' => '다선수', 'b' => '라선수', 'date' => '2025-01-04']);
    assert_true(str_contains($same['notes'][0], '경기일 2025-01-04에 이미 입력된 다선수 vs 라선수 세트 4개'), $same['notes'][0] ?? '');
    assert_same(['ok', 308], [array_column($same['items'], null, 'key')['a.gap']['status'], array_column($same['items'], null, 'key')['a.gap']['value']]);
});

test('매치 기록 CG: 입력 검사 — 경기일 형식, 기록 1~3개·중복·없는 종류, 같은 선수', function () {
    setup_sheet();
    $p = static fn(array $over) => array_replace(['a' => ['player' => '가선수'], 'b' => ['player' => '나선수'], 'date' => '2026-01-01',
        'records' => ['a.gap']], $over);
    $bad = [['date' => '2026-13-01'], ['date' => '20260101'], ['records' => []], ['records' => ['a.gap', 'b.gap', 'h.gap', 'h.streak']],
        ['records' => ['a.gap', 'a.gap']], ['records' => ['x.gap']], ['b' => ['player' => '가선수']]];
    foreach ($bad as $over) {
        assert_throws(ActionError::class, fn() => template_params('match-records', $p($over), players_cache(), template_ctx()), 'BAD_PARAMS');
    }
    $ok = template_params('match-records', $p(['date' => '']), players_cache(), template_ctx());
    assert_same([date('Y-m-d'), ['a.gap']], [$ok['date'], $ok['records']], '경기일을 비우면 오늘');
    assert_throws(ActionError::class, fn() => match_records_view(['a' => '가선수', 'b' => '가선수', 'date' => '2026-01-01']), 'BAD_PARAMS');
});
