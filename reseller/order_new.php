<?php
// reseller/order_new.php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('reseller');
$user = current_user();

// ambil semua produk
$stmt = $pdo->query("SELECT id, code, name, voltage, price FROM products ORDER BY name");
$products = $stmt->fetchAll();

// map id -> harga (kalau nanti butuh)
$productPrices = [];
foreach ($products as $p) {
    $productPrices[$p['id']] = (int) $p['price'];
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notes_reseller = trim($_POST['notes_reseller'] ?? '');

    $product_ids = $_POST['product_id'] ?? [];
    $custom_names = $_POST['custom_name'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $modes = $_POST['mode'] ?? [];

    $items = [];

    if (is_array($qtys)) {
        $count = count($qtys);
        for ($i = 0; $i < $count; $i++) {
            $mode = $modes[$i] ?? 'normal';
            $pid = isset($product_ids[$i]) ? (int) $product_ids[$i] : 0;
            $custom_name = isset($custom_names[$i]) ? trim($custom_names[$i]) : '';
            $qty = (int) ($qtys[$i] ?? 0);

            if ($qty <= 0) {
                continue;
            }

            if ($mode === 'custom') {
                if ($custom_name === '') {
                    continue;
                }
                $items[] = [
                    'product_id' => null,
                    'custom_name' => $custom_name,
                    'qty' => $qty,
                ];
            } else {
                if ($pid <= 0) {
                    continue;
                }
                $items[] = [
                    'product_id' => $pid,
                    'custom_name' => null,
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

            // insert ke orders, sertakan created_by
            $stmt = $pdo->prepare("
    INSERT INTO orders (code, reseller_id, order_date, status, notes_reseller, created_by)
    VALUES (?, ?, NOW(), 'menunggu_konfirmasi', ?, ?)
");
            $stmt->execute([
                $orderCode,
                $user['reseller_id'],
                $notes_reseller,
                $user['id'],          // user yang sedang login (reseller)
            ]);
            $orderId = $pdo->lastInsertId();


            // insert item
            $stmtItem = $pdo->prepare("
                INSERT INTO order_items (order_id, product_id, custom_name, qty_order, qty_done, qty_shipped, note)
                VALUES (?, ?, ?, ?, 0, 0, '')
            ");

            foreach ($items as $it) {
                $stmtItem->execute([
                    $orderId,
                    $it['product_id'],
                    $it['custom_name'],
                    $it['qty'],
                ]);
            }

            $pdo->commit();

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
                    <th style="width:50px;" class="text-center">No</th>
                    <th class="text-nowrap text-center" style="min-width:240px;">Produk</th>
                    <th style="width:80px;" class="text-center">Qty</th>
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

    <button type="submit" class="btn btn-primary">Simpan Order</button>
    <a href="<?= base_url('reseller/orders.php') ?>" class="btn btn-secondary">Batal</a>
</form>

<!-- Template baris item -->
<template id="rowTemplate">
    <tr>
        <td class="align-middle no-col text-center"></td>
        <td>
            <div class="mb-1">
                <div class="form-check form-switch">
                    <input class="form-check-input custom-toggle" type="checkbox">
                    <label class="form-check-label small">Produk custom</label>
                </div>
                <input type="hidden" name="mode[]" class="mode-input" value="normal">
            </div>

            <!-- pilih dari daftar -->
            <select name="product_id[]" class="form-select form-select-sm product-select">
                <option value="">Pilih produk...</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" data-price="<?= (int) $p['price'] ?>">
                        <?= esc($p['name']) ?> (<?= esc($p['voltage']) ?>V)
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- produk custom -->
            <input type="text" name="custom_name[]" class="form-control form-control-sm mt-1 custom-name-input d-none"
                placeholder="Nama produk custom">
        </td>
        <td class="align-bottom">
            <input type="number" name="qty[]" class="form-control form-control-sm qty-input" min="1" value="1">
        </td>
        <td class="text-nowrap align-bottom">
            <input type="text" class="form-control form-control-sm price-display text-end" readonly>
        </td>
        <td class="text-nowrap align-bottom">
            <input type="text" class="form-control form-control-sm subtotal-display text-end" readonly>
        </td>
        <td class="text-center align-bottom">
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
                if (noCol) noCol.textContent = no++;
            });
        }

        function recalcRow(row) {
            const select = row.querySelector('.product-select');
            const qtyInput = row.querySelector('.qty-input');
            const priceEl = row.querySelector('.price-display');
            const subEl = row.querySelector('.subtotal-display');
            const mode = row.querySelector('.mode-input').value;

            let price = 0;
            if (mode === 'normal') {
                const option = select.options[select.selectedIndex];
                price = option && option.dataset.price ? parseInt(option.dataset.price, 10) : 0;
            }

            const qty = qtyInput.value ? parseInt(qtyInput.value, 10) : 0;
            const subtotal = price * qty;

            if (mode === 'normal') {
                priceEl.value = price > 0 ? formatRupiah(price) : '';
                subEl.value = subtotal > 0 ? formatRupiah(subtotal) : '';
            } else {
                priceEl.value = '—';
                subEl.value = '—';
            }
        }

        function recalcGrandTotal() {
            let total = 0;
            itemsTableBody.querySelectorAll('tr').forEach(row => {
                const mode = row.querySelector('.mode-input').value;
                if (mode !== 'normal') return; // produk custom: belum ada harga, skip total

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
                // allowClear: true, // bisa diaktifkan kalau mau ada tombol x di dalam select
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
            const modeInput = newRow.querySelector('.mode-input');
            const customToggle = newRow.querySelector('.custom-toggle');
            const customInput = newRow.querySelector('.custom-name-input');

            // aktifkan select2
            initSelect2For(select);

            // event change untuk select2
            $(select).on('change', function () {
                recalcRow(newRow);
                recalcGrandTotal();
            });

            qtyInput.addEventListener('input', function () {
                recalcRow(newRow);
                recalcGrandTotal();
            });

            btnDel.addEventListener('click', function () {
                newRow.remove();
                renumberRows();
                recalcGrandTotal();
            });

            // toggle produk custom
            customToggle.addEventListener('change', function () {
                if (customToggle.checked) {
                    // mode custom
                    modeInput.value = 'custom';

                    // kosongkan dan disable select2
                    $(select).val(null).trigger('change');
                    $(select).prop('disabled', true);

                    // tampilkan input custom
                    customInput.classList.remove('d-none');
                    customInput.focus();

                    // harga & subtotal jadi tanda strip
                    newRow.querySelector('.price-display').value = '—';
                    newRow.querySelector('.subtotal-display').value = '—';
                    recalcGrandTotal();
                } else {
                    // kembali ke mode normal
                    modeInput.value = 'normal';

                    $(select).prop('disabled', false);

                    customInput.classList.add('d-none');
                    customInput.value = '';

                    recalcRow(newRow);
                    recalcGrandTotal();
                }
            });

            renumberRows();
            recalcRow(newRow);
            recalcGrandTotal();
        }

        // tambah 1 baris default saat halaman dibuka
        addRow();

        // tombol tambah produk
        btnAddItem.addEventListener('click', function () {
            addRow();
        });
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>