<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('reseller');
$user = current_user();

if (!function_exists('format_rupiah')) {
    function format_rupiah(int $v): string
    {
        return 'Rp ' . number_format($v, 0, ',', '.');
    }
}

$id = (int) ($_GET['id'] ?? 0);

// Ambil data order milik reseller yang sedang login
$stmt = $pdo->prepare("
    SELECT o.*, r.name AS reseller_name
    FROM orders o
    JOIN resellers r ON r.id = o.reseller_id
    WHERE o.id = ? AND o.reseller_id = ?
");
$stmt->execute([$id, $user['reseller_id']]);
$order = $stmt->fetch();

if (!$order) {
    die("Order tidak ditemukan");
}

// Ambil item order, dukung produk custom + harga
$stmt = $pdo->prepare("
    SELECT 
        oi.*,
        p.code,
        p.voltage,
        p.price AS unit_price,
        COALESCE(oi.custom_name, p.name) AS name
    FROM order_items oi
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = ?
");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

// Hitung total order berdasarkan harga produk
$totalOrder = 0;
$totalQtyOrder = 0;
foreach ($items as $it) {
    $price = isset($it['unit_price']) ? (int) $it['unit_price'] : 0;
    $qty = (int) $it['qty_order'];
    $totalQtyOrder += $qty;
    if ($price > 0 && $qty > 0) {
        $totalOrder += $price * $qty;
    }
}

// Ambil data pembayaran untuk order ini
$stmt = $pdo->prepare("
    SELECT * 
    FROM order_payments 
    WHERE order_id = ? 
    ORDER BY pay_date, id
");
$stmt->execute([$id]);
$payments = $stmt->fetchAll();

$totalPaid = 0;
foreach ($payments as $pay) {
    $totalPaid += (int) $pay['amount'];
}

$sisaBayar = max($totalOrder - $totalPaid, 0);

// Status pembayaran
if ($totalOrder > 0 && $totalPaid >= $totalOrder) {
    $statusBayar = 'Lunas';
    $statusBayarClass = 'success';
} elseif ($totalPaid > 0) {
    $statusBayar = 'Belum Lunas';
    $statusBayarClass = 'warning';
} else {
    $statusBayar = 'Belum Ada Pembayaran';
    $statusBayarClass = 'secondary';
}

// Ambil data pengiriman
$stmt = $pdo->prepare("SELECT * FROM shipments WHERE order_id = ? ORDER BY ship_date");
$stmt->execute([$id]);
$shipments = $stmt->fetchAll();

// Ambil item per pengiriman (jika ada)
$shipmentItems = [];
if ($shipments) {
    $shipmentIds = array_column($shipments, 'id');
    $placeholders = implode(',', array_fill(0, count($shipmentIds), '?'));

    $sql = "
        SELECT 
            si.*,
            oi.product_id,
            COALESCE(oi.custom_name, p.name) AS product_name
        FROM shipment_items si
        JOIN order_items oi ON oi.id = si.order_item_id
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE si.shipment_id IN ($placeholders)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($shipmentIds);

    foreach ($stmt->fetchAll() as $row) {
        $shipmentItems[$row['shipment_id']][] = $row;
    }
}

include __DIR__ . '/../partials/header.php';
?>
<h3 class="mb-3">Detail Order <?= esc($order['code']) ?></h3>

<div class="mb-3 d-flex flex-wrap gap-2 align-items-center">
    <span class="badge bg-<?= badge_status($order['status']) ?>">
        <?= esc(format_status($order['status'])) ?>
    </span>
    <span class="badge bg-<?= esc($statusBayarClass) ?>">
        Pembayaran: <?= esc($statusBayar) ?>
    </span>
</div>

<div class="card mb-4">
    <div class="card-body">
        <p><strong>Reseller:</strong> <?= esc($order['reseller_name']) ?></p>
        <p><strong>Tanggal Order:</strong> <?= esc($order['order_date']) ?></p>
        <p><strong>Total Qty Order:</strong> <?= (int) $totalQtyOrder ?></p>

        <hr>

        <p class="mb-1"><strong>Total Order:</strong> <?= format_rupiah($totalOrder) ?></p>
        <p class="mb-1"><strong>Sudah Dibayar:</strong> <?= format_rupiah($totalPaid) ?></p>
        <p class="mb-0"><strong>Sisa Pembayaran:</strong> <?= format_rupiah($sisaBayar) ?></p>

        <?php if ($order['notes_reseller']): ?>
            <hr>
            <p><strong>Catatan Anda:</strong><br><?= nl2br(esc($order['notes_reseller'])) ?></p>
        <?php endif; ?>
        <?php if ($order['notes_internal']): ?>
            <p><strong>Catatan Admin:</strong><br><?= nl2br(esc($order['notes_internal'])) ?></p>
        <?php endif; ?>
    </div>
</div>

<h5>Item Order</h5>
<div class="card mb-4">
    <div class="card-body">
        <table id="itemsTable" class="table table-sm table-bordered align-middle w-100">
            <thead>
                <tr>
                    <th class="text-center">No</th>
                    <th class="text-nowrap text-center">Nama Produk</th>
                    <th class="text-center align-middle">Qty Pesan</th>
                    <th class="text-center align-middle align-items-center">Harga / pcs</th>
                    <th class="text-center">Subtotal</th>
                    <th class="text-center">Produksi Selesai</th>
                    <th class="text-center">Sudah Dikirim</th>
                    <th class="text-center">Sisa Kirim</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$items): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted">Belum ada item.</td>
                    </tr>
                <?php else: ?>
                    <?php
                    $no = 1;
                    foreach ($items as $it):
                        $sisa = $it['qty_order'] - $it['qty_shipped'];
                        $price = isset($it['unit_price']) ? (int) $it['unit_price'] : 0;
                        $qty = (int) $it['qty_order'];
                        $sub = $price > 0 && $qty > 0 ? $price * $qty : 0;
                        ?>
                        <tr>
                            <td class="text-center"><?= $no++ ?></td>
                            <td class="">
                                <?= esc($it['name']) ?>
                                <?php
                                $volt = trim((string) $it['voltage']);
                                if ($volt !== '' && $volt !== '-') {
                                    echo ' (' . esc($volt) . 'V)';
                                }
                                ?>
                            </td>
                            <td class="text-center"><?= (int) $it['qty_order'] ?></td>
                            <td class="text-center">
                                <?= $price > 0 ? format_rupiah($price) : '<span class="text-muted">-</span>' ?>
                            </td>
                            <td class="text-center">
                                <?= $sub > 0 ? format_rupiah($sub) : '<span class="text-muted">-</span>' ?>
                            </td>
                            <td class="text-center"><?= (int) $it['qty_done'] ?></td>
                            <td class="text-center"><?= (int) $it['qty_shipped'] ?></td>
                            <td class="text-center">
                                <?php
                                $sisaInt = (int) $sisa;
                                if ($sisaInt === 0) {
                                    echo '<span class="badge bg-success">0</span>';
                                } else if ($sisaInt < $qty) {
                                    ?> <span class="badge bg-warning"><?= $sisaInt; ?></span>
                                    <?php
                                } else {
                                    ?> <span class="badge bg-danger"><?= $sisaInt; ?></span>
                                    <?php
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery('#itemsTable').DataTable({
                searching: false,
                info: false,
                ordering: false,
                columnDefs: [
                    {
                        className: 'dtr-control',
                        orderable: false,
                        targets: 0
                    }
                ],
                responsive: {
                    details: {
                        type: 'column'
                    }
                },
                language: {
                    emptyTable: "Belum ada item.",
                    lengthMenu: "Menampilkan _MENU_ data"
                }
            });


        }
    });

</script>

<?php if ($payments): ?>
    <h5>Pembayaran</h5>
    <div class="card  mb-4">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead>
                        <tr>
                            <th class="text-center">Tanggal</th>
                            <th class="text-nowrap text-center">Nominal</th>
                            <th class="text-nowrap text-center">Catatan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <?php $date = strtotime($p['pay_date']); ?>
                            <tr>
                                <td class="text-center"><?= date('d-m-Y', $date) ?></td>
                                <td class="text-nowrap text-center" style="min-width: 140px;">
                                    <?= format_rupiah((int) $p['amount']) ?>
                                </td>
                                <td class="text-nowrap text-center"><?= esc($p['notes']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<h5>Pengiriman</h5>
<?php if (!$shipments): ?>
    <p class="text-muted">Belum ada pengiriman.</p>
<?php else: ?>
    <?php foreach ($shipments as $s): ?>
        <div class="card mb-3">
            <div class="card-body">
                <p class="mb-1"><strong>Tanggal Kirim:</strong> <?= esc($s['ship_date']) ?></p>
                <p class="mb-1"><strong>Ekspedisi:</strong> <?= esc($s['courier']) ?></p>
                <p class="mb-1"><strong>No Resi:</strong> <?= esc($s['tracking_number']) ?></p>
                <?php if ($s['notes']): ?>
                    <p class="mb-2"><strong>Catatan:</strong> <?= nl2br(esc($s['notes'])) ?></p>
                <?php endif; ?>

                <?php if (!empty($shipmentItems[$s['id']])): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th class="text-nowrap text-center align-middle" style="width: 36px;">No</th>
                                    <th class="text-nowrap text-center align-middle">Produk</th>
                                    <th class="text-center align-middle">Qty Kirim</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $no = 1;
                                $qty_total = 0;
                                foreach ($shipmentItems[$s['id']] as $si):
                                    $qty_total += $si['qty'];
                                    ?>
                                    <tr>
                                        <td class="text-center align-middle"><?= $no++ ?></td>
                                        <td><?= esc($si['product_name']) ?></td>
                                        <td class="text-center align-middle"><?= (int) $si['qty'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td class="text-center align-middle" colspan="2"><strong>Total Qty</strong></td>
                                    <td class="text-center align-middle"><strong><?= $qty_total ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<a href="<?= base_url('reseller/orders.php') ?>" class="btn btn-secondary">Kembali</a>

<?php include __DIR__ . '/../partials/footer.php'; ?>