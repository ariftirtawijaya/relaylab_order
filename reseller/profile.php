<?php
// reseller/profile.php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('reseller');

$userSession = current_user();
$userId = $userSession['id'];

// ambil data user dari DB
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) {
    die("User tidak ditemukan");
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $new_password_again = $_POST['new_password_again'] ?? '';

    // Validasi dasar nama & email
    if ($name === '' || $email === '') {
        $error = "Nama dan email wajib diisi.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Format email tidak valid.";
    } else {
        // cek email sudah dipakai user lain atau tidak
        $stmt = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE email = ? AND id <> ?");
        $stmt->execute([$email, $userId]);
        $count = $stmt->fetch()['c'] ?? 0;
        if ($count > 0) {
            $error = "Email sudah digunakan oleh user lain.";
        }
    }

    // Kalau tidak ada error sampai sini, lanjut cek password bila diisi
    $updatePassword = false;
    if (!$error && ($current_password !== '' || $new_password !== '' || $new_password_again !== '')) {
        // berarti user ingin ganti password
        if ($current_password === '' || $new_password === '' || $new_password_again === '') {
            $error = "Untuk mengganti password, semua kolom password harus diisi.";
        } elseif (!password_verify($current_password, $user['password_hash'])) {
            $error = "Password sekarang tidak sesuai.";
        } elseif ($new_password !== $new_password_again) {
            $error = "Password baru dan konfirmasi password baru tidak sama.";
        } else {
            $updatePassword = true;
        }
    }

    if (!$error) {
        // update nama & email
        if ($updatePassword) {
            $newHash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, password_hash = ? WHERE id = ?");
            $stmt->execute([$name, $email, $newHash, $userId]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $stmt->execute([$name, $email, $userId]);
        }

        // update data di session juga
        $_SESSION['user']['name'] = $name;
        $_SESSION['user']['email'] = $email;

        $success = "Profil berhasil diperbarui.";
        // refresh data user untuk tampilan form
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    }
}

include __DIR__ . '/../partials/header.php';
?>
<h3 class="mb-3">Profil Akun Reseller</h3>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= esc($success) ?></div>
<?php endif; ?>

<form method="post" class="card card-body shadow-sm border-0 mb-4">
    <h5 class="mb-3">Data Utama</h5>
    <div class="mb-3">
        <label class="form-label">Nama</label>
        <input type="text" name="name" class="form-control" required value="<?= esc($user['name']) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label">Email (untuk login)</label>
        <input type="email" name="email" class="form-control" required value="<?= esc($user['email']) ?>">
    </div>

    <hr>

    <h5 class="mb-3">Ganti Password (opsional)</h5>
    <p class="text-muted small">
        Isi bagian ini hanya jika ingin mengganti password. Kalau tidak, biarkan kosong.
    </p>

    <div class="mb-3">
        <label class="form-label">Password Sekarang</label>
        <input type="password" name="current_password" class="form-control">
    </div>

    <div class="row">
        <div class="mb-3 col-md-6">
            <label class="form-label">Password Baru</label>
            <input type="password" name="new_password" class="form-control">
        </div>
        <div class="mb-3 col-md-6">
            <label class="form-label">Konfirmasi Password Baru</label>
            <input type="password" name="new_password_again" class="form-control">
        </div>
    </div>

    <button class="btn btn-primary">Simpan Perubahan</button>
</form>

<a href="<?= base_url('reseller/dashboard.php') ?>" class="btn btn-secondary">Kembali ke Dashboard</a>

<?php include __DIR__ . '/../partials/footer.php'; ?>