<?php
declare(strict_types=1);

// 관리자가 발급한 재설정 링크로 새 비밀번호 지정
require __DIR__ . '/app/bootstrap.php';

portal_start();
$token = (string)($_GET['token'] ?? '');
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    form_check();
    try {
        auth_reset_password($token, post_str('password'), post_str('password2'));
        portal_head('비밀번호를 바꿨습니다');
        echo '<p class="msg ok">새 비밀번호로 로그인하세요. 다른 기기의 로그인은 모두 종료되었습니다.</p><p><a class="btn" href="login.php">로그인</a></p>';
        portal_foot();
        exit;
    } catch (ActionError $e) {
        $error = $e->getMessage();
        http_response_code($e->status);
    }
}
if (link_valid($token, 'reset') === null) {
    http_response_code(410);
    portal_head('재설정 링크를 사용할 수 없습니다');
    echo '<p class="msg err">재설정 링크가 만료되었거나 이미 사용되었습니다. 관리자에게 새 링크를 요청하세요.</p>';
    portal_foot();
    exit;
}
portal_head('비밀번호 재설정');
portal_error($error);
?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <label>새 비밀번호 (12자 이상) <input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
  <label>새 비밀번호 확인 <input type="password" name="password2" autocomplete="new-password" minlength="12" required></label>
  <button class="primary">변경</button>
</form>
<?php
portal_foot();
