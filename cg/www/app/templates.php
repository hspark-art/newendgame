<?php
declare(strict_types=1);

/**
 * CG 템플릿 목록과 공용 도우미.
 * 템플릿마다 두 파일:
 *   app/templates/<slug>.def.php  정의 (파라미터, 필드, AUTO 계산, 표시 문자열, 요약)
 *   app/templates/<slug>.view.php HTML 조각 (모든 값은 h()로 이스케이프)
 * 스타일은 assets/cg.css 에 템플릿 이름(.cg-<slug>)으로 둔다.
 */

const RACE_NAMES = ['P' => '프로토스', 'T' => '테란', 'Z' => '저그'];

/** @return array<string, array> slug => 정의 (표시 순서대로) */
function cg_templates(): array
{
    static $all = null;
    if ($all !== null) {
        return $all;
    }
    $all = [];
    foreach (glob(APP_DIR . '/templates/*.def.php') as $file) {
        $def = require $file;
        $all[$def['slug']] = $def;
    }
    uasort($all, static fn($a, $b) => $a['order'] <=> $b['order']);
    return $all;
}

function template_get(string $slug): array
{
    $all = cg_templates();
    if (!isset($all[$slug])) {
        throw new ActionError('UNKNOWN_TEMPLATE', '알 수 없는 CG 종류입니다.', 422);
    }
    return $all[$slug];
}

/** 파라미터 조회 맥락: 마지막 정상 데이터의 선수·예측자·연도 목록 */
function template_ctx(): array
{
    return ['players' => players_cache(), 'predictors' => json_dec(setting_get('predictors_cache', '{}')) ?: [],
        'years' => json_dec(setting_get('years_cache', '[]')) ?: []];
}

/**
 * 파라미터 정규화·검증 (템플릿의 params 정의에 따라). 같은 대상이면 입력 순서와 관계없이 같은 결과.
 * 키에 점이 있으면 중첩 배열로 저장한다: 'a.player' → ['a' => ['player' => …]]
 */
function template_params(string $slug, array $in, array $players, array $ctx = []): array
{
    $tpl = template_get($slug);
    $ctx += ['players' => $players, 'predictors' => [], 'years' => []];
    $out = [];
    foreach ($tpl['params'] as $p) {
        $raw = array_reduce(explode('.', $p['key']), static fn($c, $k) => is_array($c) ? ($c[$k] ?? null) : null, $in);
        $v = param_value($p, $raw, $ctx);
        $ref = &$out;
        foreach (explode('.', $p['key']) as $k) {
            $ref = &$ref[$k];
        }
        $ref = $v;
        unset($ref);
    }
    if (isset($tpl['check'])) {
        $tpl['check']($out);
    }
    return $out;
}

function param_value(array $p, mixed $raw, array $ctx): mixed
{
    $s = is_scalar($raw) ? trim((string)$raw) : '';
    $label = $p['label'];
    switch ($p['type']) {
        case 'player':
            $s = strtolower($s);
            if (!isset($ctx['players'][$s])) {
                throw new ActionError('BAD_PARAMS', "$label: 선수를 선택하세요.", 422);
            }
            return $s;
        case 'race':
            $s = strtoupper($s);
            if (!isset(RACE_NAMES[$s])) {
                throw new ActionError('BAD_PARAMS', "$label: 종족(P/T/Z)을 선택하세요.", 422);
            }
            return $s;
        case 'race_any':
            // 값을 보내지 않으면 기본 종족, 빈 문자열이면 "전체 종족"
            $s = $raw === null ? (string)($p['default_value'] ?? '') : strtoupper($s);
            if ($s !== '' && !isset(RACE_NAMES[$s])) {
                throw new ActionError('BAD_PARAMS', "$label: 종족을 다시 선택하세요.", 422);
            }
            return $s;
        case 'int':
            if ($s === '') {
                return $p['default'];
            }
            if (!preg_match('/^\d{1,3}$/D', $s) || (int)$s < $p['min'] || (int)$s > $p['max']) {
                throw new ActionError('BAD_PARAMS', "$label: {$p['min']}~{$p['max']} 사이로 입력하세요.", 422);
            }
            return (int)$s;
        case 'year':
            if ($s === '' && $ctx['years']) {
                return (string)$ctx['years'][0];
            }
            if (!preg_match('/^(19|20)\d{2}$/D', $s)) {
                throw new ActionError('BAD_PARAMS', "$label: 연도를 선택하세요.", 422);
            }
            return $s;
        case 'predictor_slots':
            $list = [];
            foreach (is_array($raw) ? array_slice($raw, 0, $p['max']) : [] as $id) {
                $id = strtolower(trim((string)$id));
                if ($id === '') {
                    continue;
                }
                if (!isset($ctx['predictors'][$id])) {
                    throw new ActionError('BAD_PARAMS', "$label: 없는 예측자입니다.", 422);
                }
                if (in_array($id, $list, true)) {
                    throw new ActionError('BAD_PARAMS', "$label: 같은 사람을 두 번 넣었습니다.", 422);
                }
                $list[] = $id;
            }
            return $list;
    }
    throw new ActionError('BAD_PARAMS', "$label: 알 수 없는 입력 종류입니다.", 422);
}

function params_key(array $params): string
{
    $sort = static function (array $a) use (&$sort): array {
        if (!array_is_list($a)) {
            ksort($a);
        }
        return array_map(static fn($v) => is_array($v) ? $sort($v) : $v, $a);
    };
    return sha1(json_enc($sort($params)));
}

/** 입력 필드(파생 제외)의 AUTO 값. 목록형 CG의 빈 행은 null */
function template_auto(string $slug, array $params, array $ds): array
{
    $tpl = template_get($slug);
    $auto = $tpl['auto']($params, $ds);
    $out = [];
    foreach ($tpl['fields'] as $key => $def) {
        if (!isset($def['derived'])) {
            $out[$key] = $auto[$key] ?? null;
        }
    }
    return $out;
}

/** 페이지 리스트에 보여 줄 한 줄 요약. $ctx는 template_ctx() (여러 번 부를 때 한 번만 읽어 넘긴다) */
function template_summary(string $slug, array $params, ?array $ctx = null): string
{
    return template_get($slug)['summary']($params, $ctx ?? template_ctx());
}

/**
 * 송출 가능 여부: 필수 값이 모두 있고, 목록형 CG는 표시할 행이 1개 이상이어야 한다.
 * @return list<string> 문제 목록 (비어 있으면 송출 가능)
 */
function template_problems(string $slug, array $final, array $params): array
{
    $tpl = template_get($slug);
    $problems = ov_sendable($tpl['fields'], $final);
    if (!$problems) {
        $view = $tpl['present']($final, $params);
        if (array_key_exists('rows', $view) && !$view['rows']) {
            $problems[] = '표시할 행이 없습니다. 행 값을 입력하거나 다른 조건을 고르세요.';
        }
    }
    return $problems;
}

/** FINAL 값 → 송출 화면 문자열 */
function template_present(string $slug, array $final, array $params, bool $mock): array
{
    $view = template_get($slug)['present']($final, $params);
    return ['template' => $slug, 'mock' => $mock] + $view;
}

/** HTML 조각 렌더링 */
function cg_render(array $view): string
{
    $file = APP_DIR . '/templates/' . basename($view['template']) . '.view.php';
    ob_start();
    (static function () use ($file, $view) {
        require $file;
    })();
    return (string)ob_get_clean();
}

// ---------------------------------------------------------------- 정의 파일에서 쓰는 도우미

function pname(array $players, string $id): string
{
    return (string)($players[$id]['name'] ?? $id);
}

function fmt_rate(?int $tenths): string
{
    return $tenths === null ? '' : intdiv($tenths, 10) . '.' . ($tenths % 10);
}

/** 패널 표에 보여 줄 값 */
function fmt_field(array $def, mixed $v): string
{
    if ($v === null) {
        return '—';
    }
    return match ($def['type']) {
        'rate' => fmt_rate((int)$v) . '%',
        default => (string)$v,
    };
}

/** "33승 21패" */
function text_record(?int $w, ?int $l): string
{
    return sprintf('%s승 %s패', $w ?? '-', $l ?? '-');
}

/** "(61.1%)" 또는 "(자료 없음)" */
function text_rate_paren(?int $t): string
{
    return $t === null ? '(자료 없음)' : '(' . fmt_rate($t) . '%)';
}

/** "73.6%" 또는 "—" */
function text_pct(?int $t): string
{
    return $t === null ? '—' : fmt_rate($t) . '%';
}

/** 목록형 CG의 행 필드 만들기: 1~$n행, 각 행은 $spec (필드 이름 => 정의), 모두 비울 수 있음 */
function row_fields(int $n, array $spec): array
{
    $out = [];
    for ($i = 1; $i <= $n; $i++) {
        foreach ($spec as $k => $def) {
            if (isset($def['derived'])) {
                $pre = static fn($x) => "r$i.$x";
                $def['derived'] = isset($def['derived']['calc'])
                    ? array_replace($def['derived'], ['parts' => array_map($pre, $def['derived']['parts']), 'total' => $pre($def['derived']['total'])])
                    : array_map($pre, $def['derived']);
            }
            $out["r$i.$k"] = $def + ['optional' => true, 'group' => "{$i}행"];
        }
    }
    return $out;
}

/** 행 i의 값들 (접두어 제거). 행 전체가 비어 있으면 null */
function row_values(array $final, int $i, array $keys): ?array
{
    $row = [];
    foreach ($keys as $k) {
        $row[$k] = $final["r$i.$k"] ?? null;
    }
    return array_filter($row, static fn($v) => $v !== null && $v !== '') ? $row : null;
}

/** 두 선수 파라미터 공통 검사 */
function check_two_players(array $p): void
{
    if (($p['a']['player'] ?? null) === ($p['b']['player'] ?? null)) {
        throw new ActionError('BAD_PARAMS', 'A와 B에 서로 다른 선수를 고르세요.', 422);
    }
}
