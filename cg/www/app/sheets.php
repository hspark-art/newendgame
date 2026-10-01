<?php
declare(strict_types=1);

/**
 * Google 시트 읽기 (Sheets API v4, 서비스 계정).
 * - 시트는 공개하지 않는다. 시트 소유자가 서비스 계정 이메일에 "뷰어" 권한만 준다.
 * - 권한 범위는 읽기 전용(spreadsheets.readonly). 액세스 토큰은 새로고침할 때마다 새로 받고 저장하지 않는다.
 * - 서비스 계정 키는 비밀 폴더(data.php의 secrets_dir)에만 두고 화면·로그·오류 메시지에 내보내지 않는다.
 * - 필요한 탭·열만 읽는다. 상금 열(Results G, 상금 보정 C·D, 선수별 통계 J~L)은 읽지 않는다.
 */

const GOOGLE_TOKEN_URI = 'https://oauth2.googleapis.com/token';
const SHEETS_API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets/';
const SHEETS_SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

/** 탭별로 읽을 열 (한 탭에서 떨어진 열은 범위를 나눠 읽고, 원래 열 위치에 맞춰 합친다) */
const SHEET_RANGES = [
    'results' => ['A:F', 'H:H'],      // Winner·Race·Loser·Race·Map·Date + Double Chance (G: Prize는 읽지 않음)
    'players' => ['A:O'],             // ID·Race·W/L (P열 이후 상금은 읽지 않음)
    'matches' => ['A:I'],
    'predictions' => ['A:Q'],
    'adjust' => ['A:B', 'E:E'],       // 날짜·선수명·더블 찬스 횟수
    'stats' => ['B:B', 'M:N'],        // 선수명·더블 성공 횟수·더블 시도
];

/** "H:H" → 7 (A=0) */
function col_index(string $range): int
{
    $letters = (string)preg_replace('/[^A-Z].*$/', '', strtoupper($range));
    $n = 0;
    foreach (str_split($letters) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

/** 접속 주소. config의 google.token_uri / google.sheets_base 는 테스트(가짜 서버)에서만 바꾼다 */
function google_endpoints(): array
{
    $g = (array)config('google', []);
    return ['token' => (string)($g['token_uri'] ?? GOOGLE_TOKEN_URI), 'sheets' => (string)($g['sheets_base'] ?? SHEETS_API_BASE)];
}

/**
 * HTTP 요청 (PHP 스트림 + openssl, 인증서 확인). 테스트는 $GLOBALS['CG_HTTP']에 같은 모양의 함수를 넣어 바꾼다.
 * @return array{status:int, body:string}
 */
function http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 10): array
{
    if (isset($GLOBALS['CG_HTTP'])) {
        return ($GLOBALS['CG_HTTP'])($method, $url, $headers, $body);
    }
    if (str_starts_with($url, 'https:') && !extension_loaded('openssl')) {
        throw new ProviderError('PHP openssl 확장이 없어 Google 시트에 접속할 수 없습니다. (php.ini의 extension=openssl 확인)');
    }
    $ctx = stream_context_create([
        'http' => ['method' => $method, 'header' => implode("\r\n", array_merge($headers, ['Connection: close'])),
            'content' => $body ?? '', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0,
            'protocol_version' => 1.1, 'user_agent' => 'EndgameCG/' . APP_VERSION],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $res = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int)$m[1];
        }
    }
    if ($res === false) {
        throw new ProviderError('Google 서버에 연결하지 못했습니다. 인터넷 연결·방화벽을 확인하세요.');
    }
    return ['status' => $status, 'body' => $res];
}

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/**
 * 서비스 계정 키(JSON) 확인. 필요한 값만 남긴다.
 * @return array{client_email:string, private_key:string, private_key_id:string}
 * @throws InvalidArgumentException 키가 아니거나 형식이 틀리면 (키 내용은 메시지에 넣지 않음)
 */
function google_key_parse(string $json): array
{
    $k = json_decode($json, true);
    if (!is_array($k) || ($k['type'] ?? '') !== 'service_account') {
        throw new InvalidArgumentException('서비스 계정 키 파일(JSON)이 아닙니다.');
    }
    $email = (string)($k['client_email'] ?? '');
    $pem = (string)($k['private_key'] ?? '');
    if (!preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+\.iam\.gserviceaccount\.com$/D', $email)) {
        throw new InvalidArgumentException('키 파일의 서비스 계정 이메일 형식이 올바르지 않습니다.');
    }
    if (isset($k['token_uri']) && $k['token_uri'] !== google_endpoints()['token']) {
        throw new InvalidArgumentException('키 파일의 토큰 주소가 Google 주소가 아닙니다.');
    }
    if (!str_contains($pem, 'PRIVATE KEY') || @openssl_pkey_get_private($pem) === false) {
        throw new InvalidArgumentException('키 파일의 개인 키를 읽을 수 없습니다.');
    }
    return ['client_email' => $email, 'private_key' => $pem, 'private_key_id' => (string)($k['private_key_id'] ?? '')];
}

/** 서비스 계정 JWT(RS256) → 읽기 전용 액세스 토큰 (1시간). 저장하지 않는다 */
function google_access_token(array $key): string
{
    $now = time();
    $aud = google_endpoints()['token'];
    $head = b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $key['private_key_id']]));
    $claims = b64url(json_encode(['iss' => $key['client_email'], 'scope' => SHEETS_SCOPE, 'aud' => $aud,
        'iat' => $now, 'exp' => $now + 3600]));
    $sig = '';
    if (!openssl_sign("$head.$claims", $sig, $key['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new ProviderError('서비스 계정 키로 서명하지 못했습니다. 키 파일을 다시 등록하세요.');
    }
    $res = http_request('POST', $aud, ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$head.$claims." . b64url($sig)]));
    $j = json_decode($res['body'], true);
    if ($res['status'] !== 200 || !is_string($j['access_token'] ?? null)) {
        throw new ProviderError($res['status'] === 400 || $res['status'] === 401
            ? '서비스 계정 인증에 실패했습니다. 키가 삭제·사용 중지되었거나 PC 시계가 맞지 않을 수 있습니다.'
            : "Google 인증 서버 오류 (HTTP {$res['status']})");
    }
    return $j['access_token'];
}

/** Sheets API GET. 오류는 원인별 안내로 바꾼다 (응답 본문·토큰은 메시지에 넣지 않음) */
function sheets_get(string $pathAndQuery, string $token): array
{
    $res = http_request('GET', google_endpoints()['sheets'] . $pathAndQuery, ['Authorization: Bearer ' . $token]);
    if ($res['status'] === 200) {
        $j = json_decode($res['body'], true);
        if (is_array($j)) {
            return $j;
        }
        throw new ProviderError('Google 시트 응답을 읽을 수 없습니다.');
    }
    throw new ProviderError(match ($res['status']) {
        403 => '시트에 접근할 권한이 없습니다. 시트를 서비스 계정 이메일과 "뷰어"로 공유했는지 확인하세요.',
        404 => '시트를 찾을 수 없습니다. 시트 주소를 확인하세요.',
        429 => 'Google 요청 한도를 넘었습니다. 잠시 뒤 다시 시도하세요.',
        default => "Google 시트 응답 오류 (HTTP {$res['status']})",
    });
}

/** 시트 주소 또는 ID → ID. 형식이 아니면 null */
function sheet_id_from(string $s): ?string
{
    $s = trim($s);
    if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]{20,100})#', $s, $m)) {
        return $m[1];
    }
    return preg_match('/^[a-zA-Z0-9_-]{20,100}$/D', $s) ? $s : null;
}

/**
 * 시트에서 필요한 탭을 읽는다. Results 탭이 없으면 실패, 검증·예측 탭이 없으면 null (해당 기능만 검증 불가).
 * @param array{id:string, tabs:array<string,string>} $cfg
 * @return array{results:list<array>, players:?list<array>, matches:?list<array>, predictions:?list<array>}
 */
function sheets_fetch_tables(?array $cfg = null, ?array $key = null): array
{
    $cfg ??= sheet_config();
    if ($cfg['id'] === '') {
        throw new ProviderError('Google 시트 주소가 설정되지 않았습니다. 데이터 설정에서 입력하세요.');
    }
    $key ??= google_key_load();
    if ($key === null) {
        throw new ProviderError('서비스 계정 키가 등록되지 않았습니다. 데이터 설정에서 키 파일을 등록하세요.');
    }
    $token = google_access_token($key);
    $meta = sheets_get(rawurlencode($cfg['id']) . '?fields=' . rawurlencode('sheets.properties.title'), $token);
    $titles = array_map(static fn($s) => (string)($s['properties']['title'] ?? ''), (array)($meta['sheets'] ?? []));
    $want = []; // [키, 시작 열, 범위]
    foreach (SHEET_RANGES as $k => $cols) {
        $title = $cfg['tabs'][$k] ?? SHEET_TABS_DEFAULT[$k];
        if (in_array($title, $titles, true)) {
            foreach ($cols as $c) {
                $want[] = [$k, col_index($c), "'" . str_replace("'", "''", $title) . "'!" . $c];
            }
        }
    }
    if (!in_array('results', array_column($want, 0), true)) {
        throw new ProviderError("시트에 '" . ($cfg['tabs']['results'] ?? 'Results') . "' 탭이 없습니다. 탭 이름을 확인하세요.");
    }
    $q = implode('&', array_map(static fn($w) => 'ranges=' . rawurlencode($w[2]), $want))
        . '&majorDimension=ROWS&valueRenderOption=UNFORMATTED_VALUE&dateTimeRenderOption=FORMATTED_STRING';
    $batch = sheets_get(rawurlencode($cfg['id']) . '/values:batchGet?' . $q, $token);
    $ranges = (array)($batch['valueRanges'] ?? []);
    if (count($ranges) !== count($want)) {
        throw new ProviderError('Google 시트 응답의 탭 수가 요청과 다릅니다.');
    }
    $tables = array_fill_keys(array_keys(SHEET_RANGES), null);
    foreach ($want as $i => [$k, $offset]) {
        $tables[$k] ??= [];
        foreach ((array)($ranges[$i]['values'] ?? []) as $r => $row) {
            foreach (is_array($row) ? array_values($row) : [] as $j => $v) {
                $tables[$k][$r][$offset + $j] = $v;
            }
            $tables[$k][$r] ??= [];
        }
    }
    // 빈 칸을 ''로 채운 순서 있는 행 목록으로
    foreach ($tables as $k => $rows) {
        if ($rows === null) {
            continue;
        }
        ksort($rows);
        $list = [];
        $max = $rows ? max(array_keys($rows)) : -1;
        for ($r = 0; $r <= $max; $r++) {
            $row = $rows[$r] ?? [];
            $line = $row ? array_fill(0, max(array_keys($row)) + 1, '') : [];
            foreach ($row as $j => $v) {
                $line[$j] = $v;
            }
            $list[] = $line;
        }
        $tables[$k] = $list;
    }
    $tables['results'] ??= [];
    return $tables;
}
