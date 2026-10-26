<?php
declare(strict_types=1);

/*
 * eloboard.com 온라인 전적 조회 (#6 온라인 상대 전적, v0.10.0)
 * - 운영자가 [eloboard 조회]를 누를 때만 접속한다 (예약·일괄 조회 없음). 한 번에 최대 3페이지.
 * - 서버 전체에서 한 번에 하나만 (파일 잠금), 요청 사이 최소 2초, 같은 주소는 6시간 캐시(읽은 숫자만 저장, HTML은 저장·기록하지 않음).
 * - 주소는 숫자 id와 검증한 날짜로만 만든다. 화면을 확실히 읽지 못하면 값을 비운다 (추측 금지).
 * - 선수 ↔ eloboard id 연결은 관리자가 직접 등록한 것만 쓴다 (이름으로 추측하지 않음).
 */

const ELO_BASE = 'https://eloboard.com/players/';
const ELO_CACHE_TTL = 21600;
const ELO_GAP = 2.0;
const ELO_TIMEOUT = 10;
const ELO_RACE_KO = ['T' => '테란', 'P' => '프로토스', 'Z' => '저그'];
const ELO_PARSE_ERROR = 'eloboard 화면을 확실히 읽지 못했습니다 — 직접 입력';
const ELO_PERIOD_NOTE = '기간 지정 시 종족전은 eloboard가 제공하지 않음 — 직접 입력';

final class EloError extends RuntimeException
{
}

/** 시계·대기 (테스트는 $GLOBALS['CG_CLOCK'], $GLOBALS['CG_SLEEP']로 바꾼다) */
function elo_clock(): float
{
    return isset($GLOBALS['CG_CLOCK']) ? (float)($GLOBALS['CG_CLOCK'])() : microtime(true);
}

function elo_sleep(float $sec): void
{
    if (isset($GLOBALS['CG_SLEEP'])) {
        ($GLOBALS['CG_SLEEP'])($sec);
        return;
    }
    usleep((int)ceil($sec * 1e6));
}

/** eloboard 선수 주소(https://eloboard.com/players/22) 또는 숫자 → id. 아니면 null */
function elo_id_from(mixed $v): ?int
{
    $s = is_scalar($v) ? trim((string)$v) : '';
    if (preg_match('#^(?:https?://(?:www\.)?eloboard\.com/players/)?([1-9]\d{0,8})(?:[/?\#].*)?$#Di', $s, $m)) {
        return (int)$m[1];
    }
    return null;
}

function elo_date_ok(string $d): bool
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $d, $m) === 1 && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && $m[1] >= 2000;
}

/** 맞대결 주소. since/until은 빈 값(전체) 또는 YYYY-MM-DD */
function elo_url_rivals(int $a, int $b, string $since, string $until): string
{
    if ($a < 1 || $b < 1 || $a === $b) {
        throw new EloError('eloboard 선수 번호가 올바르지 않습니다.');
    }
    foreach ([$since, $until] as $d) {
        if ($d !== '' && !elo_date_ok($d)) {
            throw new EloError('기간 날짜가 올바르지 않습니다.');
        }
    }
    $q = ['tab' => 'rivals', 'vs' => $b];
    if ($since !== '') {
        $q['since'] = $since;
    }
    if ($until !== '') {
        $q['until'] = $until;
    }
    return ELO_BASE . $a . '?' . http_build_query($q);
}

function elo_url_races(int $a): string
{
    if ($a < 1) {
        throw new EloError('eloboard 선수 번호가 올바르지 않습니다.');
    }
    return ELO_BASE . $a . '?tab=races';
}

/** 보이는 글자만 남긴다: 스크립트·주석 제거, 태그 → ' | ' */
function elo_text(string $html): string
{
    $s = preg_replace(['#<(script|style|template)\b.*?</\1\s*>#is', '#<!--.*?-->#s'], ' ', $html) ?? '';
    $s = preg_replace('#<[^>]*>#', ' | ', $s) ?? '';
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
}

function elo_num(string $s): int
{
    return (int)str_replace(',', '', $s);
}

/** 표시된 비율(%)이 승/(승+패)와 0.1 이내인지 */
function elo_rate_ok(int $w, int $l, string $pct): bool
{
    return $w + $l > 0 && abs($w * 100 / ($w + $l) - (float)$pct) <= 0.1 + 1e-9;
}

/** 맞대결 탭: {a, b, games} (A = 주소의 선수). 구조가 하나로 확실하지 않으면 EloError */
function elo_parse_rivals(string $html): array
{
    $t = elo_text($html);
    $S = '[\s|]*';
    $N = '(\d{1,3}(?:,\d{3})*|\d+)';
    $P = '(\d{1,3}(?:\.\d)?)';
    $re = "/(?<![A-Za-z])[PTZ]{$S}{$N}{$S}:{$S}{$N}{$S}(?:\d{4}\.\d{2}\.\d{2}{$S}~{$S}\d{4}\.\d{2}\.\d{2}{$S}·{$S})?{$N}{$S}경기"
        . "{$S}[PTZ]{$S}[^|]{1,40}?{$S}{$P}{$S}%{$S}맞대결 승률{$S}{$P}{$S}%/u";
    if (preg_match_all($re, $t, $m, PREG_SET_ORDER) !== 1) {
        throw new EloError(ELO_PARSE_ERROR);
    }
    [$a, $b, $g] = [elo_num($m[0][1]), elo_num($m[0][2]), elo_num($m[0][3])];
    if ($g !== $a + $b || !elo_rate_ok($a, $b, $m[0][4]) || !elo_rate_ok($b, $a, $m[0][5])) {
        throw new EloError(ELO_PARSE_ERROR);
    }
    return ['a' => $a, 'b' => $b, 'games' => $g];
}

/** 종족별 전적 탭 (통산): {T: [승, 패], P: …, Z: …} */
function elo_parse_races(string $html): array
{
    $t = elo_text($html);
    $S = '[\s|]*';
    $N = '(\d{1,3}(?:,\d{3})*|\d+)';
    $out = [];
    foreach (ELO_RACE_KO as $r => $ko) {
        $re = "/(?<![A-Za-z]){$r}{$S}vs{$S}{$ko}{$S}(\d{1,3}(?:\.\d)?){$S}%{$S}{$N}{$S}승{$S}{$N}{$S}패/u";
        if (preg_match_all($re, $t, $m, PREG_SET_ORDER) !== 1) {
            throw new EloError(ELO_PARSE_ERROR);
        }
        [$w, $l] = [elo_num($m[0][2]), elo_num($m[0][3])];
        if (!elo_rate_ok($w, $l, $m[0][1])) {
            throw new EloError(ELO_PARSE_ERROR);
        }
        $out[$r] = [$w, $l];
    }
    return $out;
}

// ---------------------------------------------------------------- 접속 (잠금·간격·캐시)

function elo_cache_path(): string
{
    return storage_dir() . '/eloboard_cache.json';
}

function elo_cache_read(): array
{
    $s = (string)@file_get_contents(elo_cache_path());
    try {
        $j = $s === '' ? [] : json_dec($s);
    } catch (JsonException) {
        $j = []; // 손상된 캐시는 버린다
    }
    return is_array($j) ? $j : [];
}

/**
 * 주소 하나를 읽어 숫자로 바꾼다. 6시간 안에 읽은 주소는 캐시(네트워크 없음).
 * 호출 전 elo_lock()으로 잠가 둔다. 실패는 재시도하지 않는다.
 */
function elo_get(string $url, string $kind, array $op): array
{
    $cache = elo_cache_read();
    $now = elo_clock();
    if (isset($cache[$url]) && $now - (float)$cache[$url]['t'] < ELO_CACHE_TTL) {
        return $cache[$url]['data'] + ['cached' => true];
    }
    $lastFile = storage_dir() . '/eloboard_last.txt';
    $wait = (float)@file_get_contents($lastFile) + ELO_GAP - $now;
    if ($wait > 0) {
        elo_sleep(min($wait, ELO_GAP)); // 시계가 뒤로 가도 2초보다 오래 기다리지 않음
    }
    $start = elo_clock();
    @file_put_contents($lastFile, sprintf('%.3f', $start), LOCK_EX);
    $path = (string)parse_url($url, PHP_URL_PATH) . '?' . (string)parse_url($url, PHP_URL_QUERY);
    try {
        $res = http_request('GET', $url, ['Accept: text/html'], null, ELO_TIMEOUT,
            'EndgameCG/' . APP_VERSION . ' (+https://etalent.co.kr/cg/)');
    } catch (ProviderError) {
        cg_log('data', 'ELOBOARD', $op, ['detail' => "$path 연결 실패"]);
        throw new EloError('eloboard에 연결하지 못했습니다 (시간 초과·네트워크). 이전 값을 유지합니다.');
    }
    @file_put_contents($lastFile, sprintf('%.3f', elo_clock()), LOCK_EX);
    $ms = (int)round((elo_clock() - $start) * 1000);
    cg_log('data', 'ELOBOARD', $op, ['detail' => "$path {$res['status']} {$ms}ms"]);
    if ($res['status'] !== 200) {
        throw new EloError("eloboard 응답 오류 ({$res['status']}). 이전 값을 유지합니다.");
    }
    $data = $kind === 'races' ? ['races' => elo_parse_races($res['body'])] : elo_parse_rivals($res['body']);
    foreach ($cache as $k => $c) {
        if ($now - (float)($c['t'] ?? 0) >= ELO_CACHE_TTL) {
            unset($cache[$k]);
        }
    }
    $cache[$url] = ['t' => $now, 'data' => $data];
    @file_put_contents(elo_cache_path(), json_enc($cache), LOCK_EX);
    return $data + ['cached' => false];
}

/** 서버 전체 조회 잠금. 이미 잠겨 있으면 바로 오류 (기다리지 않음) */
function elo_lock()
{
    $fp = @fopen(storage_dir() . '/eloboard.lock', 'c');
    if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
        throw new ActionError('ELO_BUSY', '다른 조회가 진행 중입니다. 잠시 뒤 다시 누르세요.', 409);
    }
    return $fp;
}

// ---------------------------------------------------------------- 선수 연결 (관리자)

/** 시트 선수 id => eloboard 숫자 id */
function elo_map(): array
{
    $m = json_dec(setting_get('eloboard_players', '{}'));
    return is_array($m) ? array_filter($m, 'is_int') : [];
}

function eloboard_map_view(array $op): array
{
    $map = elo_map();
    return ['admin' => ($op['role'] ?? '') === 'admin', 'players' => array_values(array_map(static fn($p) => ['id' => $p['id'],
        'name' => $p['name'], 'race' => $p['race'], 'elo' => $map[$p['id']] ?? null], players_cache()))];
}

/** 연결 저장. url 비우면 삭제. 저장 후 마지막 정상 데이터로 AUTO를 다시 계산한다 (네트워크 없음) */
function eloboard_map_save(array $in, array $op): array
{
    require_admin_op($op);
    $pid = (string)($in['player'] ?? '');
    if (!isset(players_cache()[$pid])) {
        throw new ActionError('NO_PLAYER', '데이터에 없는 선수입니다.', 422);
    }
    $raw = trim((string)($in['url'] ?? ''));
    $id = $raw === '' ? null : elo_id_from($raw);
    if ($raw !== '' && $id === null) {
        throw new ActionError('BAD_ELO_URL', 'eloboard 선수 주소를 https://eloboard.com/players/숫자 형식으로 입력하세요.', 422);
    }
    $map = elo_map();
    unset($map[$pid]);
    if ($id !== null) {
        $map[$pid] = $id;
    }
    setting_set('eloboard_players', json_enc((object)$map));
    cg_log('data', 'ELOBOARD_MAP', $op, ['detail' => "$pid eloboard: " . ($id === null ? '(없음)' : "players/$id")]);
    $ds = dataset_cache_get(data_source());
    if ($ds !== null) {
        data_apply($ds, $op, false);
    }
    return eloboard_map_view($op);
}

// ---------------------------------------------------------------- 조회 결과 → AUTO

/** 기간 표시: '' = 전체, 26.01.01~10.26 (같은 해면 끝 날짜 연도 생략) */
function elo_period_text(string $since, string $until): string
{
    if ($since === '' && $until === '') {
        return '';
    }
    $f = static fn(string $d, bool $year) => $d === '' ? '' : ($year ? substr($d, 2, 2) . '.' : '') . substr($d, 5, 2) . '.' . substr($d, 8, 2);
    $sameYear = $since !== '' && $until !== '' && substr($since, 0, 4) === substr($until, 0, 4);
    return $f($since, true) . '~' . $f($until, !$sameYear);
}

/** CG 제목 자동값 (최대 40자 안) */
function elo_title(string $since, string $until): string
{
    $p = elo_period_text($since, $until);
    return $p === '' ? '온라인 상대 전적' : '온라인 전적 ' . $p;
}

/** 결과 저장 키: 선수·상대 종족·기간·연결된 eloboard id (연결을 바꾸면 이전 결과는 쓰지 않는다) */
function elo_result_key(array $p, array $map): ?string
{
    $ea = $map[$p['a']['player']] ?? null;
    $eb = $map[$p['b']['player']] ?? null;
    if ($ea === null || $eb === null) {
        return null;
    }
    return implode('|', [$p['a']['player'], $p['a']['vs'], $ea, $p['b']['player'], $p['b']['vs'], $eb, $p['since'] ?? '', $p['until'] ?? '']);
}

function elo_results(): array
{
    $r = json_dec(setting_get('eloboard_results', '{}'));
    return is_array($r) ? $r : [];
}

/** 데이터에 연결·조회 결과를 붙인다 (템플릿 auto가 읽음) */
function dataset_with_eloboard(array $ds): array
{
    $ds['eloboard'] = ['map' => elo_map(), 'results' => elo_results()];
    return $ds;
}

/** 이 파라미터의 조회 결과 (없으면 null) */
function elo_result_for(array $p, array $ds): ?array
{
    $key = elo_result_key($p, $ds['eloboard']['map'] ?? []);
    return $key === null ? null : ($ds['eloboard']['results'][$key] ?? null);
}

/** 패널 표시용 한 줄: eloboard · 기간 · 조회 시각 · N경기 */
function elo_info(array $r): string
{
    $p = elo_period_text($r['since'], $r['until']);
    return sprintf('eloboard · %s · %s 조회', $p === '' ? '전체' : $p, substr($r['at'], 5, 11))
        . ($r['games'] === null ? '' : ' · ' . number_format($r['games']) . '경기');
}

/**
 * [eloboard 조회] (운영자 클릭). 읽은 숫자를 저장하고 AUTO를 다시 계산한다. PREVIEW·PROGRAM은 바꾸지 않는다.
 * 접속·응답 오류: 이전 값 유지 + 오류. 화면을 확실히 읽지 못함: 값 비움 + 오류.
 */
function online_fetch(array $in, array $op): array
{
    $params = template_params('online-h2h', is_array($in['params'] ?? null) ? $in['params'] : [], players_cache(), template_ctx());
    $map = elo_map();
    $missing = array_values(array_filter([$params['a']['player'], $params['b']['player']], static fn($id) => !isset($map[$id])));
    if ($missing) {
        throw new ActionError('ELO_NO_MAP', 'eloboard 선수 연결이 없습니다: ' . implode(', ', array_map(static fn($id) =>
            pname(players_cache(), $id), $missing)) . ' — 관리자가 [데이터 점검·설정] → [eloboard 선수]에서 등록하세요.', 422);
    }
    [$since, $until] = [$params['since'], $params['until']];
    [$ea, $eb] = [$map[$params['a']['player']], $map[$params['b']['player']]];
    $all = $since === '' && $until === '';
    $r = ['since' => $since, 'until' => $until, 'at' => now(), 'games' => null, 'h_a' => null, 'h_b' => null,
        'a_wins' => null, 'a_losses' => null, 'b_wins' => null, 'b_losses' => null, 'error' => null, 'note' => $all ? null : ELO_PERIOD_NOTE];
    $lock = elo_lock();
    try {
        try {
            $h = elo_get(elo_url_rivals($ea, $eb, $since, $until), 'rivals', $op);
            $r = array_replace($r, ['games' => $h['games'], 'h_a' => $h['a'], 'h_b' => $h['b']]);
            if ($all) {
                $ra = elo_get(elo_url_races($ea), 'races', $op)['races'][$params['a']['vs']];
                $rb = elo_get(elo_url_races($eb), 'races', $op)['races'][$params['b']['vs']];
                $r = array_replace($r, ['a_wins' => $ra[0], 'a_losses' => $ra[1], 'b_wins' => $rb[0], 'b_losses' => $rb[1]]);
            }
        } catch (EloError $e) {
            if ($e->getMessage() !== ELO_PARSE_ERROR) {
                throw new ActionError('ELO_FETCH', $e->getMessage(), 502);
            }
            // 읽지 못한 화면: 값을 모두 비운다 (일부만 맞을 수 있는 값을 남기지 않음)
            $r = array_replace($r, ['games' => null, 'h_a' => null, 'h_b' => null, 'a_wins' => null, 'a_losses' => null,
                'b_wins' => null, 'b_losses' => null, 'error' => ELO_PARSE_ERROR]);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    $results = elo_results();
    $results[elo_result_key($params, $map)] = $r;
    setting_set('eloboard_results', json_enc(array_slice($results, -100, null, true)));
    $ds = dataset_cache_get(data_source());
    if ($ds !== null) {
        data_apply($ds, $op, false);
    }
    return ['info' => elo_info($r), 'note' => $r['note'], 'error' => $r['error']];
}
