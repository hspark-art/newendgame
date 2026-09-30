<?php
require __DIR__ . '/app/bootstrap.php';

if (is_post()) {
    csrf_check();
    logout();
}
redirect('login.php');
