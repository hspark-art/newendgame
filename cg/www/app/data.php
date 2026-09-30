<?php
declare(strict_types=1);

/**
 * 데이터 소스 관리: 지금 쓰는 소스(mock | sheet), 마지막 정상 데이터 보관, 시트 설정, 서비스 계정 키 보관,
 * xlsx 가져오기, 선수 부가 정보(닉네임), 데이터 점검 결과.
 *
 * 비밀값 규칙
 * - 서비스 계정 키는 secrets_dir()의 파일 하나에만 둔다 (권한 0600, 웹 접근 차단). DB·Git·배포 zip·브라우저에 넣지 않는다.
 * - 화면에는 서비스 계정 이메일만 보여 준다. 시트 ID·탭 이름은 관리자에게만 보인다.
 */

const DATA_SOURCES = ['mock' => 'MOCK 데이터', 'sheet' => 'Google 시트'];

function data_source(): string
{
    $s = setting_get('data_source', 'mock');
    return isset(DATA_SOURCES[$s]) ? $s : 'mock';
}

/** @return array{id:string, tabs:array<string,string>} */
function sheet_config(): array
{
    $tabs = json_dec(setting_get('sheet_tabs', '{}')) ?: [];
    return ['id' => (string)setting_get('sheet_id', ''), 'tabs' => array_replace(SHEET_TABS_DEFAULT, array_intersect_key($tabs, SHEET_TABS_DEFAULT))];
}

// ---------------------------------------------------------------- 서비스 계정 키 (비밀 폴더)

function secrets_dir(): string
{
    $dir = (string)(config('secrets_dir') ?: storage_dir() . '/secrets');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    @chmod($dir, 0700);
    // 웹 폴더 안에 있을 때를 대비해 접근 차단 파일을 둔다 (Apache, app/.htaccess와 같은 방식)
    if (!is_file("$dir/.htaccess")) {
        @file_put_contents("$dir/.htaccess", "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}

/** 웹 버전: 키 폴더가 웹 문서 폴더 안이면 true (Apache .htaccess로만 막힘 → 웹 폴더 밖 secrets_dir 권장) */
function secrets_in_web_root(): bool
{
    $root = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $dir = realpath(secrets_dir());
    return is_web() && $root !== false && $root !== '' && $dir !== false && str_starts_with($dir . '/', rtrim($root, '/') . '/');
}

function google_key_path(): string
{
    return secrets_dir() . '/google-service-account.json';
}

/** 등록된 키 (없거나 깨졌으면 null) */
function google_key_load(): ?array
{
    $path = google_key_path();
    if (!is_file($path)) {
        return null;
    }
    try {
        return google_key_parse((string)file_get_contents($path));
    } catch (InvalidArgumentException) {
        return null;
    }
}

function google_key_save(string $json, array $op): array
{
    require_admin_op($op);
    try {
        $key = google_key_parse($json);
    } catch (InvalidArgumentException $e) {
        throw new ActionError('BAD_KEY', $e->getMessage(), 422);
    }
    $path = google_key_path();
    $tmp = $path . '.tmp';
    $old = umask(077); // 처음부터 본인만 읽을 수 있게 만든다
    try {
        $ok = @file_put_contents($tmp, $json, LOCK_EX) !== false;
    } finally {
        umask($old);
    }
    @chmod($tmp, 0600);
    if (!$ok || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new ActionError('KEY_WRITE', '키 파일을 저장할 수 없습니다. 저장 폴더 권한을 확인하세요.', 500);
    }
    cg_log('data', 'KEY_SET', $op, ['detail' => '서비스 계정 키 등록: ' . $key['client_email']]);
    state_bump();
    return ['client_email' => $key['client_email']];
}

function google_key_remove(array $op): array
{
    require_admin_op($op);
    $path = google_key_path();
    if (is_file($path)) {
        // 내용을 덮어쓴 뒤 지운다
        @file_put_contents($path, str_repeat("\0", (int)filesize($path)));
        @unlink($path);
    }
    cg_log('data', 'KEY_REMOVE', $op, ['detail' => '서비스 계정 키 삭제']);
    state_bump();
    return ['removed' => true];
}

function require_admin_op(array $op): void
{
    if (($op['role'] ?? '') !== 'admin') {
        throw new ActionError('ADMIN_ONLY', '관리자만 데이터 설정을 바꿀 수 있습니다.', 403);
    }
}

// ---------------------------------------------------------------- 마지막 정상 데이터 (캐시)

function dataset_cache_put(array $ds, string $now): void
{
    $json = json_enc($ds);
    $body = base64_encode((string)gzcompress($json, 6));
    db_exec('DELETE FROM cg_dataset_cache WHERE source = ?', [$ds['source']]);
    db_exec('INSERT INTO cg_dataset_cache (source, fetched_at, sha256, body) VALUES (?, ?, ?, ?)',
        [$ds['source'], $now, hash('sha256', $json), $body]);
}

function dataset_cache_get(string $source): ?array
{
    $row = db_one('SELECT body, sha256 FROM cg_dataset_cache WHERE source = ?', [$source]);
    if ($row === null) {
        return null;
    }
    $json = @gzuncompress((string)base64_decode($row['body'], true));
    if ($json === false || !hash_equals($row['sha256'], hash('sha256', $json))) {
        return null; // 손상된 캐시는 쓰지 않는다
    }
    $ds = json_dec($json);
    return is_array($ds) ? $ds : null;
}

/** 선수 부가 정보(운영자가 입력한 닉네임)를 데이터에 합친다. 시트에 없는 값이라 추측하지 않고 입력한 것만 쓴다 */
function dataset_with_player_info(array $ds): array
{
    foreach (db_all('SELECT player, nickname FROM cg_player_info') as $r) {
        if (isset($ds['players'][$r['player']])) {
            $ds['players'][$r['player']]['nickname'] = $r['nickname'];
        }
    }
    return $ds;
}

/** 새로고침 결과 요약 (패널 상단·점검 창). 목록 전체는 캐시의 check에 있다 */
function data_check_summary(array $ds, string $now): array
{
    $c = $ds['check'] ?? null;
    if ($c === null) {
        return ['source' => $ds['source'], 'mock' => true, 'at' => $now];
    }
    return [
        'source' => $ds['source'], 'mock' => false, 'at' => $now, 'method' => $c['method'], 'counts' => $c['counts'],
        'verified' => array_map(static fn($v) => $v['available'], $ds['verify']),
        'anomalies' => count($c['anomalies']), 'mismatches' => count($c['mismatches']), 'unavailable' => $c['unavailable'],
    ];
}

/** 데이터 점검 창: 요약 + 이상 사례·불일치 목록 (최대 200건씩) */
function data_check_view(): array
{
    $ds = dataset_cache_get(data_source());
    $c = $ds['check'] ?? null;
    return [
        'summary' => json_dec(setting_get('data_check', 'null')),
        'anomalies' => array_slice($c['anomalies'] ?? [], 0, 200),
        'mismatches' => array_slice($c['mismatches'] ?? [], 0, 200),
        'unavailable' => $c['unavailable'] ?? [],
    ];
}

// ---------------------------------------------------------------- 설정 (관리자)

function data_settings_view(array $op): array
{
    $key = google_key_load();
    $admin = ($op['role'] ?? '') === 'admin';
    $cfg = sheet_config();
    return [
        'source' => data_source(),
        'sources' => DATA_SOURCES,
        'admin' => $admin,
        'sheet_id' => $admin ? $cfg['id'] : ($cfg['id'] === '' ? '' : '설정됨'),
        'tabs' => $admin ? $cfg['tabs'] : [],
        'key_email' => $key['client_email'] ?? null,
        'key_dir_public' => $admin && secrets_in_web_root(),
        'openssl' => extension_loaded('openssl'),
        'zip' => class_exists('ZipArchive'),
    ];
}

function data_settings_save(array $in, array $op): array
{
    require_admin_op($op);
    $source = (string)($in['source'] ?? data_source());
    if (!isset(DATA_SOURCES[$source])) {
        throw new ActionError('BAD_SOURCE', '알 수 없는 데이터 소스입니다.', 422);
    }
    $sheet = trim((string)($in['sheet'] ?? ''));
    $id = $sheet === '' ? '' : sheet_id_from($sheet);
    if ($id === null) {
        throw new ActionError('BAD_SHEET', 'Google 시트 주소 형식이 아닙니다. 주소창의 주소를 그대로 붙여 넣으세요.', 422);
    }
    $tabs = [];
    foreach (SHEET_TABS_DEFAULT as $k => $def) {
        $t = trim((string)($in['tabs'][$k] ?? $def));
        if ($t === '' || mb_strlen($t) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $t)) {
            throw new ActionError('BAD_TAB', '탭 이름이 올바르지 않습니다.', 422);
        }
        $tabs[$k] = $t;
    }
    db_tx(function () use ($source, $id, $tabs, $op) {
        $prev = data_source();
        setting_set('data_source', $source);
        setting_set('sheet_id', $id);
        setting_set('sheet_tabs', json_enc($tabs));
        cg_log('data', 'SOURCE_SET', $op, ['detail' => '데이터 소스: ' . DATA_SOURCES[$source] . ($prev !== $source ? ' (변경)' : '')]);
        state_bump();
    });
    return data_settings_view($op);
}

/** 연결 테스트: 시트를 읽어 검증까지 해 보고 결과만 돌려준다 (저장·반영하지 않음) */
function data_test(array $op): array
{
    require_admin_op($op);
    try {
        $ds = sheet_dataset(sheets_fetch_tables(), 'api');
    } catch (ProviderError $e) {
        throw new ActionError('SOURCE_ERROR', $e->getMessage() . ($e->problems ? ': ' . implode(' / ', array_slice($e->problems, 0, 5)) : ''), 502);
    }
    return data_check_summary($ds, now());
}

/** xlsx 가져오기 (시트 연결이 안 될 때의 예비). 성공하면 데이터 소스를 Google 시트로 바꾸고 반영한다 */
function data_import_xlsx(array $in, array $op): array
{
    require_admin_op($op);
    $b64 = (string)($in['file'] ?? '');
    $bytes = base64_decode($b64, true);
    if ($bytes === false || $bytes === '') {
        throw new ActionError('BAD_FILE', '파일을 읽을 수 없습니다.', 422);
    }
    try {
        $ds = sheet_dataset(xlsx_tables($bytes, sheet_config()['tabs']), 'xlsx', true);
    } catch (ProviderError $e) {
        throw new ActionError('SOURCE_ERROR', $e->getMessage() . ($e->problems ? ': ' . implode(' / ', array_slice($e->problems, 0, 5)) : ''), 422);
    }
    setting_set('data_source', 'sheet');
    return data_refresh($op, $ds);
}

// ---------------------------------------------------------------- 선수 부가 정보

function player_info_view(): array
{
    $info = [];
    foreach (db_all('SELECT player, nickname FROM cg_player_info') as $r) {
        $info[$r['player']] = $r['nickname'];
    }
    return array_values(array_map(static fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'race' => $p['race'],
        'nickname' => $info[$p['id']] ?? ''], players_cache()));
}

/** 닉네임 저장 (빈 값이면 삭제). 저장 후 마지막 정상 데이터로 AUTO를 다시 계산한다 (네트워크 접속 없음) */
function player_info_save(array $in, array $op): array
{
    $pid = (string)($in['player'] ?? '');
    $nick = trim((string)($in['nickname'] ?? ''));
    if (!isset(players_cache()[$pid])) {
        throw new ActionError('NO_PLAYER', '데이터에 없는 선수입니다.', 422);
    }
    if (mb_strlen($nick) > 20 || preg_match('/[\x00-\x1F\x7F]/u', $nick)) {
        throw new ActionError('BAD_NICK', '닉네임은 20자 이내로 입력하세요.', 422);
    }
    db_tx(function () use ($pid, $nick, $op) {
        db_exec('DELETE FROM cg_player_info WHERE player = ?', [$pid]);
        if ($nick !== '') {
            db_exec('INSERT INTO cg_player_info (player, nickname, updated_at) VALUES (?, ?, ?)', [$pid, $nick, now()]);
        }
        cg_log('data', 'PLAYER_INFO', $op, ['detail' => "$pid 닉네임: " . ($nick === '' ? '(없음)' : $nick)]);
    });
    $ds = dataset_cache_get(data_source());
    if ($ds !== null) {
        data_apply($ds, $op, false);
    }
    return ['players' => player_info_view()];
}
