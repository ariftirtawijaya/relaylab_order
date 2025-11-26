<?php
require_once __DIR__ . '/app/config.php';

if (!empty($_SESSION['user'])) {
    if ($_SESSION['user']['role'] === 'admin') {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . '/reseller/dashboard.php');
    }
} else {
    header('Location: ' . BASE_URL . '/login.php');
}
exit;
