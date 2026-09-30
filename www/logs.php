<?php
/**
 * 작업 기록 (admin 전용) — 로그인, 개인정보 열람, 지급 처리, 데이터 삭제 등
 */
require __DIR__ . '/app/bootstrap.php';

require_admin_role();

const ACTION_LABELS = [
    'install' => '설치', 'login' => '로그인', 'logout' => '로그아웃', 'password_change' => '비밀번호 변경',
    'broadcast_create' => '회차 등록', 'broadcast_update' => '회차 수정', 'chat_purge' => '채팅 데이터 삭제',
    'import' => '백업 업로드', 'export' => '내려받기',
    'prize_create' => '당첨 등록', 'prize_bulk_create' => '당첨 일괄 등록', 'prize_update' => '지급 정보 수정',
    'prize_bulk_status' => '지급 상태 일괄 변경', 'prize_delete' => '지급 기록 삭제',
    'pii_view' => '개인정보 열람', 'pii_purge' => '개인정보 자동 파기',
    'excluded_add' => '제외 명단 추가', 'excluded_delete' => '제외 명단 삭제',
    'admin_create' => '계정 생성', 'admin_password_reset' => '계정 비밀번호 변경', 'admin_toggle' => '계정 상태 변경', 'admin_role' => '계정 권한 변경',
];

$action = input_str('action', '', 50);
$perPage = 100;
$page = max(1, input_int('page', 1));
$where = '1';
$params = [];
if ($action !== '') {
    $where .= ' AND l.action = ?';
    $params[] = $action;
}
$total = (int) db_value("SELECT COUNT(*) FROM audit_logs l WHERE $where", $params);
$rows = db_all(
    "SELECT l.*, a.username, a.display_name FROM audit_logs l LEFT JOIN admins a ON a.id = l.admin_id WHERE $where ORDER BY l.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
    $params
);

page_header('작업 기록', ['menu' => 'logs', 'wide' => true]);
?>
<div class="page-head"><h1>작업 기록</h1></div>
<form class="filters" method="get">
  <label>종류
    <select name="action" data-autosubmit>
      <option value="">전체</option>
      <?php foreach (ACTION_LABELS as $k => $label): ?><option value="<?= h($k) ?>" <?= $action === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
    </select>
  </label>
</form>
<div class="card flush">
<table class="table">
  <thead><tr><th>시각</th><th>담당</th><th>작업</th><th>대상</th><th>내용</th><th>IP</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="nowrap muted small"><?= fmt_dt($r['created_at'], 'Y-m-d H:i:s') ?></td>
      <td><?= h($r['display_name'] ?? '-') ?> <span class="muted small"><?= h($r['username'] ?? '') ?></span></td>
      <td><?= h(ACTION_LABELS[$r['action']] ?? $r['action']) ?></td>
      <td class="small"><?= h($r['target']) ?></td>
      <td class="small"><?= h($r['detail']) ?></td>
      <td class="muted small"><?= h($r['ip']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty">기록이 없습니다.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?= pagination($total, $page, $perPage) ?>
<?php
page_footer();
