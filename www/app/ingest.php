<?php
/**
 * 채팅·후원 저장 (실시간 수집과 백업 파일 업로드가 함께 사용)
 *
 * 수집 화면이 보내는 이벤트 형식
 *   채팅: {uid, t, k:"c", u, n, m, kd, b}
 *   후원: {uid, t, k:"d", ty, st, u, n, a, tu, tn, x}
 *   uid = "수집창ID:순번"  → 같은 채팅이 두 번 저장되지 않게 막는 고유값
 *   t   = 받은 시각 (밀리초, 서버 시각 기준으로 보정된 값)
 *
 * 중복 방지
 *   1) 같은 수집창의 재전송·백업 재업로드 → uid 가 같으므로 저장 안 됨
 *   2) PC 두 대로 동시에 수집 → 다른 수집창에 같은 사람·같은 내용이 앞뒤 5초 안에 있으면 저장 안 됨
 */
declare(strict_types=1);

const DONATION_TYPES = [
    'balloon'      => ['normal', 'relay', 'video', 'mission', 'battle'],
    'adballoon'    => ['normal', 'station'],
    'subscription' => ['new', 'renew', 'gift', 'gift_random'],
];
const CROSS_COLLECTOR_WINDOW_MS = 5000;

function ms_to_datetime(int $ms): string
{
    return date('Y-m-d H:i:s', intdiv($ms, 1000)) . sprintf('.%03d', $ms % 1000);
}

function collector_id_from_uid(string $uid): string
{
    $pos = strpos($uid, ':');
    return $pos === false ? 'unknown' : substr($uid, 0, $pos);
}

/**
 * 이벤트 하나를 검사·정리합니다. 형식이 틀리면 null.
 */
function normalize_event(array $e): ?array
{
    $uid = $e['uid'] ?? null;
    $t = $e['t'] ?? null;
    if (!is_string($uid) || !preg_match('/^[A-Za-z0-9_.-]{1,40}:[A-Za-z0-9_.-]{1,23}$/', $uid)) {
        return null;
    }
    if (!is_int($t) && !(is_float($t) && floor($t) === $t) && !(is_string($t) && ctype_digit($t))) {
        return null;
    }
    $t = (int) $t;
    $nowMs = (int) (microtime(true) * 1000);
    if ($t < $nowMs - 86400000 * 365 || $t > $nowMs + 86400000) {
        return null;
    }
    $str = fn($key, $max) => mb_substr(is_scalar($e[$key] ?? null) ? trim((string) $e[$key]) : '', 0, $max);

    $rawUser = $str('u', 64);
    $base = [
        'uid'          => $uid,
        'collector_id' => collector_id_from_uid($uid),
        'ms'           => $t,
        'sent_at'      => ms_to_datetime($t),
        'raw_user_id'  => $rawUser,
        'user_id'      => normalize_user_id($rawUser),
        'nickname'     => $str('n', 100),
    ];

    if (($e['k'] ?? '') === 'c') {
        if ($rawUser === '') {
            return null;
        }
        $kind = ($e['kd'] ?? 'chat') === 'emoticon' ? 'emoticon' : 'chat';
        return $base + [
            'k'       => 'c',
            'message' => $str('m', 500),
            'kind'    => $kind,
            'badges'  => max(0, min(65535, (int) ($e['b'] ?? 0))),
        ];
    }

    if (($e['k'] ?? '') === 'd') {
        $type = (string) ($e['ty'] ?? '');
        $subtype = (string) ($e['st'] ?? '');
        if (!isset(DONATION_TYPES[$type]) || !in_array($subtype, DONATION_TYPES[$type], true)) {
            return null;
        }
        $rawTarget = $str('tu', 64);
        return $base + [
            'k'               => 'd',
            'type'            => $type,
            'subtype'         => $subtype,
            'amount'          => max(0, min(10000000, (int) ($e['a'] ?? 0))),
            'target_user_id'  => normalize_user_id($rawTarget),
            'target_nickname' => $str('tn', 100),
            'extra'           => $str('x', 200),
        ];
    }
    return null;
}

/**
 * @return array{chats:int, donations:int, duplicates:int, invalid:int}
 */
function ingest_events(int $broadcastId, array $events, string $source): array
{
    $result = ['chats' => 0, 'donations' => 0, 'duplicates' => 0, 'invalid' => 0];
    $chats = [];
    $donations = [];
    foreach ($events as $e) {
        $n = is_array($e) ? normalize_event($e) : null;
        if (!$n) {
            $result['invalid']++;
            continue;
        }
        if ($n['k'] === 'c') {
            $chats[] = $n;
        } else {
            $donations[] = $n;
        }
    }
    if (!$chats && !$donations) {
        return $result;
    }

    // 이 회차에 등록된 다른 수집창 목록 (PC 여러 대 동시 수집 여부 확인용)
    $collectorIds = array_column(db_all('SELECT id FROM collectors WHERE broadcast_id = ?', [$broadcastId]), 'id');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 이미 저장된 uid 는 먼저 걸러냅니다. (백업 재업로드가 빨라집니다)
        $chats = drop_existing_uids($broadcastId, $chats, 'chat_messages', $result);
        $donations = drop_existing_uids($broadcastId, $donations, 'donations', $result);
        $chats = cross_collector_filter($broadcastId, $chats, $collectorIds, 'chat', $result);
        $donations = cross_collector_filter($broadcastId, $donations, $collectorIds, 'donation', $result);

        $now = now();
        foreach (array_chunk($chats, 200) as $chunk) {
            $rows = [];
            $params = [];
            foreach ($chunk as $c) {
                $rows[] = '(?,?,?,?,?,?,?,?,?,?,?,?)';
                array_push($params, $broadcastId, $c['uid'], $c['collector_id'], $c['sent_at'], $c['raw_user_id'], $c['user_id'],
                    $c['nickname'], $c['message'], $c['kind'], $c['badges'], $source, $now);
            }
            $inserted = db_exec(db_insert_ignore() . ' INTO chat_messages (broadcast_id, uid, collector_id, sent_at, raw_user_id, user_id, nickname, message, kind, badges, source, created_at) VALUES ' . implode(',', $rows), $params);
            $result['chats'] += $inserted;
            $result['duplicates'] += count($chunk) - $inserted;
        }
        foreach (array_chunk($donations, 200) as $chunk) {
            $rows = [];
            $params = [];
            foreach ($chunk as $d) {
                $rows[] = '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                array_push($params, $broadcastId, $d['uid'], $d['collector_id'], $d['sent_at'], $d['type'], $d['subtype'], $d['raw_user_id'],
                    $d['user_id'], $d['nickname'], $d['amount'], $d['target_user_id'], $d['target_nickname'], $d['extra'], $source, $now);
            }
            $inserted = db_exec(db_insert_ignore() . ' INTO donations (broadcast_id, uid, collector_id, sent_at, type, subtype, raw_user_id, user_id, nickname, amount, target_user_id, target_nickname, extra, source, created_at) VALUES ' . implode(',', $rows), $params);
            $result['donations'] += $inserted;
            $result['duplicates'] += count($chunk) - $inserted;
        }
        if ($result['chats'] || $result['donations']) {
            db_exec(
                'UPDATE broadcasts SET chat_count = chat_count + ?, donation_count = donation_count + ? WHERE id = ?',
                [$result['chats'], $result['donations'], $broadcastId]
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $result;
}

function drop_existing_uids(int $broadcastId, array $items, string $table, array &$result): array
{
    if (!$items) {
        return $items;
    }
    $table = $table === 'donations' ? 'donations' : 'chat_messages';
    $existing = [];
    foreach (array_chunk(array_column($items, 'uid'), 500) as $uids) {
        foreach (db_all("SELECT uid FROM $table WHERE broadcast_id = ? AND uid IN (" . db_placeholders($uids) . ')', array_merge([$broadcastId], $uids)) as $r) {
            $existing[$r['uid']] = true;
        }
    }
    if (!$existing) {
        return $items;
    }
    $kept = [];
    foreach ($items as $item) {
        if (isset($existing[$item['uid']])) {
            $result['duplicates']++;
        } else {
            $kept[] = $item;
        }
    }
    return $kept;
}

/**
 * 다른 수집창이 이미 저장한 것과 같은 채팅·후원을 걸러냅니다.
 */
function cross_collector_filter(int $broadcastId, array $items, array $collectorIds, string $what, array &$result): array
{
    if (!$items) {
        return $items;
    }
    $kept = [];
    foreach ($items as $item) {
        $others = array_diff($collectorIds, [$item['collector_id']]);
        if (!$others) {
            $kept[] = $item;
            continue;
        }
        $from = ms_to_datetime($item['ms'] - CROSS_COLLECTOR_WINDOW_MS);
        $to = ms_to_datetime($item['ms'] + CROSS_COLLECTOR_WINDOW_MS);
        if ($what === 'chat') {
            $exists = db_value(
                'SELECT 1 FROM chat_messages WHERE broadcast_id = ? AND user_id = ? AND sent_at BETWEEN ? AND ? AND collector_id <> ? AND message = ? LIMIT 1',
                [$broadcastId, $item['user_id'], $from, $to, $item['collector_id'], $item['message']]
            );
        } else {
            $exists = db_value(
                'SELECT 1 FROM donations WHERE broadcast_id = ? AND user_id = ? AND sent_at BETWEEN ? AND ? AND collector_id <> ? AND type = ? AND subtype = ? AND amount = ? AND target_user_id = ? LIMIT 1',
                [$broadcastId, $item['user_id'], $from, $to, $item['collector_id'], $item['type'], $item['subtype'], $item['amount'], $item['target_user_id']]
            );
        }
        if ($exists) {
            $result['duplicates']++;
        } else {
            $kept[] = $item;
        }
    }
    return $kept;
}

/** 수집창 상태를 기록합니다. (빈 값은 기존 값 유지, 숫자는 더하기) */
function touch_collector(string $collectorId, int $broadcastId, array $fields, int $chatDelta = 0, int $donationDelta = 0): void
{
    $now = now();
    $keep = fn(string $col, string $when = '') => "CASE WHEN {new." . ($when ?: $col) . "} = '' THEN $col ELSE {new.$col} END";
    db_upsert('collectors', [
        'id'                => $collectorId,
        'broadcast_id'      => $broadcastId,
        'admin_id'          => current_admin()['id'] ?? null,
        'label'             => mb_substr((string) ($fields['label'] ?? ''), 0, 100),
        'soop_broadcast_no' => mb_substr((string) ($fields['soop_broadcast_no'] ?? ''), 0, 30),
        'status'            => mb_substr((string) ($fields['status'] ?? ''), 0, 20),
        'status_message'    => mb_substr((string) ($fields['status_message'] ?? ''), 0, 255),
        'chat_count'        => $chatDelta,
        'donation_count'    => $donationDelta,
        'started_at'        => $now,
        'last_seen_at'      => $now,
    ], ['id'], [
        'label'             => $keep('label'),
        'soop_broadcast_no' => $keep('soop_broadcast_no'),
        'status'            => $keep('status'),
        'status_message'    => $keep('status_message', 'status'),
        'chat_count'        => 'chat_count + {new.chat_count}',
        'donation_count'    => 'donation_count + {new.donation_count}',
        'last_seen_at'      => '{new.last_seen_at}',
    ]);
}
