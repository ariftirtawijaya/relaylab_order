<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('reseller');
$user = current_user();

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT o.*, r.name AS reseller_name
  FROM orders o
  JOIN resellers r ON r.id = o.reseller_id
  WHERE o.id = ? AND o.reseller_id = ?");
$stmt->execute([$id, $user['reseller_id']]);
$order = $stmt->fetch();

if (!$order) {
    die("Order tidak ditemukan");
}

$stmt = $pdo->prepare("SELECT oi.*, p.code, p.name
  FROM order_items oi
  JOIN products p ON p.id = oi.product_id
  WHERE oi.order_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

// pengiriman
$stmt = $pdo->prepare("SELECT * FROM shipments WHERE order_id = ? ORDER BY ship_date");
$stmt->execute([$id]);
$shipments = $stmt->fetchAll();

$shipmentItems = [];
if ($shipments) {
    $shipmentIds = array_column($shipments, 'id');
    $in = implode(',', array_fill(0, count($shipmentIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT si.*, oi.product_id, p.name AS product_name
         FROM shipment_items si
         JOIN order_items oi ON oi.id = si.order_item_id
         JOIN products p ON p.id = oi.product_id
         WHERE si.shipment_id IN ($in)"
    );
    $stmt->execute($shipmentIds);
    foreach ($stmt->fetchAll() as $row) {
        $shipmentItems[$row['shipment_id']][] = $row;
    }
}

include __DIR__ . '/../partials/header.php';
?>
<h3 class="mb-3">Detail Order <?= esc($order['code']) ?></h3>

<div class="mb-3">
    <span class="badge bg-<?= badge_status($order['status']) ?>">
        <?= esc(format_status($order['status'])) ?>
    </span>

</div>


<div class="card mb-4">
    <div class="card-body">
        <p><strong>Reseller:</strong> <?= esc($order['reseller_name']) ?></p>
        <p><strong>Tanggal Order:</strong> <?= esc($order['order_date']) ?></p>
        <?php if ($order['notes_reseller']): ?>
            <p><strong>Catatan Anda:</strong><br><?= nl2br(esc($order['notes_reseller'])) ?></p>
        <?php endif; ?>
        <?php if ($order['notes_internal']): ?>
            <p><strong>Catatan Admin:</strong><br><?= nl2br(esc($order['notes_internal'])) ?></p>
        <?php endif; ?>
    </div>
</div>

<h5>Item Order</h5>
<div class="table-responsive mb-4">
    <table id="orderItemsTable" class="table table-sm table-striped align-middle nowrap" style="width:100%;">
        <thead>
            <tr>
                <th>No</th>
                <th>Nama Produk</th>
                <th>Qty Pesan</th>
                <th>Produksi Selesai</th>
                <th>Sudah Dikirim</th>
                <th>Sisa Kirim</th>
            </tr>
        </thead>
        <tbody>
            <?php $no = 1;
            foreach ($items as $it):
                $sisa = $it['qty_order'] - $it['qty_shipped'];
                ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><?= esc($it['name']) ?></td>
                    <td><?= (int) $it['qty_order'] ?></td>
                    <td><?= (int) $it['qty_done'] ?></td>
                    <td><?= (int) $it['qty_shipped'] ?></td>
                    <td><?= (int) $sisa ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>


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
                                    <th>Produk</th>
                                    <th>Qty Kirim</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($shipmentItems[$s['id']] as $si): ?>
                                    <tr>
                                        <td><?= esc($si['product_name']) ?></td>
                                        <td><?= (int) $si['qty'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<a href="<?= base_url('reseller/orders.php') ?>" class="btn btn-secondary">Kembali</a>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        $('#orderItemsTable').DataTable({
            responsive: true,
            paging: false,     // tidak perlu halaman, semua tampil
            searching: false,  // tidak perlu kotak search
            info: false,       // sembunyikan "Showing 1 to ..."
            ordering: false,   // kalau mau urutan fix dari server
            scrollX: true,     // kalau kolom banyak / nama panjang, bisa geser horizontal
            language: {
                emptyTable: "Tidak ada data item order",
            }
        });
    });
</script>


<?php include __DIR__ . '/../partials/footer.php'; ?>