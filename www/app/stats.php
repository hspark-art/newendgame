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

// ── 누적 순위 (여러 방송 모아 보기) ─────────────────────────
const CUMULATIVE_WEEKS = [2, 4, 8, 12, 26];
const CUMULATIVE_SORTS = [
    'streak'     => ['🔥 연속 출석', 2],   // [이름, 목록에 보일 최솟값]
    'attend'     => ['참여 회차', 1],
    'chats'      => ['기간 채팅', 1],
    'balloons'   => ['기간 별풍선', 1],
    'adballoons' => ['기간 애드벌룬', 1],
];

/**
 * 회차별 시청자 요약(broadcast_users)을 최신으로 맞춥니다.
 * 채팅·후원 수가 마지막 계산 때와 달라진 회차만 다시 계산합니다.
 * @param int $minAgeSec 마지막 계산 후 이 시간이 지나지 않았으면 건너뜀 (수집 중인 회차를 목록 화면에서 매번 다시 세지 않도록)
 * @param int $budgetSec 이 시간을 넘기면 나머지는 다음에 계산 (첫 사용 때 오래된 회차가 많아도 화면이 멈추지 않도록)
 * @return int[] 아직 계산하지 못한 회차 번호
 */
function refresh_broadcast_users(array $broadcastIds, int $minAgeSec = 0, int $budgetSec = 60): array
{
    $broadcastIds = array_values(array_unique(array_map('intval', $broadcastIds)));
    if (!$broadcastIds) {
        return [];
    }
    $started = microtime(true);
    $pending = [];
    $rows = db_all('SELECT id, chat_count, donation_count, users_sig, users_at FROM broadcasts WHERE id IN (' . db_placeholders($broadcastIds) . ')', $broadcastIds);
    foreach ($rows as $b) {
        $sig = $b['chat_count'] . ':' . $b['donation_count'];
        if ($b['users_sig'] === $sig) {
            continue;
        }
        $fresh = $b['users_sig'] !== null && $b['users_at'] && strtotime((string) $b['users_at']) > time() - $minAgeSec;
        if ($fresh) {
            continue;
        }
        if (microtime(true) - $started > $budgetSec) {
            $pending[] = (int) $b['id'];
            continue;
        }
        rebuild_broadcast_users((int) $b['id'], $sig);
    }
    return $pending;
}

function rebuild_broadcast_users(int $bid, string $sig): void
{
    // 배지는 회차 안에서 한 번이라도 붙은 것을 모두 모읍니다. (비트마다 MAX 를 더하면 OR 와 같음 — 두 DB 공통 문법)
    $bits = implode(' + ', array_map(fn($b) => "MAX(badges & $b)", [BADGE_BJ, BADGE_MANAGER, BADGE_TOPFAN, BADGE_FAN, BADGE_SUBSCRIBER, BADGE_ADMIN]));
    // 그 회차에서 마지막으로 쓴 닉네임: "시각|닉네임" 중 가장 늦은 것에서 닉네임 부분만 (사람마다 따로 찾는 것보다 훨씬 빠름)
    $cat = db_driver() === 'sqlite' ? "sent_at || '|' || nickname" : "CONCAT(sent_at, '|', nickname)";
    $lastNick = "SUBSTR(MAX($cat), INSTR(MAX($cat), '|') + 1)";
    $same = 'broadcast_id = broadcast_users.broadcast_id AND user_id = broadcast_users.user_id';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        db_exec('DELETE FROM broadcast_users WHERE broadcast_id = ?', [$bid]);
        db_exec(
            "INSERT INTO broadcast_users (broadcast_id, user_id, nickname, badges, chats)
             SELECT broadcast_id, user_id, $lastNick, $bits, COUNT(*) FROM chat_messages WHERE broadcast_id = ? AND user_id <> '' GROUP BY broadcast_id, user_id",
            [$bid]
        );
        // 채팅 없이 후원만 한 사람도 넣습니다. (채팅한 사람은 이미 있어서 건너뜀)
        db_exec(
            db_insert_ignore() . " INTO broadcast_users (broadcast_id, user_id, nickname)
             SELECT broadcast_id, user_id, $lastNick FROM donations WHERE broadcast_id = ? AND user_id <> '' GROUP BY broadcast_id, user_id",
            [$bid]
        );
        db_exec(
            "UPDATE broadcast_users SET
                balloons = (SELECT COALESCE(SUM(amount), 0) FROM donations WHERE $same AND type = 'balloon'),
                adballoons = (SELECT COALESCE(SUM(amount), 0) FROM donations WHERE $same AND type = 'adballoon')
             WHERE broadcast_id = ? AND user_id IN (SELECT user_id FROM donations WHERE broadcast_id = ?)",
            [$bid, $bid]
        );
        db_exec('UPDATE broadcasts SET users_sig = ?, users_at = ? WHERE id = ?', [$sig, now(), $bid]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * 누적 순위 — 최근 N주 방송을 모아 연속 출석·참여 회차·기간 채팅·기간 후원 (기존 끝장전 누적 순위와 같은 기준)
 *  - 참여: 그 방송일에 채팅 또는 후원이 1건 이상
 *  - 연속 출석: 가장 최근 방송일부터 거꾸로 빠짐없이 온 방송일 수 (기간과 상관없이 전체 기록 기준)
 *  - 닉네임·배지: 가장 최근에 온 방송 기준
 */
function cumulative_ranking(int $weeks, bool $exclude, string $q, string $sort): array
{
    @set_time_limit(300);
    $weeks = in_array($weeks, CUMULATIVE_WEEKS, true) ? $weeks : 8;
    $sort = isset(CUMULATIVE_SORTS[$sort]) ? $sort : 'streak';
    $cutoff = date('Y-m-d', strtotime("-$weeks weeks"));
    $staffBits = BADGE_BJ | BADGE_MANAGER | BADGE_ADMIN;

    $byDate = [];
    foreach (db_all('SELECT id, broadcast_date FROM broadcasts ORDER BY broadcast_date DESC, id DESC') as $b) {
        $byDate[(string) $b['broadcast_date']][] = (int) $b['id'];
    }
    $periodIds = [];
    foreach ($byDate as $date => $ids) {
        if ($date >= $cutoff) {
            array_push($periodIds, ...$ids);
        }
    }
    $pending = refresh_broadcast_users($periodIds);

    $blank = ['nickname' => '', 'badges' => 0, 'staff' => false, 'attend' => 0, 'last' => '', 'chats' => 0, 'balloons' => 0, 'adballoons' => 0, 'streak' => 0];
    $users = [];
    if ($periodIds) {
        db_stream(
            'SELECT bu.user_id, bu.nickname, bu.badges, bu.chats, bu.balloons, bu.adballoons, b.broadcast_date
             FROM broadcast_users bu JOIN broadcasts b ON b.id = bu.broadcast_id
             WHERE bu.broadcast_id IN (' . db_placeholders($periodIds) . ') ORDER BY b.broadcast_date, b.id',
            $periodIds,
            function (array $r) use (&$users, $blank, $staffBits) {
                $u = &$users[$r['user_id']];
                $u ??= $blank;
                if ($u['last'] !== $r['broadcast_date']) {
                    $u['attend']++;
                    $u['last'] = (string) $r['broadcast_date'];
                }
                $u['chats'] += (int) $r['chats'];
                $u['balloons'] += (int) $r['balloons'];
                $u['adballoons'] += (int) $r['adballoons'];
                if ($r['nickname'] !== '') {
                    $u['nickname'] = $r['nickname'];
                }
                $u['badges'] = (int) $r['badges'];
                $u['staff'] = $u['staff'] || ((int) $r['badges'] & $staffBits);
            }
        );
    }

    // 연속 출석: 최근 방송일부터 거꾸로, 모든 방송일에 온 사람만 남기며 셉니다.
    $alive = null;
    $step = 0;
    foreach ($byDate as $ids) {
        if (refresh_broadcast_users($ids)) {
            break; // 아직 계산하지 못한 회차가 있으면 거기서 멈춤
        }
        $step++;
        $present = [];
        foreach (db_all('SELECT user_id, nickname, badges FROM broadcast_users WHERE broadcast_id IN (' . db_placeholders($ids) . ')', $ids) as $r) {
            if ($alive === null || isset($alive[$r['user_id']])) {
                $present[$r['user_id']] = $r;
            }
        }
        if (!$present) {
            break;
        }
        foreach ($present as $uid => $r) {
            if (!isset($users[$uid])) { // 기간 밖(최근 방송이 기간보다 오래됨)의 연속 출석자
                $users[$uid] = ['nickname' => $r['nickname'], 'badges' => (int) $r['badges'], 'staff' => (bool) ((int) $r['badges'] & $staffBits)] + $blank;
            }
            $users[$uid]['streak'] = $step;
        }
        $alive = $present;
    }

    $excluded = $exclude ? array_flip(array_column(db_all('SELECT user_id FROM excluded_users'), 'user_id')) : [];
    $min = CUMULATIVE_SORTS[$sort][1];
    $rows = [];
    foreach ($users as $uid => $u) {
        $uid = (string) $uid;
        if ($exclude && ($u['staff'] || isset($excluded[$uid]))) {
            continue;
        }
        if ($u[$sort] < $min) {
            continue;
        }
        if ($q !== '' && mb_stripos($uid, $q) === false && mb_stripos($u['nickname'], $q) === false) {
            continue;
        }
        unset($u['last'], $u['staff']);
        $rows[] = ['user_id' => $uid] + $u;
    }
    $tie = ['streak' => ['attend', 'chats'], 'attend' => ['streak', 'chats'], 'chats' => ['attend', 'balloons'],
        'balloons' => ['adballoons', 'chats'], 'adballoons' => ['balloons', 'chats']][$sort];
    usort($rows, fn($a, $b) => [$b[$sort], $b[$tie[0]], $b[$tie[1]], $a['user_id']] <=> [$a[$sort], $a[$tie[0]], $a[$tie[1]], $b['user_id']]);

    $dates = array_keys($byDate);
    return [
        'rows'       => $rows,
        'weeks'      => $weeks,
        'sort'       => $sort,
        'from'       => $cutoff,
        'broadcasts' => count($periodIds),
        'last_date'  => $dates ? (string) $dates[0] : '',
        'pending'    => $pending,
    ];
}
