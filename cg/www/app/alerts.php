<?php
declare(strict_types=1);

/**
 * 관리자 알림: 데이터 오류(시트 집계 불일치·이상 경기·대조 불가·시트 입력 점검)와 새로고침 실패를 한 목록으로 모은다.
 * - 데이터를 반영할 때마다(새로고침·경기 제외 확정) 현재 문제 목록과 맞춘다. 새 문제 → 새 알림, 사라진 문제 → 해결됨.
 * - 관리자가 [확인]하면 "확인함"으로 옮긴다. 같은 문제라도 내용(값)이 바뀌거나 해결 뒤 다시 생기면 다시 새 알림이 된다.
 * - 화면(조작 패널·웹 관리자 화면)에만 알린다. 메일·메신저 등 밖으로 보내지 않는다.
 */

const ALERT_KINDS = [
    'mismatch' => '시트 집계와 다름',
    'anomaly' => '이상 경기',
    'unavailable' => '대조 불가',
    'lint' => '시트 입력 점검',
    'refresh' => '새로고침 실패',
];
const ALERT_KEEP_DAYS = 30; // 해결된 알림 보관 기간

function require_admin_alerts(array $op): void
{
    if (($op['role'] ?? '') !== 'admin') {
        throw new ActionError('ADMIN_ONLY', '알림은 관리자만 볼 수 있습니다.', 403);
    }
}

/**
 * 데이터 점검 결과 → 알림 항목. MOCK 데이터는 점검 대상이 아니므로 없음.
 * @return list<array{0:string, 1:string, 2:string, 3:string}> [종류, 같은 문제를 알아보는 값, 제목, 설명]
 */
function alert_items(array $ds): array
{
    $c = $ds['check'] ?? null;
    if ($c === null) {
        return [];
    }
    $items = [];
    foreach ($c['mismatches'] as $m) {
        $items[] = ['mismatch', "{$m['kind']}|{$m['who']}|{$m['item']}", "{$m['who']} {$m['item']}: 시트 {$m['sheet']} / 계산 {$m['calc']}",
            '이 수치를 쓰는 CG는 송출이 막힙니다. 시트를 고친 뒤 새로고침하거나, 확인한 값을 타이틀 에디터에 직접 입력하세요.'];
    }
    foreach ($c['anomalies'] as $a) {
        $items[] = ['anomaly', $a['text'], $a['text'], ($a['sub'] ?? '') === 'sets'
            ? '관련 선수의 끝장전 CG가 막혀 있습니다. 특별 경기라면 [데이터 점검·설정 → 끝장전 통계 제외 확정]을 누르세요.'
            : '관련 선수의 끝장전 CG가 막혀 있습니다. 시트를 고친 뒤 새로고침하세요.'];
    }
    foreach ($c['unavailable'] as $u) {
        $items[] = ['unavailable', $u, $u, '이 항목을 쓰는 CG는 시트와 대조할 수 없어 확인 전까지 송출이 막힐 수 있습니다. 탭 이름·머리글을 확인하세요.'];
    }
    foreach ($c['lint'] ?? [] as $l) {
        $items[] = ['lint', $l['text'], $l['text'], '프로그램은 공백을 지우고 읽었지만, 시트 자체 집계가 이 칸을 다르게 셀 수 있습니다.'];
    }
    return $items;
}

/** 알림 하나 기록. 새 알림이 되면 true */
function alert_upsert(string $kind, string $id, string $title, string $detail, string $now): bool
{
    $key = sha1("$kind|$id");
    $title = mb_substr($title, 0, 250);
    $row = db_one('SELECT id, title, detail, resolved_at FROM cg_alerts WHERE akey = ?', [$key]);
    if ($row === null) {
        db_exec('INSERT INTO cg_alerts (akey, kind, title, detail, first_at, last_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$key, $kind, $title, $detail, $now, $now]);
        return true;
    }
    if ($row['resolved_at'] !== null || $row['title'] !== $title || $row['detail'] !== $detail) {
        db_exec('UPDATE cg_alerts SET title = ?, detail = ?, first_at = ?, last_at = ?, resolved_at = NULL, acked_at = NULL,
            acked_by = NULL WHERE id = ?', [$title, $detail, $now, $now, $row['id']]);
        return true;
    }
    db_exec('UPDATE cg_alerts SET last_at = ? WHERE id = ?', [$now, $row['id']]);
    return false;
}

/** 데이터 반영 때: 지금 데이터의 문제 목록과 맞춘다 (새로고침 실패 알림은 따로) */
function alerts_sync(array $ds, string $now): void
{
    $seen = [];
    foreach (alert_items($ds) as [$kind, $id, $title, $detail]) {
        $seen[sha1("$kind|$id")] = true;
        alert_upsert($kind, $id, $title, $detail, $now);
    }
    foreach (db_all("SELECT id, akey FROM cg_alerts WHERE resolved_at IS NULL AND kind <> 'refresh'") as $r) {
        if (!isset($seen[$r['akey']])) {
            db_exec('UPDATE cg_alerts SET resolved_at = ? WHERE id = ?', [$now, $r['id']]);
        }
    }
    db_exec('DELETE FROM cg_alerts WHERE resolved_at IS NOT NULL AND resolved_at < ?',
        [date('Y-m-d H:i:s', strtotime($now) - ALERT_KEEP_DAYS * 86400)]);
}

/** 새로고침 실패 (마지막 정상 데이터는 유지됨) */
function alert_refresh_failed(string $source, string $detail, string $now): void
{
    alert_upsert('refresh', $source, '데이터 새로고침 실패 — ' . (data_sources()[$source] ?? $source),
        $detail . ' (마지막 정상 데이터와 송출 중인 CG는 그대로 유지됩니다)', $now);
}

function alert_refresh_ok(string $now): void
{
    db_exec("UPDATE cg_alerts SET resolved_at = ? WHERE kind = 'refresh' AND resolved_at IS NULL", [$now]);
}

/** 확인하지 않은 새 알림 수 */
function alerts_new_count(): int
{
    return (int)db_value('SELECT COUNT(*) FROM cg_alerts WHERE resolved_at IS NULL AND acked_at IS NULL');
}

/** 알림 목록: 새 알림 · 확인함 · 해결됨(최근 100건) */
function alerts_view(array $op): array
{
    require_admin_alerts($op);
    $row = static fn(array $r) => ['id' => (int)$r['id'], 'kind' => $r['kind'], 'kind_label' => ALERT_KINDS[$r['kind']] ?? $r['kind'],
        'title' => $r['title'], 'detail' => $r['detail'], 'first_at' => $r['first_at'], 'last_at' => $r['last_at'],
        'resolved_at' => $r['resolved_at'], 'acked_at' => $r['acked_at'], 'acked_by' => $r['acked_by']];
    $order = "CASE kind WHEN 'refresh' THEN 0 WHEN 'mismatch' THEN 1 WHEN 'anomaly' THEN 2 WHEN 'unavailable' THEN 3 ELSE 4 END, first_at DESC, id";
    return [
        'new' => array_map($row, db_all("SELECT * FROM cg_alerts WHERE resolved_at IS NULL AND acked_at IS NULL ORDER BY $order")),
        'acked' => array_map($row, db_all("SELECT * FROM cg_alerts WHERE resolved_at IS NULL AND acked_at IS NOT NULL ORDER BY $order")),
        'resolved' => array_map($row, db_all('SELECT * FROM cg_alerts WHERE resolved_at IS NOT NULL ORDER BY resolved_at DESC, id DESC LIMIT 100')),
        'keep_days' => ALERT_KEEP_DAYS,
    ];
}

/** [확인]: id 하나 또는 all=true면 새 알림 전부 */
function alert_ack(array $in, array $op): array
{
    require_admin_alerts($op);
    $now = now();
    db_tx(function () use ($in, $op, $now) {
        if (!empty($in['all'])) {
            db_exec('UPDATE cg_alerts SET acked_at = ?, acked_by = ? WHERE resolved_at IS NULL AND acked_at IS NULL', [$now, $op['name']]);
        } else {
            $id = in_int($in, 'id');
            db_exec('UPDATE cg_alerts SET acked_at = ?, acked_by = ? WHERE id = ? AND acked_at IS NULL', [$now, $op['name'], $id]);
        }
        state_bump();
    });
    return alerts_view($op);
}
