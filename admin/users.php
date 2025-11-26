<?php
// admin/users.php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('admin');

// handle delete user (opsional, bisa dimatikan kalau tidak mau hapus)
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];

    // Jangan hapus diri sendiri
    $me = current_user();
    if ($me['id'] == $id) {
        // abaikan atau kasih pesan
    } else {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
    }

    redirect('admin/users.php');
}

// ambil semua user + reseller name (kalau ada)
$sql = "SELECT u.*, r.name AS reseller_name
        FROM users u
        LEFT JOIN resellers r ON r.id = u.reseller_id
        ORDER BY u.created_at DESC";
$users = $pdo->query($sql)->fetchAll();

include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Manajemen User Login</h3>
    <a href="<?= base_url('admin/user_form.php') ?>" class="btn btn-primary btn-sm">Tambah User</a>
</div>

<table class="table table-sm table-striped align-middle">
    <thead>
        <tr>
            <th>Nama</th>
            <th>Email</th>
            <th>Role</th>
            <th>Reseller</th>
            <th>Dibuat</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$users): ?>
            <tr>
                <td colspan="6" class="text-center text-muted">Belum ada user.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= esc($u['name']) ?></td>
                    <td><?= esc($u['email']) ?></td>
                    <td><?= esc($u['role']) ?></td>
                    <td><?= esc($u['reseller_name']) ?></td>
                    <td><?= esc($u['created_at']) ?></td>
                    <td class="text-end">
                        <a href="<?= base_url('admin/user_form.php?id=' . $u['id']) ?>"
                            class="btn btn-sm btn-outline-secondary">Edit</a>
                        <?php if ($u['id'] != current_user()['id']): ?>
                            <a href="<?= base_url('admin/users.php?delete=' . $u['id']) ?>" class="btn btn-sm btn-outline-danger"
                                onclick="return confirm('Hapus user ini?')">Hapus</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php include __DIR__ . '/../partials/footer.php'; ?>