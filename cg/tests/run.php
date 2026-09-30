<?php
declare(strict_types=1);

// 사용법: php tests/run.php [테스트 이름 일부]
require __DIR__ . '/lib.php';

foreach (glob(__DIR__ . '/*_test.php') as $file) {
    require $file;
}

exit(run_tests($argv[1] ?? ''));
