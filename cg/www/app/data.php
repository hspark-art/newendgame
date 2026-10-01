<?php
declare(strict_types=1);

/**
 * 데이터 소스 관리: 지금 쓰는 소스(sheet, 테스트에서만 mock), 마지막 정상 데이터 보관, 시트 설정, 서비스 계정 키 보관,
 * xlsx 가져오기, 선수 부가 정보(닉네임), 데이터 점검 결과.
 *
 * 비밀값 규칙
 * - 서비스 계정 키는 secrets_dir()의 파일 하나에만 둔다 (권한 0600, 웹 접근 차단). DB·Git·배포 zip·브라우저에 넣지 않는다.
 * - 화면에는 서비스 계정 이메일만 보여 준다. 시트 ID·탭 이름은 관리자에게만 보인다.
 */

/**
 * 쓸 수 있는 데이터 소스. 배포본은 Google 시트뿐이다.
 * MOCK(검증용 가짜 수치)은 배포본에 들어 있지 않고, 설정에 mock_dir가 있을 때(자동 테스트·개발)만 쓴다.
 */
function data_sources(): array
{
    return ['sheet' => 'Google 시트'] + (config('mock_dir') ? ['mock' => 'MOCK 데이터 (테스트용)'] : []);
}

function data_source(): string
{
    $all = data_sources();
    $s = setting_get('data_source', isset($all['mock']) ? 'mock' : 'sheet');
    return isset($all[$s]) ? $s : 'sheet';
}

/** 시트를 자동으로 읽을 준비가 되었는지 (시트 주소·서비스 계정 키 등록). 아니면 패널이 자동 새로고침을 하지 않는다 */
function data_ready(): bool
{
    return data_source() === 'mock' || (sheet_config()['id'] !== '' && is_file(google_key_path()));
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

// ---------------------------------------------------------------- MOCK 데이터 지우기 (v0.4.1)

/** v0.1~v0.4.0 배포본에 들어 있던 MOCK 선수·중계진 id. 기존 DB에서 MOCK으로 만든 페이지를 찾을 때만 쓴다 */
const MOCK_LEGACY_IDS = ['jo-iljang', 'jang-yunchul', 'mock-p1', 'mock-p2', 'mock-p3', 'mock-z1', 'mock-z2', 'mock-z3', 'mock-z4',
    'mock-t1', 'kim-minchul', 'kim-jisung', 'lee-jaeho', 'hwang-byungyoung', 'park-sanghyun', 'do-jaewook', 'yoo-youngjin',
    'lim-sungchun', 'lee-seungwon'];

/**
 * 기존 DB에서 MOCK 데이터를 지운다 (마이그레이션 4). 여러 번 실행해도 결과가 같다.
 * - MOCK 선수·중계진으로 만든 페이지, 그 CG의 수정값, MOCK 선수 닉네임, MOCK 마지막 정상 데이터·점검 결과·목록.
 * - 선수와 무관한 페이지(예: 다승 순위 전체 종족)는 남기고, MOCK 수치로 계산해 둔 AUTO 값만 비운다 → 시트를 불러오면 다시 계산.
 * - PREVIEW·PROGRAM이 MOCK이면 비운다(송출 중이던 MOCK 화면도 내린다).
 * - 데이터 소스가 MOCK이면 기본값(Google 시트)으로 돌린다.
 */
function mock_purge(): void
{
    $ids = array_flip(MOCK_LEGACY_IDS);
    $usesMock = static function (mixed $v) use (&$usesMock, $ids): bool {
        if (is_array($v)) {
            foreach ($v as $x) {
                if ($usesMock($x)) {
                    return true;
                }
            }
            return false;
        }
        return is_string($v) && isset($ids[$v]);
    };
    $gone = [];
    foreach (db_all('SELECT id, params_json FROM cg_instances') as $r) {
        if ($usesMock(json_dec($r['params_json']))) {
            $gone[(int)$r['id']] = true;
        }
    }
    $pages = 0;
    foreach (array_keys($gone) as $iid) {
        $pages += (int)db_value('SELECT COUNT(*) FROM cg_rundown WHERE instance_id = ?', [$iid]);
        db_exec('DELETE FROM cg_rundown WHERE instance_id = ?', [$iid]);
        db_exec('DELETE FROM cg_overrides WHERE instance_id = ?', [$iid]);
        db_exec('DELETE FROM cg_instances WHERE id = ?', [$iid]);
    }
    $cleared = (int)db_value("SELECT COUNT(*) FROM cg_instances WHERE auto_source = 'mock'");
    db_exec("UPDATE cg_instances SET auto_json = NULL, issues_json = NULL, auto_source = NULL, auto_at = NULL WHERE auto_source = 'mock'");

    $channels = [];
    $pv = db_one("SELECT rundown_id, instance_id FROM cg_channels WHERE layer = 1 AND kind = 'preview'");
    if ($pv !== null && $pv['instance_id'] !== null && isset($gone[(int)$pv['instance_id']])) {
        db_exec("UPDATE cg_channels SET rundown_id = NULL, instance_id = NULL WHERE kind = 'preview'");
        $channels[] = 'preview';
    }
    foreach (db_all("SELECT layer, snapshot_json FROM cg_channels WHERE kind = 'program' AND snapshot_json IS NOT NULL") as $r) {
        $snap = json_dec($r['snapshot_json']);
        if (!empty($snap['mock']) || isset($gone[(int)($snap['instance_id'] ?? 0)])) {
            db_exec("UPDATE cg_channels SET snapshot_json = NULL, visible = 0, rundown_id = NULL, instance_id = NULL
                WHERE layer = ? AND kind = 'program'", [$r['layer']]);
            $channels[] = 'program';
        }
    }

    $nicks = (int)db_value('SELECT COUNT(*) FROM cg_player_info WHERE player IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
        MOCK_LEGACY_IDS);
    db_exec('DELETE FROM cg_player_info WHERE player IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', MOCK_LEGACY_IDS);
    $cache = (int)db_value("SELECT COUNT(*) FROM cg_dataset_cache WHERE source = 'mock'");
    db_exec("DELETE FROM cg_dataset_cache WHERE source = 'mock'");
    db_exec("UPDATE cg_sources SET status = 'NEVER', last_attempt_at = NULL, last_success_at = NULL, last_error = NULL WHERE id = 'mock'");
    $check = json_dec(setting_get('data_check', 'null'));
    $wasMock = setting_get('data_source') === 'mock' || !empty($check['mock']);
    if ($wasMock) {
        // 선수·중계진·연도 목록과 점검 결과가 MOCK에서 온 것 → 처음 설치한 상태로 (시트를 불러오면 다시 만든다)
        db_exec("DELETE FROM cg_settings WHERE k IN ('data_source', 'data_check', 'players_cache', 'predictors_cache', 'years_cache')");
    }
    if ($gone || $cleared || $channels || $nicks || $cache || $wasMock) {
        state_bump(array_values(array_unique($channels)));
        cg_log('data', 'MOCK_PURGE', ['name' => '업데이트'], ['detail' => sprintf('MOCK 데이터 삭제: 페이지 %d개, AUTO 비움 %d개, 닉네임 %d개%s',
            $pages, $cleared, $nicks, in_array('program', $channels, true) ? ', 송출 중이던 MOCK 화면 내림' : '')]);
    }
}

/** 관리자가 "통계 제외 확정"한 경기 id 목록 */
function match_exclusions(): array
{
    $l = json_dec(setting_get('match_exclusions', '[]'));
    return is_array($l) ? array_values(array_filter($l, 'is_string')) : [];
}

/** 캐시(원본) → 쓸 데이터: 경기 제외 확정 반영 + 닉네임 */
function dataset_prepare(array $ds): array
{
    return dataset_with_player_info(dataset_finalize($ds, match_exclusions()));
}

/**
 * 이상 경기(세트 수가 9가 아닌 경기)를 끝장전 통계에서 제외하는 것을 확정하거나 취소한다 (관리자).
 * 확정한 경기는 통계에서 빠지고 관련 선수의 CG를 막지 않는다. 네트워크 없이 마지막 정상 데이터로 다시 계산한다.
 */
function match_exclude(array $in, array $op): array
{
    require_admin_op($op);
    $id = (string)($in['match'] ?? '');
    $on = !empty($in['on']);
    $ds = dataset_cache_get(data_source());
    $m = null;
    foreach ($ds['matches_all'] ?? [] as $x) {
        if ($x['id'] === $id) {
            $m = $x;
        }
    }
    if ($m === null || $m['anomaly_kind'] !== 'sets') {
        throw new ActionError('BAD_MATCH', '제외할 수 있는 경기가 아닙니다. (세트 수가 9가 아닌 경기만 제외할 수 있습니다)', 422);
    }
    $list = array_values(array_diff(match_exclusions(), [$id]));
    if ($on) {
        $list[] = $id;
    }
    setting_set('match_exclusions', json_enc($list));
    cg_log('data', 'MATCH_EXCLUDE', $op, ['detail' => sprintf('%s %s vs %s %d:%d — %s', $m['date'], $m['playerA'], $m['playerB'],
        $m['scoreA'], $m['scoreB'], $on ? '끝장전 통계 제외 확정' : '제외 취소')]);
    data_apply($ds, $op, false);
    return data_check_view();
}

/** 선수 부가 정보(운영자가 입력한 닉네임)를 데이터에 합친다. 시트 '닉네임' 탭 값보다 우선한다. 추측하지 않고 입력한 것만 쓴다 */
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
        'source' => $ds['source'], 'mock' => false, 'at' => $ds['fetched_at'] ?? $now, 'method' => $c['method'], 'counts' => $c['counts'],
        'verified' => array_map(static fn($v) => $v['available'], $ds['verify']),
        'anomalies' => count($c['anomalies']), 'mismatches' => count($c['mismatches']), 'unavailable' => $c['unavailable'],
        'lint' => count($c['lint'] ?? []),
    ];
}

/** 데이터 점검 창: 요약 + 이상 사례·불일치·입력 점검 목록 (최대 200건씩) */
function data_check_view(): array
{
    $ds = dataset_cache_get(data_source());
    $c = $ds === null ? null : dataset_finalize($ds, match_exclusions())['check'];
    return [
        'summary' => json_dec(setting_get('data_check', 'null')),
        'anomalies' => array_slice($c['anomalies'] ?? [], 0, 200),
        'excluded' => $c['excluded'] ?? [],
        'mismatches' => array_slice($c['mismatches'] ?? [], 0, 200),
        'unavailable' => $c['unavailable'] ?? [],
        'lint' => array_slice($c['lint'] ?? [], 0, 200),
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
        'sources' => data_sources(),
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
    if (!isset(data_sources()[$source])) {
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
        cg_log('data', 'SOURCE_SET', $op, ['detail' => '데이터 소스: ' . data_sources()[$source] . ($prev !== $source ? ' (변경)' : '')]);
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
    return data_check_summary(dataset_finalize($ds, match_exclusions()), now());
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
    // 시트 '닉네임' 탭의 값 (원본 캐시). 프로그램에서 입력한 값(nickname)이 있으면 그쪽이 CG에 나간다
    $raw = dataset_cache_get(data_source());
    return array_values(array_map(static fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'race' => $p['race'],
        'nickname' => $info[$p['id']] ?? '', 'sheet_nick' => (string)($raw['players'][$p['id']]['nickname'] ?? '')], players_cache()));
}

/** 닉네임 저장 (빈 값이면 삭제 → 시트 '닉네임' 탭 값이 있으면 그 값을 쓴다). 저장 후 마지막 정상 데이터로 AUTO를 다시 계산한다 (네트워크 접속 없음) */
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
