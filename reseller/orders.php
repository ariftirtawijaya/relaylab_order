<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('reseller');
$user = current_user();
$resellerId = $user['reseller_id'];

$stmt = $pdo->prepare("SELECT o.*, 
    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
  FROM orders o
  WHERE o.reseller_id = ?
  ORDER BY o.order_date DESC");
$stmt->execute([$resellerId]);
$orders = $stmt->fetchAll();

include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Order Saya</h3>
    <a href="<?= base_url('reseller/order_new.php') ?>" class="btn btn-primary btn-sm">Buat Order Baru</a>
</div>

<table class="table table-sm table-striped align-middle">
    <thead>
        <tr>
            <th>No</th>
            <th>Tanggal</th>
            <th>Item</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php $no = 1;
        foreach ($orders as $o): ?>
            <tr>
                <td><?= $no++ ?></td>
                <td><?= esc($o['order_date']) ?></td>
                <td><?= (int) $o['item_count'] ?></td>
                <td><?= esc(format_status($o['status'])) ?></td>
                <td class="text-end">
                    <a href="<?= base_url('reseller/order_view.php?id=' . $o['id']) ?>"
                        class="btn btn-sm btn-outline-secondary">Detail</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php include __DIR__ . '/../partials/footer.php'; ?>