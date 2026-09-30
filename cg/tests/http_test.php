<?php
declare(strict_types=1);

// 실제 PHP 서버를 띄워 HTTP로 확인하는 테스트 (웹 모드 계정·권한·비밀 출력, PC 모드 접근 규칙)

require_once __DIR__ . '/http_lib.php';

function web_server(): TestServer
{
    $dir = $GLOBALS['TEST_TMP'] . '/web-' . bin2hex(random_bytes(3));
    @mkdir($dir, 0775, true);
    $db = test_db_config($dir);
    if ($db['driver'] === 'mysql') {
        fresh_db(); // 테스트 DB 비우기
    }
    return new TestServer(['mode' => 'web', 'db' => $db, 'storage_dir' => $dir], __DIR__ . '/web_router.php');
}

test('http(web): 설치 → 초대 → 가입 → 승인 → 조작 → 비밀 출력 → 정지·재설정·시도 제한', function () {
    $srv = web_server();
    try {
        $b = $srv->base;
        $admin = new Client($b);
        $anon = new Client($b);

        // 설치
        assert_true(str_contains($anon->get('/login.php')['body'], 'install.php'), '관리자 없으면 설치 안내');
        $admin->get('/install.php');
        $r = $admin->req('POST', '/install.php', ['_csrf' => $admin->csrf, 'username' => 'admin', 'name' => '관리자',
            'password' => 'admin-pass-1234', 'password2' => 'admin-pass-1234']);
        assert_same(403, $r['status'], 'Origin 없는 폼 제출 거부');
        $r = $admin->form('/install.php', ['username' => 'admin', 'name' => '관리자', 'password' => 'short', 'password2' => 'short']);
        assert_same(422, $r['status'], '짧은 비밀번호 거부');
        $r = $admin->form('/install.php', ['username' => 'admin', 'name' => '관리자', 'password' => 'admin-pass-1234', 'password2' => 'admin-pass-1234']);
        assert_same(303, $r['status']);
        assert_same('admin.php', $r['location']);
        assert_same(404, $anon->get('/install.php')['status'], '설치 후 install.php 동작 안 함');

        // 로그인 없이 접근
        assert_same('login.php', $anon->get('/index.php')['location']);
        assert_same(401, $anon->get('/api/state.php')['status']);
        assert_same(404, $anon->get('/output.php?layer=1')['status'], '토큰 없는 송출 주소 404');
        assert_same(403, $anon->get('/app/config.php')['status']);

        // 초대 링크
        $admin->get('/admin.php');
        $admin->form('/admin.php', ['do' => 'invite', 'label' => '박작가', 'hours' => '72']);
        $page = $admin->get('/admin.php')['body'];
        assert_true((bool)preg_match('#invite\.php\?token=([A-Za-z0-9_-]+)#', $page, $m), '초대 링크 표시');
        $token = $m[1];
        assert_true(!str_contains($admin->get('/admin.php')['body'], $token), '초대 링크는 한 번만 표시');

        // 가입 신청
        $op = new Client($b);
        $op->get('/invite.php?token=' . $token);
        $r = $op->form('/invite.php?token=' . $token, ['username' => 'Bad Name', 'name' => '박작가', 'password' => 'operator-pass-1', 'password2' => 'operator-pass-1']);
        assert_same(422, $r['status'], '아이디 형식 오류');
        $r = $op->form('/invite.php?token=' . $token, ['username' => 'writer1', 'name' => '박작가', 'password' => 'operator-pass-1', 'password2' => 'operator-pass-1']);
        assert_same('pending.php', $r['location']);
        assert_same('pending.php', $op->get('/index.php')['location'], '승인 전에는 패널 대신 대기 화면');
        assert_same(404, $op->get('/output.php?layer=1')['status'], '승인 전 계정은 송출 화면도 불가');
        assert_same(403, $op->get('/api/state.php')['status']);
        $other = new Client($b);
        assert_same(410, $other->get('/invite.php?token=' . $token)['status'], '사용한 초대 링크 재사용 불가');

        // 승인
        $uid = (int)db_value_web($srv, "SELECT id FROM cg_users WHERE username = 'writer1'");
        $admin->get('/admin.php');
        $admin->form('/admin.php', ['do' => 'user', 'id' => (string)$uid, 'action' => 'approve']);
        $r = $op->get('/index.php');
        assert_same(200, $r['status'], '승인 후 조작 패널');
        assert_same(403, $op->get('/admin.php')['status'], '운영자는 관리자 화면 불가');

        // 조작
        $j = $op->action('refresh_data')['json'];
        assert_true($j['ok'] === true, '데이터 새로고침');
        $r = $op->action('page_add', ['template' => 'race-win-rate', 'params' => ['a' => ['player' => 'jo-iljang', 'vs' => 'P'],
            'b' => ['player' => 'jang-yunchul', 'vs' => 'Z']]]);
        assert_true($r['json']['ok'] === true, '페이지 추가');
        $st = $op->get('/api/state.php')['json'];
        assert_same('박작가', $st['operator']['name']);
        assert_same('operator', $st['operator']['role']);
        assert_same(403, $op->action('take', ['preview_rev' => $st['preview']['rev']], 'https://evil.example')['status'], '다른 출처 조작 거부');
        $bad = $op->req('POST', '/api/action.php', ['action' => 'show'], ['Origin: ' . $b, 'X-CSRF-Token: wrong'], true);
        assert_same(403, $bad['status'], 'CSRF 토큰 틀리면 거부');
        $r = $op->action('take', ['preview_rev' => $st['preview']['rev']]);
        assert_true($r['json']['ok'] === true, 'TAKE');
        $url = $r['json']['state']['outputs']['program'];
        assert_true(str_contains($url, 'output.php?t='), '송출 주소에 비밀 토큰');
        $path = substr($url, strlen($b));

        // 비밀 송출 주소 (로그인 없이)
        $obs = new Client($b);
        $out = $obs->get($path);
        assert_same(200, $out['status']);
        assert_true(str_contains($out['body'], '33승 21패'), '토큰 주소로 송출 화면');
        assert_true(!str_contains($out['headers'], 'X-Frame-Options'), '송출 화면은 브라우저 소스용으로 프레임 허용');
        $tok = (string)parse_url($url, PHP_URL_QUERY);
        parse_str($tok, $q);
        assert_same(200, $obs->get('/api/output.php?t=' . $q['t'] . '&layer=1')['status']);
        assert_same(401, $obs->get('/api/output.php?t=' . $q['t'] . '&layer=1&ch=preview')['status'], '토큰으로 PREVIEW는 불가');
        assert_same(401, $obs->get('/api/state.php')['status'], '토큰으로 패널 상태 불가');
        assert_same(404, $obs->get('/output.php?t=wrong&layer=1')['status']);
        assert_same(404, $obs->get('/api/output.php?t=' . $q['t'] . '&layer=2')['status'], '없는 레이어 404');

        // 송출 주소 재발급
        $admin->get('/admin.php');
        $admin->form('/admin.php', ['do' => 'rotate']);
        assert_same(404, $obs->get($path)['status'], '재발급 후 이전 주소 차단');

        // 관리자 자기 계정 변경 불가
        $adminId = (int)db_value_web($srv, "SELECT id FROM cg_users WHERE username = 'admin'");
        $admin->form('/admin.php', ['do' => 'user', 'id' => (string)$adminId, 'action' => 'suspend']);
        assert_same('active', db_value_web($srv, "SELECT status FROM cg_users WHERE username = 'admin'"));

        // 정지 → 즉시 차단
        $admin->form('/admin.php', ['do' => 'user', 'id' => (string)$uid, 'action' => 'suspend']);
        assert_same(401, $op->get('/api/state.php')['status'], '정지하면 기존 로그인 즉시 무효');
        $again = new Client($b);
        $again->get('/login.php');
        assert_same(403, $again->form('/login.php', ['username' => 'writer1', 'password' => 'operator-pass-1'])['status'], '정지 계정 로그인 불가');
        $admin->get('/admin.php');
        $admin->form('/admin.php', ['do' => 'user', 'id' => (string)$uid, 'action' => 'activate']);

        // 재설정 링크
        $admin->get('/admin.php');
        $admin->form('/admin.php', ['do' => 'reset', 'id' => (string)$uid]);
        $admin->form('/admin.php', ['do' => 'reset', 'id' => (string)$uid]);
        preg_match('#reset\.php\?token=([A-Za-z0-9_-]+)#', $admin->get('/admin.php')['body'], $m);
        $rt = $m[1] ?? '';
        assert_true($rt !== '', '재설정 링크 표시');
        $rc = new Client($b);
        $rc->get('/reset.php?token=' . $rt);
        $r = $rc->form('/reset.php?token=' . $rt, ['password' => 'new-operator-pass', 'password2' => 'new-operator-pass']);
        assert_same(200, $r['status']);
        assert_same(410, (new Client($b))->get('/reset.php?token=' . $rt)['status'], '재설정 링크 1회용');
        $l = new Client($b);
        $l->get('/login.php');
        assert_same(401, $l->form('/login.php', ['username' => 'writer1', 'password' => 'operator-pass-1'])['status'], '이전 비밀번호 거부');
        $l->get('/login.php');
        assert_same('index.php', $l->form('/login.php', ['username' => 'writer1', 'password' => 'new-operator-pass'])['location']);

        // 로그인 시도 제한 (아이디당 10회 / 5분)
        $atk = new Client($b);
        for ($i = 0; $i < 10; $i++) {
            $atk->get('/login.php');
            $atk->form('/login.php', ['username' => 'admin', 'password' => 'wrong-' . $i]);
        }
        $atk->get('/login.php');
        assert_same(429, $atk->form('/login.php', ['username' => 'admin', 'password' => 'admin-pass-1234'])['status'], '10회 실패 후 맞는 비밀번호도 잠시 거부');

        // 로그아웃
        $l->get('/index.php');
        $l->form('/logout.php', []);
        assert_same(401, $l->get('/api/state.php')['status'], '로그아웃 후 차단');

        // 계정 기록은 조작 패널 로그에 섞이지 않음
        assert_true(!in_array('auth', array_column($st['logs'], 'type'), true), '패널 로그에 계정 기록 없음');
    } finally {
        $srv->stop();
    }
});

/** 테스트 서버가 쓰는 DB를 직접 조회 */
function db_value_web(TestServer $srv, string $sql): mixed
{
    $cfg = require $srv->dir . '/config.php';
    $saved = $GLOBALS['CG_CONFIG'] ?? null;
    $GLOBALS['CG_CONFIG'] = $cfg;
    db_reset();
    try {
        return db_value($sql);
    } finally {
        $GLOBALS['CG_CONFIG'] = $saved;
        db_reset();
    }
}

test('http(pc): 조작은 로컬 Host만, 웹 전용 화면·내부 파일 차단, 송출 화면 프레임 허용', function () {
    $dir = $GLOBALS['TEST_TMP'] . '/pc-' . bin2hex(random_bytes(3));
    @mkdir($dir, 0775, true);
    $srv = new TestServer(['mode' => 'desktop', 'db' => ['driver' => 'sqlite', 'path' => "$dir/cg.sqlite"], 'storage_dir' => $dir,
        'operator' => '운영자'], dirname(__DIR__) . '/desktop/router.php');
    try {
        $c = new Client($srv->base);
        assert_same(200, $c->get('/')['status']);
        assert_true(str_contains($c->get('/')['headers'], 'X-Frame-Options: DENY'), '패널은 프레임 금지');
        assert_same(200, $c->get('/output.php?layer=1')['status']);
        foreach (['/login.php', '/admin.php', '/install.php', '/app/config.php', '/app/control.php', '/APP/control.php',
            '//app/config.php', '//api/state.php'] as $p) {
            assert_same(404, $c->get($p)['status'], "차단: $p");
        }
        $evil = $c->req('GET', '/api/state.php', null, ['Host: attacker.example:' . parse_url($srv->base, PHP_URL_PORT)]);
        assert_same(403, $evil['status'], 'DNS 리바인딩 Host 거부');
        $c->get('/');
        assert_same(403, $c->action('refresh_data', [], 'http://evil.example')['status'], '다른 출처 조작 거부');
        $r = $c->req('POST', '/api/action.php', ['action' => 'refresh_data'], ['Origin: ' . $srv->base, 'X-CSRF-Token: ' . $c->csrf]);
        assert_same(415, $r['status'], 'JSON이 아닌 조작 요청 거부');
        assert_true($c->action('refresh_data')['json']['ok'] === true, '정상 조작');
        assert_same(false, $c->get('/api/ping.php')['json']['lan'], 'ping: 이 PC 전용 모드');
        assert_same(404, $c->get('/output.php?layer=3')['status'], '없는 레이어 404');
        assert_true(!is_file($dir . '/error.log') || !str_contains((string)file_get_contents($dir . '/error.log'), 'BAD_LAYER'), '없는 레이어는 오류 기록에 남지 않음');
    } finally {
        $srv->stop();
    }
});

test('http: 폼 제출이 가능한 Referrer-Policy (송출 화면만 no-referrer)', function () {
    $dir = $GLOBALS['TEST_TMP'] . '/rp-' . bin2hex(random_bytes(3));
    @mkdir($dir, 0775, true);
    $srv = new TestServer(['mode' => 'web', 'db' => ['driver' => 'sqlite', 'path' => "$dir/cg.sqlite"], 'storage_dir' => $dir],
        __DIR__ . '/web_router.php');
    try {
        $c = new Client($srv->base);
        assert_true(str_contains($c->get('/install.php')['headers'], 'Referrer-Policy: same-origin'), '계정 화면 same-origin');
        assert_true(str_contains($c->get('/output.php?t=' . 'x')['headers'], 'Referrer-Policy: no-referrer'), '송출 화면 no-referrer');
    } finally {
        $srv->stop();
    }
});
