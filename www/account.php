<?php
/**
 * 내 정보 (표시 이름·비밀번호 변경)
 */
require __DIR__ . '/app/bootstrap.php';

$me = require_login();

if (is_post()) {
    csrf_check();
    $name = input_str('display_name', '', 50);
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $row = db_one('SELECT password_hash FROM admins WHERE id = ?', [$me['id']]);

    if ($name === '') {
        flash('error', '표시 이름을 입력해 주세요.');
    } elseif ($new !== '' && !password_verify($current, $row['password_hash'])) {
        flash('error', '현재 비밀번호가 올바르지 않습니다.');
    } elseif ($new !== '' && ($err = password_policy_error($new))) {
        flash('error', $err);
    } elseif ($new !== '' && $new !== ($_POST['new_password2'] ?? '')) {
        flash('error', '새 비밀번호 확인이 일치하지 않습니다.');
    } else {
        db_exec('UPDATE admins SET display_name = ? WHERE id = ?', [$name, $me['id']]);
        if ($new !== '') {
            db_exec('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            audit('password_change', $me['username']);
        }
        flash('success', '저장했습니다.');
    }
    redirect('account.php');
}

page_header('내 정보', ['menu' => 'account']);
?>
<div class="narrow">
  <h1>내 정보</h1>
  <form method="post" class="form card">
    <?= csrf_field() ?>
    <label>아이디 <input value="<?= h($me['username']) ?>" disabled></label>
    <label>표시 이름 <input name="display_name" required maxlength="50" value="<?= h($me['display_name']) ?>"></label>
    <h2>비밀번호 변경 <span class="muted small">바꿀 때만 입력</span></h2>
    <label>현재 비밀번호 <input type="password" name="current_password" autocomplete="current-password"></label>
    <label>새 비밀번호 <input type="password" name="new_password" autocomplete="new-password"><span class="muted small">8자 이상, 영문+숫자</span></label>
    <label>새 비밀번호 확인 <input type="password" name="new_password2" autocomplete="new-password"></label>
    <button class="btn primary">저장</button>
  </form>
</div>
<?php
page_footer();
