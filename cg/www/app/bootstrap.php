<?php
declare(strict_types=1);

/**
 * 모든 진입점이 가장 먼저 불러온다. 함수 정의와 버전만 준비하고,
 * 실제 시작(설정·마이그레이션·보안 헤더)은 app_start()에서 한다.
 */

define('APP_DIR', __DIR__);
define('WWW_DIR', dirname(__DIR__));

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Seoul');

require_once APP_DIR . '/helpers.php';
require_once APP_DIR . '/db.php';
require_once APP_DIR . '/migrate.php';
require_once APP_DIR . '/route.php';
require_once APP_DIR . '/guard.php';
require_once APP_DIR . '/auth.php';
require_once APP_DIR . '/stats.php';
require_once APP_DIR . '/provider.php';

define('APP_VERSION', (string)(json_decode((string)file_get_contents(APP_DIR . '/version.json'), true)['version'] ?? '0'));

/** kind: panel | output | api | portal */
function app_start(string $kind): void
{
    set_exception_handler('handle_uncaught_exception');
    if (!config_load()) {
        if ($kind === 'api') {
            define('API_REQUEST', true);
        }
        deny(503, 'NOT_CONFIGURED', '설정 파일이 없습니다. 설치 안내(INSTALL_KR.md 또는 README_KR.txt)를 확인하세요.');
    }
    run_migrations();
    send_headers($kind);
}
