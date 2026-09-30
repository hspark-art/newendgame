<?php
declare(strict_types=1);

/**
 * DB 계층: PC는 SQLite, 웹은 MySQL. 두 DB에서 모두 동작하는 SQL만 쓴다.
 * - UPSERT(ON DUPLICATE/ON CONFLICT), INSERT IGNORE, IF()는 쓰지 않는다.
 * - UPDATE 뒤 영향받은 행 수에 의존하지 않는다 (MySQL은 값이 바뀐 행만 센다).
 */

function db(): PDO
{
    static $pdo = null;
    if (isset($GLOBALS['CG_DB_RESET'])) {
        unset($GLOBALS['CG_DB_RESET']);
        $pdo = null;
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (db_driver() === 'sqlite') {
        $path = (string)config('db.path');
        if ($path === '') {
            throw new RuntimeException('SQLite 파일 경로(db.path)가 설정되지 않았습니다.');
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('데이터 폴더를 만들 수 없습니다: ' . $dir);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, $opts);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            config('db.host', 'localhost'),
            (int)config('db.port', 3306),
            config('db.name', '')
        );
        $pdo = new PDO($dsn, (string)config('db.user', ''), (string)config('db.pass', ''), $opts);
        $pdo->exec("SET time_zone = '+09:00'");
    }
    return $pdo;
}

/** 다음 db() 호출 때 새로 연결한다 (테스트·재시작 확인용). */
function db_reset(): void
{
    $GLOBALS['CG_DB_RESET'] = true;
    $GLOBALS['CG_TX_DEPTH'] = 0;
}

function db_driver(): string
{
    return config('db.driver') === 'mysql' ? 'mysql' : 'sqlite';
}

function db_query(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $i = 0;
    foreach ($params as $k => $v) {
        $key = is_int($k) ? ++$i : (str_starts_with((string)$k, ':') ? $k : ':' . $k);
        $type = match (true) {
            is_int($v) => PDO::PARAM_INT,
            is_bool($v) => PDO::PARAM_INT,
            $v === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
        $st->bindValue($key, is_bool($v) ? (int)$v : $v, $type);
    }
    $st->execute();
    return $st;
}

function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_value(string $sql, array $params = []): mixed
{
    $v = db_query($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function db_exec(string $sql, array $params = []): void
{
    db_query($sql, $params);
}

function db_last_id(): int
{
    return (int)db()->lastInsertId();
}

/**
 * 트랜잭션. SQLite는 BEGIN IMMEDIATE로 시작 시점에 쓰기 잠금을 잡는다.
 * 중첩 호출은 바깥 트랜잭션에 합쳐진다.
 */
function db_tx(callable $fn): mixed
{
    $depth = $GLOBALS['CG_TX_DEPTH'] ?? 0;
    if ($depth > 0) {
        $GLOBALS['CG_TX_DEPTH'] = $depth + 1;
        try {
            return $fn();
        } finally {
            $GLOBALS['CG_TX_DEPTH'] = $depth;
        }
    }
    $pdo = db();
    if (db_driver() === 'sqlite') {
        $pdo->exec('BEGIN IMMEDIATE');
    } else {
        $pdo->beginTransaction();
    }
    $GLOBALS['CG_TX_DEPTH'] = 1;
    try {
        $result = $fn();
        if (db_driver() === 'sqlite') {
            $pdo->exec('COMMIT');
        } else {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if (db_driver() === 'sqlite') {
            $pdo->exec('ROLLBACK');
        } elseif ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $GLOBALS['CG_TX_DEPTH'] = 0;
    }
}

function setting_get(string $k, ?string $default = null): ?string
{
    $v = db_value('SELECT v FROM cg_settings WHERE k = ?', [$k]);
    return $v === null ? $default : (string)$v;
}

function setting_set(string $k, string $v): void
{
    if (db_value('SELECT 1 FROM cg_settings WHERE k = ?', [$k]) === null) {
        db_exec('INSERT INTO cg_settings (k, v) VALUES (?, ?)', [$k, $v]);
    } else {
        db_exec('UPDATE cg_settings SET v = ? WHERE k = ?', [$v, $k]);
    }
}
