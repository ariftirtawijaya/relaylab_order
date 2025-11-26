<?php
// admin/resellers.php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('admin');

// handle delete
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM resellers WHERE id = ?");
    $stmt->execute([$id]);
    redirect('admin/resellers.php');
}

$resellers = $pdo->query("SELECT * FROM resellers ORDER BY created_at DESC")->fetchAll();

include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Master Reseller</h3>
    <a href="<?= base_url('admin/reseller_form.php') ?>" class="btn btn-primary btn-sm">Tambah Reseller</a>
</div>

<table class="table table-sm table-striped align-middle">
    <thead>
        <tr>
            <th>Nama</th>
            <th>WhatsApp</th>
            <th>Alamat</th>
            <th>Dibuat</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$resellers): ?>
            <tr>
                <td colspan="5" class="text-center text-muted">Belum ada data reseller.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($resellers as $r): ?>
                <tr>
                    <td><?= esc($r['name']) ?></td>
                    <td><?= esc($r['whatsapp']) ?></td>
                    <td><?= nl2br(esc($r['address'])) ?></td>
                    <td><?= esc($r['created_at']) ?></td>
                    <td class="text-end">
                        <a href="<?= base_url('admin/reseller_form.php?id=' . $r['id']) ?>"
                            class="btn btn-sm btn-outline-secondary">Edit</a>
                        <a href="<?= base_url('admin/resellers.php?delete=' . $r['id']) ?>"
                            class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus reseller ini?')">Hapus</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php include __DIR__ . '/../partials/footer.php'; ?>