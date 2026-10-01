<?php
/**
 * 모든 페이지가 가장 먼저 불러오는 파일입니다.
 * 설정 읽기 → 오류 처리 → DB 준비(자동 업데이트) → 세션 시작 순서로 진행합니다.
 */
declare(strict_types=1);

const APP_VERSION = '1.3.1';
define('APP_ROOT', dirname(__DIR__));   // www 폴더
const APP_DIR = __DIR__;                // www/app 폴더

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Seoul');

require APP_DIR . '/helpers.php';
require APP_DIR . '/db.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/crypto.php';
require APP_DIR . '/layout.php';
require APP_DIR . '/migrate.php';

// ── 설정 읽기 ──────────────────────────────────────────────
$GLOBALS['APP_CONFIG'] = [];
// 웹: www/app/config.php  /  PC 버전: www 바깥의 data/config.php (프로그램을 새 버전으로 바꿔도 설정·자료 유지)
$configFile = APP_DIR . '/config.php';
if (!is_file($configFile)) {
    $configFile = dirname(APP_ROOT) . '/data/config.php';
}
if (is_file($configFile)) {
    $loaded = require $configFile;
    $GLOBALS['APP_CONFIG'] = is_array($loaded) ? $loaded : [];
}

// ── 오류 처리 ──────────────────────────────────────────────
ini_set('display_errors', config('debug') ? '1' : '0');
error_reporting(E_ALL);
set_exception_handler('handle_uncaught_exception');

// config.php 가 없으면 설치 화면으로 안내합니다.
if (!app_configured() && !defined('INSTALLING')) {
    if (defined('API_REQUEST')) {
        json_response(['ok' => false, 'error' => '사이트 설정(config.php)이 없습니다.'], 500);
    }
    redirect('install.php');
}

// ── DB 자동 업데이트 ───────────────────────────────────────
// 파일을 새로 올린 뒤 첫 접속 때 필요한 DB 변경을 자동으로 적용합니다.
if (app_configured()) {
    if (defined('INSTALLING')) {
        try {
            run_migrations();
        } catch (PDOException $e) {
            $GLOBALS['INSTALL_DB_ERROR'] = $e->getMessage();
        }
    } else {
        run_migrations();
    }
}

if (!defined('CHECK_CLI')) {   // 서버 점검 도구(app/check.php)는 세션·화면 헤더 없이 씀
    start_session();
    send_security_headers();
}
