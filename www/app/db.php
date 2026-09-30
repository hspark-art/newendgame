<?php
/**
 * DB 연결과 조회 도우미 함수
 *
 * 두 가지 DB 를 지원합니다.
 *  - mysql  : 웹호스팅용 (MySQL / MariaDB)
 *  - sqlite : PC 버전용 (파일 하나로 저장, 별도 DB 서버 불필요)
 * SQL 은 가능한 한 두 DB 에서 똑같이 동작하는 문법만 쓰고,
 * 다른 부분은 아래 db_insert_ignore(), db_upsert() 를 씁니다.
 */
declare(strict_types=1);

function db_driver(): string
{
    return (config('db', [])['driver'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = config('db', []);
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if (db_driver() === 'sqlite') {
        $path = (string) ($c['path'] ?? '');
        $dir = dirname($path);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, $options);
        $pdo->exec('PRAGMA journal_mode = WAL');   // 수집 저장 중에도 조회 가능
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 15000');
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $c['host'] ?? 'localhost',
        (int) ($c['port'] ?? 3306),
        $c['name'] ?? ''
    );
    $pdo = new PDO($dsn, $c['user'] ?? '', $c['pass'] ?? '', $options);
    // 모든 시각은 한국 시간 기준으로 저장·조회합니다.
    $pdo->exec("SET time_zone = '+09:00'");
    return $pdo;
}

function db_query(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $i = 0;
    foreach ($params as $key => $value) {
        $param = is_int($key) ? ++$i : $key;
        $type = match (true) {
            is_int($value)  => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default         => PDO::PARAM_STR,
        };
        $st->bindValue($param, $value, $type);
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
    $value = db_query($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

function db_exec(string $sql, array $params = []): int
{
    return db_query($sql, $params)->rowCount();
}

function db_last_id(): int
{
    return (int) db()->lastInsertId();
}

/** IN (...) 조건에 쓸 물음표 목록을 만듭니다. */
function db_placeholders(array $values): string
{
    return implode(',', array_fill(0, max(1, count($values)), '?'));
}

/** 이미 있는 값(고유키 중복)은 조용히 건너뛰는 INSERT 의 앞부분 */
function db_insert_ignore(): string
{
    return db_driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
}

/**
 * 넣되, 고유키가 겹치면 지정한 칸만 고칩니다.
 * $update 는 ['칸' => 'SQL 식'] 이며, 식 안의 {new.칸} 은 새로 넣으려던 값을 뜻합니다.
 */
function db_upsert(string $table, array $data, array $conflictColumns, array $update): int
{
    $cols = array_keys($data);
    $sqlite = db_driver() === 'sqlite';
    $sets = [];
    foreach ($update as $col => $expr) {
        $expr = preg_replace_callback('/\{new\.(\w+)\}/', fn($m) => $sqlite ? "excluded.{$m[1]}" : "VALUES({$m[1]})", $expr);
        $sets[] = "$col = $expr";
    }
    $sql = "INSERT INTO $table (" . implode(', ', $cols) . ') VALUES (' . db_placeholders($cols) . ')'
        . ($sqlite ? ' ON CONFLICT(' . implode(', ', $conflictColumns) . ') DO UPDATE SET ' : ' ON DUPLICATE KEY UPDATE ')
        . implode(', ', $sets);
    return db_exec($sql, array_values($data));
}

/** 큰 조회를 한 줄씩 읽을 때 (MySQL 은 결과를 메모리에 다 올리지 않도록 설정) */
function db_stream(string $sql, array $params, callable $onRow): void
{
    $mysql = db_driver() === 'mysql';
    if ($mysql) {
        db()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    }
    try {
        $st = db_query($sql, $params);
        while (($row = $st->fetch()) !== false) {
            $onRow($row);
        }
        $st->closeCursor();
    } finally {
        if ($mysql) {
            db()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }
}

/**
 * MySQL 형식으로 쓴 CREATE TABLE 문을 SQLite 용으로 바꿉니다.
 * (자동 번호, 테이블 안의 KEY 정의 → 별도 CREATE INDEX, ENGINE 설정 제거)
 * @return string[] 실행할 SQL 목록
 */
function ddl_for_driver(string $sql): array
{
    if (db_driver() !== 'sqlite' || !preg_match('/^\s*CREATE TABLE IF NOT EXISTS (\w+)\s*\((.*)\)[^)]*$/si', $sql, $m)) {
        return [$sql];
    }
    [, $table, $body] = $m;
    $columns = [];
    $indexes = [];
    foreach (preg_split('/,\s*\n/', trim($body)) as $line) {
        $line = trim($line, " \t\r\n,");
        if (preg_match('/^(UNIQUE\s+)?KEY\s+(\w+)\s*\(([^)]+)\)$/i', $line, $k)) {
            $unique = $k[1] !== '' ? 'UNIQUE ' : '';
            $indexes[] = "CREATE {$unique}INDEX IF NOT EXISTS {$table}_{$k[2]} ON $table ({$k[3]})";
            continue;
        }
        $line = preg_replace('/\b(BIG)?INT UNSIGNED AUTO_INCREMENT PRIMARY KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $line);
        $columns[] = $line;
    }
    return array_merge(["CREATE TABLE IF NOT EXISTS $table (\n    " . implode(",\n    ", $columns) . "\n)"], $indexes);
}
