<?php
declare(strict_types=1);

// 웹 계정 규칙 (함수 단위)

function admin_op(): array
{
    $u = auth_install_admin('boss', '관리자', 'boss-pass-12345', 'boss-pass-12345');
    return ['name' => '관리자 (boss)', 'role' => 'admin', 'user_id' => (int)$u['id']];
}

test('auth: 첫 관리자는 한 번만, 입력 규칙', function () {
    fresh_db(['mode' => 'web']);
    assert_throws(ActionError::class, fn() => auth_install_admin('AB', '관리자', 'boss-pass-12345', 'boss-pass-12345'), 'VALIDATION');
    assert_throws(ActionError::class, fn() => auth_install_admin('boss', '', 'boss-pass-12345', 'boss-pass-12345'), 'VALIDATION');
    assert_throws(ActionError::class, fn() => auth_install_admin('boss', '관리자', 'boss-pass-12345', 'different-12345'), 'VALIDATION');
    admin_op();
    assert_throws(ActionError::class, fn() => auth_install_admin('boss2', '관리자2', 'boss-pass-12345', 'boss-pass-12345'), 'ALREADY_INSTALLED');
    $u = db_one("SELECT * FROM cg_users WHERE username = 'boss'");
    assert_true(password_verify('boss-pass-12345', $u['password_hash']), '비밀번호 해시 저장');
    assert_true(!str_contains($u['password_hash'], 'boss-pass'), '원문 저장 안 함');
});

test('auth: 초대 링크 — 원문 미저장, 1회용, 만료, 취소, 기간 제한', function () {
    fresh_db(['mode' => 'web']);
    $admin = admin_op();
    $t = auth_invite_create('테스트', 72, $admin);
    assert_same(0, (int)db_value('SELECT COUNT(*) FROM cg_links WHERE token_hash = ?', [$t]), '원문 저장 안 함');
    assert_same(1, (int)db_value('SELECT COUNT(*) FROM cg_links WHERE token_hash = ?', [hash('sha256', $t)]));
    assert_throws(ActionError::class, fn() => auth_invite_create('x', 169, $admin), 'VALIDATION');
    assert_throws(ActionError::class, fn() => auth_invite_create('x', 0, $admin), 'VALIDATION');
    $u = auth_register($t, 'writer', '작가', 'writer-pass-123', 'writer-pass-123');
    assert_same('pending', $u['status']);
    assert_same('operator', $u['role']);
    assert_throws(ActionError::class, fn() => auth_register($t, 'writer2', '작가2', 'writer-pass-123', 'writer-pass-123'), 'LINK_INVALID');
    $t2 = auth_invite_create('만료', 1, $admin);
    db_exec("UPDATE cg_links SET expires_at = '2000-01-01 00:00:00' WHERE token_hash = ?", [hash('sha256', $t2)]);
    assert_throws(ActionError::class, fn() => auth_register($t2, 'late', '늦음', 'writer-pass-123', 'writer-pass-123'), 'LINK_INVALID');
    $t3 = auth_invite_create('취소', 24, $admin);
    auth_link_revoke((int)db_value('SELECT id FROM cg_links WHERE token_hash = ?', [hash('sha256', $t3)]), $admin);
    assert_throws(ActionError::class, fn() => auth_register($t3, 'late2', '늦음', 'writer-pass-123', 'writer-pass-123'), 'LINK_INVALID');
    $t4 = auth_invite_create('중복 아이디', 24, $admin);
    assert_throws(ActionError::class, fn() => auth_register($t4, 'WRITER', '작가', 'writer-pass-123', 'writer-pass-123'), 'USERNAME_TAKEN');
    assert_true(link_valid($t4, 'invite') !== null, '실패한 가입은 링크를 쓰지 않음');
});

test('auth: 같은 초대 링크로 동시에 두 번 가입 → 하나만 성공 (별도 연결)', function () {
    if (db_driver() === 'mysql') {
        fresh_db(['mode' => 'web']);
    } else {
        fresh_db(['mode' => 'web']);
    }
    $admin = admin_op();
    $t = auth_invite_create('경쟁', 24, $admin);
    // 두 번째 연결을 따로 열어 첫 번째 트랜잭션이 열린 동안 시도한다
    $cfg = $GLOBALS['CG_CONFIG'];
    $pdo2 = db_driver() === 'sqlite'
        ? new PDO('sqlite:' . $cfg['db']['path'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])
        : new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s', $cfg['db']['host'], $cfg['db']['port'], $cfg['db']['name']),
            $cfg['db']['user'], $cfg['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if (db_driver() === 'sqlite') {
        $pdo2->exec('PRAGMA busy_timeout = 100');
    } else {
        $pdo2->exec('SET innodb_lock_wait_timeout = 1');
    }
    $second = null;
    db_tx(function () use ($t, $pdo2, &$second) {
        auth_register($t, 'first', '첫째', 'writer-pass-123', 'writer-pass-123');
        // 첫 트랜잭션이 끝나기 전에 다른 연결이 같은 링크를 쓰려고 함 → 잠금 때문에 실패해야 한다
        try {
            if (db_driver() === 'sqlite') {
                $pdo2->exec('BEGIN IMMEDIATE');
            } else {
                $pdo2->beginTransaction();
            }
            $st = $pdo2->prepare('UPDATE cg_links SET used_at = ? WHERE token_hash = ? AND used_at IS NULL');
            $st->execute([now(), hash('sha256', $t)]);
            $second = 'ran';
            $pdo2->rollBack();
        } catch (PDOException $e) {
            $second = 'blocked';
        }
    });
    assert_same('blocked', $second, '다른 연결은 잠금에 막힘');
    assert_throws(ActionError::class, fn() => auth_register($t, 'second', '둘째', 'writer-pass-123', 'writer-pass-123'), 'LINK_INVALID');
    assert_same(2, (int)db_value('SELECT COUNT(*) FROM cg_users'));
});

test('auth: 계정 상태 변경 규칙과 세션 무효화', function () {
    fresh_db(['mode' => 'web']);
    $admin = admin_op();
    $u = auth_register(auth_invite_create('', 24, $admin), 'writer', '작가', 'writer-pass-123', 'writer-pass-123');
    $id = (int)$u['id'];
    assert_throws(ActionError::class, fn() => auth_user_update($id, 'suspend', $admin), 'BAD_STATE');
    auth_user_update($id, 'approve', $admin);
    $gen = (int)db_value('SELECT session_gen FROM cg_users WHERE id = ?', [$id]);
    auth_user_update($id, 'suspend', $admin);
    assert_same($gen + 1, (int)db_value('SELECT session_gen FROM cg_users WHERE id = ?', [$id]), '정지 시 세션 세대 증가');
    auth_user_update($id, 'activate', $admin);
    auth_user_update($id, 'make_admin', $admin);
    assert_same('admin', db_value('SELECT role FROM cg_users WHERE id = ?', [$id]));
    assert_throws(ActionError::class, fn() => auth_user_update($admin['user_id'], 'make_operator', $admin), 'SELF');
    assert_throws(ActionError::class, fn() => auth_user_update($id, 'nope', $admin), 'BAD_STATE');
    // 재설정 링크: 새로 만들면 이전 링크 무효, 사용하면 세대 증가
    $r1 = auth_reset_create($id, $admin);
    $r2 = auth_reset_create($id, $admin);
    assert_same(null, link_valid($r1, 'reset'));
    $gen = (int)db_value('SELECT session_gen FROM cg_users WHERE id = ?', [$id]);
    auth_reset_password($r2, 'brand-new-pass-1', 'brand-new-pass-1');
    assert_same($gen + 1, (int)db_value('SELECT session_gen FROM cg_users WHERE id = ?', [$id]));
    assert_throws(ActionError::class, fn() => auth_reset_password($r2, 'brand-new-pass-2', 'brand-new-pass-2'), 'LINK_INVALID');
    assert_throws(ActionError::class, fn() => auth_change_password($id, 'wrong', 'another-pass-12', 'another-pass-12'), 'VALIDATION');
    auth_change_password($id, 'brand-new-pass-1', 'another-pass-12', 'another-pass-12');
    assert_true(password_verify('another-pass-12', db_value('SELECT password_hash FROM cg_users WHERE id = ?', [$id])));
});

test('auth: 로그인 실패 제한은 5분 창', function () {
    fresh_db(['mode' => 'web']);
    admin_op();
    $_SERVER['REMOTE_ADDR'] = '10.0.0.9';
    for ($i = 0; $i < 10; $i++) {
        assert_throws(ActionError::class, fn() => auth_login('boss', 'bad'), 'LOGIN_FAILED');
    }
    assert_throws(ActionError::class, fn() => auth_login('boss', 'boss-pass-12345'), 'RATE_LIMIT');
    db_exec("UPDATE cg_attempts SET window_start = '2000-01-01 00:00:00'");
    for ($i = 0; $i < 9; $i++) {
        assert_throws(ActionError::class, fn() => auth_login('boss', 'bad'), 'LOGIN_FAILED');
    }
    assert_same('boss', auth_login('boss', 'boss-pass-12345')['username'], '9번 실패 후 10번째 성공');
    assert_same(null, db_value("SELECT hits FROM cg_attempts WHERE bucket = 'user:boss'"), '성공하면 아이디 기록 삭제');
    assert_same('boss', auth_login('boss', 'boss-pass-12345')['username'], '창이 지나면 다시 로그인');
    assert_throws(ActionError::class, fn() => auth_login('ghost', 'whatever-123'), 'LOGIN_FAILED');
});

test('release: manifest 무결성 검사 (누락·변경 검출)', function () {
    $root = $GLOBALS['TEST_TMP'] . '/rel-' . bin2hex(random_bytes(3));
    @mkdir("$root/app", 0775, true);
    file_put_contents("$root/a.php", 'A');
    file_put_contents("$root/b.php", 'B');
    assert_same('no_manifest', release_verify($root)['status']);
    file_put_contents("$root/app/manifest.sha256", hash('sha256', 'A') . "  a.php\n" . hash('sha256', 'B') . "  b.php\n"
        . hash('sha256', 'C') . "  c.php\n");
    file_put_contents("$root/b.php", 'B2');
    $r = release_verify($root);
    assert_same('mismatch', $r['status']);
    assert_same(['c.php'], $r['missing']);
    assert_same(['b.php'], $r['changed']);
    file_put_contents("$root/b.php", 'B');
    file_put_contents("$root/c.php", 'C');
    assert_same('ok', release_verify($root)['status']);
});
