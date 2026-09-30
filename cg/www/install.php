<?php
declare(strict_types=1);

/**
 * 웹 버전 첫 설치: 관리자 계정 생성. 계정이 하나라도 있으면 동작하지 않는다.
 * 설치가 끝나면 서버에서 이 파일을 삭제하세요.
 */
require __DIR__ . '/app/bootstrap.php';

portal_start();
if (auth_user_count() > 0) {
    http_response_code(404);
    portal_head('이미 설치되었습니다');
    echo '<p>관리자 계정이 이미 있습니다. 보안을 위해 서버에서 <b>install.php</b> 파일을 삭제하세요.</p><p><a class="btn" href="login.php">로그인</a></p>';
    portal_foot();
    exit;
}
$error = null;
$in = ['username' => 'admin', 'name' => '관리자'];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    form_check();
    $in = ['username' => post_str('username', 60), 'name' => post_str('name', 60)];
    try {
        $u = auth_install_admin($in['username'], $in['name'], post_str('password'), post_str('password2'));
        auth_start_session_for($u);
        flash('ok', '관리자 계정을 만들었습니다. 이제 서버에서 install.php 파일을 삭제하세요.');
        redirect('admin.php');
    } catch (ActionError $e) {
        $error = $e->getMessage();
        http_response_code($e->status);
    }
}
portal_head('첫 관리자 만들기');
portal_error($error);
?>
<p>DB 연결을 확인했고 테이블을 만들었습니다. 첫 관리자 계정을 만드세요. 기본 비밀번호는 없습니다.</p>
<form method="post" class="card">
  <?= csrf_field() ?>
  <label>아이디 <input name="username" value="<?= h($in['username']) ?>" required pattern="[a-z0-9][a-z0-9._\-]{2,29}"></label>
  <label>표시 이름 <input name="name" value="<?= h($in['name']) ?>" maxlength="30" required></label>
  <label>비밀번호 (12자 이상) <input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
  <label>비밀번호 확인 <input type="password" name="password2" autocomplete="new-password" minlength="12" required></label>
  <button class="primary">관리자 만들기</button>
</form>
<?php
portal_foot();
