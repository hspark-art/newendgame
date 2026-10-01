<?php
declare(strict_types=1);

/**
 * 번호식 DB 마이그레이션. 이미 배포된 번호의 내용은 절대 고치지 않고, 새 번호를 맨 끝에 추가한다.
 * DDL 토큰: {pk} 자동 증가 기본키, {opts} MySQL 테이블 옵션, {bigtext} 큰 글자 칸(MySQL MEDIUMTEXT, 최대 16MB).
 */

function ddl(string $sql): string
{
    $mysql = db_driver() === 'mysql';
    return strtr($sql, [
        '{pk}' => $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
        '{opts}' => $mysql ? 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '',
        '{bigtext}' => $mysql ? 'MEDIUMTEXT' : 'TEXT',
    ]);
}

/** @return array<int, list<string|Closure>> */
function migrations(): array
{
    return [
        1 => [
            'CREATE TABLE cg_settings (k VARCHAR(64) NOT NULL PRIMARY KEY, v TEXT NOT NULL) {opts}',
            'CREATE TABLE cg_sessions (id {pk}, name VARCHAR(100) NOT NULL, started_at VARCHAR(19) NOT NULL,
                ended_at VARCHAR(19) NULL) {opts}',
            'CREATE TABLE cg_instances (id {pk}, template VARCHAR(40) NOT NULL, params_key CHAR(40) NOT NULL,
                params_json TEXT NOT NULL, auto_json TEXT NULL, auto_source VARCHAR(30) NULL, auto_at VARCHAR(19) NULL,
                created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL) {opts}',
            'CREATE UNIQUE INDEX cg_instances_key ON cg_instances (template, params_key)',
            'CREATE TABLE cg_overrides (id {pk}, session_id INT NOT NULL, instance_id INT NOT NULL,
                field VARCHAR(40) NOT NULL, value_json VARCHAR(255) NOT NULL, auto_at_set_json VARCHAR(255) NOT NULL,
                keep_next SMALLINT NOT NULL DEFAULT 0, updated_by VARCHAR(50) NOT NULL,
                created_at VARCHAR(19) NOT NULL, updated_at VARCHAR(19) NOT NULL) {opts}',
            'CREATE UNIQUE INDEX cg_overrides_key ON cg_overrides (session_id, instance_id, field)',
            'CREATE TABLE cg_rundown (id {pk}, page_no INT NOT NULL, instance_id INT NOT NULL, sort INT NOT NULL,
                label VARCHAR(100) NOT NULL, created_at VARCHAR(19) NOT NULL) {opts}',
            'CREATE UNIQUE INDEX cg_rundown_page ON cg_rundown (page_no)',
            'CREATE TABLE cg_channels (layer SMALLINT NOT NULL, kind VARCHAR(10) NOT NULL, rundown_id INT NULL,
                instance_id INT NULL, display_json TEXT NOT NULL, snapshot_json TEXT NULL,
                visible SMALLINT NOT NULL DEFAULT 0, take_id INT NOT NULL DEFAULT 0, rev INT NOT NULL DEFAULT 0,
                updated_at VARCHAR(19) NOT NULL, PRIMARY KEY (layer, kind)) {opts}',
            'CREATE TABLE cg_sources (id VARCHAR(30) NOT NULL PRIMARY KEY, label VARCHAR(50) NOT NULL,
                status VARCHAR(10) NOT NULL, last_attempt_at VARCHAR(19) NULL, last_success_at VARCHAR(19) NULL,
                last_error TEXT NULL) {opts}',
            'CREATE TABLE cg_logs (id {pk}, created_at VARCHAR(19) NOT NULL, type VARCHAR(10) NOT NULL,
                action VARCHAR(30) NOT NULL, operator VARCHAR(50) NOT NULL, session_id INT NULL, instance_id INT NULL,
                template VARCHAR(40) NULL, field VARCHAR(40) NULL, auto_json VARCHAR(255) NULL,
                prev_json VARCHAR(255) NULL, new_json VARCHAR(255) NULL, detail TEXT NULL) {opts}',
            // 웹 버전 계정 (PC에서는 비어 있음)
            'CREATE TABLE cg_users (id {pk}, username VARCHAR(40) NOT NULL, password_hash VARCHAR(255) NOT NULL,
                name VARCHAR(50) NOT NULL, role VARCHAR(10) NOT NULL, status VARCHAR(10) NOT NULL,
                session_gen INT NOT NULL DEFAULT 0, created_at VARCHAR(19) NOT NULL, approved_at VARCHAR(19) NULL,
                last_login_at VARCHAR(19) NULL) {opts}',
            'CREATE UNIQUE INDEX cg_users_username ON cg_users (username)',
            'CREATE TABLE cg_links (id {pk}, token_hash CHAR(64) NOT NULL, kind VARCHAR(10) NOT NULL, user_id INT NULL,
                label VARCHAR(100) NOT NULL, created_by INT NOT NULL, expires_at VARCHAR(19) NOT NULL,
                used_at VARCHAR(19) NULL, revoked_at VARCHAR(19) NULL, created_at VARCHAR(19) NOT NULL) {opts}',
            'CREATE UNIQUE INDEX cg_links_token ON cg_links (token_hash)',
            'CREATE TABLE cg_attempts (bucket VARCHAR(100) NOT NULL PRIMARY KEY, hits INT NOT NULL,
                window_start VARCHAR(19) NOT NULL) {opts}',
            static function (): void {
                $now = now();
                foreach ([
                    'state_rev' => '0',
                    'current_session_id' => '1',
                    'csrf_secret' => rand_token(32),
                    'output_token' => rand_token(32),
                ] as $k => $v) {
                    db_exec('INSERT INTO cg_settings (k, v) VALUES (?, ?)', [$k, $v]);
                }
                db_exec('INSERT INTO cg_sessions (name, started_at) VALUES (?, ?)', ['첫 방송 세션', $now]);
                $display = json_enc(['right' => 0, 'bottom' => 4, 'scale_pct' => 100]);
                foreach (['preview', 'program'] as $kind) {
                    db_exec(
                        'INSERT INTO cg_channels (layer, kind, display_json, updated_at) VALUES (1, ?, ?, ?)',
                        [$kind, $display, $now]
                    );
                }
                db_exec("INSERT INTO cg_sources (id, label, status) VALUES ('mock', 'MOCK 데이터', 'NEVER')");
            },
        ],
        // v0.3.0: Google 시트 연결 — 마지막 정상 데이터 보관, CG별 검증 사유, 선수 부가 정보(닉네임)
        // MySQL은 DDL이 바로 커밋되므로, 중간에 실패해도 다시 실행할 수 있게 만든다
        2 => [
            'CREATE TABLE IF NOT EXISTS cg_dataset_cache (source VARCHAR(30) NOT NULL PRIMARY KEY, fetched_at VARCHAR(19) NOT NULL,
                sha256 CHAR(64) NOT NULL, body {bigtext} NOT NULL) {opts}',
            static function (): void {
                try {
                    db_value('SELECT issues_json FROM cg_instances WHERE 1 = 0');
                } catch (PDOException) {
                    db()->exec('ALTER TABLE cg_instances ADD COLUMN issues_json TEXT NULL');
                }
            },
            'CREATE TABLE IF NOT EXISTS cg_player_info (player VARCHAR(40) NOT NULL PRIMARY KEY, nickname VARCHAR(20) NOT NULL,
                updated_at VARCHAR(19) NOT NULL) {opts}',
            static function (): void {
                if (db_value("SELECT COUNT(*) FROM cg_sources WHERE id = 'sheet'") == 0) {
                    db_exec("INSERT INTO cg_sources (id, label, status) VALUES ('sheet', 'Google 시트', 'NEVER')");
                }
            },
        ],
        // v0.4.0: 관리자 알림 (데이터 오류·새로고침 실패). akey = sha1(종류|같은 문제를 알아보는 값)
        3 => [
            'CREATE TABLE IF NOT EXISTS cg_alerts (id {pk}, akey CHAR(40) NOT NULL UNIQUE, kind VARCHAR(20) NOT NULL,
                title VARCHAR(255) NOT NULL, detail TEXT NOT NULL, first_at VARCHAR(19) NOT NULL, last_at VARCHAR(19) NOT NULL,
                resolved_at VARCHAR(19) NULL, acked_at VARCHAR(19) NULL, acked_by VARCHAR(50) NULL) {opts}',
        ],
        // v0.4.1: MOCK 데이터 제거 — 배포본에서 MOCK JSON을 뺐고, 기존 DB의 MOCK 페이지·캐시도 지운다 (data.php mock_purge)
        4 => [
            static function (): void {
                mock_purge();
            },
        ],
        // v0.5.0: 맵 한글 이름 (프로그램 입력 — 시트 '맵 이름' 탭보다 우선). CG 디자인은 cg_settings에 둔다
        5 => [
            'CREATE TABLE IF NOT EXISTS cg_map_info (map VARCHAR(60) NOT NULL PRIMARY KEY, name_ko VARCHAR(20) NOT NULL,
                updated_at VARCHAR(19) NOT NULL) {opts}',
        ],
    ];
}

function schema_version(): int
{
    try {
        $v = db_value("SELECT v FROM cg_settings WHERE k = 'schema_version'");
    } catch (PDOException) {
        return 0; // 첫 실행: 테이블이 아직 없음
    }
    return (int)($v ?? 0);
}

function run_migrations(): void
{
    $all = migrations();
    $latest = max(array_keys($all));
    if (schema_version() >= $latest) {
        return;
    }
    $apply = static function () use ($all): void {
        $current = schema_version(); // 잠금 뒤 다시 확인
        foreach ($all as $version => $steps) {
            if ($version <= $current) {
                continue;
            }
            foreach ($steps as $step) {
                $step instanceof Closure ? $step() : db()->exec(ddl($step));
            }
            setting_set('schema_version', (string)$version);
        }
    };
    if (db_driver() === 'mysql') {
        // MySQL의 DDL은 자동 커밋되므로 트랜잭션 대신 이름 잠금으로 한 번만 실행한다.
        db_value("SELECT GET_LOCK('cg_migrate', 15)");
        try {
            $apply();
        } finally {
            db_value("SELECT RELEASE_LOCK('cg_migrate')");
        }
    } else {
        db_tx($apply);
    }
}
