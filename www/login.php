<?php
require __DIR__ . '/app/bootstrap.php';

if (current_admin()) {
    redirect('index.php');
}
// 관리자 계정이 하나도 없으면(처음 설치) 첫 관리자 만들기 화면으로
if ((int) db_value('SELECT COUNT(*) FROM admins') === 0 && is_file(__DIR__ . '/install.php')) {
    redirect('install.php');
}

$error = null;
if (is_post()) {
    csrf_check();
    $username = input_str('username', '', 50);
    $error = attempt_login($username, (string) ($_POST['password'] ?? ''));
    if ($error === null) {
        $next = local_url_or((string) ($_SESSION['after_login'] ?? ''), 'index.php');
        unset($_SESSION['after_login']);
        redirect($next);
    }
}

page_header('로그인', ['bare' => true]);
?>
<div class="narrow login-box">
  <h1><?= h(config('app_name', '끝장전 채팅·상품 관리')) ?></h1>
  <div class="card">
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label>아이디 <input name="username" required autofocus autocomplete="username" value="<?= h(input_str('username', '', 50)) ?>"></label>
      <label>비밀번호 <input type="password" name="password" required autocomplete="current-password"></label>
      <button type="submit" class="btn primary block">로그인</button>
    </form>
  </div>
</div>
<?php
page_footer();
