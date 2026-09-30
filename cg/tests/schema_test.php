<?php
declare(strict_types=1);

test('schema: 첫 실행 시 테이블·기본값 생성', function () {
    fresh_db();
    assert_same(1, schema_version());
    assert_same('0', setting_get('state_rev'));
    assert_same('1', setting_get('current_session_id'));
    assert_true(strlen((string)setting_get('csrf_secret')) >= 40, 'csrf_secret 길이');
    assert_true(strlen((string)setting_get('output_token')) >= 40, 'output_token 길이');
    assert_same(2, (int)db_value('SELECT COUNT(*) FROM cg_channels WHERE layer = 1'));
    assert_same('NEVER', db_value("SELECT status FROM cg_sources WHERE id = 'mock'"));
    assert_same('첫 방송 세션', db_value('SELECT name FROM cg_sessions WHERE id = 1'));
});

test('schema: 마이그레이션 재실행은 아무것도 바꾸지 않음', function () {
    fresh_db();
    $secret = setting_get('csrf_secret');
    reopen_db();
    run_migrations();
    assert_same($secret, setting_get('csrf_secret'));
    assert_same(1, (int)db_value('SELECT COUNT(*) FROM cg_sessions'));
});

test('schema: ddl 토큰 치환', function () {
    fresh_db();
    $expected = db_driver() === 'mysql'
        ? 'CREATE TABLE t (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        : 'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT) ';
    assert_same($expected, ddl('CREATE TABLE t (id {pk}) {opts}'));
});

test('db: 트랜잭션 오류 시 되돌림, 중첩은 바깥에 합쳐짐', function () {
    fresh_db();
    assert_throws(RuntimeException::class, function () {
        db_tx(function () {
            setting_set('state_rev', '5');
            db_tx(fn() => setting_set('x', '1'));
            throw new RuntimeException('boom');
        });
    });
    assert_same('0', setting_get('state_rev'));
    assert_same(null, setting_get('x'));
    db_tx(fn() => setting_set('state_rev', '7'));
    assert_same('7', setting_get('state_rev'));
});

test('db: 0·null·문자열 값 구분 저장', function () {
    fresh_db();
    db_exec('INSERT INTO cg_logs (created_at, type, action, operator, session_id, auto_json) VALUES (?, ?, ?, ?, ?, ?)',
        [now(), 'data', 't', 'x', 0, null]);
    $row = db_one('SELECT session_id, auto_json FROM cg_logs');
    assert_same(0, (int)$row['session_id']);
    assert_same(null, $row['auto_json']);
});
