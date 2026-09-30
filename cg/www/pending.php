<?php
declare(strict_types=1);

// 가입 신청 후 승인 대기 화면
require __DIR__ . '/app/bootstrap.php';

portal_start();
$u = auth_user_optional();
if ($u === null) {
    redirect('login.php');
}
if ($u['status'] === 'active') {
    redirect('index.php');
}
portal_head('승인 대기 중', $u);
?>
<div class="card">
  <p><b><?= h($u['name']) ?></b> (<?= h($u['username']) ?>) 님의 가입 신청이 접수되었습니다.</p>
  <p>관리자가 승인하면 조작 패널을 사용할 수 있습니다. 승인 후 이 화면을 새로고침하세요.</p>
  <p><a class="btn" href="pending.php">새로고침</a></p>
</div>
<?php
portal_foot();
