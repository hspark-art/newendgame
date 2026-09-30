<?php
/**
 * 상품 사진 보내기 (로그인한 관리자만)
 * 사진은 주소로 직접 열 수 없는 저장 폴더에 있으므로 이 파일을 거쳐서만 보여줍니다.
 */
require __DIR__ . '/app/bootstrap.php';
require APP_DIR . '/prizes.php';

require_login();
session_write_close();

$item = prize_item(input_int('id'));
$file = $item && $item['photo'] !== '' ? storage_dir('uploads/prizes') . '/' . basename($item['photo']) : '';
if ($file === '' || !is_file($file)) {
    http_response_code(404);
    exit;
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=86400');
readfile($file);
