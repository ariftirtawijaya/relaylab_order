<?php
// reseller/order_new.php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('reseller');
$user = current_user();

// ambil semua produk aktif
$stmt = $pdo->query("SELECT id, code, name, voltage, price FROM products ORDER BY name");
$products = $stmt->fetchAll();

// bikin map id -> harga untuk dipakai saat insert
$productPrices = [];
foreach ($products as $p) {
    $productPrices[$p['id']] = (int) $p['price'];
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notes_reseller = trim($_POST['notes_reseller'] ?? '');

    $product_ids = $_POST['product_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];

    $items = [];

    // filter baris yang valid
    if (is_array($product_ids) && is_array($qtys)) {
        for ($i = 0; $i < count($product_ids); $i++) {
            $pid = (int) ($product_ids[$i] ?? 0);
            $qty = (int) ($qtys[$i] ?? 0);

            if ($pid > 0 && $qty > 0) {
                $items[] = [
                    'product_id' => $pid,
                    'qty' => $qty,
                ];
            }
        }
    }

    if (empty($items)) {
        $error = "Minimal harus ada 1 produk yang dipilih.";
    } else {
        try {
            $pdo->beginTransaction();

            // buat kode order
            $orderCode = generate_order_code($pdo);

            // insert ke tabel orders
            $stmt = $pdo->prepare("
                INSERT INTO orders (code, reseller_id, order_date, status, notes_reseller)
                VALUES (?, ?, NOW(), 'menunggu_konfirmasi', ?)
            ");
            $stmt->execute([$orderCode, $user['reseller_id'], $notes_reseller]);
            $orderId = $pdo->lastInsertId();

            // insert item order
            $stmtItem = $pdo->prepare("
                INSERT INTO order_items (order_id, product_id, qty_order, qty_done, qty_shipped, note)
                VALUES (?, ?, ?, 0, 0, '')
            ");

            foreach ($items as $it) {
                $pid = $it['product_id'];
                $qty = $it['qty'];

                // lepasin kalau product_id tidak dikenal (harusnya tidak terjadi)
                if (!isset($productPrices[$pid])) {
                    continue;
                }

                $stmtItem->execute([$orderId, $pid, $qty]);
            }

            $pdo->commit();

            // redirect ke detail order setelah berhasil
            redirect('reseller/order_view.php?id=' . $orderId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = "Terjadi kesalahan saat menyimpan order: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/../partials/header.php';
?>

<h3 class="mb-3">Buat Order Baru</h3>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
<?php endif; ?>

<form method="post" id="orderForm" class="card card-body border-0 shadow-sm mb-4">
    <div class="mb-3">
        <label class="form-label">Catatan untuk Admin (opsional)</label>
        <textarea name="notes_reseller" rows="3"
            class="form-control"><?= esc($_POST['notes_reseller'] ?? '') ?></textarea>
    </div>

    <hr>

    <h5 class="mb-3">Item Produk</h5>

    <div class="mb-2">
        <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddItem">
            Tambah Produk
        </button>
    </div>

    <div id="orderItemsWrapper" class="table-responsive mb-3">
        <table class="table table-sm table-striped align-middle" id="itemsTable">
            <thead>
                <tr>
                    <th style="width:50px;">No</th>
                    <th class="text-nowrap">Produk</th>
                    <th style="width:80px;">Qty</th>
                    <th class="text-nowrap" style="min-width:120px;">Harga / pcs</th>
                    <th class="text-nowrap" style="min-width:120px;">Subtotal</th>
                    <th style="width:60px;"></th>
                </tr>
            </thead>

            <tbody>
                <!-- baris item akan ditambahkan via JS -->
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

    <button type="submit" class="btn btn-primary">Simpan Order</button>
    <a href="<?= base_url('reseller/orders.php') ?>" class="btn btn-secondary">Batal</a>
</form>

<!-- Template baris item (dipakai JS) -->
<template id="rowTemplate">
    <tr>
        <td class="align-middle no-col"></td>
        <td>
            <select name="product_id[]" class="form-select form-select-sm product-select">
                <option value="">Pilih produk...</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" data-price="<?= (int) $p['price'] ?>">
                        <?= esc($p['name']) ?> (<?= esc($p['voltage']) ?>V)
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td>
            <input type="number" name="qty[]" class="form-control form-control-sm qty-input text-center" min="1"
                value="1">
        </td>
        <td class="text-nowrap">
            <input type="text" class="form-control form-control-sm price-display text-center" readonly>
        </td>
        <td class="text-nowrap">
            <input type="text" class="form-control form-control-sm subtotal-display text-center" readonly>
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row">&times;</button>
        </td>
    </tr>
</template>


<script>
    document.addEventListener('DOMContentLoaded', function () {
        const itemsTableBody = document.querySelector('#itemsTable tbody');
        const rowTemplate = document.querySelector('#rowTemplate');
        const grandTotalEl = document.getElementById('grandTotalDisplay');
        const btnAddItem = document.getElementById('btnAddItem');

        function formatRupiah(angka) {
            if (isNaN(angka) || angka <= 0) return 'Rp 0';
            return 'Rp ' + angka.toLocaleString('id-ID');
        }

        function renumberRows() {
            const rows = itemsTableBody.querySelectorAll('tr');
            let no = 1;
            rows.forEach(row => {
                const noCol = row.querySelector('.no-col');
                if (noCol) {
                    noCol.textContent = no++;
                }
            });
        }

        function recalcRow(row) {
            const select = row.querySelector('.product-select');
            const qtyInput = row.querySelector('.qty-input');
            const priceEl = row.querySelector('.price-display');
            const subEl = row.querySelector('.subtotal-display');

            const option = select.options[select.selectedIndex];
            const price = option && option.dataset.price ? parseInt(option.dataset.price, 10) : 0;
            const qty = qtyInput.value ? parseInt(qtyInput.value, 10) : 0;

            const subtotal = price * qty;

            priceEl.value = price > 0 ? formatRupiah(price) : '';
            subEl.value = subtotal > 0 ? formatRupiah(subtotal) : '';
        }

        function recalcGrandTotal() {
            let total = 0;
            itemsTableBody.querySelectorAll('tr').forEach(row => {
                const select = row.querySelector('.product-select');
                const qtyInput = row.querySelector('.qty-input');
                const option = select.options[select.selectedIndex];
                const price = option && option.dataset.price ? parseInt(option.dataset.price, 10) : 0;
                const qty = qtyInput.value ? parseInt(qtyInput.value, 10) : 0;
                total += price * qty;
            });
            grandTotalEl.textContent = formatRupiah(total);
        }

        function initSelect2For(element) {
            $(element).select2({
                theme: 'bootstrap-5',
                width: '100%',
                placeholder: 'Pilih produk...',
                allowClear: false,
                dropdownParent: $('#orderItemsWrapper')
            });
        }

        function addRow() {
            const clone = rowTemplate.content.cloneNode(true);
            itemsTableBody.appendChild(clone);
            const newRow = itemsTableBody.querySelector('tr:last-child');

            const select = newRow.querySelector('.product-select');
            const qtyInput = newRow.querySelector('.qty-input');
            const btnDel = newRow.querySelector('.btn-remove-row');

            // aktifkan select2
            initSelect2For(select);

            // pakai jQuery untuk event change di select2
            $(select).on('change', function () {
                recalcRow(newRow);
                recalcGrandTotal();
            });

            // qty pakai event input biasa
            qtyInput.addEventListener('input', function () {
                recalcRow(newRow);
                recalcGrandTotal();
            });

            btnDel.addEventListener('click', function () {
                newRow.remove();
                renumberRows();
                recalcGrandTotal();
            });

            renumberRows();
        }


        // Tambah 1 baris default saat halaman dibuka
        addRow();

        // Tambah baris baru saat klik tombol
        btnAddItem.addEventListener('click', function () {
            addRow();
        });
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>