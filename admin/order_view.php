<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('admin');
$user = current_user();

if (!function_exists('format_rupiah')) {
    function format_rupiah(int $v): string
    {
        return 'Rp ' . number_format($v, 0, ',', '.');
    }
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    die("Order tidak valid");
}

// ====== HANDLE: mapping item custom -> produk resmi ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['link_item'])) {
    $order_item_id = (int) ($_POST['order_item_id'] ?? 0);
    $product_id = (int) ($_POST['product_id'] ?? 0);

    if ($order_item_id > 0 && $product_id > 0) {
        // Pastikan item ini milik order yang sedang dibuka
        $stmt = $pdo->prepare("SELECT id FROM order_items WHERE id = ? AND order_id = ?");
        $stmt->execute([$order_item_id, $id]);
        $oi = $stmt->fetch();

        if ($oi) {
            // Pastikan produk ada
            $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ?");
            $stmt->execute([$product_id]);
            $prod = $stmt->fetch();

            if ($prod) {
                // Update: set product_id, kosongkan custom_name
                $stmt = $pdo->prepare("
                    UPDATE order_items
                    SET product_id = ?, custom_name = NULL
                    WHERE id = ?
                ");
                $stmt->execute([$product_id, $order_item_id]);
            }
        }
    }

    redirect('admin/order_view.php?id=' . $id);
}

// ====== HANDLE: tambah pembayaran order ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    $amount_raw = trim($_POST['amount'] ?? '');
    // Hilangkan titik pemisah ribuan kalau ada
    $amount_clean = str_replace(['.', ',', ' '], '', $amount_raw);
    $amount = (int) $amount_clean;
    $notes = trim($_POST['notes'] ?? '');

    if ($amount > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO order_payments (order_id, amount, notes, created_by)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$id, $amount, $notes, $user['id']]);
    }

    redirect('admin/order_view.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $status = $_POST['status'] ?? 'menunggu_konfirmasi';
    if (!in_array($status, ['menunggu_konfirmasi', 'diproses', 'selesai'], true)) {
        $status = 'menunggu_konfirmasi';
    }

    // Update status dulu
    $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);

    // Ambil data order (kode order & reseller)
    $stmtO = $pdo->prepare("
        SELECT o.code, r.whatsapp, r.name AS reseller_name
        FROM orders o
        JOIN resellers r ON r.id = o.reseller_id
        WHERE o.id = ?
    ");
    $stmtO->execute([$id]);
    $od = $stmtO->fetch();

    $orderCode = $od['code'];
    $resWA = $od['whatsapp'];

    // Kirim WA hanya saat status berubah ke diproses
    if ($status === 'diproses' && $resWA) {

        // Ambil item order (nama produk)
        $stmtI = $pdo->prepare("
            SELECT COALESCE(oi.custom_name, p.name) AS name, oi.qty_order
            FROM order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id = ?
        ");
        $stmtI->execute([$id]);
        $items = $stmtI->fetchAll();

        $itemText = "";
        foreach ($items as $it) {
            $itemText .= "- {$it['name']} ({$it['qty_order']} pcs)\n";
        }



        $msg =
            "📦 *Order Kamu Sedang Diproses!*\n" .
            "---------------------------------\n" .
            "Kode Order: *{$orderCode}*\n" .
            "Tanggal: " . date('d-m-Y H:i') . "\n\n" .
            "Halo *{$od['reseller_name']},* Order kamu sudah diproses dan sedang dikerjakan tim produksi RelayLab 🙏";

        send_wa_notification($resWA, $msg);
    }

    redirect('admin/order_view.php?id=' . $id);
}


// ====== HANDLE LAMA: update qty_done (progress produksi) ======
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

// ====== HANDLE: tambah pengiriman (dengan foto resi + WA) ======
// ====== HANDLE: tambah pengiriman ======
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_shipment'])) {
    $courier = trim($_POST['courier'] ?? '');
    $tracking = trim($_POST['tracking_number'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    // Tanggal kirim manual, fallback ke sekarang
    $shipDate = trim($_POST['ship_date'] ?? '');
    if ($shipDate === '') {
        $shipDate = date('Y-m-d H:i:s');
    }

    // Ambil qty yang dikirim
    $qty_ship = $_POST['ship_qty'] ?? [];
    $itemsToShip = [];

    foreach ($qty_ship as $itemId => $q) {
        $q = (int) $q;
        if ($q > 0) {
            $itemsToShip[(int) $itemId] = $q;
        }
    }

    // ==== UPLOAD FOTO RESI ====
    $resiFilename = null;
    $resiLocalPath = null;

    // PENTING: pakai 'receipt_image' sesuai name di <input>
    if (!empty($_FILES['receipt_image']['name'])) {
        $uploadDir = __DIR__ . '/../uploads/resi/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $ext = strtolower(pathinfo($_FILES['receipt_image']['name'], PATHINFO_EXTENSION));
        // optional: batasi ke jpg/png/jpeg/webp
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowedExt, true)) {
            $ext = 'jpg'; // fallback
        }

        $resiFilename = 'resi_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
        $resiLocalPath = $uploadDir . $resiFilename;

        if (!move_uploaded_file($_FILES['receipt_image']['tmp_name'], $resiLocalPath)) {
            $resiFilename = null;
            $resiLocalPath = null;
        }
    }

    if (!empty($itemsToShip)) {
        $pdo->beginTransaction();
        try {
            // === INSERT SHIPMENT (tambahkan kolom receipt_image kalau sudah ada di DB) ===
            $stmt = $pdo->prepare("
                INSERT INTO shipments (order_id, ship_date, courier, tracking_number, notes, receipt_image)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $id,
                $shipDate,
                $courier,
                $tracking,
                $notes,
                $resiFilename
            ]);
            $shipId = $pdo->lastInsertId();

            // === PROSES ITEM ===
            $stmtSelectItem = $pdo->prepare("
                SELECT id, qty_order, qty_shipped
                FROM order_items
                WHERE id = ? AND order_id = ?
            ");

            $stmtInsertShipItem = $pdo->prepare("
                INSERT INTO shipment_items (shipment_id, order_item_id, qty)
                VALUES (?, ?, ?)
            ");

            $stmtUpdateShipped = $pdo->prepare("
                UPDATE order_items SET qty_shipped = ?
                WHERE id = ?
            ");

            foreach ($itemsToShip as $itemId => $qShip) {
                $stmtSelectItem->execute([$itemId, $id]);
                $row = $stmtSelectItem->fetch();

                if (!$row) {
                    continue;
                }

                $maxShip = $row['qty_order'] - $row['qty_shipped'];
                if ($maxShip <= 0) {
                    continue;
                }
                if ($qShip > $maxShip) {
                    $qShip = $maxShip;
                }

                // Insert detail
                $stmtInsertShipItem->execute([$shipId, $itemId, $qShip]);

                // Update shipped
                $stmtUpdateShipped->execute([$row['qty_shipped'] + $qShip, $itemId]);
            }

            // === AUTO SET STATUS JIKA SUDAH SELESAI ===
            $check = $pdo->prepare("
                SELECT COUNT(*) c
                FROM order_items
                WHERE order_id = ? AND qty_shipped < qty_order
            ");
            $check->execute([$id]);
            if ((int) $check->fetch()['c'] === 0) {
                $pdo->prepare("UPDATE orders SET status = 'selesai' WHERE id = ?")
                    ->execute([$id]);
            }

            // ==== AMBIL NOMOR WA RESELLER ====
            $stmtW = $pdo->prepare("
                SELECT r.whatsapp, r.name
                FROM orders o
                JOIN resellers r ON r.id = o.reseller_id
                WHERE o.id = ?
            ");
            $stmtW->execute([$id]);
            $res = $stmtW->fetch();

            $resWA = $res['whatsapp'] ?? null;
            $resName = $res['name'] ?? '';

            // ==== Susun daftar item yang dikirim ====
            $stmtItems = $pdo->prepare("
                SELECT COALESCE(oi.custom_name, p.name) AS name, si.qty
                FROM shipment_items si
                JOIN order_items oi ON oi.id = si.order_item_id
                LEFT JOIN products p ON p.id = oi.product_id
                WHERE si.shipment_id = ?
            ");
            $stmtItems->execute([$shipId]);
            $sentItems = $stmtItems->fetchAll();

            $itemText = "";
            foreach ($sentItems as $si) {
                $itemText .= "- {$si['name']} ({$si['qty']} pcs)\n";
            }

            // ==== Kirim WA ====
            if ($resWA) {
                $msg =
                    "🚚 *Pengiriman Pesanan RelayLab*\n" .
                    "---------------------------------\n" .
                    "Pesanan kamu sudah dikirim!\n\n" .
                    "*Ekspedisi:* {$courier}\n" .
                    "*No Resi:* {$tracking}\n" .
                    "*Tanggal:* {$shipDate}\n\n" .
                    "*Produk yang dikirim:*\n{$itemText}\n" .
                    ($resiFilename ? "Foto resi terlampir di atas.\n\n" : "\n") .
                    "Terima kasih sudah order 🙏";

                // kalau ada file dan path lokalnya valid → kirim pakai FILE
                if ($resiLocalPath && file_exists($resiLocalPath)) {
                    send_wa_notification_with_file($resWA, $msg, $resiLocalPath);
                } else {
                    // fallback: kirim teks saja
                    send_wa_notification($resWA, $msg);
                }
            }

            $pdo->commit();
            redirect('admin/order_view.php?id=' . $id);

        } catch (Throwable $e) {
            $pdo->rollBack();
            die("Gagal membuat pengiriman: " . $e->getMessage());
        }
    }
}




// ====== AMBIL DATA ORDER ======
$stmt = $pdo->prepare("SELECT o.*, r.name AS reseller_name
  FROM orders o
  JOIN resellers r ON r.id = o.reseller_id
  WHERE o.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) {
    die("Order tidak ditemukan");
}

// ====== AMBIL ITEM ORDER (SUPPORT CUSTOM + HARGA) ======
$stmt = $pdo->prepare("
    SELECT 
        oi.*,
        p.code,
        p.price AS unit_price,
        COALESCE(oi.custom_name, p.name) AS name,
        oi.custom_name AS raw_custom_name
    FROM order_items oi
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = ?
");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

// Hitung total order dari harga produk
$totalOrder = 0;
foreach ($items as $it) {
    $price = isset($it['unit_price']) ? (int) $it['unit_price'] : 0;
    $qty = (int) $it['qty_order'];
    if ($price > 0 && $qty > 0) {
        $totalOrder += $price * $qty;
    }
}

// ====== AMBIL SEMUA PRODUK UNTUK DROPDOWN MAPPING CUSTOM ======
$stmt = $pdo->query("SELECT id, name, voltage FROM products ORDER BY name");
$allProducts = $stmt->fetchAll();

// ====== AMBIL PEMBAYARAN UNTUK ORDER INI ======
$stmt = $pdo->prepare("
    SELECT op.*, u.name AS admin_name
    FROM order_payments op
    LEFT JOIN users u ON u.id = op.created_by
    WHERE op.order_id = ?
    ORDER BY op.pay_date, op.id
");
$stmt->execute([$id]);
$payments = $stmt->fetchAll();

$totalPaid = 0;
foreach ($payments as $p) {
    $totalPaid += (int) $p['amount'];
}
$sisaBayar = max($totalOrder - $totalPaid, 0);

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

// ====== AMBIL PENGIRIMAN ======
$stmt = $pdo->prepare("SELECT * FROM shipments WHERE order_id = ? ORDER BY ship_date");
$stmt->execute([$id]);
$shipments = $stmt->fetchAll();

$shipmentItems = [];
if ($shipments) {
    $shipmentIds = array_column($shipments, 'id');
    $in = implode(',', array_fill(0, count($shipmentIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT si.*, oi.product_id,
                COALESCE(oi.custom_name, p.name) AS product_name
         FROM shipment_items si
         JOIN order_items oi ON oi.id = si.order_item_id
         LEFT JOIN products p ON p.id = oi.product_id
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
                <hr>
                <p class="mb-1"><strong>Total Order:</strong> <?= format_rupiah($totalOrder) ?></p>
                <p class="mb-1"><strong>Sudah Dibayar:</strong> <?= format_rupiah($totalPaid) ?></p>
                <p class="mb-2"><strong>Sisa Pembayaran:</strong> <?= format_rupiah($sisaBayar) ?></p>
                <span class="badge bg-<?= esc($statusBayarClass) ?>">
                    Status Pembayaran: <?= esc($statusBayar) ?>
                </span>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="post" class="row g-2 align-items-center">
                    <input type="hidden" name="update_status" value="1">
                    <div class="col-12 mb-2">
                        <label class="form-label mb-0"><strong>Status Order:</strong></label>
                    </div>
                    <div class="col-auto">
                        <select name="status" class="form-select form-select-sm">
                            <option value="menunggu_konfirmasi" <?= $order['status'] == 'menunggu_konfirmasi' ? 'selected' : ''; ?>>Menunggu Konfirmasi</option>
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
                <?php if ($order['notes_internal']): ?>
                    <p><strong>Catatan Internal:</strong><br><?= nl2br(esc($order['notes_internal'])) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Kolom kanan: Form pembayaran + riwayat pembayaran -->
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header">
                <strong>Input Pembayaran</strong>
            </div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <input type="hidden" name="add_payment" value="1">
                    <div class="col-12">
                        <label class="form-label">Nominal (Rp)</label>
                        <input type="text" name="amount" class="form-control form-control-sm"
                            placeholder="contoh: 150000">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Catatan (opsional)</label>
                        <input type="text" name="notes" class="form-control form-control-sm"
                            placeholder="misal: DP pertama, pelunasan, dll">
                    </div>
                    <div class="col-12 mt-2">
                        <button class="btn btn-sm btn-success">Simpan Pembayaran</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($payments): ?>
            <div class="card">
                <div class="card-header">
                    <strong>Riwayat Pembayaran</strong>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Nominal</th>
                                    <th>Admin</th>
                                    <th>Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $p): ?>
                                    <tr>
                                        <td><?= esc($p['pay_date']) ?></td>
                                        <td><?= format_rupiah((int) $p['amount']) ?></td>
                                        <td><?= esc($p['admin_name'] ?? '-') ?></td>
                                        <td><?= esc($p['notes']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
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
                    <th>Harga / pcs</th>
                    <th>Subtotal</th>
                    <th>Selesai (qty_done)</th>
                    <th>Sudah Dikirim</th>
                    <th>Sisa Kirim</th>
                    <th>Custom / Mapping</th>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1;
                foreach ($items as $it):
                    $sisa = $it['qty_order'] - $it['qty_shipped'];
                    $isCustom = ($it['product_id'] === null);
                    $price = isset($it['unit_price']) ? (int) $it['unit_price'] : 0;
                    $qty = (int) $it['qty_order'];
                    $sub = $price > 0 && $qty > 0 ? $price * $qty : 0;
                    ?>
                    <tr class="<?= $isCustom ? 'table-warning' : '' ?>">
                        <td><?= $no++ ?></td>
                        <td>
                            <?= esc($it['name']) ?>
                            <?php if ($isCustom && $it['raw_custom_name']): ?>
                                <br>
                                <span class="badge bg-warning text-dark">Custom: butuh mapping</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $it['qty_order'] ?></td>
                        <td><?= $price > 0 ? format_rupiah($price) : '<span class="text-muted">-</span>' ?></td>
                        <td><?= $sub > 0 ? format_rupiah($sub) : '<span class="text-muted">-</span>' ?></td>
                        <td style="max-width:80px;">
                            <input type="number" name="qty_done[<?= $it['id'] ?>]" min="0"
                                max="<?= (int) $it['qty_order'] ?>" class="form-control form-control-sm"
                                value="<?= (int) $it['qty_done'] ?>">
                        </td>
                        <td><?= (int) $it['qty_shipped'] ?></td>
                        <td><?= (int) $sisa ?></td>
                        <td style="min-width:200px;">
                            <?php if ($isCustom): ?>
                                <form method="post" class="d-flex gap-1">
                                    <input type="hidden" name="link_item" value="1">
                                    <input type="hidden" name="order_item_id" value="<?= (int) $it['id'] ?>">
                                    <select name="product_id" class="form-select form-select-sm" required>
                                        <option value="">Pilih produk...</option>
                                        <?php foreach ($allProducts as $p): ?>
                                            <option value="<?= $p['id'] ?>">
                                                <?= esc($p['name']) ?> (<?= esc($p['voltage']) ?>V)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary">Link</button>
                                </form>
                            <?php else: ?>
                                <span class="badge bg-success">Normal</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <button class="btn btn-sm btn-primary">Simpan Progress</button>
</form>

<h5 class="mb-2">Buat Pengiriman Baru</h5>
<form method="post" enctype="multipart/form-data" class="card card-body mb-4">
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
            <label class="form-label">Tanggal & Jam Pengiriman</label>
            <input type="datetime-local" name="ship_date" class="form-control form-control-sm" required>
        </div>

        <div class="col-md-4 mb-2">
            <label class="form-label">Foto Resi (opsional)</label>
            <input type="file" name="receipt_image" class="form-control form-control-sm" accept="image/*">
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