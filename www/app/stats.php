<?php
/**
 * 집계: 방송 요약, 후원 순위, 채팅 활동량
 */
declare(strict_types=1);

const DONATION_SORTS = [
    'balloon'   => ['balloons', '별풍선'],
    'adballoon' => ['adballoons', '애드벌룬'],
    'subs'      => ['subs', '구독'],
    'gifts'     => ['gifts', '구독 선물'],
    'recent'    => ['last_at', '최근 후원'],
];
const ACTIVITY_SORTS = [
    'effective' => '인정 채팅 수',
    'total'     => '전체 채팅 수',
    'slots'     => '활동 구간 수',
    'first'     => '첫 채팅 순',
];

/** 요청값에서 조회 조건을 읽습니다. */
function stats_filters(): array
{
    return [
        'from'    => input_datetime('from'),
        'to'      => input_datetime('to'),
        'exclude' => !isset($_GET['submitted']) || isset($_GET['exclude']), // 기본값: 제외 적용
        'q'       => input_str('q', '', 100),
    ];
}

/** 제외 명단 + (선택) 이 방송에서 방송인·매니저 표시가 붙은 아이디 */
function excluded_ids(int $broadcastId, bool $includeStaff = true): array
{
    $ids = array_column(db_all('SELECT user_id FROM excluded_users'), 'user_id');
    if ($includeStaff) {
        $staff = db_all(
            'SELECT DISTINCT user_id FROM chat_messages WHERE broadcast_id = ? AND (badges & ?) <> 0',
            [$broadcastId, BADGE_BJ | BADGE_MANAGER | BADGE_ADMIN]
        );
        $ids = array_merge($ids, array_column($staff, 'user_id'));
    }
    return array_values(array_unique($ids));
}

function time_where(array $f, array &$params, string $col = 'sent_at'): string
{
    $sql = '';
    if ($f['from']) {
        $sql .= " AND $col >= ?";
        $params[] = $f['from'];
    }
    if ($f['to']) {
        $sql .= " AND $col <= ?";
        $params[] = $f['to'];
    }
    return $sql;
}

function broadcast_summary(int $bid): array
{
    $chat = db_one(
        'SELECT COUNT(*) AS cnt, COUNT(DISTINCT user_id) AS users, MIN(sent_at) AS first_at, MAX(sent_at) AS last_at FROM chat_messages WHERE broadcast_id = ?',
        [$bid]
    );
    $don = [];
    foreach (db_all('SELECT type, COUNT(*) AS cnt, SUM(amount) AS amount, COUNT(DISTINCT user_id) AS users FROM donations WHERE broadcast_id = ? GROUP BY type', [$bid]) as $r) {
        $don[$r['type']] = $r;
    }
    return [
        'chat'       => $chat,
        'donations'  => $don,
        'collectors' => db_all('SELECT c.*, a.display_name FROM collectors c LEFT JOIN admins a ON a.id = c.admin_id WHERE c.broadcast_id = ? ORDER BY c.last_seen_at DESC', [$bid]),
    ];
}

/**
 * 후원 순위
 * 별풍선·애드벌룬은 개수 합계, 구독은 알림 건수(신규+연속), 구독 선물은 받은 사람 수 기준입니다.
 */
function donation_ranking(int $bid, array $f, string $sort, int $minBalloon, ?int $limit, int $offset = 0): array
{
    $params = [$bid];
    $where = 'broadcast_id = ? AND user_id <> \'\'' . time_where($f, $params);
    if ($f['exclude']) {
        $ex = excluded_ids($bid);
        if ($ex) {
            $where .= ' AND user_id NOT IN (' . db_placeholders($ex) . ')';
            array_push($params, ...$ex);
        }
    }
    if ($f['q'] !== '') {
        $where .= ' AND (user_id LIKE ? OR nickname LIKE ?)';
        $params[] = '%' . $f['q'] . '%';
        $params[] = '%' . $f['q'] . '%';
    }
    $inner = "SELECT user_id,
            SUM(CASE WHEN type = 'balloon' THEN amount ELSE 0 END) AS balloons,
            SUM(CASE WHEN type = 'adballoon' THEN amount ELSE 0 END) AS adballoons,
            SUM(CASE WHEN type = 'subscription' AND subtype IN ('new','renew') THEN 1 ELSE 0 END) AS subs,
            SUM(CASE WHEN type = 'subscription' AND subtype = 'gift' THEN 1 ELSE 0 END) AS gifts,
            COUNT(*) AS events, MIN(sent_at) AS first_at, MAX(sent_at) AS last_at
        FROM donations WHERE $where GROUP BY user_id";
    $having = $minBalloon > 0 ? ' WHERE balloons >= ' . (int) $minBalloon : '';

    $total = (int) db_value("SELECT COUNT(*) FROM ($inner) t$having", $params);
    $sums = db_one("SELECT COALESCE(SUM(balloons),0) AS balloons, COALESCE(SUM(adballoons),0) AS adballoons, COALESCE(SUM(subs),0) AS subs, COALESCE(SUM(gifts),0) AS gifts FROM ($inner) t$having", $params);

    $order = (DONATION_SORTS[$sort] ?? DONATION_SORTS['balloon'])[0];
    $sql = "SELECT * FROM ($inner) t$having ORDER BY $order DESC, balloons DESC, adballoons DESC, user_id";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    }
    $rows = db_all($sql, $params);
    attach_latest_nicknames($bid, $rows, 'donations');
    return ['rows' => $rows, 'total' => $total, 'sums' => $sums];
}

/** 각 행에 가장 최근 닉네임을 붙입니다. */
function attach_latest_nicknames(int $bid, array &$rows, string $table): void
{
    if (!$rows) {
        return;
    }
    $ids = array_column($rows, 'user_id');
    $table = $table === 'donations' ? 'donations' : 'chat_messages';
    $latest = [];
    foreach (db_all("SELECT user_id, nickname FROM $table WHERE broadcast_id = ? AND user_id IN (" . db_placeholders($ids) . ') ORDER BY sent_at', array_merge([$bid], $ids)) as $r) {
        $latest[$r['user_id']] = $r['nickname'];
    }
    foreach ($rows as &$row) {
        $row['nickname'] = $latest[$row['user_id']] ?? '';
    }
}

/**
 * 채팅 활동량
 *
 * 인정 채팅(도배 제외) 규칙
 *  - 직전 인정 채팅으로부터 interval 초 안에 다시 친 채팅은 제외
 *  - 직전 인정 채팅과 같은 내용을 repeat 초 안에 다시 친 채팅은 제외
 * 활동 구간 수: 조회 범위를 slot 분 단위로 나눴을 때 인정 채팅이 1개 이상 있는 구간의 수
 *              (오래 꾸준히 참여했는지 보는 지표)
 */
function activity_ranking(int $bid, array $f, array $rule, string $sort, int $minCount, ?int $limit, int $offset = 0): array
{
    $interval = max(0, $rule['interval']);
    $repeat = max(0, $rule['repeat']);
    $slotSec = max(1, $rule['slot']) * 60;

    $params = [$bid];
    $where = 'broadcast_id = ?' . time_where($f, $params);
    $excluded = $f['exclude'] ? array_flip(excluded_ids($bid, false)) : [];

    $users = [];
    db_stream("SELECT user_id, nickname, message, badges, sent_at FROM chat_messages WHERE $where ORDER BY user_id, sent_at, id", $params,
        function (array $r) use (&$users, $interval, $repeat, $slotSec) {
            $uid = $r['user_id'];
            $msg = $r['message'];
            $ts = datetime_to_seconds((string) $r['sent_at']);
            if (!isset($users[$uid])) {
                $users[$uid] = ['nickname' => '', 'badges' => 0, 'total' => 0, 'effective' => 0,
                    'slots' => [], 'first' => $r['sent_at'], 'last' => $r['sent_at'], 'lt' => null, 'lm' => null];
            }
            $u = &$users[$uid];
            $u['nickname'] = $r['nickname'];
            $u['badges'] |= (int) $r['badges'];
            $u['total']++;
            $u['last'] = $r['sent_at'];
            $counted = $u['lt'] === null
                || (($ts - $u['lt']) >= $interval && !($msg === $u['lm'] && ($ts - $u['lt']) < $repeat));
            if ($counted) {
                $u['effective']++;
                $u['lt'] = $ts;
                $u['lm'] = $msg;
                $u['slots'][(int) floor($ts / $slotSec)] = true;
            }
        }
    );

    $q = mb_strtolower($f['q']);
    $sumTotal = 0;
    $sumEffective = 0;
    $rows = [];
    foreach ($users as $uid => $u) {
        if (isset($excluded[$uid])) {
            continue;
        }
        if ($f['exclude'] && ($u['badges'] & (BADGE_BJ | BADGE_MANAGER | BADGE_ADMIN))) {
            continue;
        }
        if ($q !== '' && !str_contains(mb_strtolower((string) $uid), $q) && !str_contains(mb_strtolower((string) $u['nickname']), $q)) {
            continue;
        }
        if ($u['effective'] < $minCount) {
            continue;
        }
        $sumTotal += $u['total'];
        $sumEffective += $u['effective'];
        $rows[] = [
            'user_id'   => (string) $uid,
            'nickname'  => $u['nickname'],
            'badges'    => $u['badges'],
            'total'     => $u['total'],
            'effective' => $u['effective'],
            'slots'     => count($u['slots']),
            'first_at'  => substr((string) $u['first'], 0, 19),
            'last_at'   => substr((string) $u['last'], 0, 19),
        ];
    }
    usort($rows, match ($sort) {
        'total' => fn($a, $b) => [$b['total'], $b['effective']] <=> [$a['total'], $a['effective']],
        'slots' => fn($a, $b) => [$b['slots'], $b['effective']] <=> [$a['slots'], $a['effective']],
        'first' => fn($a, $b) => $a['first_at'] <=> $b['first_at'],
        default => fn($a, $b) => [$b['effective'], $b['slots']] <=> [$a['effective'], $a['slots']],
    });
    $total = count($rows);
    if ($limit !== null) {
        $rows = array_slice($rows, $offset, $limit);
    }
    return ['rows' => $rows, 'total' => $total, 'sum_total' => $sumTotal, 'sum_effective' => $sumEffective];
}

/** '2026-09-30 18:00:01.250' → 초 단위 숫자 (같은 분은 계산 결과를 재사용해 빠르게 처리) */
function datetime_to_seconds(string $value): float
{
    static $minutes = [];
    $minute = substr($value, 0, 16);
    if (!isset($minutes[$minute])) {
        if (count($minutes) > 5000) {
            $minutes = [];
        }
        $minutes[$minute] = (int) strtotime($minute . ':00');
    }
    return $minutes[$minute] + (float) substr($value, 17);
}

function activity_rule_from_request(): array
{
    return [
        'interval' => max(0, min(600, input_int('interval', 3))),
        'repeat'   => max(0, min(3600, input_int('repeat', 60))),
        'slot'     => max(1, min(240, input_int('slot', 30))),
    ];
}

function donation_type_label(string $type, string $subtype): string
{
    return match ("$type/$subtype") {
        'balloon/normal'           => '별풍선',
        'balloon/relay'            => '별풍선(중계방)',
        'balloon/video'            => '영상풍선',
        'balloon/mission'          => '도전미션 별풍선',
        'balloon/battle'           => '대결미션 별풍선',
        'adballoon/normal'         => '애드벌룬',
        'adballoon/station'        => '방송국 애드벌룬',
        'subscription/new'         => '구독',
        'subscription/renew'       => '연속 구독 알림',
        'subscription/gift'        => '구독 선물',
        'subscription/gift_random' => '랜덤 구독 선물',
        default                    => "$type/$subtype",
    };
}

function donation_amount_label(array $d): string
{
    return match ($d['type']) {
        'balloon', 'adballoon' => fmt_num($d['amount']) . '개',
        default => match ($d['subtype']) {
            'gift_random' => fmt_num($d['amount']) . '명',
            'renew'       => fmt_num($d['amount']) . '개월',
            default       => '1건',
        },
    };
}
