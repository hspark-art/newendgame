<?php
declare(strict_types=1);

/**
 * 테스트용 가짜 Google 서버 (php -S 라우터). 실제 HTTP 스트림으로 토큰 → 탭 목록 → batchGet 흐름을 확인한다.
 * 환경변수 FAKE_GOOGLE_DIR 안의 pub.pem(서명 확인용 공개 키)과 tables.json(탭 이름 => 행 목록)을 쓴다.
 */
$dir = (string)getenv('FAKE_GOOGLE_DIR');
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
$fail = static function (int $code, string $msg): never {
    http_response_code($code);
    echo json_encode(['error' => ['code' => $code, 'message' => $msg]]);
    exit;
};
if ($path === '/health') {
    echo '{"ok":true}';
    return true;
}
if ($path === '/token') {
    parse_str((string)file_get_contents('php://input'), $form);
    $parts = explode('.', (string)($form['assertion'] ?? ''));
    if (($form['grant_type'] ?? '') !== 'urn:ietf:params:oauth:grant-type:jwt-bearer' || count($parts) !== 3) {
        $fail(400, 'invalid_grant');
    }
    $dec = static fn(string $s) => (string)base64_decode(strtr($s, '-_', '+/'));
    $ok = openssl_verify("$parts[0].$parts[1]", $dec($parts[2]), (string)file_get_contents("$dir/pub.pem"), OPENSSL_ALGO_SHA256);
    $claims = json_decode($dec($parts[1]), true);
    if ($ok !== 1 || ($claims['scope'] ?? '') !== 'https://www.googleapis.com/auth/spreadsheets.readonly') {
        $fail(400, 'invalid_grant');
    }
    file_put_contents("$dir/token_claims.json", json_encode($claims));
    echo json_encode(['access_token' => 'fake-token-xyz', 'expires_in' => 3600, 'token_type' => 'Bearer']);
    return true;
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer fake-token-xyz') {
    $fail(401, 'unauthenticated');
}
$tables = json_decode((string)file_get_contents("$dir/tables.json"), true);
if (preg_match('#^/v4/spreadsheets/([A-Za-z0-9_-]+)/values:batchGet$#', $path)) {
    file_put_contents("$dir/batch_query.txt", $_SERVER['QUERY_STRING'] ?? '');
    $out = [];
    foreach (explode('&', (string)($_SERVER['QUERY_STRING'] ?? '')) as $kv) {
        [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
        if ($k === 'ranges') {
            $r = rawurldecode($v);
            $title = str_replace("''", "'", substr($r, 1, strrpos($r, "'!") - 1));
            $out[] = ['range' => $r, 'majorDimension' => 'ROWS', 'values' => $tables[$title] ?? []];
        }
    }
    echo json_encode(['spreadsheetId' => 'x', 'valueRanges' => $out]);
    return true;
}
if (preg_match('#^/v4/spreadsheets/([A-Za-z0-9_-]+)$#', $path)) {
    echo json_encode(['sheets' => array_map(static fn($t) => ['properties' => ['title' => $t]], array_keys($tables))]);
    return true;
}
$fail(404, 'not found');
