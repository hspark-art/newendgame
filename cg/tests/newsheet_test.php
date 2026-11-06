<?php
declare(strict_types=1);

// 새 양식 시트 (v0.12.0): 기존 양식 합성 표(fx_tables)와 같은 경기를 새 양식 탭으로 만들어 같은 결과가 나오는지 확인한다.
// 합성 데이터만 쓴다 (실제 시트 내용은 저장소에 넣지 않는다).

/**
 * fx_tables()와 같은 경기를 새 양식 탭(탭 이름 => 행 목록)으로. 집계 탭(선수통계·맵통계·예측순위)은 여기서 따로 센다.
 * 기존 표의 4세트 이상 경기(2025-01-04 다선수 vs 라선수)는 새 양식에서 '통계 포함 X' 이벤트 경기(5세트 중 1세트 미입력)로 둔다.
 * 열 순서는 일부러 섞는다 (머리글 이름으로 읽는지 확인).
 */
function fx_new_tabs(?callable $tamper = null): array
{
    $old = fx_tables();
    $byMatch = [];
    foreach (array_slice($old['results'], 1) as [$w, $wr, $l, $lr, $map, $d, $prize, $dcp]) {
        $pair = [$w, $l];
        sort($pair, SORT_STRING);
        $byMatch[$d . '|' . implode('|', $pair)][] = compact('w', 'wr', 'l', 'lr', 'map', 'd', 'prize', 'dcp');
    }
    $sh = ['상태', '경기번호', '날짜', '종류', '세트', '맵', '선수A', '선수B', '승자', '패자', '승자종족', '패자종족', '상금', '더블세트', '통계 포함', '추가 열'];
    $gh = ['경기번호', '날짜', '종류', '선수A', '선수B', '더블찬스 세트1', '더블찬스 세트2', 'A상금 보정', 'B상금 보정', 'A더블찬스 보정',
        'B더블찬스 보정', 'A종족', 'B종족', 'A세트승', 'B세트승', '상태', 'A상금', 'B상금', '통계 포함'];
    $sets = [$sh];
    $games = [$gh];
    $agg = $mapAgg = [];
    $n = 0;
    foreach ($byMatch as $key => $rows) {
        $n++;
        $d = $rows[0]['d'];
        $id = str_replace('-', '', $d) . '-1';
        $a = $rows[0]['w'];
        $ar = $rows[0]['wr'];
        $b = $rows[0]['l'];
        $br = $rows[0]['lr'];
        $event = count($rows) !== 9;
        $score = [$a => 0, $b => 0];
        $calc = [$a => 0, $b => 0];
        $dcWon = [$a => 0, $b => 0];
        foreach ($rows as $i => $s) {
            $score[$s['w']]++;
            $calc[$s['w']] += $s['prize'] + $s['dcp'];
            $dc = $s['dcp'] > 0;
            $sets[] = ['완료', $id, $d, $event ? '이벤트' : '정규', $i + 1, $s['map'], $a, $b, $s['w'], $s['l'], $s['wr'], $s['lr'],
                $s['prize'] + $s['dcp'], $dc ? 'O' : '', $event ? 'X' : 'O', '무시'];
            if ($event) {
                continue;
            }
            $dcWon[$s['w']] += $dc ? 1 : 0;
            foreach ([[$s['w'], 0, $s['lr']], [$s['l'], 1, $s['wr']]] as [$p, $k, $vs]) {
                $agg[$p]['set'][$k] = ($agg[$p]['set'][$k] ?? 0) + 1;
                $agg[$p][$vs][$k] = ($agg[$p][$vs][$k] ?? 0) + 1;
                $agg[$p]['dc'][$k] = ($agg[$p]['dc'][$k] ?? 0) + ($dc ? 1 : 0);
            }
            $m = &$mapAgg[$s['map']];
            $m['n'] = ($m['n'] ?? 0) + 1;
            if ($s['wr'] !== $s['lr']) {
                $m[$s['wr'] . $s['lr']] = ($m[$s['wr'] . $s['lr']] ?? 0) + 1;
            }
            unset($m);
        }
        if ($event) {
            $sets[] = ['미입력', $id, $d, '이벤트', count($rows) + 1, 'Map 9', $a, $b, '', '', '', '', '', '', 'X', ''];
        }
        // 상금 보정 (기존 '상금 보정' 탭: 나선수 2024-01-06 → 500000 / 0 / 더블 1회)
        $adj = ['A' => [0, 0], 'B' => [0, 0]];
        $total = [$a => $calc[$a], $b => $calc[$b]];
        foreach ($old['adjust'] as $i => $r) {
            if ($i > 0 && $r[0] === $d && in_array($r[1], [$a, $b], true)) {
                $side = $r[1] === $a ? 'A' : 'B';
                $adj[$side] = [$r[2] + $r[3] - $calc[$r[1]], $r[4] - $dcWon[$r[1]]];
                $total[$r[1]] = $r[2] + $r[3];
                $agg[$r[1]]['dc'][0] += $adj[$side][1];
            }
        }
        $games[] = [$id, $d, $event ? '이벤트' : '정규', $a, $b, 2, 4, $adj['A'][0] ?: '', $adj['B'][0] ?: '', $adj['A'][1] ?: '', $adj['B'][1] ?: '',
            $ar, $br, $score[$a], $score[$b], '종료', $total[$a], $total[$b], $event ? 'X' : 'O'];
        foreach ([$a => $b, $b => $a] as $p => $o) {
            $agg[$p]['prize'] = ($agg[$p]['prize'] ?? 0) + $total[$p];
            if (!$event) {
                $agg[$p]['m'][$score[$p] > $score[$o] ? 0 : 1] = ($agg[$p]['m'][$score[$p] > $score[$o] ? 0 : 1] ?? 0) + 1;
            }
        }
    }
    $race = ['가선수' => 'Z', '나선수' => 'P', '다선수' => 'T', '라선수' => 'Z'];
    $players = [['구분 이름', '표시 이름', '종족', '닉네임', '활동'], ['가선수', '', 'Z', '', 'O'], ['나선수', '', 'P', 'Na', 'O'],
        ['다선수', '', 'T', '', 'O'], ['라선수', '', 'Z', '', 'O'], ['쉬는선수', '', 'P', 'Rest', '']];
    $ps = [['구분 이름', '표시 이름', '종족', '매치 승', '매치 패', '세트 승', '세트 패', 'vsT 세트승', 'vsT 세트패', 'vsZ 세트승', 'vsZ 세트패',
        'vsP 세트승', 'vsP 세트패', '더블찬스 참여', '더블찬스 성공', '총상금']];
    foreach ($agg as $p => $x) {
        $v = static fn($k, $i) => $x[$k][$i] ?? 0;
        $ps[] = [$p, $p, $race[$p], $v('m', 0), $v('m', 1), $v('set', 0), $v('set', 1), $v('T', 0), $v('T', 1), $v('Z', 0), $v('Z', 1),
            $v('P', 0), $v('P', 1), $v('dc', 0) + $v('dc', 1), $v('dc', 0), $x['prize']];
    }
    $ms = [['맵', '한글 이름', '매치업', '앞 종족', '뒤 종족', '세트 수', '앞 종족 승', '뒤 종족 승', '맵 전체 사용 횟수']];
    foreach ($mapAgg as $map => $x) {
        foreach ([['Z', 'P'], ['T', 'Z'], ['P', 'T']] as [$f, $s]) {
            $ms[] = [$map, '', "{$f}v{$s}", $f, $s, ($x[$f . $s] ?? 0) + ($x[$s . $f] ?? 0), $x[$f . $s] ?? 0, $x[$s . $f] ?? 0, $x['n']];
        }
    }
    $ms[] = ['Map 99', '', 'ZvP', 'Z', 'P', 0, 0, 0, 0]; // 아직 쓰지 않은 맵
    // 예측: 기존 표의 기록을 경기번호·세트로 (중계진은 '구분 이름'), 결과 대기는 '결과 대기'
    $pred = [['경기번호', '세트', '중계진', '선택', '갯수', '메모', '날짜', '연도', '실제 승자', '결과', '지수 기여', '오류']];
    $rank = [];
    foreach (array_slice($old['predictions'], 2) as $r) {
        if (($r[6] ?? '') === '') {
            continue;
        }
        $res = $r[8] === '' ? '결과 대기' : $r[8];
        $pred[] = ['20260105-1', (int)substr($r[3], 4), $r[6], 'A', $r[5], '', $r[0], 2026, '', $res, '', ''];
        if ($res !== '결과 대기') {
            $x = &$rank[$r[6]];
            $x ??= [0, 0, 0, 0];
            $x[0]++;
            $x[1] += $res === '성공' ? 1 : 0;
            $x[2] += $res === '성공' ? $r[5] : -$r[5];
            $x[3] += $r[5];
            unset($x);
        }
    }
    $ranking = [['연도', 2026], ['미션 지수 = …'], [], ['중계진(구분)', '표시 이름', '예측 수(완료)', '성공', '실패', '적중률', '완료 갯수 합', '지수', '수익률', '순위']];
    foreach ($rank as $who => [$t, $w, $idx, $amt]) {
        $ranking[] = [$who, predictor_name($who), $t, $w, $t - $w, $w / $t, $amt, $idx, $idx / $amt, 1];
    }
    $tabs = [
        '안내' => [['끝장전 데이터 시트 (합성)']],
        '경기' => $games, '세트' => $sets, '예측' => $pred, '선수' => $players,
        '맵' => [['맵', '한글 이름', '사용'], ['Map 1', '맵 하나', 'O'], ['Map 99', '안 쓴 맵', '']],
        '중계진' => [['구분 이름', '표시 이름', '역할'], ['김중계 캐스터', '김중계', '캐스터'], ['이해설 해설', '이해설', '해설']],
        '선수통계' => $ps, '맵통계' => $ms, '예측순위' => $ranking,
        '오늘경기' => [['KEY', 'VALUE'], ['적용 경기번호', '20250104-1']],
        '오류' => [['탭', '행', '열 이름', '내용', '종류']],
        '선수카드' => [['읽지 않는 탭']],
    ];
    if ($tamper) {
        $tamper($tabs);
    }
    return $tabs;
}

/** 새 양식 탭 → sheet_dataset 입력 */
function fx_new_input(array $tabs): array
{
    $t = [];
    foreach (SHEET_NEW_TABS as $k => $title) {
        $t[$k] = $tabs[$title] ?? null;
    }
    return ['new' => $t];
}

/** 머리글 이름으로 열 번호 */
function fx_col(array $rows, string $name): int
{
    return (int)array_search($name, $rows[0], true);
}

test('새 양식: 기존 양식과 같은 경기 → 세트·끝장전·상금·예측·선수 정보·CG 계산이 같음, 대조 8가지 모두 일치', function () {
    $old = sheet_dataset(fx_tables(), 'api');
    $new = sheet_dataset(fx_new_input(fx_new_tabs()), 'api');
    assert_same([], $new['check']['mismatches'], '시트 집계와 모두 일치');
    assert_same([], $new['check']['unavailable']);
    assert_same(['sets' => true, 'matches' => true, 'predictions' => true, 'double' => true, 'mission' => true, 'maps' => true,
        'mapsets' => true, 'prize' => true], array_map(fn($v) => $v['available'], $new['verify']));
    $isEvent = fn($g) => $g['date'] === '2025-01-04';
    $proj = fn(array $gs) => array_map(fn($g) => array_diff_key($g, ['row' => 0]), array_values($gs));
    assert_same($proj(array_filter($old['games'], fn($g) => !$isEvent($g))), $proj($new['games']), '세트 (이벤트 경기 제외)');
    $mproj = fn(array $ms) => array_map(fn($m) => array_diff_key($m, ['rows' => 0]), $ms);
    assert_same($mproj($old['matches']), $mproj($new['matches']), '통계에 쓰는 끝장전');
    assert_same(4, count($new['matches']));
    // 새 양식에는 기본·더블 찬스 상금 구분이 없어 합계만 같다 (구분은 더블 세트 수로 나눈 값)
    assert_same(array_map(fn($x) => $x[2], $old['prize']), array_map(fn($x) => $x[2], $new['prize']), '총상금 (이벤트 경기 상금 포함, 보정 반영)');
    assert_same($old['double_chance']['가선수'], $new['double_chance']['가선수'], '더블 찬스');
    // 보정: 새 양식은 성공·참여에 같이 더한다 (기존: 경기당 2회 − 성공). 나선수 2024-01-06 성공 2 → 1 보정
    assert_same(['wins' => 3, 'losses' => 2, 'bad' => false], $new['double_chance']['나선수'], '더블 찬스 보정 (새 양식 기준)');
    $pp = fn($ps) => array_map(fn($r) => array_diff_key($r, ['row' => 0]), $ps);
    assert_same($pp($old['predictions']), $pp($new['predictions']), '예측 기록 (결과 대기 제외)');
    assert_same(stats_prediction_ranking($old['predictions'], '2026'), stats_prediction_ranking($new['predictions'], '2026'));
    assert_same(stats_mission_ranking($old['predictions'], '2026'), stats_mission_ranking($new['predictions'], '2026'));
    foreach (['가선수', '나선수'] as $p) {
        assert_same($old['players'][$p], $new['players'][$p], "선수 정보 $p (종족·닉네임)");
        assert_same(stats_race_record($old['matches'], $p, 'T'), stats_race_record($new['matches'], $p, 'T'));
        assert_same(stats_player_record($old['matches'], $old['games'], $p), stats_player_record($new['matches'], $new['games'], $p));
    }
    assert_same(stats_win_ranking($old['matches'], $old['players'], null, 5), stats_win_ranking($new['matches'], $new['players'], null, 5));
    $rk = fn($r) => array_map(fn($x) => [$x['player'], $x['total'], $x['rank']], $r);
    assert_same($rk(stats_prize_ranking($old['prize'], $old['players'], 5)), $rk(stats_prize_ranking($new['prize'], $new['players'], 5)));
    assert_same(['Map 1' => '맵 하나'], $new['map_names'], '쓴 맵의 한글 이름만');
    assert_same('김중계', $new['predictors']['김중계']['name']);
    assert_same('new', $new['check']['format']);
    assert_true(str_contains(implode(' ', $new['check']['sheet_notes']), '20250104-1'), '이벤트 경기 제외 안내');
    assert_same([], $new['check']['anomalies'], '이벤트 경기는 이상 경기가 아님 (미입력 세트는 조용히 건너뜀)');
});

test('새 양식: 시트 집계와 다르면 기존과 같은 불일치·차단, 오류 탭은 시트 입력 점검', function () {
    $ds = sheet_dataset(fx_new_input(fx_new_tabs(function (array &$t) {
        $ps = &$t['선수통계'];
        foreach ($ps as $i => &$r) {
            if ($i > 0 && $r[0] === '가선수') {
                $r[fx_col($ps, '세트 승')]++;
                $r[fx_col($ps, '총상금')] += 1;
                $r[fx_col($ps, '매치 승')]++;
            }
        }
        unset($r, $ps);
        $t['예측순위'][4][3]++; // 첫 중계진 성공 수
        $t['오류'][] = ['경기', 5, '세트3승자', '승자는 A 또는 B', '오류'];
    })), 'api');
    $kinds = array_map(fn($m) => $m['kind'] . ':' . $m['who'] . ':' . $m['item'], $ds['check']['mismatches']);
    foreach (['sets:가선수:세트 전적', 'prize:가선수:총상금', 'matches:가선수:매치 승패', 'predictions:김중계:예측 전체·적중'] as $k) {
        assert_true(in_array($k, $kinds, true), "불일치 $k — " . implode(', ', $kinds));
    }
    assert_same(false, $ds['verify']['sets']['players']['가선수']['all']);
    assert_same(false, $ds['verify']['matches']['players']['가선수'], '매치 승패가 다르면 끝장전 CG 차단');
    assert_same(false, $ds['verify']['prize']['players']['가선수']);
    assert_same(true, $ds['verify']['prize']['players']['나선수']);
    assert_true(str_contains(implode(' ', array_column($ds['check']['lint'], 'text')), '시트 오류 탭: 경기 5행 세트3승자 — 승자는 A 또는 B (오류)'));
    // data_check_view까지: 시트 형식·안내
    fresh_db();
    setting_set('data_source', 'sheet');
    data_refresh(op(), $ds);
    $sum = panel_state(op())['data']['check'];
    assert_same('new', $sum['format']);
    assert_true(count($sum['sheet_notes']) >= 1);
});

test('새 양식: 결과 대기 = 진행 중 경기, 미입력은 건너뜀, 동명이인 표시 이름, 필수 머리글 없으면 실패', function () {
    $live = fx_new_tabs(function (array &$t) {
        // 오늘 경기: 3세트 완료 + 6세트 결과 대기 (경기 탭 상태 '진행 중')
        $g = $t['경기'][1];
        $g[0] = '20260301-1';
        $g[1] = '2026-03-01';
        $g[fx_col($t['경기'], '상태')] = '진행 중';
        $t['경기'][] = $g;
        $sc = $t['세트'][0];
        for ($i = 1; $i <= 9; $i++) {
            $row = array_combine($sc, array_fill(0, count($sc), ''));
            $row = array_replace($row, ['상태' => $i <= 3 ? '완료' : '결과 대기', '경기번호' => '20260301-1', '날짜' => '2026-03-01', '세트' => $i,
                '맵' => 'Map ' . $i, '선수A' => '가선수', '선수B' => '나선수', '통계 포함' => 'O']);
            if ($i <= 3) {
                $row = array_replace($row, ['승자' => '가선수', '패자' => '나선수', '승자종족' => 'Z', '패자종족' => 'P', '상금' => 100000]);
            }
            $t['세트'][] = array_values($row);
        }
        // 시트 집계(선수통계·맵통계)는 진행 중 경기의 완료 세트도 센다 (세트 탭 상태 '완료')
        foreach ($t['선수통계'] as $i => &$r) {
            if ($i > 0 && in_array($r[0], ['가선수', '나선수'], true)) {
                $win = $r[0] === '가선수';
                $r[fx_col($t['선수통계'], $win ? '세트 승' : '세트 패')] += 3;
                $r[fx_col($t['선수통계'], $win ? 'vsP 세트승' : 'vsZ 세트패')] += 3;
            }
        }
        foreach ($t['맵통계'] as $i => &$r) {
            if ($i > 0 && in_array($r[0], ['Map 1', 'Map 2', 'Map 3'], true)) {
                $r[fx_col($t['맵통계'], '맵 전체 사용 횟수')]++;
                if ($r[2] === 'ZvP') {
                    $r[fx_col($t['맵통계'], '세트 수')]++;
                    $r[fx_col($t['맵통계'], '앞 종족 승')]++;
                }
            }
        }
        unset($r);
        // 동명이인: 다선수의 표시 이름을 '가선수'로 (구분 이름은 다름)
        $t['선수'][3][1] = '가선수';
    });
    $ds = sheet_dataset(fx_new_input($live), 'api');
    assert_same(1, count($ds['check']['live']), '진행 중 경기');
    assert_same([], $ds['check']['anomalies']);
    assert_same(4, count($ds['matches']), '진행 중 경기는 통계에 넣지 않음');
    assert_same([], $ds['check']['mismatches'], '진행 중 경기는 대조에서 뺌');
    assert_same(['다선수', '가선수', 'T'], [$ds['players']['다선수']['id'], $ds['players']['다선수']['name'], $ds['players']['다선수']['race']]);
    assert_same('가선수', $ds['players']['가선수']['name']);
    $bad = fx_new_tabs(function (array &$t) {
        $t['세트'][0][fx_col($t['세트'], '승자')] = '이긴 선수';
    });
    $e = assert_throws(ProviderError::class, fn() => sheet_dataset(fx_new_input($bad), 'api'));
    assert_true(str_contains($e->getMessage(), "새 양식 '세트' 탭의 머리글") && str_contains($e->getMessage(), '승자'), $e->getMessage());
    $odd = fx_new_tabs(function (array &$t) {
        $t['세트'][3][0] = '취소';
    });
    assert_throws(ProviderError::class, fn() => sheet_dataset(fx_new_input($odd), 'api'));
});

test('새 양식: 설정 저장(관리자만)·Google에서 새 양식 탭 읽기·xlsx 가져오기·기존 형식으로 되돌리기', function () {
    fresh_db();
    $k = fx_key();
    $log = [];
    $GLOBALS['CG_HTTP'] = fx_transport(fx_new_tabs(), $k['pub'], $log);
    try {
        google_key_save($k['json'], op());
        data_settings_save(['source' => 'sheet', 'sheet' => 'TESTsheetID_0123456789abc', 'sheet_format' => 'new',
            'new_sheet' => 'https://docs.google.com/spreadsheets/d/NEWsheetID_0123456789abcd/edit'], op());
        $v = data_settings_view(op());
        assert_same(['new', 'NEWsheetID_0123456789abcd', 'TESTsheetID_0123456789abc'], [$v['sheet_format'], $v['new_sheet_id'], $v['sheet_id']]);
        assert_same('설정됨', data_settings_view(['name' => '운영', 'role' => 'operator', 'user_id' => 2])['new_sheet_id'], '운영자에게 주소를 보여 주지 않음');
        data_refresh(op());
        $batch = rawurldecode($log[2][1]);
        assert_true(str_contains($batch, 'NEWsheetID_0123456789abcd/values:batchGet') && str_contains($batch, "'세트'!A:DZ")
            && str_contains($batch, "'선수통계'!A:DZ") && !str_contains($batch, '선수카드'), '새 양식 시트의 필요한 탭만');
        $sum = panel_state(op())['data']['check'];
        assert_same(['new', 0, 4], [$sum['format'], $sum['mismatches'], $sum['counts']['valid_matches']]);
        // xlsx 가져오기도 새 양식으로
        data_import_xlsx(['file' => base64_encode(fx_xlsx(fx_new_tabs()))], op());
        assert_same(['new', 0], [panel_state(op())['data']['check']['format'], panel_state(op())['data']['check']['mismatches']]);
        // 형식 오류·권한
        assert_throws(ActionError::class, fn() => data_settings_save(['source' => 'sheet', 'sheet_format' => 'x'], op()), 'BAD_SHEET');
        assert_throws(ActionError::class, fn() => data_settings_save(['source' => 'sheet', 'new_sheet' => 'https://example.com'], op()), 'BAD_SHEET');
        assert_throws(ActionError::class, fn() => data_settings_save(['source' => 'sheet', 'sheet_format' => 'new'],
            ['name' => '운영', 'role' => 'operator', 'user_id' => 2]), 'ADMIN_ONLY');
        // 새 양식 주소가 없으면 안내
        data_settings_save(['source' => 'sheet', 'sheet' => 'TESTsheetID_0123456789abc', 'sheet_format' => 'new', 'new_sheet' => ''], op());
        assert_true(!data_ready());
        $e = assert_throws(ActionError::class, fn() => data_refresh(op()), 'SOURCE_ERROR');
        assert_true(str_contains($e->getMessage(), '새 양식 시트 주소가 설정되지 않았습니다'), $e->getMessage());
        // 기존 형식으로 되돌리기
        $GLOBALS['CG_HTTP'] = fx_transport(fx_tabs(), $k['pub'], $log);
        data_settings_save(['source' => 'sheet', 'sheet' => 'TESTsheetID_0123456789abc', 'sheet_format' => 'old'], op());
        data_refresh(op());
        assert_same('old', panel_state(op())['data']['check']['format']);
    } finally {
        unset($GLOBALS['CG_HTTP']);
    }
});
