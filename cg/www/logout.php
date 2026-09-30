<?php
declare(strict_types=1);

// 로그아웃 (POST만)
require __DIR__ . '/app/bootstrap.php';

portal_start();
form_check();
$u = auth_user_optional();
if ($u !== null) {
    cg_log('auth', 'LOGOUT', auth_op($u));
}
auth_logout();
redirect('login.php');
