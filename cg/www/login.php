<?php
declare(strict_types=1);

// 웹 버전 로그인
require __DIR__ . '/app/bootstrap.php';

portal_start();
if (auth_user_count() === 0) {
    portal_head('설치가 필요합니다');
    echo '<p>아직 관리자 계정이 없습니다. <a href="install.php">install.php</a> 에서 첫 관리자를 만드세요.</p>';
    portal_foot();
    exit;
}
$u = auth_user_optional();
if ($u !== null) {
    redirect($u['status'] === 'active' ? 'index.php' : 'pending.php');
}
$error = null;
$username = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    form_check();
    $username = post_str('username', 60);
    try {
        $u = auth_login($username, post_str('password', 200));
        auth_start_session_for($u);
        redirect($u['status'] === 'active' ? 'index.php' : 'pending.php');
    } catch (ActionError $e) {
        $error = $e->getMessage();
        http_response_code($e->status);
    }
}
portal_head('로그인');
portal_error($error);
?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <label>아이디 <input name="username" value="<?= h($username) ?>" autocomplete="username" required autofocus></label>
  <label>비밀번호 <input type="password" name="password" autocomplete="current-password" required></label>
  <button class="primary">로그인</button>
</form>
<p class="hint">계정이 없으면 관리자에게 초대 링크를 요청하세요. 비밀번호를 잊었으면 관리자에게 재설정 링크를 요청하세요.</p>
<?php
portal_foot();
