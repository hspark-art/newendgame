<?php
declare(strict_types=1);

/**
 * 화면에 내려보내는 상태: 조작 패널(panel_state)과 송출 화면(output_payload).
 */

/** 입력칸에 채울 문자열 */
function fmt_input(array $def, mixed $v): string
{
    if ($v === null) {
        return '';
    }
    return match ($def['type']) {
        'rate' => fmt_rate((int)$v),
        'srate' => fmt_srate((int)$v),
        default => (string)$v,
    };
}

/** default_next 파라미터(자유 입력의 양식 번호): 페이지 리스트에서 쓰지 않은 다음 번호를 기본값으로 */
function param_default_next(string $slug, array $p, array $rundown): array
{
    if (empty($p['default_next'])) {
        return $p;
    }
    $used = array_map(static fn($r) => (int)($r['params'][$p['key']] ?? 0),
        array_filter($rundown, static fn($r) => $r['template'] === $slug));
    $p['default'] = min($p['max'], max([0, ...$used]) + 1);
    return $p;
}

/** 송출값 비교: 키 순서와 관계없이 같은 값인지 (버전이 바뀌어 필드 순서가 달라져도 '송출값과 다름'으로 남지 않게) */
function finals_same(?array $a, ?array $b): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    ksort($a);
    ksort($b);
    return $a === $b;
}

function panel_state(array $op): array
{
    $sid = current_session_id();
    $ctx = template_ctx();
    $players = $ctx['players'];
    $pv = channel_get('preview');
    $pg = channel_get('program');
    $snap = $pg['snapshot'];

    $manual = [];
    foreach (db_all('SELECT instance_id, COUNT(*) AS c FROM cg_overrides WHERE session_id = ? GROUP BY instance_id', [$sid]) as $r) {
        $manual[(int)$r['instance_id']] = (int)$r['c'];
    }
    $liveFinal = null;
    if ($snap !== null) {
        $liveFinal = instance_state(instance_get($snap['instance_id']), $sid)['final'];
    }
    $rundown = [];
    foreach (rundown_rows() as $r) {
        $tpl = template_get($r['template']);
        $iid = (int)$r['instance_id'];
        $inProgram = $snap !== null && $pg['rundown_id'] === (int)$r['id'];
        $rundown[] = [
            'id' => (int)$r['id'],
            'page_no' => (int)$r['page_no'],
            'label' => $r['label'],
            'template' => $r['template'],
            'short' => $tpl['short'],
            'summary' => template_summary($r['template'], json_dec($r['params_json']), $ctx),
            'params' => json_dec($r['params_json']),
            'instance_id' => $iid,
            'manual' => $manual[$iid] ?? 0,
            'cued' => $pv['rundown_id'] === (int)$r['id'],
            'in_program' => $inProgram,
            'on_air' => $inProgram && $pg['visible'] === 1,
            // 같은 CG가 송출 중인데 현재 값(FINAL)이 송출값과 다름 → TAKE 또는 UPDATE LIVE 필요
            'pending_live' => $snap !== null && $snap['instance_id'] === $iid && !finals_same($liveFinal, $snap['final']),
        ];
    }

    $preview = [
        'rev' => $pv['rev'], 'rundown_id' => $pv['rundown_id'], 'instance_id' => $pv['instance_id'],
        'display' => $pv['display'], 'fields' => [], 'problems' => [], 'page_no' => null, 'label' => '',
        'template' => null, 'template_name' => '', 'summary' => '', 'mock' => false,
    ];
    if ($pv['instance_id'] !== null) {
        $inst = instance_get($pv['instance_id']);
        $st = instance_state($inst, $sid);
        $row = $pv['rundown_id'] === null ? null : db_one('SELECT * FROM cg_rundown WHERE id = ?', [$pv['rundown_id']]);
        $sameLive = $snap !== null && $snap['instance_id'] === $inst['id'];
        foreach ($st['merged'] as $key => $f) {
            $def = $st['tpl']['fields'][$key];
            $live = $sameLive ? ($snap['final'][$key] ?? null) : null;
            $hidden = in_array($key, $st['hidden'], true);
            $preview['fields'][] = [
                'key' => $key, 'label' => $def['label'], 'group' => $def['group'] ?? '', 'type' => $def['type'],
                'hidden' => $hidden,
                'derived' => isset($def['derived']),
                'has_manual' => $f['has_manual'], 'origin' => $f['origin'], 'differs' => $f['differs'],
                'auto_changed' => $f['auto_changed'], 'keep' => $f['keep'],
                'auto_text' => fmt_field($def, $f['auto']),
                'manual_text' => $f['has_manual'] ? fmt_input($def, $f['manual']) : '',
                'final_text' => $hidden ? '빠짐' : fmt_field($def, $f['final']),
                'calc_text' => isset($def['derived']) ? fmt_field($def, $f['calc']) : '',
                'auto_at_set_text' => $f['auto_changed'] ? fmt_field($def, $f['auto_at_set']) : '',
                'live_text' => $sameLive ? fmt_field($def, $live) : null,
                'live_differs' => $sameLive && $live !== $st['final'][$key],
            ];
        }
        $preview = array_merge($preview, [
            'page_no' => $row === null ? null : (int)$row['page_no'],
            'label' => $row['label'] ?? '',
            'template' => $inst['template'],
            'template_name' => $st['tpl']['name'],
            'summary' => template_summary($inst['template'], $inst['params'], $ctx),
            'problems' => $st['problems'],
            'mock' => $st['mock'],
            'auto_missing' => $inst['auto'] === null,
        ]);
    }

    $program = [
        'rev' => $pg['rev'], 'take_id' => $pg['take_id'], 'visible' => $pg['visible'] === 1, 'empty' => $snap === null,
        'instance_id' => $snap['instance_id'] ?? null, 'page_no' => $snap['page_no'] ?? null,
        'label' => $snap['label'] ?? '', 'title' => $snap['view']['title'] ?? '',
        'taken_at' => $snap['taken_at'] ?? null, 'taken_ts' => isset($snap['taken_at']) ? strtotime($snap['taken_at']) : null,
        'effect' => $snap['effect'] ?? null, 'dur_ms' => $snap['dur_ms'] ?? null,
        'same_target' => $snap !== null && $pv['instance_id'] === $snap['instance_id'],
        'pending_live' => $snap !== null && !finals_same($liveFinal, $snap['final']),
    ];
    // UPDATE LIVE로 보낼 수 있는 저장된 수정값·항목 빼기가 있는지 (자동값 변경은 TAKE로만)
    $program['live_manual'] = $program['same_target']
        && (bool)array_filter($preview['fields'], static fn($f) => $f['live_differs'] && ($f['has_manual'] || $f['hidden']
            || ($snap !== null && in_array($f['key'], $snap['hidden'] ?? [], true))));

    $session = db_one('SELECT id, name, started_at FROM cg_sessions WHERE id = ?', [$sid]);
    $session['id'] = (int)$session['id'];
    return [
        'ok' => true,
        'rev' => (int)setting_get('state_rev', '0'),
        'server_ts' => time(),
        'mode' => config('mode'),
        'version' => APP_VERSION,
        'operator' => ['name' => $op['name'], 'role' => $op['role']],
        'alerts_new' => $op['role'] === 'admin' ? alerts_new_count() : null, // 관리자 알림 (확인하지 않은 것)
        'session' => $session,
        'keep_count' => (int)db_value('SELECT COUNT(*) FROM cg_overrides WHERE session_id = ? AND keep_next = 1', [$sid]),
        'source' => source_status(),
        // 이전 버전에서 올린 뒤 아직 새로고침하지 않아 예측자·연도 목록이 없음 → 패널이 한 번 새로고침한다
        // v0.6: 맵 목록에 최근 사용 정보(recent)가 없으면 이전 버전 캐시 → 한 번 새로고침해 최근 맵 순서로
        'caches_ready' => setting_get('years_cache') !== null && setting_get('maps_cache') !== null
            && ($ctx['maps'] === [] || isset(array_values($ctx['maps'])[0]['recent'])),
        // ready: 시트 주소·키가 등록되어 자동 새로고침을 할 수 있음 (아니면 패널은 "시트 연결 필요"만 표시)
        'data' => ['source' => data_source(), 'check' => json_dec(setting_get('data_check', 'null')), 'ready' => data_ready()],
        // 페이지 추가 대화상자는 템플릿의 params 정의로 입력칸을 만든다
        'templates' => array_map(static fn($t) => ['slug' => $t['slug'], 'name' => $t['name'], 'short' => $t['short'],
            'params' => array_map(static fn($p) => array_intersect_key(param_default_next($t['slug'], $p, $rundown),
                array_flip(['key', 'label', 'type', 'auto_from', 'default_value', 'min', 'max', 'default'])), $t['params'])],
            array_values(cg_templates())),
        'players' => array_values($players),
        'predictors' => array_values($ctx['predictors']),
        'years' => $ctx['years'],
        'maps' => array_values($ctx['maps']),
        'match' => match_view(),
        'rundown' => $rundown,
        'preview' => $preview,
        'program' => $program,
        'outputs' => output_urls() + ['seen' => output_seen(1)],
        'logs' => array_map('log_view', logs_recent(40)),
    ];
}

/** 로그 한 줄 표시: 수정 기록은 "A 승: 33(AUTO) → 34" 형식 */
function log_view(array $l): array
{
    $detail = (string)($l['detail'] ?? '');
    if ($l['field'] !== null) {
        $def = cg_templates()[$l['template']]['fields'][$l['field']] ?? ['label' => $l['field'], 'type' => 'text'];
        $val = static fn(?string $j) => $j === null || $j === 'null' ? null : json_dec($j);
        $prev = $val($l['prev_json']);
        $new = $val($l['new_json']);
        $auto = $val($l['auto_json']);
        $detail = field_label($def) . ': ' . ($prev === null ? 'AUTO ' . fmt_field($def, $auto) : fmt_field($def, $prev))
            . ' → ' . ($new === null ? 'AUTO ' . fmt_field($def, $auto) : fmt_field($def, $new));
    }
    return [
        'id' => (int)$l['id'], 'time' => substr($l['created_at'], 11), 'date' => substr($l['created_at'], 0, 10),
        'type' => $l['type'], 'action' => $l['action'], 'operator' => $l['operator'], 'detail' => $detail,
    ];
}

function output_urls(): array
{
    $base = base_url();
    $query = (is_web() ? 't=' . rawurlencode((string)setting_get('output_token')) . '&' : '') . 'layer=1';
    $lan = [];
    if (is_desktop() && getenv('CG_LAN') === '1') {
        $port = (string)($_SERVER['SERVER_PORT'] ?? '3100');
        foreach (gethostbynamel(gethostname()) ?: [] as $ip) {
            if (!str_starts_with($ip, '127.')) {
                $lan[] = "http://$ip:$port/output.php?layer=1";
            }
        }
    }
    return [
        'program' => "$base/output.php?$query",
        'lan' => $lan,
        'preview_monitor' => 'output.php?layer=1&ch=preview',
        'program_monitor' => 'output.php?layer=1&ch=program&ghost=1',
    ];
}

/** 송출 화면 상태. since가 현재 rev와 같으면 바뀐 것이 없다는 표시만 보낸다. */
function output_payload(string $kind, int $layer = 1, ?int $since = null): array
{
    $ch = channel_get($kind, $layer);
    if ($since !== null && $since === $ch['rev']) {
        return ['ok' => true, 'rev' => $ch['rev'], 'same' => true];
    }
    if ($kind === 'program') {
        $snap = $ch['snapshot'];
        return [
            'ok' => true, 'rev' => $ch['rev'], 'channel' => 'program', 'layer' => $layer,
            'take_id' => $ch['take_id'],
            'visible' => $snap !== null && $ch['visible'] === 1,
            'html' => $snap === null ? '' : cg_render($snap['view']),
            'display' => $snap['display'] ?? $ch['display'],
            'template' => $snap['template'] ?? null,
            'effect' => $snap['effect'] ?? 'slide',
            'dur_ms' => $snap['dur_ms'] ?? 350,
            'design' => design_payload(),
        ];
    }
    $html = '';
    if ($ch['instance_id'] !== null) {
        $st = instance_state(instance_get($ch['instance_id']), current_session_id());
        $html = $st['view'] === null
            ? '<div class="cg-empty">값이 비어 있어 송출할 수 없습니다</div>'
            : cg_render($st['view']);
    }
    return [
        'ok' => true, 'rev' => $ch['rev'], 'channel' => 'preview', 'layer' => $layer,
        // PREVIEW는 애니메이션 없이 바로 바꾼다. 인스턴스가 바뀌면 교체로 본다.
        'take_id' => $ch['instance_id'] ?? 0,
        'visible' => $html !== '',
        'html' => $html,
        'display' => $ch['display'],
        'template' => null,
        'effect' => 'cut',
        'dur_ms' => 0,
        'design' => design_payload(),
    ];
}

/** 송출 화면 연결 확인용 기록 (클라이언트별 최근 수신 시각, 60초 지나면 지움) */
function output_heartbeat(string $client, int $layer = 1): void
{
    if (!preg_match('/^[a-z0-9]{8,32}$/D', $client)) {
        return;
    }
    db_tx(function () use ($client, $layer) {
        $key = "output_seen_$layer";
        $map = json_dec(setting_get($key, '{}')) ?: [];
        $now = time();
        $map[$client] = $now;
        $map = array_filter($map, static fn($t) => $t >= $now - 60);
        arsort($map);
        setting_set($key, json_enc(array_slice($map, 0, 20, true)));
    });
}

/** @return array{count:int, last:?int} 최근 15초 안에 신호를 보낸 송출 화면 수 */
function output_seen(int $layer = 1): array
{
    $map = json_dec(setting_get("output_seen_$layer", '{}')) ?: [];
    $now = time();
    return [
        'count' => count(array_filter($map, static fn($t) => $t >= $now - 15)),
        'last' => $map ? max($map) : null,
    ];
}
