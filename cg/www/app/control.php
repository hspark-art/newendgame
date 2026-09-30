<?php
declare(strict_types=1);

/**
 * 방송 상태 동작: 방송 세션, 페이지 리스트, PREVIEW/PROGRAM, 데이터 갱신.
 * 상태를 바꾸는 함수는 모두 트랜잭션 안에서 state_bump()로 변경 번호를 올린다 (폴링 기준).
 * $op = guard_control()이 돌려준 운영자 정보 {name, role, user_id}
 */

const EFFECTS = ['cut', 'fade', 'slide'];

function current_session_id(): int
{
    return (int)setting_get('current_session_id', '1');
}

/** 변경 번호를 1 올리고, 바뀐 채널(preview/program)에 그 번호를 기록한다. */
function state_bump(array $kinds = [], int $layer = 1): int
{
    db_exec("UPDATE cg_settings SET v = v + 1 WHERE k = 'state_rev'");
    $rev = (int)setting_get('state_rev', '0');
    foreach (array_unique($kinds) as $kind) {
        db_exec('UPDATE cg_channels SET rev = ?, updated_at = ? WHERE layer = ? AND kind = ?', [$rev, now(), $layer, $kind]);
    }
    return $rev;
}

function channel_get(string $kind, int $layer = 1): array
{
    $row = db_one('SELECT * FROM cg_channels WHERE layer = ? AND kind = ?', [$layer, $kind]);
    if ($row === null) {
        throw new ActionError('BAD_LAYER', '없는 레이어입니다.', 404);
    }
    foreach (['rundown_id', 'instance_id'] as $k) {
        $row[$k] = $row[$k] === null ? null : (int)$row[$k];
    }
    foreach (['visible', 'take_id', 'rev', 'layer'] as $k) {
        $row[$k] = (int)$row[$k];
    }
    $row['display'] = json_dec($row['display_json']);
    $row['snapshot'] = $row['snapshot_json'] === null ? null : json_dec($row['snapshot_json']);
    return $row;
}

/** 마지막 정상 데이터의 선수 목록 (id => {id, name, race}) */
function players_cache(): array
{
    $j = setting_get('players_cache');
    return $j === null ? [] : json_dec($j);
}

function source_status(): array
{
    $s = db_one('SELECT * FROM cg_sources WHERE id = ?', [data_source()]);
    $s['stale'] = $s['status'] === 'ERROR' && $s['last_success_at'] !== null;
    return $s;
}

// ---------------------------------------------------------------- CG 인스턴스

function instance_get(int $id): array
{
    $row = db_one('SELECT * FROM cg_instances WHERE id = ?', [$id]);
    if ($row === null) {
        throw new ActionError('NOT_FOUND', 'CG를 찾을 수 없습니다.', 404);
    }
    $row['id'] = (int)$row['id'];
    $row['params'] = json_dec($row['params_json']);
    $row['auto'] = $row['auto_json'] === null ? null : json_dec($row['auto_json']);
    $row['issues'] = ($row['issues_json'] ?? null) === null ? [] : json_dec($row['issues_json']);
    return $row;
}

/** 같은 템플릿·파라미터면 기존 인스턴스를, 없으면 새로 만든다. */
function instance_for(string $slug, array $params, ?array $ds): array
{
    $key = params_key($params);
    $id = db_value('SELECT id FROM cg_instances WHERE template = ? AND params_key = ?', [$slug, $key]);
    if ($id !== null) {
        return instance_get((int)$id);
    }
    $now = now();
    $auto = $ds === null ? null : template_auto($slug, $params, $ds);
    $issues = $ds === null ? [] : template_issues($slug, $params, $ds);
    db_exec(
        'INSERT INTO cg_instances (template, params_key, params_json, auto_json, auto_source, auto_at, created_at, updated_at,
            issues_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$slug, $key, json_enc($params), $auto === null ? null : json_enc($auto), $ds['source'] ?? null,
            $ds === null ? null : $now, $now, $now, json_enc($issues)]
    );
    return instance_get(db_last_id());
}

/** @return array<string, array{value:mixed, auto_at_set:mixed, keep:bool}> */
function overrides_for(int $instanceId, int $sessionId): array
{
    $out = [];
    foreach (db_all('SELECT field, value_json, auto_at_set_json, keep_next FROM cg_overrides
        WHERE session_id = ? AND instance_id = ?', [$sessionId, $instanceId]) as $r) {
        $out[$r['field']] = [
            'value' => json_dec($r['value_json']),
            'auto_at_set' => json_dec($r['auto_at_set_json']),
            'keep' => (int)$r['keep_next'] === 1,
        ];
    }
    return $out;
}

/** 인스턴스의 현재 AUTO/MANUAL/FINAL과 송출 문자열 */
function instance_state(array $inst, int $sessionId): array
{
    $tpl = template_get($inst['template']);
    $merged = ov_merge($tpl['fields'], $inst['auto'], overrides_for($inst['id'], $sessionId));
    $final = ov_final($merged);
    $problems = template_problems($inst['template'], $final, $inst['params'], $inst['issues'], manual_keys($merged));
    $mock = $inst['auto_source'] === 'mock';
    return [
        'tpl' => $tpl,
        'merged' => $merged,
        'final' => $final,
        'problems' => $problems,
        'mock' => $mock,
        'view' => $problems ? null : template_present($inst['template'], $final, $inst['params'], $mock),
    ];
}

// ---------------------------------------------------------------- 페이지 리스트

function rundown_rows(): array
{
    return db_all('SELECT r.id, r.page_no, r.instance_id, r.sort, r.label, i.template, i.params_json
        FROM cg_rundown r JOIN cg_instances i ON i.id = r.instance_id ORDER BY r.sort, r.id');
}

function rundown_get(int $id): array
{
    $row = db_one('SELECT * FROM cg_rundown WHERE id = ?', [$id]);
    if ($row === null) {
        throw new ActionError('NOT_FOUND', '페이지를 찾을 수 없습니다.', 404);
    }
    return $row;
}

function page_label(mixed $v): string
{
    $s = trim((string)$v);
    if (mb_strlen($s) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $s)) {
        throw new ActionError('BAD_LABEL', '메모는 100자 이내로 입력하세요.', 422);
    }
    return $s;
}

function page_no_check(mixed $v, ?int $exceptId = null): int
{
    if (!is_int($v) && !(is_string($v) && preg_match('/^\d{1,3}$/D', $v))) {
        throw new ActionError('BAD_PAGE_NO', '페이지 번호는 1~999 사이 숫자입니다.', 422);
    }
    $n = (int)$v;
    if ($n < 1 || $n > 999) {
        throw new ActionError('BAD_PAGE_NO', '페이지 번호는 1~999 사이 숫자입니다.', 422);
    }
    $other = db_value('SELECT id FROM cg_rundown WHERE page_no = ?', [$n]);
    if ($other !== null && (int)$other !== $exceptId) {
        throw new ActionError('PAGE_NO_TAKEN', "$n 번 페이지가 이미 있습니다.", 409);
    }
    return $n;
}

/** 페이지 추가 입력 확인. 데이터를 먼저 불러와야 선수를 고를 수 있다. */
function page_input(array $in): array
{
    $slug = (string)($in['template'] ?? '');
    template_get($slug);
    $players = players_cache();
    if (!$players) {
        throw new ActionError('NO_DATA', '선수 목록이 없습니다. 데이터 새로고침을 먼저 하세요.', 409);
    }
    return [$slug, template_params($slug, (array)($in['params'] ?? []), $players, template_ctx())];
}

/**
 * 새 인스턴스의 AUTO 계산용: 마지막 정상 데이터(캐시). 네트워크에 접속하지 않는다.
 * 캐시가 없으면 null (값 없음 상태로 만들고 새로고침으로 채움). MOCK은 로컬 파일이라 바로 읽는다.
 */
function dataset_or_null(): ?array
{
    $ds = dataset_cache_get(data_source());
    if ($ds === null && data_source() === 'mock') {
        try {
            $ds = provider_load('mock');
        } catch (ProviderError) {
            return null;
        }
    }
    return $ds === null ? null : dataset_with_player_info($ds);
}

function page_add(array $in, array $op): array
{
    [$slug, $params] = page_input($in);
    $label = page_label($in['label'] ?? '');
    $ds = dataset_or_null();
    return db_tx(function () use ($slug, $params, $label, $in, $ds, $op) {
        $pageNo = isset($in['page_no']) && $in['page_no'] !== '' && $in['page_no'] !== null
            ? page_no_check($in['page_no'])
            : (int)db_value('SELECT COALESCE(MAX(page_no), 0) FROM cg_rundown') + 1;
        if ($pageNo > 999) {
            throw new ActionError('BAD_PAGE_NO', '페이지 번호가 999를 넘습니다. 번호를 직접 지정하세요.', 422);
        }
        $inst = instance_for($slug, $params, $ds);
        $sort = (int)db_value('SELECT COALESCE(MAX(sort), 0) FROM cg_rundown') + 1;
        db_exec('INSERT INTO cg_rundown (page_no, instance_id, sort, label, created_at) VALUES (?, ?, ?, ?, ?)',
            [$pageNo, $inst['id'], $sort, $label, now()]);
        $id = db_last_id();
        cg_log('broadcast', 'PAGE_ADD', $op, ['instance_id' => $inst['id'], 'template' => $slug,
            'detail' => sprintf('%03d %s', $pageNo, template_summary($slug, $params))]);
        $kinds = [];
        if (channel_get('preview')['rundown_id'] === null) {
            preview_set($id, $inst['id']);
            $kinds[] = 'preview';
        }
        state_bump($kinds);
        return ['id' => $id, 'page_no' => $pageNo];
    });
}

/** 페이지 수정: 선수·종족(파라미터)·메모·번호. 파라미터가 바뀌면 다른 인스턴스로 연결된다. */
function page_update(int $id, array $in, array $op): array
{
    $row = rundown_get($id);
    $inst = instance_get((int)$row['instance_id']);
    [$slug, $params] = isset($in['params'])
        ? page_input(array_replace($in, ['template' => $inst['template']]))
        : [$inst['template'], $inst['params']];
    $label = array_key_exists('label', $in) ? page_label($in['label']) : $row['label'];
    $ds = params_key($params) === $inst['params_key'] ? null : dataset_or_null();
    return db_tx(function () use ($id, $row, $slug, $params, $label, $in, $ds, $op) {
        $pageNo = isset($in['page_no']) ? page_no_check($in['page_no'], $id) : (int)$row['page_no'];
        $inst = instance_for($slug, $params, $ds);
        db_exec('UPDATE cg_rundown SET page_no = ?, instance_id = ?, label = ? WHERE id = ?', [$pageNo, $inst['id'], $label, $id]);
        cg_log('broadcast', 'PAGE_EDIT', $op, ['instance_id' => $inst['id'], 'template' => $slug,
            'detail' => sprintf('%03d %s', $pageNo, template_summary($slug, $params))]);
        $kinds = [];
        if (channel_get('preview')['rundown_id'] === $id) {
            preview_set($id, $inst['id']);
            $kinds[] = 'preview';
        }
        state_bump($kinds);
        return ['id' => $id, 'page_no' => $pageNo];
    });
}

function page_copy(int $id, array $op): array
{
    $row = rundown_get($id);
    $inst = instance_get((int)$row['instance_id']);
    return page_add(['template' => $inst['template'], 'params' => $inst['params'], 'label' => $row['label']], $op);
}

function page_remove(int $id, array $op): void
{
    db_tx(function () use ($id, $op) {
        $row = rundown_get($id);
        db_exec('DELETE FROM cg_rundown WHERE id = ?', [$id]);
        cg_log('broadcast', 'PAGE_REMOVE', $op, ['instance_id' => (int)$row['instance_id'],
            'detail' => sprintf('%03d %s', $row['page_no'], $row['label'])]);
        $kinds = [];
        if (channel_get('preview')['rundown_id'] === $id) {
            preview_set(null, null);
            $kinds[] = 'preview';
        }
        state_bump($kinds);
    });
}

/** 순서 한 칸 이동 (dir: -1 위, +1 아래) */
function page_move(int $id, int $dir): void
{
    db_tx(function () use ($id, $dir) {
        $rows = array_map(static fn($r) => (int)$r['id'], rundown_rows());
        $i = array_search($id, $rows, true);
        if ($i === false) {
            throw new ActionError('NOT_FOUND', '페이지를 찾을 수 없습니다.', 404);
        }
        $j = $i + ($dir < 0 ? -1 : 1);
        if (!isset($rows[$j])) {
            return;
        }
        [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
        foreach ($rows as $n => $rid) {
            db_exec('UPDATE cg_rundown SET sort = ? WHERE id = ?', [$n + 1, $rid]);
        }
        state_bump();
    });
}

// ---------------------------------------------------------------- PREVIEW

function preview_set(?int $rundownId, ?int $instanceId, int $layer = 1): void
{
    db_exec("UPDATE cg_channels SET rundown_id = ?, instance_id = ? WHERE layer = ? AND kind = 'preview'",
        [$rundownId, $instanceId, $layer]);
}

/** 페이지 번호로 PREVIEW에 큐 */
function cue_page(int $pageNo): array
{
    return db_tx(function () use ($pageNo) {
        $row = db_one('SELECT * FROM cg_rundown WHERE page_no = ?', [$pageNo]);
        if ($row === null) {
            throw new ActionError('NO_PAGE', sprintf('%03d 번 페이지가 없습니다.', $pageNo), 404);
        }
        preview_set((int)$row['id'], (int)$row['instance_id']);
        state_bump(['preview']);
        return ['page_no' => (int)$row['page_no']];
    });
}

/** 리스트에서 이전/다음 페이지를 PREVIEW에 큐 (NEXT). 끝이면 그대로 둔다. */
function cue_step(int $dir): ?array
{
    return db_tx(function () use ($dir) {
        $rows = rundown_rows();
        if (!$rows) {
            throw new ActionError('NO_PAGE', '페이지 리스트가 비어 있습니다.', 404);
        }
        $cur = channel_get('preview')['rundown_id'];
        $ids = array_map(static fn($r) => (int)$r['id'], $rows);
        $i = $cur === null ? false : array_search($cur, $ids, true);
        $j = $i === false ? ($dir < 0 ? count($rows) - 1 : 0) : $i + ($dir < 0 ? -1 : 1);
        if (!isset($rows[$j])) {
            return null;
        }
        preview_set((int)$rows[$j]['id'], (int)$rows[$j]['instance_id']);
        state_bump(['preview']);
        return ['page_no' => (int)$rows[$j]['page_no']];
    });
}

/** 위치·크기. PREVIEW에 바로 반영되고, PROGRAM에는 다음 TAKE부터 적용된다. */
function preview_display(array $in, array $op): void
{
    $num = static function ($v, int $min, int $max, string $label): int {
        if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d{1,4}$/D', $v))) {
            throw new ActionError('BAD_DISPLAY', "$label: 숫자를 입력하세요.", 422);
        }
        $n = (int)$v;
        if ($n < $min || $n > $max) {
            throw new ActionError('BAD_DISPLAY', "$label: $min~$max 사이로 입력하세요.", 422);
        }
        return $n;
    };
    $d = [
        'right' => $num($in['right'] ?? 0, -500, 1920, '오른쪽 여백'),
        'bottom' => $num($in['bottom'] ?? 4, -500, 1080, '아래 여백'),
        'scale_pct' => $num($in['scale_pct'] ?? 100, 50, 200, '크기(%)'),
    ];
    db_tx(function () use ($d, $op) {
        db_exec("UPDATE cg_channels SET display_json = ? WHERE layer = 1 AND kind = 'preview'", [json_enc($d)]);
        cg_log('broadcast', 'DISPLAY', $op, ['detail' => "right {$d['right']}, bottom {$d['bottom']}, {$d['scale_pct']}%"]);
        state_bump(['preview']);
    });
}

// ---------------------------------------------------------------- PROGRAM

/**
 * TAKE: 확인한 PREVIEW를 송출 스냅샷으로 고정해 PROGRAM에 보내고 표시한다.
 * expectedPreviewRev가 다르면(그 사이 PREVIEW가 바뀜) 거부한다.
 */
function program_take(int $expectedPreviewRev, array $opts, array $op): array
{
    $effect = in_array($opts['effect'] ?? null, EFFECTS, true) ? $opts['effect'] : 'slide';
    $dur = $effect === 'cut' ? 0 : max(100, min(2000, (int)($opts['dur_ms'] ?? 350)));
    return db_tx(function () use ($expectedPreviewRev, $effect, $dur, $opts, $op) {
        $pv = channel_get('preview');
        if ($pv['rev'] !== $expectedPreviewRev) {
            throw new ActionError('PREVIEW_CHANGED', 'PREVIEW가 방금 바뀌었습니다. 화면을 확인하고 다시 TAKE 하세요.', 409);
        }
        if ($pv['instance_id'] === null) {
            throw new ActionError('PREVIEW_EMPTY', 'PREVIEW에 큐된 페이지가 없습니다.', 409);
        }
        $inst = instance_get($pv['instance_id']);
        $sid = current_session_id();
        $st = instance_state($inst, $sid);
        if ($st['problems']) {
            throw new ActionError('NOT_SENDABLE', '값이 비어 있어 송출할 수 없습니다: ' . implode(' ', $st['problems']), 422);
        }
        $pg = channel_get('program');
        $row = $pv['rundown_id'] === null ? null : db_one('SELECT * FROM cg_rundown WHERE id = ?', [$pv['rundown_id']]);
        $snap = [
            'take_id' => $pg['take_id'] + 1,
            'session_id' => $sid,
            'rundown_id' => $pv['rundown_id'],
            'page_no' => $row === null ? null : (int)$row['page_no'],
            'label' => $row['label'] ?? '',
            'instance_id' => $inst['id'],
            'template' => $inst['template'],
            'params' => $inst['params'],
            'final' => $st['final'],
            'view' => $st['view'],
            'display' => $pv['display'],
            'effect' => $effect,
            'dur_ms' => $dur,
            'mock' => $st['mock'],
            'taken_at' => now(),
        ];
        db_exec("UPDATE cg_channels SET rundown_id = ?, instance_id = ?, snapshot_json = ?, visible = 1, take_id = ?
            WHERE layer = 1 AND kind = 'program'", [$pv['rundown_id'], $inst['id'], json_enc($snap), $snap['take_id']]);
        cg_log('broadcast', 'TAKE', $op, ['instance_id' => $inst['id'], 'template' => $inst['template'],
            'detail' => sprintf('%s %s · %s', $snap['page_no'] === null ? '---' : sprintf('%03d', $snap['page_no']),
                $snap['view']['title'], $effect === 'cut' ? 'CUT' : strtoupper($effect) . " {$dur}ms")]);
        $kinds = ['program'];
        if (!empty($opts['auto_next'])) {
            cue_step(1);
        }
        state_bump($kinds);
        return ['take_id' => $snap['take_id']];
    });
}

/** SHOW / OUT: 현재 PROGRAM 표시 여부만 바꾼다. PREVIEW를 보내지 않고 데이터도 지우지 않는다. */
function program_visibility(bool $show, array $op): void
{
    db_tx(function () use ($show, $op) {
        $pg = channel_get('program');
        if ($pg['snapshot'] === null) {
            throw new ActionError('PROGRAM_EMPTY', '송출 중인 CG가 없습니다. 먼저 TAKE 하세요.', 409);
        }
        if ($pg['visible'] === (int)$show) {
            return;
        }
        db_exec("UPDATE cg_channels SET visible = ? WHERE layer = 1 AND kind = 'program'", [(int)$show]);
        cg_log('broadcast', $show ? 'SHOW' : 'OUT', $op, ['instance_id' => $pg['instance_id'],
            'detail' => $pg['snapshot']['view']['title'] ?? '']);
        state_bump(['program']);
    });
}

// ---------------------------------------------------------------- 데이터 갱신

/**
 * 데이터 새로고침: 지금 소스(MOCK 또는 Google 시트)에서 불러와 반영한다. $dataset을 주면(xlsx 가져오기·테스트) 그것을 쓴다.
 * 실패하면 마지막 정상 AUTO를 그대로 두고 소스 상태만 ERROR로 바꾼다. 수동값과 PROGRAM은 절대 바꾸지 않는다.
 */
function data_refresh(array $op, ?array $dataset = null): array
{
    $now = now();
    $source = data_source();
    try {
        $ds = $dataset ?? provider_load($source);
    } catch (ProviderError $e) {
        $detail = $e->getMessage() . ($e->problems ? ': ' . implode(' / ', array_slice($e->problems, 0, 5)) : '');
        db_tx(function () use ($now, $detail, $op, $source) {
            db_exec("UPDATE cg_sources SET status = 'ERROR', last_attempt_at = ?, last_error = ? WHERE id = ?", [$now, $detail, $source]);
            cg_log('error', 'REFRESH_FAIL', $op, ['detail' => $detail]);
            state_bump();
        });
        throw new ActionError('SOURCE_ERROR', '데이터를 불러오지 못했습니다. 마지막 정상 데이터를 유지합니다. (' . $detail . ')', 502);
    }
    return data_apply($ds, $op, true);
}

/**
 * 데이터 반영: 캐시·선수 목록 저장, 모든 CG 인스턴스의 AUTO와 검증 사유를 다시 계산한다.
 * $fetched = false (닉네임 변경 등)이면 새로 불러온 것이 아니므로 캐시·소스 상태는 그대로 둔다.
 */
function data_apply(array $ds, array $op, bool $fetched): array
{
    $now = now();
    $ds = dataset_with_player_info($ds);
    return db_tx(function () use ($ds, $now, $op, $fetched) {
        if ($fetched) {
            dataset_cache_put($ds, $now);
            setting_set('data_check', json_enc(data_check_summary($ds, $now)));
        }
        $players = [];
        foreach ($ds['players'] as $id => $p) {
            $players[$id] = ['id' => (string)$id, 'name' => $p['name'], 'race' => $p['race']];
        }
        setting_set('players_cache', json_enc($players));
        // 페이지 추가 대화상자·파라미터 검사용: 승자 예측의 중계진 목록과 예측 기록이 있는 연도
        setting_set('predictors_cache', json_enc(array_map(static fn($p) => ['id' => (string)$p['id'], 'name' => $p['name']],
            $ds['predictors'] ?? [])));
        setting_set('years_cache', json_enc(stats_prediction_years($ds['predictions'] ?? [])));
        $pv = channel_get('preview');
        $changed = [];
        foreach (db_all('SELECT id FROM cg_instances ORDER BY id') as $r) {
            $inst = instance_get((int)$r['id']);
            $auto = template_auto($inst['template'], $inst['params'], $ds);
            $issues = template_issues($inst['template'], $inst['params'], $ds);
            if ($inst['auto'] !== $auto || $inst['issues'] !== $issues) {
                $changed[] = $inst['id'];
            }
            if ($inst['auto'] !== $auto) {
                cg_log('data', 'AUTO_CHANGED', $op, ['instance_id' => $inst['id'], 'template' => $inst['template'],
                    'prev' => $inst['auto'], 'new' => $auto]);
            }
            db_exec('UPDATE cg_instances SET auto_json = ?, issues_json = ?, auto_source = ?, auto_at = ?, updated_at = ? WHERE id = ?',
                [json_enc($auto), json_enc($issues), $ds['source'], $now, $now, $inst['id']]);
        }
        if ($fetched) {
            db_exec("UPDATE cg_sources SET status = 'OK', last_attempt_at = ?, last_success_at = ?, last_error = NULL
                WHERE id = ?", [$now, $now, $ds['source']]);
            $c = $ds['check'] ?? null;
            cg_log('data', 'REFRESH', $op, ['detail' => 'AUTO 변경 ' . count($changed) . '건'
                . ($c === null ? '' : sprintf(' · 세트 %d · 끝장전 %d · 이상 %d · 불일치 %d', $c['counts']['games'],
                    $c['counts']['matches'], count($c['anomalies']), count($c['mismatches'])))]);
        }
        state_bump(in_array($pv['instance_id'], $changed, true) ? ['preview'] : []);
        return ['changed' => count($changed)];
    });
}

// ---------------------------------------------------------------- 수동 수정 (MANUAL OVERRIDE)

/**
 * 입력값 검증. 하나라도 틀리면 아무것도 저장하지 않는다.
 * @return array<string, mixed> 필드 => 저장할 값
 */
function override_parse(array $tpl, array $values): array
{
    if (!$values) {
        throw new ActionError('VALIDATION', '저장할 수정값이 없습니다.', 422);
    }
    $parsed = [];
    $errors = [];
    foreach ($values as $key => $raw) {
        $def = $tpl['fields'][$key] ?? null;
        if ($def === null) {
            $errors[(string)$key] = '없는 항목입니다.';
            continue;
        }
        $r = field_parse($def, $raw);
        if ($r['ok']) {
            $parsed[$key] = $r['value'];
        } else {
            $errors[$key] = field_label($def) . ': ' . $r['error'];
        }
    }
    if ($errors) {
        throw new ActionError('VALIDATION', implode(' ', $errors), 422, $errors);
    }
    return $parsed;
}

/** 수정값 저장 (트랜잭션 안에서). 원본·AUTO는 바꾸지 않는다. */
function override_store(array $inst, array $parsed, array $op, int $sid): void
{
    $st = instance_state($inst, $sid);
    $now = now();
    foreach ($parsed as $key => $value) {
        $f = $st['merged'][$key];
        $row = db_one('SELECT id, value_json FROM cg_overrides WHERE session_id = ? AND instance_id = ? AND field = ?',
            [$sid, $inst['id'], $key]);
        if ($row === null) {
            db_exec('INSERT INTO cg_overrides (session_id, instance_id, field, value_json, auto_at_set_json, keep_next,
                updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)',
                [$sid, $inst['id'], $key, json_enc($value), json_enc($f['auto']), mb_substr($op['name'], 0, 50), $now, $now]);
        } else {
            db_exec('UPDATE cg_overrides SET value_json = ?, auto_at_set_json = ?, updated_by = ?, updated_at = ? WHERE id = ?',
                [json_enc($value), json_enc($f['auto']), mb_substr($op['name'], 0, 50), $now, (int)$row['id']]);
        }
        cg_log('override', 'SET', $op, ['session_id' => $sid, 'instance_id' => $inst['id'], 'template' => $inst['template'],
            'field' => $key, 'auto' => $f['auto'], 'prev' => $f['has_manual'] ? $f['manual'] : null, 'new' => $value]);
    }
}

/** SAVE TO PREVIEW: PREVIEW에 큐된 CG의 수정값 저장. PROGRAM은 바뀌지 않는다. */
function preview_save(int $instanceId, array $values, array $op): array
{
    return db_tx(function () use ($instanceId, $values, $op) {
        if (channel_get('preview')['instance_id'] !== $instanceId) {
            throw new ActionError('PANEL_STALE', 'PREVIEW가 다른 페이지로 바뀌었습니다. 확인 후 다시 저장하세요.', 409);
        }
        $inst = instance_get($instanceId);
        $parsed = override_parse(template_get($inst['template']), $values);
        override_store($inst, $parsed, $op, current_session_id());
        state_bump(['preview']);
        return ['saved' => array_keys($parsed)];
    });
}

/** RESET TO AUTO: 한 필드 또는 이 CG의 모든 수정값을 지운다. PREVIEW만 바뀐다. */
function override_reset(int $instanceId, ?string $field, array $op): array
{
    return db_tx(function () use ($instanceId, $field, $op) {
        $inst = instance_get($instanceId);
        $sid = current_session_id();
        $st = instance_state($inst, $sid);
        $keys = $field === null ? array_keys($st['merged']) : [$field];
        $done = [];
        foreach ($keys as $key) {
            if (!isset($st['merged'][$key])) {
                throw new ActionError('VALIDATION', '없는 항목입니다.', 422);
            }
            $f = $st['merged'][$key];
            if (!$f['has_manual']) {
                continue;
            }
            db_exec('DELETE FROM cg_overrides WHERE session_id = ? AND instance_id = ? AND field = ?', [$sid, $inst['id'], $key]);
            cg_log('override', 'RESET', $op, ['session_id' => $sid, 'instance_id' => $inst['id'], 'template' => $inst['template'],
                'field' => $key, 'auto' => $f['auto'], 'prev' => $f['manual'], 'new' => null]);
            $done[] = $key;
        }
        state_bump(channel_get('preview')['instance_id'] === $inst['id'] ? ['preview'] : []);
        return ['reset' => $done];
    });
}

/** KEEP OVERRIDE: 새 방송 세션에도 이 수정값을 유지할지 표시 */
function override_keep(int $instanceId, string $field, bool $keep, array $op): void
{
    db_tx(function () use ($instanceId, $field, $keep, $op) {
        $sid = current_session_id();
        $row = db_one('SELECT id FROM cg_overrides WHERE session_id = ? AND instance_id = ? AND field = ?', [$sid, $instanceId, $field]);
        if ($row === null) {
            throw new ActionError('NO_OVERRIDE', '수정값이 있는 항목만 KEEP할 수 있습니다.', 409);
        }
        db_exec('UPDATE cg_overrides SET keep_next = ? WHERE id = ?', [(int)$keep, (int)$row['id']]);
        cg_log('override', 'KEEP', $op, ['session_id' => $sid, 'instance_id' => $instanceId, 'field' => $field,
            'detail' => $keep ? '다음 세션 유지' : '유지 해제']);
        state_bump();
    });
}

/**
 * UPDATE LIVE: 송출 중인 CG(PROGRAM)를 TAKE 없이 바로 수정한다.
 * - PREVIEW에 큐된 CG가 현재 PROGRAM과 같은 인스턴스여야 한다 (다른 CG를 덮어쓰지 않음).
 * - 입력값을 수정값으로 저장한 뒤, 수정값(MANUAL) 필드만 송출 스냅샷에 반영하고 승률을 다시 계산한다.
 *   자동 갱신으로 바뀐 AUTO 값은 반영하지 않는다. 표시 상태·위치·take_id는 유지한다.
 */
function program_update_live(int $instanceId, int $expectedTakeId, int $expectedPreviewRev, array $values, array $op): array
{
    $pg = channel_get('program');
    if ($pg['snapshot'] === null) {
        throw new ActionError('PROGRAM_EMPTY', '송출 중인 CG가 없습니다.', 409);
    }
    if ($pg['snapshot']['instance_id'] !== $instanceId) {
        db_tx(function () use ($instanceId, $pg, $op) {
            cg_log('broadcast', 'UPDATE_LIVE_REJECTED', $op, ['instance_id' => $instanceId,
                'detail' => '대상 불일치: PROGRAM은 ' . ($pg['snapshot']['view']['title'] ?? '') . ' (인스턴스 ' . $pg['snapshot']['instance_id'] . ')']);
            state_bump();
        });
        throw new ActionError('TARGET_MISMATCH', '수정하려는 CG가 현재 송출 중인 CG와 다릅니다. 적용하지 않았습니다.', 409);
    }
    return db_tx(function () use ($instanceId, $expectedTakeId, $expectedPreviewRev, $values, $op) {
        $pg = channel_get('program');
        $pv = channel_get('preview');
        if ($pg['take_id'] !== $expectedTakeId || $pg['snapshot']['instance_id'] !== $instanceId) {
            throw new ActionError('PROGRAM_CHANGED', '그 사이 송출 화면이 바뀌었습니다. 확인 후 다시 시도하세요.', 409);
        }
        if ($pv['rev'] !== $expectedPreviewRev || $pv['instance_id'] !== $instanceId) {
            throw new ActionError('PREVIEW_CHANGED', 'PREVIEW가 방금 바뀌었습니다. 확인 후 다시 시도하세요.', 409);
        }
        $inst = instance_get($instanceId);
        $sid = current_session_id();
        if ($values) {
            override_store($inst, override_parse(template_get($inst['template']), $values), $op, $sid);
        }
        $st = instance_state($inst, $sid);
        $snap = $pg['snapshot'];
        // 송출에 반영하는 값: 수정값(MANUAL, 이번 입력 포함) 중 송출값과 다른 것만.
        // 자동 갱신으로 바뀐 AUTO 값은 UPDATE LIVE로 내보내지 않는다 (의도하지 않은 값이 송출되지 않게, TAKE로만 반영).
        $apply = [];
        $manualDerived = [];
        foreach ($st['merged'] as $key => $f) {
            if (isset($st['tpl']['fields'][$key]['derived']) && $f['has_manual']) {
                $manualDerived[] = $key;
            }
            if ($f['has_manual'] && ($snap['final'][$key] ?? null) !== $f['final']) {
                $apply[$key] = $f['final'];
            }
        }
        $final = ov_apply_to_final($st['tpl']['fields'], $snap['final'], $apply, $manualDerived);
        $changed = array_keys(array_filter($final, static fn($v, $k) => ($snap['final'][$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        if (!$changed) {
            throw new ActionError('NO_CHANGE', '송출 중인 값과 같아서 바꿀 내용이 없습니다. (자동값 변경은 TAKE로 반영됩니다)', 409);
        }
        $problems = template_problems($inst['template'], $final, $inst['params'], $inst['issues'], manual_keys($st['merged']));
        if ($problems) {
            throw new ActionError('NOT_SENDABLE', '값이 비어 있어 송출할 수 없습니다: ' . implode(' ', $problems), 422);
        }
        $snap['final'] = $final;
        $snap['view'] = template_present($inst['template'], $final, $inst['params'], $st['mock']);
        $snap['updated_live_at'] = now();
        db_exec("UPDATE cg_channels SET snapshot_json = ? WHERE layer = 1 AND kind = 'program'", [json_enc($snap)]);
        cg_log('broadcast', 'UPDATE_LIVE', $op, ['session_id' => $sid, 'instance_id' => $instanceId, 'template' => $inst['template'],
            'detail' => '변경: ' . implode(', ', array_map(static fn($k) => field_label($st['tpl']['fields'][$k]), $changed))]);
        state_bump(['program', 'preview']);
        return ['changed' => $changed];
    });
}

// ---------------------------------------------------------------- 방송 세션

/** 새 방송 세션: 모든 CG가 AUTO로 시작하고 KEEP 표시한 수정값만 넘어간다. PROGRAM은 그대로. */
function session_start_new(string $name, array $op): array
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
        throw new ActionError('VALIDATION', '세션 이름을 100자 이내로 입력하세요.', 422);
    }
    return db_tx(function () use ($name, $op) {
        $old = current_session_id();
        $now = now();
        db_exec('UPDATE cg_sessions SET ended_at = ? WHERE id = ?', [$now, $old]);
        db_exec('INSERT INTO cg_sessions (name, started_at) VALUES (?, ?)', [$name, $now]);
        $new = db_last_id();
        setting_set('current_session_id', (string)$new);
        $keep = db_all('SELECT * FROM cg_overrides WHERE session_id = ? AND keep_next = 1', [$old]);
        foreach ($keep as $r) {
            db_exec('INSERT INTO cg_overrides (session_id, instance_id, field, value_json, auto_at_set_json, keep_next,
                updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)',
                [$new, (int)$r['instance_id'], $r['field'], $r['value_json'], $r['auto_at_set_json'], $r['updated_by'], $now, $now]);
        }
        cg_log('session', 'NEW_SESSION', $op, ['session_id' => $new, 'detail' => "$name · KEEP 이월 " . count($keep) . '개']);
        state_bump(['preview']);
        return ['session_id' => $new, 'carried' => count($keep)];
    });
}

// ---------------------------------------------------------------- 페이지 리스트 내보내기·가져오기

function rundown_export(): array
{
    $pages = [];
    foreach (rundown_rows() as $r) {
        $pages[] = ['page_no' => (int)$r['page_no'], 'label' => $r['label'], 'template' => $r['template'],
            'params' => json_dec($r['params_json'])];
    }
    return ['format' => 'endgame-cg-pages', 'version' => 1, 'app_version' => APP_VERSION, 'exported_at' => now(), 'pages' => $pages];
}

/** 현재 리스트 뒤에 추가한다. 번호가 겹치면 비어 있는 다음 번호를 쓴다. 하나라도 틀리면 아무것도 추가하지 않는다. */
function rundown_import(mixed $data, array $op): array
{
    if (!is_array($data) || ($data['format'] ?? '') !== 'endgame-cg-pages' || !is_array($data['pages'] ?? null)) {
        throw new ActionError('BAD_FILE', '끝장전 CG에서 내보낸 페이지 파일이 아닙니다.', 422);
    }
    if (count($data['pages']) > 300) {
        throw new ActionError('BAD_FILE', '한 번에 300개까지 가져올 수 있습니다.', 422);
    }
    $items = [];
    foreach ($data['pages'] as $i => $p) {
        try {
            [$slug, $params] = page_input(is_array($p) ? $p : []);
            $items[] = [$slug, $params, page_label($p['label'] ?? ''), (int)($p['page_no'] ?? 0)];
        } catch (ActionError $e) {
            throw new ActionError('BAD_FILE', ($i + 1) . '번째 페이지: ' . $e->getMessage(), 422);
        }
    }
    $ds = dataset_or_null();
    return db_tx(function () use ($items, $ds, $op) {
        $used = array_map('intval', array_column(db_all('SELECT page_no FROM cg_rundown'), 'page_no'));
        $sort = (int)db_value('SELECT COALESCE(MAX(sort), 0) FROM cg_rundown');
        foreach ($items as [$slug, $params, $label, $no]) {
            if ($no < 1 || $no > 999 || in_array($no, $used, true)) {
                $no = 1;
                while (in_array($no, $used, true)) {
                    $no++;
                }
                if ($no > 999) {
                    throw new ActionError('BAD_PAGE_NO', '빈 페이지 번호가 없습니다.', 422);
                }
            }
            $used[] = $no;
            $inst = instance_for($slug, $params, $ds);
            db_exec('INSERT INTO cg_rundown (page_no, instance_id, sort, label, created_at) VALUES (?, ?, ?, ?, ?)',
                [$no, $inst['id'], ++$sort, $label, now()]);
        }
        cg_log('broadcast', 'PAGE_IMPORT', $op, ['detail' => count($items) . '개 페이지']);
        state_bump();
        return ['added' => count($items)];
    });
}
