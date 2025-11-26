<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('reseller');
$user = current_user();
$resellerId = $user['reseller_id'];

// Ambil semua order + total qty per order
$stmt = $pdo->prepare("
    SELECT 
        o.*,
        (
            SELECT COALESCE(SUM(qty_order), 0)
            FROM order_items oi
            WHERE oi.order_id = o.id
        ) AS total_qty
    FROM orders o
    WHERE o.reseller_id = ?
    ORDER BY o.order_date DESC
");
$stmt->execute([$resellerId]);
$orders = $stmt->fetchAll();

include __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Order Saya</h3>
    <a href="<?= base_url('reseller/order_new.php') ?>" class="btn btn-primary btn-sm">Buat Order Baru</a>
</div>

<div class="card mb-4">
    <div class="card-body">

        <table class="table table-sm table-striped align-middle w-100">
            <thead>
                <tr>
                    <th class="text-center align-middle">No</th>
                    <th class="text-center align-middle">Tanggal</th>
                    <th class="text-center align-middle">Total Qty</th>
                    <th class="text-center align-middle">Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1;
                foreach ($orders as $o): ?>
                    <?php $date = strtotime($o['order_date']); ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td class="text-center"><?= date('d-m-y', $date) ?></td>
                        <td class="text-center"><?= (int) $o['total_qty'] ?> Item</td>
                        <td class="text-center">
                            <span class=" badge bg-<?= badge_status($o['status']) ?>">
                                <?= addNewLine(format_status($o['status'])) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="<?= base_url('reseller/order_view.php?id=' . $o['id']) ?>"
                                class="btn btn-sm btn-outline-primary">Detail</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>