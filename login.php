<?php
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/helpers.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $error = 'Email atau password salah';
    } else {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'reseller_id' => $user['reseller_id'],
        ];
        if ($user['role'] === 'admin') {
            redirect('admin/dashboard.php');
        } else {
            redirect('reseller/dashboard.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Login - RelayLab Order Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light d-flex align-items-center" style="min-height:100vh;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h4 class="mb-3 text-center">Login</h4>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= esc($error) ?></div>
                        <?php endif; ?>
                        <form method="post">
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" required autofocus>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <button class="btn btn-primary w-100 mb-2">Masuk</button>
                        </form>

                        <div class="text-center mt-2">
                            <small>Belum punya akun?
                                <a href="register.php">Daftar sebagai reseller</a>
                            </small>
                        </div>
                    </div>
                </div>
                <p class="text-center mt-3 text-muted small">RelayLab Order Management System</p>
            </div>
        </div>
    </div>
</body>

</html>