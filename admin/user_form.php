<?php
// admin/user_form.php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('admin');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// ambil list reseller untuk dropdown
$resellers = $pdo->query("SELECT id, name FROM resellers ORDER BY name")->fetchAll();

$userData = [
    'name' => '',
    'email' => '',
    'role' => 'reseller',
    'reseller_id' => null,
];

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $userRow = $stmt->fetch();
    if (!$userRow) {
        die("User tidak ditemukan");
    }
    $userData = $userRow;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'reseller';
    $reseller_id = $_POST['reseller_id'] !== '' ? (int) $_POST['reseller_id'] : null;
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($name === '' || $email === '') {
        $error = "Nama dan email wajib diisi";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Format email tidak valid";
    } elseif (!in_array($role, ['admin', 'reseller'], true)) {
        $error = "Role tidak valid";
    } elseif ($role === 'reseller' && !$reseller_id) {
        $error = "Jika role reseller, harus memilih reseller";
    } elseif ($password !== '' && $password !== $password2) {
        $error = "Password dan konfirmasi tidak sama";
    } else {
        // cek email unik
        if ($id) {
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE email = ? AND id <> ?");
            $stmt->execute([$email, $id]);
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE email = ?");
            $stmt->execute([$email]);
        }
        $count = $stmt->fetch()['c'] ?? 0;
        if ($count > 0) {
            $error = "Email sudah digunakan user lain";
        } else {
            if ($id) {
                // update
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users 
                                           SET name = ?, email = ?, role = ?, reseller_id = ?, password_hash = ?
                                           WHERE id = ?");
                    $stmt->execute([$name, $email, $role, $reseller_id, $hash, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users 
                                           SET name = ?, email = ?, role = ?, reseller_id = ?
                                           WHERE id = ?");
                    $stmt->execute([$name, $email, $role, $reseller_id, $id]);
                }
            } else {
                // insert baru, password wajib
                if ($password === '') {
                    $error = "Password wajib diisi untuk user baru";
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, reseller_id)
                                           VALUES (?,?,?,?,?)");
                    $stmt->execute([$name, $email, $hash, $role, $reseller_id]);
                }
            }

            if ($error === '') {
                redirect('admin/users.php');
            }
        }
    }

    // supaya form tetap terisi ketika ada error
    $userData['name'] = $name;
    $userData['email'] = $email;
    $userData['role'] = $role;
    $userData['reseller_id'] = $reseller_id;
}

include __DIR__ . '/../partials/header.php';
?>
<h3 class="mb-3"><?= $id ? 'Edit' : 'Tambah' ?> User</h3>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
<?php endif; ?>

<form method="post" class="card card-body shadow-sm border-0">
    <div class="mb-3">
        <label class="form-label">Nama *</label>
        <input type="text" name="name" class="form-control" required value="<?= esc($userData['name']) ?>">
    </div>

    <div class="mb-3">
        <label class="form-label">Email *</label>
        <input type="email" name="email" class="form-control" required value="<?= esc($userData['email']) ?>">
    </div>

    <div class="mb-3">
        <label class="form-label">Role *</label>
        <select name="role" class="form-select" id="roleSelect">
            <option value="admin" <?= $userData['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
            <option value="reseller" <?= $userData['role'] === 'reseller' ? 'selected' : '' ?>>Reseller</option>
        </select>
    </div>

    <div class="mb-3" id="resellerWrapper">
        <label class="form-label">Reseller (jika role = reseller)</label>
        <select name="reseller_id" class="form-select">
            <option value="">-- Pilih Reseller --</option>
            <?php foreach ($resellers as $r): ?>
                <option value="<?= $r['id'] ?>" <?= (int) $userData['reseller_id'] === (int) $r['id'] ? 'selected' : '' ?>>
                    <?= esc($r['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="row">
        <div class="mb-3 col-md-6">
            <label class="form-label">
                Password <?= $id ? '(kosongkan jika tidak diubah)' : '*' ?>
            </label>
            <input type="password" name="password" class="form-control">
        </div>
        <div class="mb-3 col-md-6">
            <label class="form-label">
                Konfirmasi Password <?= $id ? '' : '*' ?>
            </label>
            <input type="password" name="password2" class="form-control">
        </div>
    </div>

    <button class="btn btn-primary">Simpan</button>
    <a href="<?= base_url('admin/users.php') ?>" class="btn btn-secondary">Kembali</a>
</form>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const roleSelect = document.getElementById('roleSelect');
        const resellerWrapper = document.getElementById('resellerWrapper');

        function toggleReseller() {
            if (roleSelect.value === 'reseller') {
                resellerWrapper.style.display = 'block';
            } else {
                resellerWrapper.style.display = 'none';
            }
        }

        toggleReseller();
        roleSelect.addEventListener('change', toggleReseller);
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>