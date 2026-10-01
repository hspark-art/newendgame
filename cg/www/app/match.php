<?php
declare(strict_types=1);

/**
 * 오늘 매치 (v0.6): 두 선수를 한 번 정해 두고
 * - 페이지 추가 창에 미리 채운다 (panel.js),
 * - 2인 CG(상대 종족 승률·매치 프리뷰·맞대결·풀세트·더블 찬스 …)를 한 번에 추가하고,
 * - 페이지 리스트의 2인 CG 선수를 한 번에 이 매치로 바꾼다 (송출 중인 페이지는 그대로).
 * 상대 종족은 각 선수의 주 종족으로 맞춘다. 저장 위치: cg_settings 'match_today' {a, b}
 */

/** 한 번에 추가할 때 기본으로 고르는 CG (온라인 상대 전적은 수동 입력이라 뺌, 맵 전적은 맵을 골라야 함) */
const MATCH_DEFAULT_TEMPLATES = ['race-win-rate', 'match-preview', 'head-to-head', 'full-set', 'double-chance'];

/** 2인 CG: 파라미터에 a.player·b.player가 있는 템플릿 (표시 순서) */
function match_templates(): array
{
    $out = [];
    foreach (cg_templates() as $slug => $t) {
        $keys = array_column($t['params'], 'type', 'key');
        if (($keys['a.player'] ?? null) === 'player' && ($keys['b.player'] ?? null) === 'player') {
            $out[] = $slug;
        }
    }
    return $out;
}

/** @return array{a:?string, b:?string} 지금 데이터에 없는 선수는 비운다 */
function match_today(): array
{
    $m = json_dec(setting_get('match_today', '{}')) ?: [];
    $players = players_cache();
    $pick = static fn($v) => is_string($v) && isset($players[$v]) ? $v : null;
    return ['a' => $pick($m['a'] ?? null), 'b' => $pick($m['b'] ?? null)];
}

/** 패널에 보내는 값 */
function match_view(): array
{
    $m = match_today();
    $players = players_cache();
    return $m + [
        'text' => $m['a'] !== null && $m['b'] !== null ? sprintf('%s (%s) vs %s (%s)', $m['a'], $players[$m['a']]['race'] ?? '?',
            $m['b'], $players[$m['b']]['race'] ?? '?') : '',
        'templates' => array_map(static fn($s) => ['slug' => $s, 'name' => template_get($s)['name'],
            'checked' => in_array($s, MATCH_DEFAULT_TEMPLATES, true)], match_templates()),
    ];
}

function match_today_save(array $in, array $op): array
{
    $players = players_cache();
    $a = (string)($in['a'] ?? '');
    $b = (string)($in['b'] ?? '');
    if (!isset($players[$a]) || !isset($players[$b])) {
        throw new ActionError('BAD_PARAMS', '오늘 매치의 두 선수를 고르세요.', 422);
    }
    if ($a === $b) {
        throw new ActionError('BAD_PARAMS', 'A와 B에 서로 다른 선수를 고르세요.', 422);
    }
    if (match_today() !== ['a' => $a, 'b' => $b]) {
        db_tx(function () use ($a, $b, $op) {
            setting_set('match_today', json_enc(['a' => $a, 'b' => $b]));
            cg_log('broadcast', 'MATCH_SET', $op, ['detail' => "오늘 매치: $a vs $b"]);
            state_bump();
        });
    }
    return match_view();
}

/**
 * 템플릿 파라미터를 오늘 매치로: A·B 선수를 바꾸고, 상대 종족(auto_from)은 상대 선수의 주 종족으로.
 * 맵은 $map이 null이 아니면 바꾼다. 그 밖의 값(경기 수 등)은 $base 그대로.
 */
function match_params(string $slug, array $base, string $a, string $b, ?string $map): array
{
    $players = players_cache();
    $p = $base;
    $p['a']['player'] = $a;
    $p['b']['player'] = $b;
    foreach (template_get($slug)['params'] as $def) {
        if (isset($def['auto_from'])) {
            // 상대 선수가 그대로면 운영자가 고른 종족을 유지한다 (선수가 바뀐 경우·값이 없는 경우만 새로)
            [$side, $field] = explode('.', $def['key']) + [1 => ''];
            $src = explode('.', $def['auto_from'])[0];
            if (($base[$src]['player'] ?? null) !== $p[$src]['player'] || ($base[$side][$field] ?? '') === '') {
                $p[$side][$field] = $players[$p[$src]['player']]['race'] ?? '';
            }
        }
        if (in_array($def['type'], ['map', 'map_any'], true) && $map !== null) {
            $p[$def['key']] = $map;
        }
    }
    return $p;
}

/**
 * 고른 2인 CG를 오늘 매치로 한 번에 추가. 같은 CG(같은 선수·조건)가 이미 페이지 리스트에 있으면 건너뛴다.
 * @return array{added:list<string>, skipped:list<string>}
 */
function match_pages_add(array $in, array $op): array
{
    match_today_save($in, $op);
    ['a' => $a, 'b' => $b] = match_today();
    $map = trim((string)($in['map'] ?? ''));
    $want = array_values(array_intersect(match_templates(), array_map('strval', (array)($in['templates'] ?? []))));
    if (!$want) {
        throw new ActionError('VALIDATION', '추가할 CG를 하나 이상 고르세요.', 422);
    }
    $exists = array_map(static fn($r) => $r['template'] . '|' . params_key(json_dec($r['params_json'])), rundown_rows());
    $added = $skipped = [];
    foreach ($want as $slug) {
        $name = template_get($slug)['name'];
        $needsMap = in_array('map', array_column(template_get($slug)['params'], 'type'), true);
        if ($needsMap && $map === '') {
            $skipped[] = "$name: 이번 맵을 고르세요";
            continue;
        }
        try {
            $params = template_params($slug, match_params($slug, [], $a, $b, $map), players_cache(), template_ctx());
        } catch (ActionError $e) {
            $skipped[] = "$name: " . $e->getMessage();
            continue;
        }
        if (in_array($slug . '|' . params_key($params), $exists, true)) {
            $skipped[] = "$name: 이미 있음";
            continue;
        }
        $r = page_add(['template' => $slug, 'params' => $params], $op);
        $added[] = sprintf('%03d %s', $r['page_no'], $name);
    }
    return ['added' => $added, 'skipped' => $skipped, 'match' => match_view()];
}

/**
 * 페이지 리스트의 2인 CG를 모두 오늘 매치로 바꾼다 (페이지 번호·메모·경기 수·맵·뺀 항목은 그대로, 수정값은 새 선수라 새로 시작).
 * 송출 중(PROGRAM)인 페이지는 화면이 갑자기 바뀌지 않게 건너뛴다.
 * @return array{changed:list<string>, skipped:list<string>}
 */
function match_pages_apply(array $in, array $op): array
{
    match_today_save($in, $op);
    ['a' => $a, 'b' => $b] = match_today();
    $pg = channel_get('program');
    $slugs = match_templates();
    $changed = $skipped = [];
    foreach (rundown_rows() as $r) {
        if (!in_array($r['template'], $slugs, true)) {
            continue;
        }
        $label = sprintf('%03d %s', (int)$r['page_no'], template_get($r['template'])['name']);
        $old = json_dec($r['params_json']);
        try {
            $params = template_params($r['template'], match_params($r['template'], $old, $a, $b, null), players_cache(), template_ctx());
        } catch (ActionError $e) {
            $skipped[] = "$label: " . $e->getMessage();
            continue;
        }
        if (params_key($params) === params_key($old)) {
            continue;
        }
        if ($pg['rundown_id'] === (int)$r['id'] && $pg['snapshot'] !== null) {
            $skipped[] = "$label: 송출 중이라 그대로 둠";
            continue;
        }
        page_update((int)$r['id'], ['params' => $params], $op);
        // 그 페이지에서 뺀 항목(빨간 −)은 새 선수 CG에도 그대로 (새 CG에 이미 정한 것이 없을 때)
        $oldHidden = instance_get((int)$r['instance_id'])['hidden'];
        $newInst = instance_get((int)rundown_get((int)$r['id'])['instance_id']);
        if ($oldHidden && !$newInst['hidden']) {
            db_exec('UPDATE cg_instances SET hidden_json = ? WHERE id = ?', [json_enc($oldHidden), $newInst['id']]);
        }
        $changed[] = $label;
    }
    return ['changed' => $changed, 'skipped' => $skipped, 'match' => match_view()];
}
