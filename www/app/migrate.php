<?php
/**
 * DB 구조 자동 업데이트
 *
 * 기능을 추가하면서 DB 구조가 바뀌면 아래 migrations() 목록 끝에 새 번호로 추가합니다.
 * 파일을 서버에 올린 뒤 첫 접속 때 아직 적용되지 않은 번호만 순서대로 실행됩니다.
 * 이미 적용된 번호의 내용은 절대 수정하지 않습니다.
 *
 * SQL 은 MySQL 형식으로 씁니다. PC 버전(SQLite)에서는 ddl_for_driver() 가 자동으로 바꿔 실행합니다.
 * (CREATE TABLE 외의 문장은 두 DB 에서 모두 동작하는 문법으로 씁니다. 예: ALTER TABLE ... ADD COLUMN ...)
 */
declare(strict_types=1);

function migrations(): array
{
    $table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    return [
        1 => [
            "CREATE TABLE IF NOT EXISTS settings (
                k VARCHAR(50) NOT NULL PRIMARY KEY,
                v TEXT NULL
            ) $table",

            "CREATE TABLE IF NOT EXISTS admins (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(50) NOT NULL,
                role VARCHAR(10) NOT NULL DEFAULT 'staff',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_login_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_username (username)
            ) $table",

            "CREATE TABLE IF NOT EXISTS login_attempts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45) NOT NULL,
                username VARCHAR(50) NOT NULL,
                success TINYINT(1) NOT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_time (created_at)
            ) $table",

            "CREATE TABLE IF NOT EXISTS audit_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                admin_id INT UNSIGNED NULL,
                action VARCHAR(50) NOT NULL,
                target VARCHAR(100) NOT NULL DEFAULT '',
                detail TEXT NULL,
                ip VARCHAR(45) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                KEY idx_time (created_at)
            ) $table",

            // 방송 회차 (끝장전 1회 = 1건)
            "CREATE TABLE IF NOT EXISTS broadcasts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(200) NOT NULL,
                streamer_id VARCHAR(50) NOT NULL,
                broadcast_date DATE NOT NULL,
                memo TEXT NULL,
                chat_count INT UNSIGNED NOT NULL DEFAULT 0,
                donation_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_date (broadcast_date)
            ) $table",

            // 실시간 수집 창 (PC 한 대의 수집 화면 1개 = 1건)
            "CREATE TABLE IF NOT EXISTS collectors (
                id VARCHAR(40) NOT NULL PRIMARY KEY,
                broadcast_id INT UNSIGNED NOT NULL,
                admin_id INT UNSIGNED NULL,
                label VARCHAR(100) NOT NULL DEFAULT '',
                soop_broadcast_no VARCHAR(30) NOT NULL DEFAULT '',
                status VARCHAR(20) NOT NULL DEFAULT '',
                status_message VARCHAR(255) NOT NULL DEFAULT '',
                chat_count INT UNSIGNED NOT NULL DEFAULT 0,
                donation_count INT UNSIGNED NOT NULL DEFAULT 0,
                started_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                KEY idx_broadcast (broadcast_id)
            ) $table",

            // 채팅 (uid = 수집창ID:순번, 같은 채팅이 두 번 저장되지 않게 막는 값)
            "CREATE TABLE IF NOT EXISTS chat_messages (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                broadcast_id INT UNSIGNED NOT NULL,
                uid VARCHAR(64) NOT NULL,
                collector_id VARCHAR(40) NOT NULL,
                sent_at DATETIME(3) NOT NULL,
                raw_user_id VARCHAR(64) NOT NULL,
                user_id VARCHAR(64) NOT NULL,
                nickname VARCHAR(100) NOT NULL,
                message VARCHAR(500) NOT NULL,
                kind VARCHAR(10) NOT NULL DEFAULT 'chat',
                badges SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                source VARCHAR(10) NOT NULL DEFAULT 'live',
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_uid (broadcast_id, uid),
                KEY idx_time (broadcast_id, sent_at),
                KEY idx_user (broadcast_id, user_id, sent_at)
            ) $table",

            // 후원 (별풍선·애드벌룬·구독)
            "CREATE TABLE IF NOT EXISTS donations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                broadcast_id INT UNSIGNED NOT NULL,
                uid VARCHAR(64) NOT NULL,
                collector_id VARCHAR(40) NOT NULL,
                sent_at DATETIME(3) NOT NULL,
                type VARCHAR(20) NOT NULL,
                subtype VARCHAR(20) NOT NULL DEFAULT '',
                raw_user_id VARCHAR(64) NOT NULL,
                user_id VARCHAR(64) NOT NULL,
                nickname VARCHAR(100) NOT NULL,
                amount INT NOT NULL DEFAULT 0,
                target_user_id VARCHAR(64) NOT NULL DEFAULT '',
                target_nickname VARCHAR(100) NOT NULL DEFAULT '',
                extra VARCHAR(200) NOT NULL DEFAULT '',
                source VARCHAR(10) NOT NULL DEFAULT 'live',
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_uid (broadcast_id, uid),
                KEY idx_time (broadcast_id, sent_at),
                KEY idx_user (broadcast_id, user_id, sent_at)
            ) $table",

            // 집계 제외 명단 (스트리머·매니저·봇 등)
            "CREATE TABLE IF NOT EXISTS excluded_users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id VARCHAR(64) NOT NULL,
                nickname VARCHAR(100) NOT NULL DEFAULT '',
                reason VARCHAR(200) NOT NULL DEFAULT '',
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_user (user_id)
            ) $table",

            // 상품 지급 (수령자 이름·연락처·주소는 암호화 저장)
            "CREATE TABLE IF NOT EXISTS prizes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                broadcast_id INT UNSIGNED NULL,
                user_id VARCHAR(64) NOT NULL,
                nickname VARCHAR(100) NOT NULL DEFAULT '',
                reason VARCHAR(200) NOT NULL DEFAULT '',
                prize_name VARCHAR(200) NOT NULL,
                prize_type VARCHAR(20) NOT NULL DEFAULT 'coupon',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                due_date DATE NULL,
                recipient_name TEXT NULL,
                recipient_phone TEXT NULL,
                recipient_address TEXT NULL,
                memo TEXT NULL,
                paid_at DATETIME NULL,
                purged_at DATETIME NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_broadcast (broadcast_id),
                KEY idx_user (user_id),
                KEY idx_status (status)
            ) $table",
        ],

        // v1.3: 상품 목록, 당첨 기록에 상품 연결·쪽지 발송 기록·기존 시스템 가져오기 번호
        2 => [
            "CREATE TABLE IF NOT EXISTS prize_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                icon VARCHAR(16) NOT NULL DEFAULT '',
                color VARCHAR(7) NOT NULL DEFAULT '#8a93a6',
                note_type VARCHAR(10) NOT NULL DEFAULT 'tax',
                photo VARCHAR(100) NOT NULL DEFAULT '',
                memo VARCHAR(500) NOT NULL DEFAULT '',
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) $table",
            'ALTER TABLE prizes ADD COLUMN item_id INT NULL',
            'ALTER TABLE prizes ADD COLUMN note_sent_at DATETIME NULL',
            'ALTER TABLE prizes ADD COLUMN note_result VARCHAR(255) NULL',
            'ALTER TABLE prizes ADD COLUMN ext_id VARCHAR(64) NULL',
            'CREATE INDEX prizes_ext_id ON prizes (ext_id)',
            'CREATE INDEX prizes_item_id ON prizes (item_id)',
            // 누적 순위용 회차별 시청자 요약 (채팅을 정리한 회차도 누적 순위에 남습니다)
            "CREATE TABLE IF NOT EXISTS broadcast_users (
                broadcast_id INT UNSIGNED NOT NULL,
                user_id VARCHAR(64) NOT NULL,
                nickname VARCHAR(100) NOT NULL DEFAULT '',
                badges INT UNSIGNED NOT NULL DEFAULT 0,
                chats INT UNSIGNED NOT NULL DEFAULT 0,
                balloons INT UNSIGNED NOT NULL DEFAULT 0,
                adballoons INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (broadcast_id, user_id),
                KEY idx_user (user_id)
            ) $table",
            'ALTER TABLE broadcasts ADD COLUMN users_sig VARCHAR(40) NULL',
            'ALTER TABLE broadcasts ADD COLUMN users_at DATETIME NULL',
        ],
    ];
}

function schema_version(): int
{
    try {
        return (int) db_value("SELECT v FROM settings WHERE k = 'schema_version'");
    } catch (PDOException) {
        return 0; // settings 테이블이 아직 없음 (최초 설치)
    }
}

function run_migrations(): void
{
    $all = migrations();
    $latest = max(array_keys($all));
    if (schema_version() >= $latest) {
        return;
    }
    // 동시에 두 명이 접속해도 한 번만 실행되도록 잠급니다.
    $unlock = migration_lock();
    if ($unlock === null) {
        return;
    }
    try {
        $current = schema_version();
        foreach ($all as $version => $statements) {
            if ($version <= $current) {
                continue;
            }
            foreach ($statements as $sql) {
                foreach (ddl_for_driver($sql) as $one) {
                    db()->exec($one);
                }
            }
            db_upsert('settings', ['k' => 'schema_version', 'v' => (string) $version], ['k'], ['v' => '{new.v}']);
        }
    } finally {
        $unlock();
    }
}

/** 잠금을 걸고, 푸는 함수를 돌려줍니다. 잠그지 못하면 null. */
function migration_lock(): ?callable
{
    if (db_driver() === 'sqlite') {
        $file = dirname((string) config('db', [])['path']) . '/migrate.lock';
        $fh = @fopen($file, 'c');
        if (!$fh || !flock($fh, LOCK_EX)) {
            return null;
        }
        return function () use ($fh) {
            flock($fh, LOCK_UN);
            fclose($fh);
        };
    }
    if ((int) db_value("SELECT GET_LOCK('endgame_migrate', 30)") !== 1) {
        return null;
    }
    return fn() => db_value("SELECT RELEASE_LOCK('endgame_migrate')");
}
