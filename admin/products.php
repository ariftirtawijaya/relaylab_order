<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('admin');

// handle delete
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$id]);
    redirect('admin/products.php');
}

$products = $pdo->query("SELECT * FROM products ORDER BY created_at DESC")->fetchAll();

include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Master Produk</h3>
    <a href="<?= base_url('admin/product_form.php') ?>" class="btn btn-primary btn-sm">Tambah Produk</a>
</div>

<table class="table table-sm table-striped align-middle">
    <thead>
        <tr>
            <th>No</th>
            <th>Nama</th>
            <th>Volt</th>
            <th>Harga</th>
            <th>Aktif</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php $no = 1;
        foreach ($products as $p): ?>
            <tr>
                <td><?= $no++ ?></td>
                <td><?= esc($p['name']) ?></td>
                <td><?= esc($p['voltage']) ?></td>
                <td>Rp<?= number_format($p['price'], 0, ',', '.') ?></td>
                <td><?= $p['is_active'] ? 'Ya' : 'Tidak' ?></td>
                <td class="text-end">
                    <a href="<?= base_url('admin/product_form.php?id=' . $p['id']) ?>"
                        class="btn btn-sm btn-outline-secondary">Edit</a>
                    <a href="<?= base_url('admin/products.php?delete=' . $p['id']) ?>" class="btn btn-sm btn-outline-danger"
                        onclick="return confirm('Hapus produk ini?')">Hapus</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php include __DIR__ . '/../partials/footer.php'; ?>