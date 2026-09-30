<?php
declare(strict_types=1);

test('router: 허용 주소만 통과', function () {
    foreach (['/', '/index.php', '/output.php', '/api/state.php', '/api/action.php', '/api/output.php',
        '/api/ping.php', '/assets/panel.js', '/assets/cg.css', '/assets/font-a.woff2'] as $ok) {
        assert_true(router_allowed($ok), "허용되어야 함: $ok");
    }
    foreach (['/app/storage/cg.sqlite', '/app/config.php', '/APP/x', '/app./config.php', '/%61pp/config.php',
        '/assets/../app/config.php', '/assets/..%2fapp', '/Index.php', '/api/State.php', '/api/admin.php',
        '/login.php', '/install.php', '/assets/x.php', '/assets/.htaccess', '/app/version.json',
        '/index.php/extra', '/assets/a..css', '/output.php.bak'] as $bad) {
        assert_true(!router_allowed($bad), "차단되어야 함: $bad");
    }
});

test('guard: desktop Host·루프백 판정', function () {
    $_SERVER['SERVER_PORT'] = '3100';
    foreach (['127.0.0.1:3100', 'localhost:3100', 'LOCALHOST:3100', '[::1]:3100'] as $h) {
        $_SERVER['HTTP_HOST'] = $h;
        assert_true(desktop_host_allowed(), "허용: $h");
    }
    foreach (['evil.example:3100', '127.0.0.1:80', '192.168.0.5:3100', '127.0.0.1.nip.io:3100', ''] as $h) {
        $_SERVER['HTTP_HOST'] = $h;
        assert_true(!desktop_host_allowed(), "거부: $h");
    }
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    assert_true(client_is_loopback());
    $_SERVER['REMOTE_ADDR'] = '192.168.0.10';
    assert_true(!client_is_loopback());
});

test('guard: 같은 출처 판정', function () {
    $_SERVER['HTTP_HOST'] = '127.0.0.1:3100';
    unset($_SERVER['HTTPS']);
    $_SERVER['SERVER_PORT'] = '3100';
    $_SERVER['HTTP_ORIGIN'] = 'http://127.0.0.1:3100';
    assert_true(same_origin());
    foreach (['http://evil.example', 'https://127.0.0.1:3100', 'http://127.0.0.1:3101', 'null', ''] as $o) {
        $_SERVER['HTTP_ORIGIN'] = $o;
        assert_true(!same_origin(), "거부: $o");
    }
});

test('guard: desktop CSRF 토큰은 비밀값에서 계산, 값이 바뀌면 달라짐', function () {
    fresh_db();
    $a = csrf_token();
    assert_same(64, strlen($a));
    setting_set('csrf_secret', 'other');
    assert_true($a !== csrf_token());
});
