<?php
declare(strict_types=1);

// 내 계정: 비밀번호 변경
require __DIR__ . '/app/bootstrap.php';

portal_start();
$op = auth_require_user();
$u = auth_user_optional();
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    form_check();
    try {
        $u = auth_change_password($u['id'], post_str('current'), post_str('password'), post_str('password2'));
        auth_start_session_for($u);
        flash('ok', '비밀번호를 바꿨습니다. 다른 기기의 로그인은 종료되었습니다.');
        redirect('account.php');
    } catch (ActionError $e) {
        $error = $e->getMessage();
        http_response_code($e->status);
    }
}
portal_head('내 계정', $u);
portal_error($error);
?>
<div class="card">
  <p>아이디 <b><?= h($u['username']) ?></b> · 이름 <b><?= h($u['name']) ?></b> · 역할 <b><?= $u['role'] === 'admin' ? '관리자' : '운영자' ?></b></p>
</div>
<h2>비밀번호 변경</h2>
<form method="post" class="card">
  <?= csrf_field() ?>
  <label>현재 비밀번호 <input type="password" name="current" autocomplete="current-password" required></label>
  <label>새 비밀번호 (12자 이상) <input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
  <label>새 비밀번호 확인 <input type="password" name="password2" autocomplete="new-password" minlength="12" required></label>
  <button class="primary">변경</button>
</form>
<?php
portal_foot();
