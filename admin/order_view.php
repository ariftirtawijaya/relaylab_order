<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('admin');

$id = (int) ($_GET['id'] ?? 0);

// handle update status order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $status = $_POST['status'] ?? 'menunggu_konfirmasi';
    if (!in_array($status, ['menunggu_konfirmasi', 'diproses', 'selesai'], true)) {
        $status = 'menunggu_konfirmasi';
    }
    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);
    redirect('admin/order_view.php?id=' . $id);
}

// handle update qty_done
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_progress'])) {
    $qty_done = $_POST['qty_done'] ?? [];
    foreach ($qty_done as $itemId => $val) {
        $itemId = (int) $itemId;
        $val = max(0, (int) $val);
        // jangan lebih dari qty_order
        $stmt = $pdo->prepare("SELECT qty_order FROM order_items WHERE id = ? AND order_id = ?");
        $stmt->execute([$itemId, $id]);
        $row = $stmt->fetch();
        if ($row) {
            $max = (int) $row['qty_order'];
            if ($val > $max)
                $val = $max;
            $upd = $pdo->prepare("UPDATE order_items SET qty_done = ? WHERE id = ?");
            $upd->execute([$val, $itemId]);
        }
    }
    redirect('admin/order_view.php?id=' . $id);
}

// handle tambah pengiriman
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_shipment'])) {
    $courier = trim($_POST['courier'] ?? '');
    $tracking = trim($_POST['tracking_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $qty_ship = $_POST['ship_qty'] ?? [];

    // filter qty_ship > 0
    $itemsToShip = [];
    foreach ($qty_ship as $itemId => $q) {
        $q = (int) $q;
        if ($q > 0) {
            $itemsToShip[(int) $itemId] = $q;
        }
    }

    if (!empty($itemsToShip)) {
        $pdo->beginTransaction();
        try {
            // buat shipment
            $stmt = $pdo->prepare("INSERT INTO shipments (order_id, ship_date, courier, tracking_number, notes)
                                   VALUES (?,?,?,?,?)");
            $stmt->execute([$id, date('Y-m-d H:i:s'), $courier, $tracking, $notes]);
            $shipId = $pdo->lastInsertId();

            $stmtSelectItem = $pdo->prepare("SELECT id, qty_order, qty_shipped FROM order_items WHERE id = ? AND order_id = ?");
            $stmtInsertShipItem = $pdo->prepare("INSERT INTO shipment_items (shipment_id, order_item_id, qty) VALUES (?,?,?)");
            $stmtUpdateShipped = $pdo->prepare("UPDATE order_items SET qty_shipped = ? WHERE id = ?");

            foreach ($itemsToShip as $itemId => $qShip) {
                $stmtSelectItem->execute([$itemId, $id]);
                $row = $stmtSelectItem->fetch();
                if (!$row)
                    continue;
                $maxShip = $row['qty_order'] - $row['qty_shipped'];
                if ($maxShip <= 0)
                    continue;
                if ($qShip > $maxShip)
                    $qShip = $maxShip;

                // insert detail shipment
                $stmtInsertShipItem->execute([$shipId, $itemId, $qShip]);

                // update qty_shipped
                $newShipped = $row['qty_shipped'] + $qShip;
                $stmtUpdateShipped->execute([$newShipped, $itemId]);
            }

            // auto: kalau semua sudah shipped, boleh bantu set status ke 'selesai' (opsional)
            $check = $pdo->prepare("SELECT COUNT(*) c FROM order_items WHERE order_id = ? AND qty_shipped < qty_order");
            $check->execute([$id]);
            $remain = $check->fetch()['c'];
            if ((int) $remain === 0) {
                $pdo->prepare("UPDATE orders SET status = 'selesai' WHERE id = ?")->execute([$id]);
            }

            $pdo->commit();
            redirect('admin/order_view.php?id=' . $id);
        } catch (Throwable $e) {
            $pdo->rollBack();
            die("Gagal membuat pengiriman: " . $e->getMessage());
        }
    }
}

// ambil order + items + shipments
$stmt = $pdo->prepare("SELECT o.*, r.name AS reseller_name
  FROM orders o
  JOIN resellers r ON r.id = o.reseller_id
  WHERE o.id = ?");
$stmt->execute([$id]);
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
<h3 class="mb-3">Detail Order (Admin) - <?= esc($order['code']) ?></h3>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-body">
                <p><strong>Reseller:</strong> <?= esc($order['reseller_name']) ?></p>
                <p><strong>Tanggal Order:</strong> <?= esc($order['order_date']) ?></p>
                <p><strong>Total Qty:</strong> <?= (int) $order['total_qty'] ?></p>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <form method="post" class="row g-2 align-items-center">
                    <input type="hidden" name="update_status" value="1">
                    <div class="col-auto">
                        <label class="form-label mb-0"><strong>Status Order:</strong></label>
                    </div>
                    <div class="col-auto">
                        <select name="status" class="form-select form-select-sm">
                            <option value="menunggu_konfirmasi" <?= $order['status'] == 'menunggu_konfirmasi' ? 'selected' : ''; ?>>Menunggu Konfirmasi
                            </option>
                            <option value="diproses" <?= $order['status'] == 'diproses' ? 'selected' : ''; ?>>Diproses
                            </option>
                            <option value="selesai" <?= $order['status'] == 'selesai' ? 'selected' : ''; ?>>Selesai
                            </option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-sm btn-primary">Update</button>
                    </div>
                </form>
                <?php if ($order['notes_reseller']): ?>
                    <hr>
                    <p><strong>Catatan Reseller:</strong><br><?= nl2br(esc($order['notes_reseller'])) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<h5 class="mb-2">Progress Produksi</h5>
<form method="post" class="card card-body mb-4">
    <input type="hidden" name="update_progress" value="1">
    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Produk</th>
                    <th>Qty Pesan</th>
                    <th>Selesai (qty_done)</th>
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
                        <td style="max-width:80px;">
                            <input type="number" name="qty_done[<?= $it['id'] ?>]" min="0"
                                max="<?= (int) $it['qty_order'] ?>" class="form-control form-control-sm"
                                value="<?= (int) $it['qty_done'] ?>">
                        </td>
                        <td><?= (int) $it['qty_shipped'] ?></td>
                        <td><?= (int) $sisa ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <button class="btn btn-sm btn-primary">Simpan Progress</button>
</form>

<h5 class="mb-2">Buat Pengiriman Baru</h5>
<form method="post" class="card card-body mb-4">
    <input type="hidden" name="create_shipment" value="1">
    <div class="row mb-3">
        <div class="col-md-4 mb-2">
            <label class="form-label">Ekspedisi</label>
            <input type="text" name="courier" class="form-control form-control-sm">
        </div>
        <div class="col-md-4 mb-2">
            <label class="form-label">No Resi</label>
            <input type="text" name="tracking_number" class="form-control form-control-sm">
        </div>
        <div class="col-md-4 mb-2">
            <label class="form-label">Catatan (optional)</label>
            <input type="text" name="notes" class="form-control form-control-sm">
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
                <tr>
                    <th>Produk</th>
                    <th>Qty Pesan</th>
                    <th>Sudah Dikirim</th>
                    <th>Sisa Bisa Dikirim</th>
                    <th>Qty Kirim (Batch ini)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it):
                    $sisa = $it['qty_order'] - $it['qty_shipped'];
                    if ($sisa <= 0)
                        continue;
                    ?>
                    <tr>
                        <td><?= esc($it['name']) ?></td>
                        <td><?= (int) $it['qty_order'] ?></td>
                        <td><?= (int) $it['qty_shipped'] ?></td>
                        <td><?= (int) $sisa ?></td>
                        <td style="max-width:80px;">
                            <input type="number" name="ship_qty[<?= $it['id'] ?>]" min="0" max="<?= (int) $sisa ?>"
                                class="form-control form-control-sm">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <button class="btn btn-sm btn-success">Simpan Pengiriman</button>
</form>

<h5>Riwayat Pengiriman</h5>
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

<a href="<?= base_url('admin/orders.php') ?>" class="btn btn-secondary">Kembali</a>

<?php include __DIR__ . '/../partials/footer.php'; ?>