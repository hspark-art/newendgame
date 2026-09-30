<?php
/**
 * 집계 제외 명단 (스트리머·매니저·봇·스태프 등)
 * 후원 순위·채팅 활동량에서 "제외 명단 빼기"를 켜면 이 명단의 아이디가 빠집니다.
 */
require __DIR__ . '/app/bootstrap.php';

$admin = require_login();

if (is_post()) {
    csrf_check();
    $action = input_str('action', '', 20);
    if ($action === 'add') {
        $userId = normalize_user_id(input_str('user_id', '', 64));
        if ($userId === '') {
            flash('error', 'SOOP 아이디를 입력해 주세요.');
        } else {
            db_exec(
                'INSERT INTO excluded_users (user_id, nickname, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE nickname = VALUES(nickname), reason = VALUES(reason)',
                [$userId, input_str('nickname', '', 100), input_str('reason', '', 200), $admin['id'], now()]
            );
            audit('excluded_add', $userId, input_str('reason', '', 200));
            flash('success', "{$userId} 를 제외 명단에 추가했습니다.");
        }
    } elseif ($action === 'delete') {
        $row = db_one('SELECT * FROM excluded_users WHERE id = ?', [input_int('id')]);
        if ($row) {
            db_exec('DELETE FROM excluded_users WHERE id = ?', [$row['id']]);
            audit('excluded_delete', $row['user_id']);
            flash('success', "{$row['user_id']} 를 제외 명단에서 뺐습니다.");
        }
    }
    redirect(local_url_or(input_str('back', '', 300), 'excluded.php'));
}

$rows = db_all('SELECT e.*, a.display_name FROM excluded_users e LEFT JOIN admins a ON a.id = e.created_by ORDER BY e.created_at DESC');

page_header('제외 명단', ['menu' => 'excluded']);
?>
<div class="page-head"><h1>집계 제외 명단</h1></div>
<p class="muted">후원 순위·채팅 활동량에서 빼야 할 아이디(스트리머 부계정, 스태프, 채팅 봇 등)를 등록합니다.
  방송인·매니저 표시가 붙은 아이디는 등록하지 않아도 자동으로 빠집니다.</p>

<form method="post" class="filters card">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="add">
  <label>SOOP 아이디 <input name="user_id" required maxlength="64"></label>
  <label>닉네임 <input name="nickname" maxlength="100"></label>
  <label>사유 <input name="reason" maxlength="200" placeholder="예: 스태프, 채팅 봇"></label>
  <button class="btn primary">추가</button>
</form>

<div class="card flush">
<table class="table">
  <thead><tr><th>아이디</th><th>닉네임</th><th>사유</th><th>등록</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= h($r['user_id']) ?></td>
      <td><?= h($r['nickname']) ?></td>
      <td><?= h($r['reason']) ?></td>
      <td class="muted small"><?= fmt_dt($r['created_at'], 'Y-m-d') ?> <?= h($r['display_name'] ?? '') ?></td>
      <td>
        <form method="post" class="inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn small" data-confirm="제외 명단에서 뺄까요?">빼기</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="5" class="empty">등록된 아이디가 없습니다.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<?php
page_footer();
