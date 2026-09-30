<?php
/**
 * 상품 지급 관련 공통 함수
 */
declare(strict_types=1);

const PRIZE_STATUSES = [
    'pending'       => '정보 대기',
    'info_received' => '정보 받음',
    'paid'          => '지급 완료',
    'expired'       => '기한 초과',
];
const PRIZE_TYPES = [
    'coupon'   => '모바일 쿠폰',
    'delivery' => '실물 배송',
    'other'    => '기타',
];
/** 쪽지 문안 종류 (상품마다 지정, 쪽지 보낼 때 문안 자동 선택) */
const NOTE_TYPES = [
    'tax'   => '제세공과금 (동의서 폼)',
    'free'  => '비과세 (동의서 폼)',
    'code'  => '코드 전달',
    'blank' => '직접 쓰기',
];
/**
 * 상품 종류별 아이콘·색 (기존 끝장전 관제 화면 PCATS 그대로)
 * 상품 목록에 연결되지 않은 기록(가져온 과거 기록 등)은 상품 이름으로 판정합니다.
 */
const PRIZE_CATEGORIES = [
    ['마우스패드', '/마우스\s*패드|패드|gigantus|mousepad/iu', '#a78bfa', '🟪'],
    ['마우스 화이트', '/viper.*white|razer.*white|화이트/iu', '#ffffff', '🖱️'],
    ['마우스 CS2', '/viper.*cs2|razer.*cs2/iu', '#ffd166', '🖱️'],
    ['마우스 Faker', '/faker|페이커/iu', '#ff4d5a', '🖱️'],
    ['마우스', '/마우스|viper|razer|mouse/iu', '#4aa3ff', '🖱️'],
    ['유니폼', '/유니폼|uniform|jamie/iu', '#f87171', '👕'],
    ['안경', '/안경|wearwhere|glass/iu', '#4ade80', '👓'],
    ['쿠폰·코드', '/쿠폰|포인트|코드|coupon|point|code/iu', '#ffb020', '🎟️'],
];
const PRIZE_CATEGORY_OTHER = ['기타', '', '#8a93a6', '🎁'];

/**
 * 지급 완료(또는 기한 초과) 후 보관 기간이 지난 수령자 정보를 파기합니다.
 * 별도 예약 작업 없이 관리자 화면을 열 때마다 확인합니다.
 */
function purge_expired_pii(): int
{
    $days = max(1, (int) config('privacy_retention_days', 30));
    $limit = date('Y-m-d H:i:s', time() - $days * 86400);
    $count = db_exec(
        "UPDATE prizes SET recipient_name = NULL, recipient_phone = NULL, recipient_address = NULL, purged_at = ?
         WHERE purged_at IS NULL AND status IN ('paid', 'expired') AND COALESCE(paid_at, updated_at) < ?
           AND (recipient_name IS NOT NULL OR recipient_phone IS NOT NULL OR recipient_address IS NOT NULL)",
        [now(), $limit]
    );
    if ($count > 0) {
        audit('pii_purge', '', "보관 기간({$days}일) 경과 수령자 정보 {$count}건 파기");
    }
    return $count;
}

function prize_status_badge(string $status): string
{
    return '<span class="status status-' . h($status) . '">' . h(PRIZE_STATUSES[$status] ?? $status) . '</span>';
}

/** 상품 이름으로 종류(아이콘·색) 판정 */
function prize_category(string $name): array
{
    foreach (PRIZE_CATEGORIES as $cat) {
        if (preg_match($cat[1], $name)) {
            return $cat;
        }
    }
    return PRIZE_CATEGORY_OTHER;
}

/** 상품 이름으로 쪽지 문안 종류 추천 (기존 시스템 규칙: 안경·유니폼 → 비과세, 코드·쿠폰 → 코드) */
function suggest_note_type(string $name): string
{
    if (preg_match('/안경|유니폼/u', $name)) return 'free';
    if (preg_match('/코드|쿠폰/u', $name)) return 'code';
    return 'tax';
}

/** 상품 목록 */
function prize_items(bool $activeOnly = false): array
{
    return db_all('SELECT * FROM prize_items' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id');
}

function prize_item(?int $id): ?array
{
    return $id ? db_one('SELECT * FROM prize_items WHERE id = ?', [$id]) : null;
}

/** 당첨 기록 한 건의 아이콘·색 (상품 목록 연결 우선, 없으면 이름으로 판정) */
function prize_icon(array $row): array
{
    if (!empty($row['item_icon'])) {
        return ['icon' => $row['item_icon'], 'color' => $row['item_color'] ?: '#8a93a6'];
    }
    $cat = prize_category((string) ($row['prize_name'] ?? ''));
    return ['icon' => $cat[3], 'color' => $cat[2]];
}

/**
 * 시청자별 당첨 기록 (전체 회차)
 * @param string[]|null $userIds null 이면 전체
 * @return array<string, list<array{icon:string,color:string,prize:string,date:string,item_id:?int,broadcast_id:?int}>>
 */
function winners_by_user(?array $userIds = null): array
{
    $sql = 'SELECT p.user_id, p.prize_name, p.item_id, p.broadcast_id, p.created_at, i.icon AS item_icon, i.color AS item_color
            FROM prizes p LEFT JOIN prize_items i ON i.id = p.item_id';
    $params = [];
    if ($userIds !== null) {
        $userIds = array_values(array_unique(array_filter($userIds, fn($v) => $v !== '')));
        if (!$userIds) {
            return [];
        }
        $sql .= ' WHERE p.user_id IN (' . db_placeholders($userIds) . ')';
        $params = $userIds;
    }
    $map = [];
    foreach (db_all($sql . ' ORDER BY p.created_at', $params) as $r) {
        if ($r['user_id'] === '') {
            continue; // 아이디 없이 가져온 과거 기록은 채팅과 맞춰 볼 수 없음
        }
        $icon = prize_icon($r);
        $map[$r['user_id']][] = [
            'icon' => $icon['icon'], 'color' => $icon['color'], 'prize' => $r['prize_name'],
            'date' => substr((string) $r['created_at'], 0, 10),
            'item_id' => $r['item_id'] !== null ? (int) $r['item_id'] : null,
            'broadcast_id' => $r['broadcast_id'] !== null ? (int) $r['broadcast_id'] : null,
        ];
    }
    return $map;
}

/** 닉네임 옆 당첨 표시: "당첨 N" + 받은 상품 아이콘 */
function render_wins(array $list): string
{
    if (!$list) {
        return '';
    }
    $html = '<span class="wins" title="' . h(implode("\n", array_map(fn($w) => $w['date'] . ' ' . $w['prize'], $list))) . '">'
        . '<span class="wcount">당첨 ' . count($list) . '</span>';
    foreach ($list as $w) {
        $html .= '<span class="wi" style="--c:' . h($w['color']) . '">' . h($w['icon']) . '</span>';
    }
    return $html . '</span>';
}

/**
 * 당첨 등록 전 경고 (같은 상품 이미 받음 / 누적 N회 / 최근 N개월 N회)
 * @return string[]
 */
function winner_warnings(string $userId, ?int $itemId, string $prizeName, int $exceptPrizeId = 0, int $recentMonths = 3): array
{
    if ($userId === '') {
        return [];
    }
    $rows = db_all('SELECT id, item_id, prize_name, created_at FROM prizes WHERE user_id = ? AND id <> ?', [$userId, $exceptPrizeId]);
    if (!$rows) {
        return [];
    }
    $warn = [];
    $same = array_filter($rows, fn($r) => ($itemId && (int) $r['item_id'] === $itemId) || ($prizeName !== '' && $r['prize_name'] === $prizeName));
    if ($same) {
        $warn[] = '🚫 같은 상품을 이미 받았습니다 (' . implode(', ', array_map(fn($r) => substr($r['created_at'], 0, 10), $same)) . ')';
    }
    $since = date('Y-m-d H:i:s', strtotime("-$recentMonths months"));
    $recent = count(array_filter($rows, fn($r) => $r['created_at'] >= $since));
    if ($recent) {
        $warn[] = "최근 {$recentMonths}개월 안에 {$recent}회 당첨";
    }
    $warn[] = '누적 당첨 ' . count($rows) . '회';
    return $warn;
}

/**
 * [상품 지급] 목록 조건 (화면과 CSV 내려받기가 같이 씁니다. 표 별칭 p)
 * @return array{0:string, 1:array}
 */
function prize_list_where(int $broadcastId, string $status, string $prize, string $note, string $q): array
{
    $where = '1';
    $params = [];
    if ($broadcastId) {
        $where .= ' AND p.broadcast_id = ?';
        $params[] = $broadcastId;
    }
    if (isset(PRIZE_STATUSES[$status])) {
        $where .= ' AND p.status = ?';
        $params[] = $status;
    }
    if ($prize !== '') {
        $where .= ' AND p.prize_name = ?';
        $params[] = $prize;
    }
    if ($note === 'sent') {
        $where .= ' AND p.note_sent_at IS NOT NULL';
    } elseif ($note === 'unsent') {
        $where .= ' AND p.note_sent_at IS NULL';
    }
    if ($q !== '') {
        $where .= ' AND (p.user_id LIKE ? OR p.nickname LIKE ? OR p.prize_name LIKE ? OR p.memo LIKE ?)';
        array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
    }
    return [$where, $params];
}

/** 같은 사람 판단 키: 아이디, 없으면(가져온 과거 기록) 공백 뺀 소문자 닉네임 */
function prize_person_key(string $userId, string $nickname): string
{
    return $userId !== '' ? $userId : '닉:' . mb_strtolower((string) preg_replace('/\s+/u', '', $nickname));
}

/** 같은 방송에서 이미 당첨 기록이 있는 아이디 목록 */
function existing_winners(?int $broadcastId, array $userIds, int $exceptPrizeId = 0): array
{
    if (!$userIds || !$broadcastId) {
        return [];
    }
    $rows = db_all(
        'SELECT user_id, prize_name FROM prizes WHERE broadcast_id = ? AND id <> ? AND user_id IN (' . db_placeholders($userIds) . ')',
        array_merge([$broadcastId, $exceptPrizeId], $userIds)
    );
    $map = [];
    foreach ($rows as $r) {
        $map[$r['user_id']][] = $r['prize_name'];
    }
    return $map;
}

/** 상품 목록 선택칸 (당첨 등록 화면 공통) */
function prize_item_select(?int $selected = null, string $label = '상품'): string
{
    $html = '<label>' . h($label) . ' <select name="item_id"><option value="">(직접 입력)</option>';
    foreach (prize_items(true) as $it) {
        $html .= '<option value="' . (int) $it['id'] . '"' . ((int) $it['id'] === $selected ? ' selected' : '') . '>'
            . h($it['icon'] . ' ' . $it['name']) . '</option>';
    }
    return $html . '</select></label>';
}

/**
 * 상품 목록에서 고른 상품과 직접 입력한 이름을 정리합니다.
 * @return array{0:?int,1:string} [item_id, prize_name] — 둘 다 비면 prize_name 이 ''
 */
function resolve_prize_input(): array
{
    $item = prize_item(input_int('item_id') ?: null);
    $name = input_str('prize_name', '', 200);
    if ($item) {
        return [(int) $item['id'], $name !== '' ? $name : $item['name']];
    }
    return [null, $name];
}

/**
 * 순위 화면 아래에 붙는 "선택한 시청자 당첨 등록" 입력칸
 * (표의 체크박스 name="pick[]", 숨은 칸 name="nick[아이디]" 와 같은 form 안에서 사용)
 */
function bulk_prize_fields(int $broadcastId, string $defaultReason): string
{
    ob_start();
    ?>
    <div class="bulk-bar">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bulk">
      <input type="hidden" name="broadcast_id" value="<?= $broadcastId ?>">
      <strong>선택한 시청자 당첨 등록</strong>
      <?= prize_item_select() ?>
      <label>상품명 (직접 입력) <input name="prize_name" maxlength="200" placeholder="목록에 없을 때만"></label>
      <label>유형 <select name="prize_type"><?php foreach (PRIZE_TYPES as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?></select></label>
      <label>선정 사유 <input name="reason" maxlength="200" value="<?= h($defaultReason) ?>"></label>
      <label>정보 제출 기한 <input type="date" name="due_date"></label>
      <button type="submit" class="btn primary" data-require-check="pick[]">등록</button>
    </div>
    <?php
    return (string) ob_get_clean();
}
