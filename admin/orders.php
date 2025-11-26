<?php
// admin/orders.php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('admin');

// Ambil semua order + info reseller + apakah ada item custom
$sql = "
    SELECT 
        o.*,
        r.name AS reseller_name,
        EXISTS (
            SELECT 1 
            FROM order_items oi 
            WHERE oi.order_id = o.id 
              AND oi.product_id IS NULL
        ) AS has_custom,
        (SELECT COUNT(*) FROM order_items oi2 WHERE oi2.order_id = o.id) AS item_count
    FROM orders o
    JOIN resellers r ON r.id = o.reseller_id
    ORDER BY o.order_date DESC, o.id DESC
";
$stmt = $pdo->query($sql);
$orders = $stmt->fetchAll();

include __DIR__ . '/../partials/header.php';
?>

<h3 class="mb-3">Daftar Order</h3>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Kode Order</th>
                        <th>Reseller</th>
                        <th>Tanggal</th>
                        <th>Item</th>
                        <th>Status</th>
                        <th>Custom</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$orders): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">Belum ada order.</td>
                        </tr>
                    <?php else: ?>
                        <?php $no = 1; ?>
                        <?php foreach ($orders as $o): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td><?= esc($o['code']) ?></td>
                                <td><?= esc($o['reseller_name']) ?></td>
                                <td><?= esc($o['order_date']) ?></td>
                                <td><?= (int) $o['item_count'] ?></td>
                                <td>
                                    <span class="badge bg-<?= badge_status($o['status']) ?>">
                                        <?= esc(format_status($o['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($o['has_custom']): ?>
                                        <span class="badge bg-warning text-dark">Ada item custom</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Normal</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="<?= base_url('admin/order_view.php?id=' . $o['id']) ?>"
                                        class="btn btn-sm btn-primary">Detail</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>