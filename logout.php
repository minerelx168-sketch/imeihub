<?php
declare(strict_types=1);
require __DIR__ . '/includes/auth.php';
auth_destroy_session();
header('Location: /');
exit;
