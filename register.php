<?php
// register.php
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/helpers.php';

$name = '';
$whatsapp = '';
$address = '';
$email = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    // Validasi dasar
    if ($name === '' || $email === '' || $password === '' || $password2 === '') {
        $error = "Nama, email, dan password wajib diisi.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Format email tidak valid.";
    } elseif ($password !== $password2) {
        $error = "Password dan konfirmasi password tidak sama.";
    } else {
        // cek email unik
        $stmt = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $count = $stmt->fetch()['c'] ?? 0;
        if ($count > 0) {
            $error = "Email sudah digunakan, silakan gunakan email lain atau login.";
        }
    }

    if (!$error) {
        try {
            $pdo->beginTransaction();

            // 1. Buat reseller
            $stmt = $pdo->prepare("INSERT INTO resellers (name, whatsapp, address) VALUES (?,?,?)");
            $stmt->execute([$name, $whatsapp, $address]);
            $resellerId = $pdo->lastInsertId();

            // 2. Buat user dengan role reseller
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, reseller_id)
                                   VALUES (?,?,?,?,?)");
            $stmt->execute([$name, $email, $hash, 'reseller', $resellerId]);
            $userId = $pdo->lastInsertId();

            $pdo->commit();

            // 3. Auto login
            $_SESSION['user'] = [
                'id' => $userId,
                'name' => $name,
                'email' => $email,
                'role' => 'reseller',
                'reseller_id' => $resellerId,
            ];

            redirect('reseller/dashboard.php');

        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = "Terjadi kesalahan saat pendaftaran: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Daftar Reseller - RelayLab Order Management System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light d-flex align-items-center" style="min-height:100vh;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h4 class="mb-3 text-center">Daftar Reseller</h4>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= esc($error) ?></div>
                        <?php endif; ?>

                        <form method="post">
                            <div class="mb-3">
                                <label class="form-label">Nama Toko / Reseller *</label>
                                <input type="text" name="name" class="form-control" required value="<?= esc($name) ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">WhatsApp</label>
                                <input type="text" name="whatsapp" class="form-control" value="<?= esc($whatsapp) ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Alamat</label>
                                <textarea name="address" rows="2" class="form-control"><?= esc($address) ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Email (untuk login) *</label>
                                <input type="email" name="email" class="form-control" required
                                    value="<?= esc($email) ?>">
                            </div>

                            <div class="row">
                                <div class="mb-3 col-md-6">
                                    <label class="form-label">Password *</label>
                                    <input type="password" name="password" class="form-control" required>
                                </div>
                                <div class="mb-3 col-md-6">
                                    <label class="form-label">Konfirmasi Password *</label>
                                    <input type="password" name="password2" class="form-control" required>
                                </div>
                            </div>

                            <button class="btn btn-primary w-100 mb-2">Daftar</button>
                        </form>

                        <div class="text-center mt-2">
                            <small>Sudah punya akun?
                                <a href="login.php">Login di sini</a>
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