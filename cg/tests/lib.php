<?php
declare(strict_types=1);

/**
 * 작은 테스트 러너 (PHPUnit·Composer 없이 동작).
 * 실행: php tests/run.php [이름 일부]
 */

require_once dirname(__DIR__) . '/www/app/bootstrap.php';

// 경고·알림도 실패로 처리한다
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false; // @로 의도적으로 숨긴 경고
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

$GLOBALS['TESTS'] = [];
$GLOBALS['TEST_TMP'] = sys_get_temp_dir() . '/cg-test-' . getmypid();

function test(string $name, callable $fn): void
{
    $GLOBALS['TESTS'][] = [$name, $fn];
}

final class AssertionFailed extends Exception
{
}

function fail(string $msg): never
{
    throw new AssertionFailed($msg);
}

function assert_same(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        fail(($msg !== '' ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_true(bool $cond, string $msg = 'expected true'): void
{
    if (!$cond) {
        fail($msg);
    }
}

/** fn 실행 시 ActionError(code) 또는 지정한 예외가 나야 한다. */
function assert_throws(string $class, callable $fn, ?string $code = null): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            fail("expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        }
        if ($code !== null && $e instanceof ActionError && $e->errCode !== $code) {
            fail("expected code $code, got {$e->errCode}: {$e->getMessage()}");
        }
        return $e;
    }
    fail("expected $class" . ($code ? "($code)" : '') . ', nothing thrown');
}

/**
 * 테스트마다 새 SQLite 파일로 시작한다.
 * @param array $extra 설정 덮어쓰기 (예: ['mode' => 'web'])
 */
function fresh_db(array $extra = []): string
{
    $dir = $GLOBALS['TEST_TMP'] . '/' . bin2hex(random_bytes(4));
    @mkdir($dir, 0775, true);
    $GLOBALS['CG_CONFIG'] = array_replace_recursive([
        'mode' => 'desktop',
        'db' => test_db_config($dir),
        'storage_dir' => $dir,
        'operator' => '테스트',
    ], $extra);
    db_reset();
    if (db_driver() === 'mysql') {
        foreach (db_all("SHOW TABLES LIKE 'cg\\_%'") as $row) {
            db()->exec('DROP TABLE `' . array_values($row)[0] . '`');
        }
    }
    run_migrations();
    return $dir;
}

/**
 * 기본은 임시 SQLite. MySQL로 돌리려면:
 * CG_TEST_MYSQL="호스트:포트:DB:아이디:비밀번호" php tests/run.php  (테스트 전용 DB — 테이블을 지웁니다)
 */
function test_db_config(string $dir): array
{
    $env = getenv('CG_TEST_MYSQL');
    if ($env === false || $env === '') {
        return ['driver' => 'sqlite', 'path' => $dir . '/cg.sqlite'];
    }
    [$host, $port, $name, $user, $pass] = explode(':', $env, 5) + ['', '3306', '', '', ''];
    return ['driver' => 'mysql', 'host' => $host, 'port' => (int)$port, 'name' => $name, 'user' => $user, 'pass' => $pass];
}

/** 앱 재시작 흉내: 연결을 끊고 같은 파일을 다시 연다. */
function reopen_db(): void
{
    db_reset();
    run_migrations();
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = "$dir/$f";
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

function run_tests(string $filter): int
{
    $pass = $fail = 0;
    foreach ($GLOBALS['TESTS'] as [$name, $fn]) {
        if ($filter !== '' && !str_contains($name, $filter)) {
            continue;
        }
        try {
            $fn();
            $pass++;
            echo "  ok   $name\n";
        } catch (Throwable $e) {
            $fail++;
            $where = $e instanceof AssertionFailed ? '' : ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            echo "  FAIL $name\n       " . get_class($e) . ': ' . $e->getMessage() . "$where\n";
        } finally {
            db_reset();
        }
    }
    rrmdir($GLOBALS['TEST_TMP']);
    echo "\n$pass passed, $fail failed\n";
    return $fail > 0 ? 1 : 0;
}

/** MOCK 데이터를 고쳐서 쓰기 (세트·예측 결과는 고친 경기 기록으로 다시 만든다) */
function mock_with(callable $change): array
{
    $ds = dataset_normalize(mock_fetch((string)config('mock_dir', APP_DIR . '/data/mock')), 'mock');
    $change($ds);
    return mock_enrich($ds);
}
