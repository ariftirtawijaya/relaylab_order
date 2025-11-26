<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('admin');

$orders = $pdo->query("SELECT o.*, r.name AS reseller_name
  FROM orders o
  JOIN resellers r ON r.id = o.reseller_id
  ORDER BY o.order_date DESC")->fetchAll();

include __DIR__ . '/../partials/header.php';
?>
<h3 class="mb-3">Semua Order</h3>

<table class="table table-sm table-striped align-middle">
    <thead>
        <tr>
            <th>No</th>
            <th>Reseller</th>
            <th>Tanggal</th>
            <th>Status</th>
            <th>Total Qty</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php $no = 1;
        foreach ($orders as $o): ?>
            <tr>
                <td><?= $no++ ?></td>
                <td><?= esc($o['reseller_name']) ?></td>
                <td><?= esc($o['order_date']) ?></td>
                <td><?= esc($o['status']) ?></td>
                <td><?= (int) $o['total_qty'] ?></td>
                <td class="text-end">
                    <a href="<?= base_url('admin/order_view.php?id=' . $o['id']) ?>"
                        class="btn btn-sm btn-outline-secondary">Detail</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php include __DIR__ . '/../partials/footer.php'; ?>