<?php
// reseller/order_new.php
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

// ===============================
// CEK: RESELLER SPECIAL / BUKAN
// ===============================
$stmtR = $pdo->prepare("SELECT is_special FROM resellers WHERE id = ?");
$stmtR->execute([$user['reseller_id']]);
$resellerRow = $stmtR->fetch(PDO::FETCH_ASSOC);

$isSpecialReseller = $resellerRow && (int) $resellerRow['is_special'] === 1;
// Aturan minimum per item
$minQtyPerItem = $isSpecialReseller ? 1 : 5;

// Ambil semua produk aktif
$stmt = $pdo->query("SELECT id, name, voltage, price FROM products WHERE is_active = 1 ORDER BY name");
$products = $stmt->fetchAll();

// Lookup produk by ID (buat rebuild keranjang & WA)
$productLookup = [];
foreach ($products as $p) {
    $productLookup[$p['id']] = $p;
}

$error = '';
$items = []; // akan diisi saat POST

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notes_reseller = trim($_POST['notes_reseller'] ?? '');

    $product_ids = $_POST['product_id'] ?? [];
    $custom_names = $_POST['custom_name'] ?? [];
    $voltages = $_POST['voltage'] ?? [];
    $qtys = $_POST['qty'] ?? [];

    $items = [];
    $minQtyError = false; // flag kalau ada item di bawah minimum

    if (is_array($qtys)) {
        $count = count($qtys);
        for ($i = 0; $i < $count; $i++) {
            $pid = isset($product_ids[$i]) ? (int) $product_ids[$i] : 0;
            $cname = isset($custom_names[$i]) ? trim($custom_names[$i]) : '';
            $qty = (int) ($qtys[$i] ?? 0);
            $volt = $voltages[$i] ?? null;

            if ($qty <= 0) {
                continue;
            }

            // CEK MINIMUM QTY PER ITEM (berlaku untuk semua reseller, tapi nilainya beda)
            if ($qty < $minQtyPerItem) {
                $minQtyError = true;
            }

            if ($pid > 0) {
                // produk normal
                $items[] = [
                    'product_id' => $pid,
                    'voltage' => $volt,
                    'custom_name' => null,
                    'qty' => $qty,
                ];
            } elseif ($cname !== '') {
                // produk custom
                $items[] = [
                    'product_id' => null,
                    'voltage' => $volt,
                    'custom_name' => $cname,
                    'qty' => $qty,
                ];
            }
        }
    }

    if (empty($items)) {
        $error = "Minimal harus ada 1 produk di keranjang.";
    } elseif ($minQtyError) {
        $error = "Qty minimal per item untuk akun Anda adalah {$minQtyPerItem} pcs.";
    } else {

        // ==========================
        //  BAGIAN TRANSAKSI DB
        // ==========================
        try {
            $pdo->beginTransaction();

            $orderCode = generate_order_code($pdo);

            $stmt = $pdo->prepare("
                INSERT INTO orders (code, reseller_id, order_date, status, notes_reseller, created_by)
                VALUES (?, ?, NOW(), 'menunggu_konfirmasi', ?, ?)
            ");
            $stmt->execute([
                $orderCode,
                $user['reseller_id'],
                $notes_reseller,
                $user['id'],
            ]);
            $orderId = $pdo->lastInsertId();

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
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = "Terjadi kesalahan saat menyimpan order: " . $e->getMessage();
        }

        // Kalau ada error, jangan lanjut kirim WA
        if (!$error) {

            // ==========================
            //  BAGIAN WHATSAPP (NON-DB)
            // ==========================

            $stmtW = $pdo->prepare("
                SELECT r.whatsapp, r.name
                FROM users u
                JOIN resellers r ON r.id = u.reseller_id
                WHERE u.id = ?
                LIMIT 1
            ");
            $stmtW->execute([$user['id']]);
            $waData = $stmtW->fetch();

            $resWA = $waData['whatsapp'] ?? null;
            $adminWA = '6289529303412';

            $itemsText = "";
            foreach ($items as $it) {
                if ($it['product_id']) {
                    $pRow = $productLookup[$it['product_id']] ?? null;
                    $name = $pRow['name'] ?? ('Produk ID ' . $it['product_id']);
                    $voltase = '';
                    if (!empty($it['voltage']) && $it['voltage'] !== '-') {
                        $voltase = ' ' . $it['voltage'] . 'V';
                    }
                } else {
                    $name = $it['custom_name'];
                    $voltase = '';
                }

                $itemsText .= "- {$name}{$voltase} ({$it['qty']} pcs)\n";
            }

            $msgReseller =
                "🛒 *Order Berhasil Dibuat!*\n" .
                "---------------------------------\n" .
                "Kode Order: *{$orderCode}*\n" .
                "Tanggal: " . date('d-m-Y H:i') . "\n\n" .
                "*Daftar Item:*\n{$itemsText}\n" .
                "Status: Menunggu Konfirmasi Admin\n\n" .
                "Terima kasih sudah order di RelayLab! 🙏";

            $msgAdmin =
                "📢 *Order Baru Masuk!*\n" .
                "---------------------------------\n" .
                "Kode Order: *{$orderCode}*\n" .
                "Reseller: {$user['name']}\n" .
                "WA: {$resWA}\n\n" .
                "*Daftar Item:*\n{$itemsText}\n" .
                "Segera review order ini di dashboard admin.";

            try {
                if ($resWA) {
                    send_wa_notification($resWA, $msgReseller);
                }
                send_wa_notification($adminWA, $msgAdmin);
            } catch (Throwable $e) {
                error_log('WA error: ' . $e->getMessage());
            }

            redirect('reseller/order_view.php?id=' . $orderId);
        }
    }
}

include __DIR__ . '/../partials/header.php';
?>

<h3 class="mb-3">Buat Order Baru</h3>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
<?php endif; ?>

<p class="text-muted small">
    Minimum order per item untuk akun Anda: <strong><?= (int) $minQtyPerItem ?> pcs</strong>.
</p>

<form method="post" id="orderForm">
    <div class="mb-3">
        <label class="form-label">Catatan untuk Admin (opsional)</label>
        <textarea name="notes_reseller" rows="3"
            class="form-control"><?= esc($_POST['notes_reseller'] ?? '') ?></textarea>
    </div>

    <hr>

    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <h5 class="mb-0">Pilih Produk</h5>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAddCustom">
            + Tambah Produk Custom
        </button>
    </div>

    <div class="mb-3">
        <input type="text" id="productSearch" class="form-control form-control-sm"
            placeholder="Cari produk, contoh: headlamp foglamp aod 12v">
    </div>

    <!-- Info & Pagination Produk -->
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <div class="small text-muted" id="productCountInfo"></div>
        <nav>
            <ul class="pagination pagination-sm mb-0" id="productPagination"></ul>
        </nav>
    </div>

    <!-- GRID PRODUK -->
    <div class="row g-2 mb-4" id="productList">
        <?php foreach ($products as $p): ?>
            <?php
            $pid = (int) $p['id'];
            $name = $p['name'];
            $voltage = trim((string) $p['voltage']);
            $price = (int) $p['price'];
            $searchText = strtolower($name . ' ' . $voltage);
            ?>
            <div class="col-12 col-sm-6 col-md-4 col-lg-3">
                <div class="card h-100 product-card" data-id="<?= $pid ?>" data-name="<?= esc($name) ?>"
                    data-voltage="<?= esc($voltage) ?>" data-price="<?= $price ?>" data-search="<?= esc($searchText) ?>">
                    <div class="card-body d-flex flex-column">
                        <div class="mb-1">
                            <div class="fw-semibold small"><?= esc($name) ?></div>
                            <?php if ($voltage !== '' && $voltage !== '-'): ?>
                                <div class="text-muted small">Voltase: <?= esc($voltage) ?>V</div>
                            <?php endif; ?>
                        </div>
                        <div class="mt-auto d-flex justify-content-between align-items-center">
                            <div class="fw-bold small"><?= format_rupiah($price) ?></div>
                            <button type="button" class="btn btn-sm btn-primary btn-add-product">
                                Tambah
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!$products): ?>
            <div class="col-12">
                <p class="text-muted">Belum ada produk terdaftar.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- KERANJANG (CARD LIST, BUKAN TABEL) -->
    <h5>Keranjang</h5>
    <div class="card mb-4">
        <div class="card-body">
            <div id="cartList"></div>

            <!-- Toast "produk ditambahkan" -->
            <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1055">
                <div id="toastAdded" class="toast align-items-center text-bg-success border-0" role="alert">
                    <div class="d-flex">
                        <div class="toast-body small">
                            Produk ditambahkan ke keranjang
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto"
                            data-bs-dismiss="toast"></button>
                    </div>
                </div>
            </div>
            <hr class="my-3">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="small text-muted">
                    Total hanya menghitung produk dengan harga. Produk custom akan dinilai admin.
                </div>
                <div class="fw-semibold">
                    Total: <span id="grandTotalDisplay">Rp 0</span>
                </div>
            </div>
        </div>
    </div>

    <!-- BAR BAWAH TOTAL (ikut submit) -->
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
        <div class="fw-semibold">
            Total Tagihan Sementara:
            <span id="grandTotalDisplayBar">Rp 0</span>
            <span class="text-muted small">(produk custom belum dihitung)</span>
        </div>
        <div class="text-sm-end">
            <button type="submit" class="btn btn-primary">Simpan Order</button>
            <a href="<?= base_url('reseller/orders.php') ?>" class="btn btn-secondary">Batal</a>
        </div>
    </div>
</form>

<!-- MODAL PRODUK CUSTOM -->
<div class="modal fade" id="customProductModal" tabindex="-1" aria-labelledby="customProductLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="customProductLabel">Tambah Produk Custom</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nama Produk Custom</label>
                    <input type="text" class="form-control" id="customNameInput"
                        placeholder="Contoh: Relay Set Custom HR-V Facelift">
                </div>
                <div class="mb-3">
                    <label class="form-label">Qty</label>
                    <input type="number" class="form-control" id="customQtyInput" value="1" min="1">
                </div>
                <p class="text-muted small mb-0">
                    Harga untuk produk custom akan ditentukan admin setelah order dibuat.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSaveCustom">Tambah ke Keranjang</button>
            </div>
        </div>
    </div>
</div>

<script>
    // Data keranjang awal (kalau POST gagal, kita rebuild dari server)
    const initialCartItems = <?php
    $initial = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error && !empty($items)) {
        foreach ($items as $it) {
            if ($it['product_id']) {
                $pRow = $productLookup[$it['product_id']] ?? null;
                $name = $pRow['name'] ?? ('Produk ID ' . $it['product_id']);
                $voltage = $it['voltage'] ?? ($pRow['voltage'] ?? '');
                $price = isset($pRow['price']) ? (int) $pRow['price'] : 0;
                $type = 'normal';
            } else {
                $name = $it['custom_name'];
                $voltage = $it['voltage'] ?? '';
                $price = 0;
                $type = 'custom';
            }
            $initial[] = [
                'type' => $type,
                'id' => $it['product_id'],
                'name' => $name,
                'voltage' => $voltage,
                'price' => $price,
                'qty' => (int) $it['qty'],
            ];
        }
    }
    echo json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;
</script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const cartList = document.getElementById('cartList');
        const grandTotalEl = document.getElementById('grandTotalDisplay');
        const grandTotalBarEl = document.getElementById('grandTotalDisplayBar');
        const productSearchInput = document.getElementById('productSearch');
        const productCardsNode = document.querySelectorAll('.product-card');
        const productCountInfo = document.getElementById('productCountInfo');
        const productPagination = document.getElementById('productPagination');
        const btnAddCustom = document.getElementById('btnAddCustom');

        const productCards = Array.from(productCardsNode);

        const PAGE_SIZE = 8;
        let allCards = productCards.slice();
        let filteredCards = productCards.slice();
        let currentPage = 1;

        let customModal;
        let customNameInput;
        let customQtyInput;

        // Bootstrap modal untuk produk custom
        if (window.bootstrap) {
            const modalEl = document.getElementById('customProductModal');
            customModal = new bootstrap.Modal(modalEl);
            customNameInput = document.getElementById('customNameInput');
            customQtyInput = document.getElementById('customQtyInput');

            document.getElementById('btnSaveCustom').addEventListener('click', function () {
                const nameRaw = (customNameInput.value || '').trim();
                let qty = parseInt(customQtyInput.value, 10) || 1;

                if (!nameRaw) {
                    alert('Nama produk custom tidak boleh kosong.');
                    customNameInput.focus();
                    return;
                }
                if (qty < 1) qty = 1;

                addCartRow({
                    type: 'custom',
                    id: null,
                    name: nameRaw,
                    voltage: '',
                    price: 0,
                    qty: qty
                });

                const toastEl = document.getElementById('toastAdded');
                if (window.bootstrap && toastEl) {
                    const toast = new bootstrap.Toast(toastEl);
                    toast.show();
                }

                customNameInput.value = '';
                customQtyInput.value = '1';
                customModal.hide();
            });
        }

        if (btnAddCustom && customModal) {
            btnAddCustom.addEventListener('click', function () {
                customNameInput.value = '';
                customQtyInput.value = '1';
                customModal.show();
                setTimeout(() => customNameInput.focus(), 200);
            });
        }

        function formatRupiah(angka) {
            if (isNaN(angka) || angka <= 0) return 'Rp 0';
            return 'Rp ' + angka.toLocaleString('id-ID');
        }

        function recalcCart() {
            let total = 0;

            cartList.querySelectorAll('.cart-item').forEach(item => {
                const type = item.getAttribute('data-row-type') || 'normal';
                const price = parseInt(item.getAttribute('data-price') || '0', 10);
                const qtyInput = item.querySelector('.qty-input');
                const subtotalSpan = item.querySelector('.subtotal-text');
                const priceSpan = item.querySelector('.price-text');
                const qtyHidden = item.querySelector('.qty-hidden');

                let qty = qtyInput ? parseInt(qtyInput.value || '0', 10) : 0;
                if (isNaN(qty) || qty < 1) qty = 1;

                if (type === 'normal') {
                    const subtotal = price * qty;
                    if (priceSpan) priceSpan.textContent = formatRupiah(price);
                    if (subtotalSpan) subtotalSpan.textContent = formatRupiah(subtotal);
                    total += subtotal;
                } else {
                    if (priceSpan) priceSpan.textContent = '—';
                    if (subtotalSpan) subtotalSpan.textContent = '—';
                }

                if (qtyHidden) {
                    qtyHidden.value = qty;
                }
            });

            grandTotalEl.textContent = formatRupiah(total);
            grandTotalBarEl.textContent = formatRupiah(total);
        }

        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function (m) {
                return map[m];
            });
        }

        function escapeHtmlAttr(text) {
            return escapeHtml(text);
        }

        function addCartRow(product) {
            const type = product.type || 'normal';
            const pid = product.id || '';
            const name = product.name || '';
            const voltage = product.voltage || '';
            const price = parseInt(product.price || '0', 10);
            const qty = parseInt(product.qty || 1, 10);

            // Jika produk normal sudah ada di keranjang → tambahkan qty
            if (type === 'normal' && pid) {
                const existing = cartList.querySelector('.cart-item[data-row-type="normal"][data-product-id="' + pid + '"]');
                if (existing) {
                    const qtyInput = existing.querySelector('.qty-input');
                    if (qtyInput) {
                        let current = parseInt(qtyInput.value || '0', 10);
                        if (isNaN(current) || current < 0) current = 0;
                        qtyInput.value = current + qty;
                    }
                    recalcCart();
                    return;
                }
            }

            const item = document.createElement('div');
            item.className = 'card cart-item mb-2';
            item.setAttribute('data-row-type', type);
            item.setAttribute('data-product-id', pid);
            item.setAttribute('data-price', price);

            const displayName = name + (voltage && voltage !== '-' ? ' (' + voltage + 'V)' : '');

            item.innerHTML = `
            <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-semibold small mb-0">${escapeHtml(displayName)}</div>
                        ${type === 'custom' ? '<div class="badge bg-secondary mt-1">Custom</div>' : ''}
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-item">&times;</button>
                </div>
                <div class="d-flex flex-wrap justify-content-between align-items-center mt-2 gap-2">
                    <div>
                        <div class="input-group input-group-sm" style="max-width: 150px;">
                            <button type="button" class="btn btn-outline-secondary btn-qty-minus">-</button>
                            <input type="number" class="form-control form-control-sm text-center qty-input"
                                   value="${qty}" min="1">
                            <button type="button" class="btn btn-outline-secondary btn-qty-plus">+</button>
                        </div>
                    </div>
                    <div class="text-end small">
                        <div>Harga / pcs:<br>
                            <span class="price-text fw-semibold">${type === 'normal' ? formatRupiah(price) : '—'}</span>
                        </div>
                        <div class="mt-1">Subtotal:<br>
                            <span class="subtotal-text fw-semibold">${type === 'normal' ? formatRupiah(price * qty) : '—'}</span>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="product_id[]" value="${type === 'normal' ? pid : ''}">
                <input type="hidden" name="custom_name[]" value="${type === 'custom' ? escapeHtmlAttr(name) : ''}">
                <input type="hidden" name="voltage[]" value="${escapeHtmlAttr(voltage)}">
                <input type="hidden" name="qty[]" class="qty-hidden" value="${qty}">
            </div>
        `;

            const minusBtn = item.querySelector('.btn-qty-minus');
            const plusBtn = item.querySelector('.btn-qty-plus');
            const qtyInput = item.querySelector('.qty-input');
            const removeBtn = item.querySelector('.btn-remove-item');

            minusBtn.addEventListener('click', function () {
                let val = parseInt(qtyInput.value || '0', 10);
                if (isNaN(val) || val <= 1) {
                    val = 1;
                } else {
                    val--;
                }
                qtyInput.value = val;
                recalcCart();
            });

            plusBtn.addEventListener('click', function () {
                let val = parseInt(qtyInput.value || '0', 10);
                if (isNaN(val) || val < 1) val = 1;
                val++;
                qtyInput.value = val;
                recalcCart();
            });

            qtyInput.addEventListener('input', function () {
                let val = parseInt(qtyInput.value || '0', 10);
                if (isNaN(val) || val < 1) val = 1;
                qtyInput.value = val;
                recalcCart();
            });

            removeBtn.addEventListener('click', function () {
                item.remove();
                recalcCart();
            });

            cartList.appendChild(item);
            recalcCart();
        }

        // Produk grid → klik "Tambah" masuk keranjang
        productCards.forEach(card => {
            const btn = card.querySelector('.btn-add-product');
            if (!btn) return;

            btn.addEventListener('click', function () {
                const pid = card.getAttribute('data-id');
                const name = card.getAttribute('data-name') || '';
                const voltage = card.getAttribute('data-voltage') || '';
                const price = parseInt(card.getAttribute('data-price') || '0', 10);

                addCartRow({
                    type: 'normal',
                    id: pid,
                    name: name,
                    voltage: voltage,
                    price: price,
                    qty: 1
                });

                const toastEl = document.getElementById('toastAdded');
                if (window.bootstrap && toastEl) {
                    const toast = new bootstrap.Toast(toastEl);
                    toast.show();
                }
            });
        });

        function showPage(page) {
            if (filteredCards.length === 0) {
                productCards.forEach(c => c.parentElement.classList.add('d-none'));
                updateCountInfo();
                updatePaginationButtons();
                return;
            }

            const totalPages = Math.max(1, Math.ceil(filteredCards.length / PAGE_SIZE));
            if (page < 1) page = 1;
            if (page > totalPages) page = totalPages;
            currentPage = page;

            const startIndex = (currentPage - 1) * PAGE_SIZE;
            const endIndex = startIndex + PAGE_SIZE;

            productCards.forEach(card => card.parentElement.classList.add('d-none'));

            filteredCards.slice(startIndex, endIndex).forEach(card => {
                card.parentElement.classList.remove('d-none');
            });

            updateCountInfo();
            updatePaginationButtons();
        }

        function updateCountInfo() {
            if (!productCountInfo) return;

            const total = filteredCards.length;
            if (total === 0) {
                productCountInfo.textContent = 'Tidak ada produk yang cocok.';
                return;
            }

            const start = (currentPage - 1) * PAGE_SIZE + 1;
            const end = Math.min(currentPage * PAGE_SIZE, total);
            productCountInfo.textContent = `Menampilkan ${start}–${end} dari ${total} produk`;
        }

        function updatePaginationButtons() {
            if (!productPagination) return;

            productPagination.innerHTML = '';

            const total = filteredCards.length;
            if (total === 0) {
                return;
            }

            const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

            function createPageItem(label, page, disabled = false, active = false) {
                const li = document.createElement('li');
                li.className = 'page-item';
                if (disabled) li.classList.add('disabled');
                if (active) li.classList.add('active');

                const a = document.createElement('a');
                a.className = 'page-link';
                a.href = '#';
                a.textContent = label;

                if (!disabled) {
                    a.addEventListener('click', function (e) {
                        e.preventDefault();
                        showPage(page);
                    });
                }

                li.appendChild(a);
                return li;
            }

            productPagination.appendChild(
                createPageItem('«', currentPage - 1, currentPage === 1)
            );

            for (let p = 1; p <= totalPages; p++) {
                productPagination.appendChild(
                    createPageItem(String(p), p, false, p === currentPage)
                );
            }

            productPagination.appendChild(
                createPageItem('»', currentPage + 1, currentPage === totalPages)
            );
        }

        function applyProductFilter() {
            const q = (productSearchInput?.value || '').toLowerCase().trim();
            const tokens = q.split(/\s+/).filter(Boolean);

            if (tokens.length === 0) {
                filteredCards = allCards.slice();
            } else {
                filteredCards = allCards.filter(card => {
                    const text = (card.getAttribute('data-search') || '').toLowerCase();
                    return tokens.every(t => text.indexOf(t) !== -1);
                });
            }

            currentPage = 1;
            showPage(currentPage);
        }

        if (productSearchInput) {
            productSearchInput.addEventListener('input', function () {
                applyProductFilter();
            });
        }

        // Inisialisasi awal
        applyProductFilter();

        // REBUILD KERANJANG kalau ada initialCartItems dari server (error submit sebelumnya)
        if (Array.isArray(initialCartItems) && initialCartItems.length > 0) {
            initialCartItems.forEach(function (it) {
                addCartRow(it);
            });
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>