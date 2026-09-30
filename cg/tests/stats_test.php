<?php
declare(strict_types=1);

test('stats: 승률 반올림 (0.1% 단위 정수)', function () {
    assert_same(611, stats_rate_tenths(33, 21), '33/54 = 61.11%');
    assert_same(512, stats_rate_tenths(129, 123), '129/252 = 51.19%');
    assert_same(630, stats_rate_tenths(34, 20), '34/54 = 62.96%');
    assert_same(63, stats_rate_tenths(1, 15), '1/16 = 6.25% → 6.3 (반올림)');
    assert_same(0, stats_rate_tenths(0, 5));
    assert_same(1000, stats_rate_tenths(7, 0));
    assert_same(null, stats_rate_tenths(0, 0), '0경기는 자료 없음');
    assert_same(null, stats_rate_tenths(null, 3));
    // 레퍼런스 스크린샷 수치 검산
    foreach ([[39, 14, 736], [35, 24, 593], [34, 20, 630], [31, 26, 544], [51, 40, 560], [51, 58, 468], [35, 39, 473]] as [$w, $l, $t]) {
        assert_same($t, stats_rate_tenths($w, $l), "$w-$l");
    }
});

test('provider: MOCK 데이터 검증 통과·MOCK 표시', function () {
    $ds = provider_load('mock');
    assert_true($ds['mock'], 'mock 플래그');
    assert_same('mock', $ds['source']);
    assert_same('조일장', $ds['players']['jo-iljang']['name']);
    assert_same(41, count($ds['matches']));
});

test('stats: MOCK 조일장 vs P = 33승 21패 (61.1%), B측 기록·노이즈 제외', function () {
    $ds = provider_load('mock');
    $r = stats_race_record($ds['matches'], 'jo-iljang', 'P');
    assert_same(33, $r['wins']);
    assert_same(21, $r['losses']);
    assert_same(7, $r['matches']);
    assert_same(5, $r['match_wins']);
    assert_same(611, stats_rate_tenths($r['wins'], $r['losses']));
});

test('stats: MOCK 장윤철 vs Z = 129승 123패 (51.2%)', function () {
    $ds = provider_load('mock');
    $r = stats_race_record($ds['matches'], 'jang-yunchul', 'Z');
    assert_same([129, 123, 30], [$r['wins'], $r['losses'], $r['matches']]);
    assert_same(512, stats_rate_tenths($r['wins'], $r['losses']));
});

test('stats: MOCK 조일장 vs T = 0경기 (자료 없음)', function () {
    $ds = provider_load('mock');
    $r = stats_race_record($ds['matches'], 'jo-iljang', 'T');
    assert_same([0, 0, 0], [$r['wins'], $r['losses'], $r['matches']]);
    assert_same(null, stats_rate_tenths($r['wins'], $r['losses']));
});

test('provider: 검증 오류 목록', function () {
    $good = ['id' => 'm1', 'date' => '2024-01-01', 'playerA' => 'a', 'playerB' => 'b', 'raceA' => 'P', 'raceB' => 'Z',
        'scoreA' => 5, 'scoreB' => 3, 'bestOf' => 9];
    $players = [['id' => 'a', 'name' => 'A', 'race' => 'P'], ['id' => 'b', 'name' => 'B', 'race' => 'Z']];
    $check = function (array $matchChanges, string $expect) use ($good, $players) {
        $ds = dataset_normalize(['players' => $players, 'matches' => [array_replace($good, $matchChanges)]], 't');
        $p = implode(' / ', dataset_validate($ds));
        assert_true(str_contains($p, $expect), "'$expect' 기대, 실제: '$p'");
    };
    assert_same([], dataset_validate(dataset_normalize(['players' => $players, 'matches' => [$good]], 't')));
    $check(['date' => '2024-02-30'], '날짜 오류');
    $check(['playerB' => 'zzz'], '알 수 없는 선수');
    $check(['playerB' => 'a'], '같은 선수끼리');
    $check(['raceA' => 'X'], '종족 오류');
    $check(['scoreA' => 5, 'scoreB' => 5], '스코어 오류');
    $check(['scoreA' => 4, 'scoreB' => 3], '스코어 오류');
    $check(['scoreA' => -1], '스코어 오류');
    $check(['scoreA' => '5.5'], '스코어 오류');
    $check(['bestOf' => 8], 'bestOf 오류');
    $check(['winner' => 'b'], '승자와 스코어 불일치');
    $dup = dataset_normalize(['players' => $players, 'matches' => [$good, $good]], 't');
    assert_true(str_contains(implode('/', dataset_validate($dup)), '중복 경기 id'));
    $dupP = dataset_normalize(['players' => array_merge($players, [$players[0]]), 'matches' => []], 't');
    assert_true(str_contains(implode('/', dataset_validate($dupP)), '중복 선수 id'));
});

test('provider: 파일 없음·형식 오류는 ProviderError', function () {
    $dir = $GLOBALS['TEST_TMP'] . '/bad-mock';
    @mkdir($dir, 0775, true);
    assert_throws(ProviderError::class, fn() => provider_load('mock', $dir));
    file_put_contents("$dir/players.json", '{"players": "x"}');
    file_put_contents("$dir/matches.json", '{"matches": []}');
    assert_throws(ProviderError::class, fn() => provider_load('mock', $dir));
    assert_throws(ProviderError::class, fn() => provider_load('sheets'));
});
