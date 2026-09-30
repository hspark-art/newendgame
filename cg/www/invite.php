<?php
declare(strict_types=1);

// 초대 링크로 가입 신청 → 관리자 승인 대기
require __DIR__ . '/app/bootstrap.php';

portal_start();
$token = (string)($_GET['token'] ?? '');
$error = null;
$in = ['username' => '', 'name' => ''];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    form_check();
    $in = ['username' => post_str('username', 60), 'name' => post_str('name', 60)];
    try {
        $u = auth_register($token, $in['username'], $in['name'], post_str('password'), post_str('password2'));
        auth_start_session_for($u);
        redirect('pending.php');
    } catch (ActionError $e) {
        $error = $e->getMessage();
        http_response_code($e->status);
    }
}
if (link_valid($token, 'invite') === null) {
    http_response_code(410);
    portal_head('초대 링크를 사용할 수 없습니다');
    echo '<p class="msg err">초대 링크가 만료되었거나 이미 사용되었거나 취소되었습니다. 관리자에게 새 링크를 요청하세요.</p>';
    portal_foot();
    exit;
}
portal_head('가입 신청');
portal_error($error);
?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <label>아이디 <input name="username" value="<?= h($in['username']) ?>" autocomplete="username" required pattern="[a-z0-9][a-z0-9._\-]{2,29}" title="영문 소문자·숫자·. _ - 3~30자"></label>
  <label>표시 이름 <input name="name" value="<?= h($in['name']) ?>" maxlength="30" required placeholder="예: 박작가"></label>
  <label>비밀번호 (12자 이상) <input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
  <label>비밀번호 확인 <input type="password" name="password2" autocomplete="new-password" minlength="12" required></label>
  <button class="primary">가입 신청</button>
</form>
<p class="hint">신청 후 관리자가 승인해야 사용할 수 있습니다. 이 링크는 한 번만 쓸 수 있습니다.</p>
<?php
portal_foot();
