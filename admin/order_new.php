<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('admin');
$user = current_user();

$errors = [];
$reseller_id    = 0;
$notes_reseller = '';
$notes_internal = '';

// Ambil semua reseller
$stmt = $pdo->query("SELECT id, name FROM resellers ORDER BY name");
$resellers = $stmt->fetchAll();

// Ambil semua produk aktif
$stmt = $pdo->query("SELECT id, name, voltage, price FROM products WHERE is_active = 1 ORDER BY name");
$products = $stmt->fetchAll();

function format_rupiah_local(int $v): string
{
    return 'Rp ' . number_format($v, 0, ',', '.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reseller_id    = (int)($_POST['reseller_id'] ?? 0);
    $notes_reseller = trim($_POST['notes_reseller'] ?? '');
    $notes_internal = trim($_POST['notes_internal'] ?? '');

    $product_ids = $_POST['product_id'] ?? [];
    $qtys        = $_POST['qty'] ?? [];

    if ($reseller_id <= 0) {
        $errors[] = 'Reseller harus dipilih.';
    }

    $items = [];
    if (!is_array($product_ids) || !is_array($qtys)) {
        $errors[] = 'Format item order tidak valid.';
    } else {
        $count = max(count($product_ids), count($qtys));
        for ($i = 0; $i < $count; $i++) {
            $pid = isset($product_ids[$i]) ? (int)$product_ids[$i] : 0;
            $qty = isset($qtys[$i]) ? (int)$qtys[$i] : 0;

            if ($pid <= 0 || $qty <= 0) {
                continue;
            }

            $items[] = [
                'product_id' => $pid,
                'qty'        => $qty,
            ];
        }
    }

    if (empty($items)) {
        $errors[] = 'Minimal harus ada 1 produk pada order.';
    }

    if (empty($errors)) {
        // Hitung total qty
        $total_qty = 0;
        foreach ($items as $it) {
            $total_qty += $it['qty'];
        }

        // Generate kode order
        if (function_exists('generate_order_code')) {
            $code = generate_order_code($pdo);
        } else {
            $code = 'ORD-' . date('Ymd-His');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO orders (
                    code, reseller_id, status, order_date, total_qty, 
                    notes_reseller, notes_internal, created_by
                )
                VALUES (?, ?, 'menunggu_konfirmasi', ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $code,
                $reseller_id,
                date('Y-m-d H:i:s'),
                $total_qty,
                $notes_reseller ?: null,
                $notes_internal ?: null,
                $user['id'],
            ]);

            $order_id = $pdo->lastInsertId();

            $stmtItem = $pdo->prepare("
                INSERT INTO order_items (order_id, product_id, custom_name, qty_order, qty_done, qty_shipped, note)
                VALUES (?, ?, NULL, ?, 0, 0, '')
            ");

            foreach ($items as $it) {
                $stmtItem->execute([
                    $order_id,
                    $it['product_id'],
                    $it['qty'],
                ]);
            }

            $pdo->commit();
            redirect('admin/order_view.php?id=' . $order_id);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Terjadi kesalahan saat menyimpan order: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../partials/header.php';
?>
<h3 class="mb-3">Buat Order Baru (Admin)</h3>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?>
                <li><?= esc($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" autocomplete="off">
    <div class="card mb-4">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Reseller</label>
                <select name="reseller_id" class="form-select" required>
                    <option value="">-- Pilih Reseller --</option>
                    <?php foreach ($resellers as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= ($reseller_id == $r['id']) ? 'selected' : '' ?>>
                            <?= esc($r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Catatan untuk Reseller (tampil di akun reseller)</label>
                <textarea name="notes_reseller" class="form-control" rows="2"><?= esc($notes_reseller) ?></textarea>
            </div>

            <div class="mb-0">
                <label class="form-label">Catatan Internal (hanya admin)</label>
                <textarea name="notes_internal" class="form-control" rows="2"><?= esc($notes_internal) ?></textarea>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><strong>Item Order</strong></span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddItem">Tambah Produk</button>
        </div>
        <div class="card-body">
            <div id="orderItemsWrapper" class="table-responsive mb-3">
                <table class="table table-sm table-striped align-middle" id="itemsTable">
                    <thead>
                        <tr>
                            <th style="width:50px;" class="text-center">No</th>
                            <th class="text-nowrap text-center" style="min-width:240px;">Produk</th>
                            <th class="text-nowrap text-center" style="min-width:80px;">Qty</th>
                            <th class="text-nowrap text-center" style="min-width:120px;">Harga / pcs</th>
                            <th class="text-nowrap text-center" style="min-width:120px;">Subtotal</th>
                            <th style="width:60px;" class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- baris item akan ditambah via JS -->
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="4" class="text-end">Total</th>
                            <th>
                                <span id="grandTotalDisplay">Rp 0</span>
                            </th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <small class="text-muted d-block mt-2">
                Pilih produk dari daftar, lalu atur qty. Harga diambil dari master produk.
            </small>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Simpan Order</button>
    <a href="<?= base_url('admin/orders.php') ?>" class="btn btn-secondary">Batal</a>
</form>

<!-- Template baris item -->
<template id="rowTemplate">
    <tr>
        <td class="align-middle no-col text-center"></td>
        <td>
            <select name="product_id[]" class="form-select form-select-sm product-select">
                <option value="">Pilih produk...</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" data-price="<?= (int)$p['price'] ?>">
                        <?= esc($p['name']) ?><?= $p['voltage'] ? ' (' . esc($p['voltage']) . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="align-bottom">
            <input type="number" name="qty[]" class="form-control form-control-sm qty-input text-center"
                   min="1" value="1">
        </td>
        <td class="text-nowrap align-bottom">
            <input type="text" class="form-control form-control-sm price-display text-center" readonly>
        </td>
        <td class="text-nowrap align-bottom">
            <input type="text" class="form-control form-control-sm subtotal-display text-center" readonly>
        </td>
        <td class="text-center align-bottom">
            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row">&times;</button>
        </td>
    </tr>
</template>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const itemsTableBody = document.querySelector('#itemsTable tbody');
    const rowTemplate    = document.querySelector('#rowTemplate');
    const grandTotalEl   = document.getElementById('grandTotalDisplay');
    const btnAddItem     = document.getElementById('btnAddItem');

    function formatRupiah(angka) {
        if (isNaN(angka) || angka <= 0) return 'Rp 0';
        return 'Rp ' + angka.toLocaleString('id-ID');
    }

    function renumberRows() {
        const rows = itemsTableBody.querySelectorAll('tr');
        let no = 1;
        rows.forEach(row => {
            const noCol = row.querySelector('.no-col');
            if (noCol) noCol.textContent = no++;
        });
    }

    function recalcRow(row) {
        const select   = row.querySelector('.product-select');
        const qtyInput = row.querySelector('.qty-input');
        const priceEl  = row.querySelector('.price-display');
        const subEl    = row.querySelector('.subtotal-display');

        const option = select.options[select.selectedIndex];
        const price  = option && option.dataset.price ? parseInt(option.dataset.price, 10) : 0;
        const qty    = qtyInput.value ? parseInt(qtyInput.value, 10) : 0;
        const subtotal = price * qty;

        priceEl.value = price > 0 ? formatRupiah(price) : '';
        subEl.value   = subtotal > 0 ? formatRupiah(subtotal) : '';
    }

    function recalcGrandTotal() {
        let total = 0;
        itemsTableBody.querySelectorAll('tr').forEach(row => {
            const select   = row.querySelector('.product-select');
            const qtyInput = row.querySelector('.qty-input');

            const option = select.options[select.selectedIndex];
            const price  = option && option.dataset.price ? parseInt(option.dataset.price, 10) : 0;
            const qty    = qtyInput.value ? parseInt(qtyInput.value, 10) : 0;

            total += price * qty;
        });
        grandTotalEl.textContent = formatRupiah(total);
    }

    function initSelect2For(element) {
        if (window.jQuery && jQuery.fn.select2) {
            $(element).select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Pilih produk...',
                dropdownParent: $('#orderItemsWrapper')
            });
        }
    }

    function addRow() {
        const clone = rowTemplate.content.cloneNode(true);
        itemsTableBody.appendChild(clone);
        const newRow = itemsTableBody.querySelector('tr:last-child');

        const select   = newRow.querySelector('.product-select');
        const qtyInput = newRow.querySelector('.qty-input');
        const btnDel   = newRow.querySelector('.btn-remove-row');

        initSelect2For(select);

        // Jika pakai select2, gunakan event jQuery, tapi native change juga tetap jalan
        if (window.jQuery && jQuery.fn.select2) {
            $(select).on('change', function () {
                recalcRow(newRow);
                recalcGrandTotal();
            });
        } else {
            select.addEventListener('change', function () {
                recalcRow(newRow);
                recalcGrandTotal();
            });
        }

        qtyInput.addEventListener('input', function () {
            if (!this.value || parseInt(this.value, 10) <= 0) {
                this.value = 1;
            }
            recalcRow(newRow);
            recalcGrandTotal();
        });

        btnDel.addEventListener('click', function () {
            newRow.remove();
            renumberRows();
            recalcGrandTotal();
        });

        renumberRows();
        recalcRow(newRow);
        recalcGrandTotal();
    }

    // Tambah 1 baris default saat halaman dibuka
    addRow();

    // Tombol tambah produk
    btnAddItem.addEventListener('click', function () {
        addRow();
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
