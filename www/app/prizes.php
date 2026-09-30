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
      <label>상품명 <input name="prize_name" required maxlength="200" placeholder="예: 기프티콘 1만원"></label>
      <label>유형 <select name="prize_type"><?php foreach (PRIZE_TYPES as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?></select></label>
      <label>선정 사유 <input name="reason" maxlength="200" value="<?= h($defaultReason) ?>"></label>
      <label>정보 제출 기한 <input type="date" name="due_date"></label>
      <button type="submit" class="btn primary" data-require-check="pick[]">등록</button>
    </div>
    <?php
    return (string) ob_get_clean();
}
