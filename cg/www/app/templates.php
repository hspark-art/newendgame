<?php
declare(strict_types=1);

/**
 * CG 템플릿 목록과 필드 정의, 파라미터 정규화, AUTO 값 계산, 표시 문자열(Presenter), HTML 조각.
 * 새 CG는 여기와 app/templates/<slug>.php, assets/cg.css 에 추가한다.
 */

const RACE_NAMES = ['P' => '프로토스', 'T' => '테란', 'Z' => '저그'];

function cg_templates(): array
{
    $side = static fn(string $s, string $who) => [
        "$s.name" => ['label' => "$who 이름", 'type' => 'text', 'max' => 12],
        "$s.wins" => ['label' => "$who 승", 'type' => 'int'],
        "$s.losses" => ['label' => "$who 패", 'type' => 'int'],
        "$s.rate" => ['label' => "$who 승률", 'type' => 'rate', 'derived' => ["$s.wins", "$s.losses"]],
    ];
    return [
        'race-win-rate' => [
            'slug' => 'race-win-rate',
            'name' => '상대 종족 승률',
            'short' => '종족승률',
            'default_title' => '중계진 스타 끝장전 상대 종족 승률',
            'fields' => ['title' => ['label' => '제목', 'type' => 'text', 'max' => 40]]
                + $side('a', 'A') + $side('b', 'B'),
        ],
    ];
}

function template_get(string $slug): array
{
    $all = cg_templates();
    if (!isset($all[$slug])) {
        throw new ActionError('UNKNOWN_TEMPLATE', '알 수 없는 CG 종류입니다.', 422);
    }
    return $all[$slug];
}

/**
 * 파라미터 정규화·검증. 같은 대상이면 입력 순서와 관계없이 같은 결과가 나온다.
 * @param array<string,array> $players 선수 목록(id => player)
 */
function template_params(string $slug, array $in, array $players): array
{
    template_get($slug);
    $out = [];
    foreach (['a' => 'A', 'b' => 'B'] as $s => $who) {
        $player = strtolower(trim((string)($in[$s]['player'] ?? '')));
        $vs = strtoupper(trim((string)($in[$s]['vs'] ?? '')));
        if (!isset($players[$player])) {
            throw new ActionError('BAD_PARAMS', "$who 선수를 선택하세요.", 422);
        }
        if (!isset(RACE_NAMES[$vs])) {
            throw new ActionError('BAD_PARAMS', "$who 선수의 상대 종족(P/T/Z)을 선택하세요.", 422);
        }
        $out[$s] = ['player' => $player, 'vs' => $vs];
    }
    return $out;
}

function params_key(array $params): string
{
    $sort = static function (array $a) use (&$sort): array {
        ksort($a);
        return array_map(static fn($v) => is_array($v) ? $sort($v) : $v, $a);
    };
    return sha1(json_enc($sort($params)));
}

/** 입력 필드(파생 제외)의 AUTO 값 */
function template_auto(string $slug, array $params, array $ds): array
{
    $tpl = template_get($slug);
    $auto = ['title' => $tpl['default_title']];
    foreach (['a', 'b'] as $s) {
        $pid = $params[$s]['player'];
        $rec = stats_race_record($ds['matches'], $pid, $params[$s]['vs']);
        $auto["$s.name"] = $ds['players'][$pid]['name'] ?? $pid;
        $auto["$s.wins"] = $rec['wins'];
        $auto["$s.losses"] = $rec['losses'];
    }
    return $auto;
}

/** 페이지 리스트에 보여 줄 한 줄 요약 */
function template_summary(string $slug, array $params, array $players): string
{
    $name = static fn(string $id) => $players[$id]['name'] ?? $id;
    return sprintf('%s vs %s / %s vs %s',
        $name($params['a']['player']), $params['a']['vs'], $name($params['b']['player']), $params['b']['vs']);
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

/** FINAL 값 → 송출 화면 문자열 */
function template_present(string $slug, array $final, array $params, bool $mock): array
{
    $tpl = template_get($slug);
    $cols = [];
    foreach (['a', 'b'] as $s) {
        $w = $final["$s.wins"];
        $l = $final["$s.losses"];
        $rate = $final["$s.rate"];
        $cols[] = [
            'name' => (string)$final["$s.name"],
            'vs' => $params[$s]['vs'],
            'record' => sprintf('%s승 %s패', $w ?? '-', $l ?? '-'),
            'rate' => $rate === null ? '(자료 없음)' : '(' . fmt_rate($rate) . '%)',
        ];
    }
    return ['template' => $tpl['slug'], 'title' => (string)$final['title'], 'cols' => $cols, 'mock' => $mock];
}

/** HTML 조각 렌더링. 모든 값은 h()로 이스케이프한다. */
function cg_render(array $view): string
{
    $file = APP_DIR . '/templates/' . basename($view['template']) . '.php';
    ob_start();
    (static function () use ($file, $view) {
        require $file;
    })();
    return (string)ob_get_clean();
}
