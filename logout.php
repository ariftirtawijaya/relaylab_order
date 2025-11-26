<?php
require_once __DIR__ . '/app/config.php';

unset($_SESSION['user']);
session_destroy();

header('Location: ' . BASE_URL . '/login.php');
exit;
