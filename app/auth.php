<?php
// app/auth.php
require_once __DIR__ . '/config.php';

function current_user()
{
    return $_SESSION['user'] ?? null;
}

function require_login()
{
    if (!current_user()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function require_role(string $role)
{
    require_login();
    if (current_user()['role'] !== $role) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
}
