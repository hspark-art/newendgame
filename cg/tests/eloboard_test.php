<?php
declare(strict_types=1);

// v0.10.0·v0.10.1: eloboard 온라인 전적 (#6 맞대결 1페이지, #20 종족전 2페이지) — 화면 읽기·안전 장치·조회 → AUTO (가짜 HTML만 사용, 실제 페이지를 저장하지 않는다)

/** 맞대결 탭을 흉내 낸 가짜 HTML. $c = <!-- --> 조각 넣기 */
function elo_fx_rivals(string $a, string $b, string $games, string $pa, string $pb, bool $c = false): string
{
    $k = $c ? '<!-- -->' : '';
    return "<html><head><title>가 · EloBoard</title><script>self.__next_f.push([1,\"\\\"since\\\":\\\"2026-01-01\\\" Z 999 : 1 경기\"])</script></head>"
        . "<body><div>기간</div><input type=\"date\"><span>~</span><input type=\"date\">"
        . "<div><span>가선수</span><span>Z</span><span>$a</span><span>$k:$k</span><span>$b</span></div>"
        . "<div>2026.01.02<!-- --> ~ <!-- -->2026.10.22 · <span>$games</span> 경기</div>"
        . "<div><span>T</span><span>나선수</span></div><div>$pa%</div><div>맞대결 승률</div><div>$pb%</div></body></html>";
}

function elo_fx_races(): string
{
    $row = static fn($r, $ko, $p, $w, $l) => "<div><b>$r</b> vs <span>$ko</span><span>$p</span><small>%</small><span>$w</span>승<span>$l</span>패</div>";
    return '<body><h3>종족별 전적</h3>' . $row('T', '테란', '56.1', '2,087', '1,630') . $row('P', '프로토스', '60.7', '1,487', '961')
        . $row('Z', '저그', '54.7', '445', '368') . '<h3>상대 티어별 전적</h3><div>1티어 50.0 % 1 승 1 패</div></body>';
}

/** 가짜 접속: 주소별 응답, 요청 기록. 시계·대기도 가짜 */
function elo_fake(array $pages): ArrayObject
{
    $log = new ArrayObject(['urls' => [], 'sleeps' => [], 't' => 1000.0]);
    $GLOBALS['CG_HTTP'] = static function (string $m, string $url) use ($pages, $log): array {
        $log['urls'] = array_merge($log['urls'], [$url]);
        $log['t'] += 0.3;
        foreach ($pages as $needle => $res) {
            if (str_contains($url, $needle)) {
                return is_array($res) ? $res : ['status' => 200, 'body' => $res];
            }
        }
        return ['status' => 404, 'body' => ''];
    };
    $GLOBALS['CG_CLOCK'] = static fn() => $log['t'];
    $GLOBALS['CG_SLEEP'] = static function (float $s) use ($log): void {
        $log['sleeps'] = array_merge($log['sleeps'], [$s]);
        $log['t'] += $s;
    };
    return $log;
}

function elo_unfake(): void
{
    unset($GLOBALS['CG_HTTP'], $GLOBALS['CG_CLOCK'], $GLOBALS['CG_SLEEP']);
}

/** MOCK 데이터 + 두 선수 연결 */
function elo_setup(): void
{
    fresh_db();
    data_refresh(op());
    eloboard_map_save(['player' => 'jo-iljang', 'url' => 'https://eloboard.com/players/22'], op());
    eloboard_map_save(['player' => 'jang-yunchul', 'url' => '12'], op());
}

function elo_params(string $since = '', string $until = ''): array
{
    return ['a' => ['player' => 'jo-iljang'], 'b' => ['player' => 'jang-yunchul'], 'since' => $since, 'until' => $until];
}

function elo_race_params(): array
{
    return ['a' => ['player' => 'jo-iljang', 'vs' => 'P'], 'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']];
}

/** 지금 PREVIEW의 상태 */
function elo_pv_state(): array
{
    return instance_state(instance_get(channel_get('preview')['instance_id']), current_session_id());
}

test('eloboard: 맞대결 읽기 (쉼표·<!-- --> 조각), 스크립트 안 숫자는 무시', function () {
    assert_same(['a' => 217, 'b' => 180, 'games' => 397], elo_parse_rivals(elo_fx_rivals('217', '180', '397', '54.7', '45.3')));
    assert_same(['a' => 573, 'b' => 534, 'games' => 1107], elo_parse_rivals(elo_fx_rivals('573', '534', '1,107', '51.8', '48.2', true)));
});

test('eloboard: 종족별 전적 읽기', function () {
    assert_same(['T' => [2087, 1630], 'P' => [1487, 961], 'Z' => [445, 368]], elo_parse_races(elo_fx_races()));
});

test('eloboard: 확실하지 않으면 읽지 않음 (구조 틀림·두 번 나옴·비율/경기 수 불일치)', function () {
    $bad = [
        '<body>기간 없음</body>',
        elo_fx_rivals('217', '180', '397', '54.7', '45.3') . elo_fx_rivals('1', '1', '2', '50.0', '50.0'),
        elo_fx_rivals('217', '180', '398', '54.7', '45.3'),
        elo_fx_rivals('217', '180', '397', '60.0', '40.0'),
    ];
    foreach ($bad as $i => $html) {
        assert_throws(EloError::class, fn() => elo_parse_rivals($html), null);
    }
    assert_throws(EloError::class, fn() => elo_parse_races(str_replace('56.1', '70.0', elo_fx_races())));
    assert_throws(EloError::class, fn() => elo_parse_races(elo_fx_races() . elo_fx_races()));
});

test('eloboard: 주소는 숫자 id·검증한 날짜로만 만든다', function () {
    assert_same('https://eloboard.com/players/22?tab=rivals&vs=12&since=2026-01-01&until=2026-10-26',
        elo_url_rivals(22, 12, '2026-01-01', '2026-10-26'));
    assert_same('https://eloboard.com/players/22?tab=rivals&vs=12', elo_url_rivals(22, 12, '', ''));
    assert_throws(EloError::class, fn() => elo_url_rivals(0, 12, '', ''));
    assert_throws(EloError::class, fn() => elo_url_rivals(22, 22, '', ''));
    assert_throws(EloError::class, fn() => elo_url_rivals(22, 12, '2026-02-30', ''));
    assert_throws(EloError::class, fn() => elo_url_rivals(22, 12, '2026-01-01&x=1', ''));
    assert_same([22, 22, 7, null, null, null], array_map('elo_id_from',
        ['https://eloboard.com/players/22', 'https://eloboard.com/players/22?tab=races', '7', 'https://evil.com/players/22', 'players/0', 'abc']));
});

test('eloboard: 제목 자동값 (기간) — 40자 안', function () {
    assert_same('온라인 상대 전적', elo_title('', ''));
    assert_same('온라인 전적 26.01.01~10.26', elo_title('2026-01-01', '2026-10-26'));
    assert_same('온라인 전적 25.01.01~26.10.26', elo_title('2025-01-01', '2026-10-26'));
    assert_same('온라인 전적 26.01.01~', elo_title('2026-01-01', ''));
    assert_same('온라인 전적 ~26.10.26', elo_title('', '2026-10-26'));
    assert_true(mb_strlen(elo_title('2025-01-01', '2026-10-26')) <= 40);
});

test('eloboard: 선수 연결 저장은 관리자만, 잘못된 주소 거부', function () {
    fresh_db();
    data_refresh(op());
    $user = ['name' => '운영', 'role' => 'operator', 'user_id' => 2];
    assert_throws(ActionError::class, fn() => eloboard_map_save(['player' => 'jo-iljang', 'url' => '22'], $user), 'ADMIN_ONLY');
    assert_throws(ActionError::class, fn() => eloboard_map_save(['player' => 'jo-iljang', 'url' => 'https://x.com/1'], op()), 'BAD_ELO_URL');
    eloboard_map_save(['player' => 'jo-iljang', 'url' => 'https://eloboard.com/players/22'], op());
    assert_same(['jo-iljang' => 22], elo_map());
    eloboard_map_save(['player' => 'jo-iljang', 'url' => ''], op());
    assert_same([], elo_map());
    assert_throws(ActionError::class, fn() => online_fetch(['params' => elo_params()], op()), 'ELO_NO_MAP');
});

test('eloboard #6: 맞대결 1페이지만 → AUTO 채움 (양쪽 승률), PROGRAM은 그대로, 수동 수정 우선', function () {
    elo_setup();
    $r = page_add(['template' => 'online-h2h', 'params' => elo_params()], op());
    cue_page($r['page_no']);
    $pv = channel_get('preview');
    program_take($pv['rev'], ['effect' => 'cut', 'dur_ms' => 0], op());
    $pgBefore = channel_get('program');
    $log = elo_fake(['tab=rivals' => elo_fx_rivals('573', '534', '1,107', '51.8', '48.2', true), 'tab=races' => elo_fx_races()]);
    try {
        $res = online_fetch(['template' => 'online-h2h', 'params' => elo_params()], op());
    } finally {
        elo_unfake();
    }
    assert_same(1, count($log['urls']), '맞대결만');
    assert_true(str_contains($log['urls'][0], 'tab=rivals'));
    assert_true(str_contains($res['info'], '전체') && str_contains($res['info'], '1,107경기'), $res['info']);
    assert_same(null, $res['error']);
    $st = elo_pv_state();
    assert_same('온라인 상대 전적', $st['final']['title']);
    assert_same([573, 534, 518, 482], [$st['final']['h.a'], $st['final']['h.b'], $st['final']['h.ra'], $st['final']['h.rb']]);
    assert_same(['573승 534패', '(51.8%)', '534승 573패', '(48.2%)'], [$st['view']['cols'][0]['record'], $st['view']['cols'][0]['rate'],
        $st['view']['cols'][1]['record'], $st['view']['cols'][1]['rate']]);
    assert_same($pgBefore, channel_get('program'), 'PROGRAM 그대로');
    preview_save($pv['instance_id'], ['h.a' => '1'], op());
    assert_same(1, elo_pv_state()['final']['h.a'], '수동 수정 우선');
});

test('eloboard #6: 기간 지정 → 맞대결만으로 송출 가능 (막지 않음), 캐시는 네트워크 없이', function () {
    elo_setup();
    $p = elo_params('2026-01-01', '2026-10-26');
    $log = elo_fake(['tab=rivals' => elo_fx_rivals('217', '180', '397', '54.7', '45.3')]);
    try {
        $res = online_fetch(['params' => $p], op()); // template 없으면 #6
        assert_same(1, count($log['urls']));
        assert_true(str_contains($log['urls'][0], 'since=2026-01-01&until=2026-10-26'));
        assert_true(!array_key_exists('note', $res));
        online_fetch(['template' => 'online-h2h', 'params' => $p], op());
        assert_same(1, count($log['urls']), '6시간 캐시');
    } finally {
        elo_unfake();
    }
    $r = page_add(['template' => 'online-h2h', 'params' => $p], op());
    cue_page($r['page_no']);
    $st = elo_pv_state();
    assert_same(['온라인 전적 26.01.01~10.26', 217, 180], [$st['final']['title'], $st['final']['h.a'], $st['final']['h.b']]);
    assert_same([], $st['problems'], '기간 지정도 송출 가능');
    assert_true(str_contains(cg_render($st['view']), '<b>217 : 180</b>'));
    take_now();
});

test('eloboard #20: 종족전 2페이지 (A·B 종족별 전적) → AUTO, 2초 간격, 캐시', function () {
    elo_setup();
    $log = elo_fake(['tab=races' => elo_fx_races(), 'tab=rivals' => elo_fx_rivals('1', '1', '2', '50.0', '50.0')]);
    try {
        $res = online_fetch(['template' => 'online-race', 'params' => elo_race_params()], op());
        assert_same(['https://eloboard.com/players/22?tab=races', 'https://eloboard.com/players/12?tab=races'], $log['urls']);
        assert_same(1, count($log['sleeps']), '2번째 요청 전 대기');
        assert_true($log['sleeps'][0] > 1.6 && $log['sleeps'][0] <= 2.001);
        assert_true(str_contains($res['info'], '통산'), $res['info']);
        online_fetch(['template' => 'online-race', 'params' => elo_race_params()], op());
        assert_same(2, count($log['urls']), '6시간 캐시');
    } finally {
        elo_unfake();
    }
    $r = page_add(['template' => 'online-race', 'params' => elo_race_params()], op());
    cue_page($r['page_no']);
    $st = elo_pv_state();
    assert_same([], $st['problems']);
    assert_same(['온라인 상대 종족 전적 (통산)', 1487, 961, 445, 368], [$st['final']['title'], $st['final']['a.wins'], $st['final']['a.losses'],
        $st['final']['b.wins'], $st['final']['b.losses']], 'A vs P, B vs Z');
    assert_same(['1487승 961패', '(60.7%)'], [$st['view']['cols'][0]['record'], $st['view']['cols'][0]['rate']]);
    // 같은 선수의 #6 페이지에는 종족전 결과가 섞이지 않는다
    $r = page_add(['template' => 'online-h2h', 'params' => elo_params()], op());
    cue_page($r['page_no']);
    assert_same(12, elo_pv_state()['final']['h.a'], 'MOCK 맞대결 그대로');
});

test('eloboard: v0.10.0에 저장한 #6 페이지 (a.vs·b.vs 파라미터, a.wins 수동값) → 오류 없이 맞대결로 표시', function () {
    elo_setup();
    $old = ['a' => ['player' => 'jo-iljang', 'vs' => 'P'], 'b' => ['player' => 'jang-yunchul', 'vs' => 'Z'], 'since' => '', 'until' => ''];
    $r = page_add(['template' => 'online-h2h', 'params' => $old], op());
    // 예전 필드의 수동값·뺀 항목이 DB에 남아 있는 경우
    $iid = (int)db_one('SELECT instance_id FROM cg_rundown WHERE page_no = ?', [$r['page_no']])['instance_id'];
    db_exec('UPDATE cg_instances SET params_json = ?, hidden_json = ? WHERE id = ?', [json_enc($old), '["a.rate","a.wins"]', $iid]);
    db_exec('INSERT INTO cg_overrides (session_id, instance_id, field, value_json, auto_at_set_json, keep_next, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)',
        [current_session_id(), $iid, 'a.wins', '5', 'null', 'test', now(), now()]);
    $log = elo_fake(['tab=rivals' => elo_fx_rivals('217', '180', '397', '54.7', '45.3')]);
    try {
        online_fetch(['template' => 'online-h2h', 'params' => $old], op());
    } finally {
        elo_unfake();
    }
    cue_page($r['page_no']);
    $st = elo_pv_state();
    assert_same([], $st['problems']);
    assert_same(217, $st['final']['h.a']);
    assert_true(!isset($st['final']['a.wins']));
    $html = cg_render($st['view']);
    assert_true(str_contains($html, 'cg-online') && !str_contains($html, 'cg-vs'));
});

test('eloboard: 요청 사이 2초 (두 CG 공통), 접속 오류는 이전 값 유지, 읽기 실패는 비움, 잠금 중이면 거부', function () {
    elo_setup();
    $p = elo_params();
    $log = elo_fake(['tab=rivals' => elo_fx_rivals('573', '534', '1,107', '51.8', '48.2'), 'tab=races' => elo_fx_races()]);
    try {
        online_fetch(['template' => 'online-race', 'params' => elo_race_params()], op());
        online_fetch(['params' => $p], op());
        assert_same(2, count($log['sleeps']), '2·3번째 요청 전 대기 (조회를 이어 눌러도)');
        foreach ($log['sleeps'] as $s) {
            assert_true($s > 1.6 && $s <= 2.001, "대기 $s");
        }
    } finally {
        elo_unfake();
    }
    $key = elo_result_key('online-h2h', $p, elo_map());
    @unlink(elo_cache_path());
    elo_fake(['tab=rivals' => ['status' => 503, 'body' => '']]);
    try {
        assert_throws(ActionError::class, fn() => online_fetch(['params' => $p], op()), 'ELO_FETCH');
    } finally {
        elo_unfake();
    }
    assert_same(573, elo_results()[$key]['h_a'], '이전 값 유지');
    elo_fake(['tab=rivals' => '<body>바뀐 화면</body>']);
    try {
        $res = online_fetch(['params' => $p], op());
    } finally {
        elo_unfake();
    }
    assert_same(ELO_PARSE_ERROR, $res['error']);
    assert_same(null, elo_results()[$key]['h_a'], '읽기 실패 → 비움');
    $fp = fopen(storage_dir() . '/eloboard.lock', 'c');
    flock($fp, LOCK_EX);
    try {
        // 같은 프로세스에서는 flock이 다시 잡히므로 다른 프로세스로 확인
        $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('$f=fopen($argv[1],"c");echo flock($f,LOCK_EX|LOCK_NB)?"got":"busy";')
            . ' ' . escapeshellarg(storage_dir() . '/eloboard.lock');
        assert_same('busy', (string)shell_exec($cmd));
    } finally {
        fclose($fp);
    }
});

test('v0.10.2: 기간 칸이 없는 예전 #6 페이지(since·until 없음)도 오류 없이 자동값 계산', function () {
    $tpl = template_get('online-h2h');
    $p = ['a' => ['player' => '가선수', 'vs' => 'P'], 'b' => ['player' => '나선수', 'vs' => 'Z']];
    $ds = ['players' => ['가선수' => ['name' => '가선수'], '나선수' => ['name' => '나선수']]];
    $auto = $tpl['auto']($p, $ds);
    assert_same('온라인 상대 전적', $auto['title']);
    assert_true(str_contains($tpl['summary']($p, ['players' => $ds['players']]), '전체'));
});
