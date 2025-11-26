<?php
// admin/product_form.php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('admin');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$product = [
    'code' => '',
    'name' => '',
    'voltage' => '12',  // default 12V
    'price' => 0,
    'notes' => '',
];

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $product = $stmt->fetch();
    if (!$product) {
        die("Produk tidak ditemukan");
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $voltage = $_POST['voltage'] ?? '12';
    $price = (int) ($_POST['price'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($name === '') {
        $error = "Nama produk wajib diisi.";
    } elseif (!in_array($voltage, ['12', '24'], true)) {
        $error = "Voltase tidak valid.";
    } elseif ($price < 0) {
        $error = "Harga tidak boleh negatif.";
    } else {

        if ($id) {
            // Update produk (kode TIDAK diubah)
            $stmt = $pdo->prepare("
                UPDATE products
                SET name = ?, voltage = ?, price = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $voltage, $price, $notes, $id]);
        } else {
            // Buat kode produk otomatis
            $code = generate_product_code($pdo);

            $stmt = $pdo->prepare("
                INSERT INTO products (code, name, voltage, price, notes)
                VALUES (?,?,?,?,?)
            ");
            $stmt->execute([$code, $name, $voltage, $price, $notes]);
        }

        redirect('admin/products.php');
    }

    // Supaya form tetap kebawa nilainya saat error
    $product['name'] = $name;
    $product['voltage'] = $voltage;
    $product['price'] = $price;
    $product['notes'] = $notes;
}

include __DIR__ . '/../partials/header.php';
?>

<h3 class="mb-3"><?= $id ? 'Edit' : 'Tambah' ?> Produk</h3>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
<?php endif; ?>

<form method="post" class="card card-body border-0 shadow-sm mb-4">

    <?php if ($id): ?>
        <div class="mb-3">
            <label class="form-label">Kode Produk</label>
            <input type="text" class="form-control" value="<?= esc($product['code']) ?>" readonly>
        </div>
    <?php endif; ?>

    <div class="mb-3">
        <label class="form-label">Nama Produk *</label>
        <input type="text" name="name" class="form-control" required value="<?= esc($product['name']) ?>">
    </div>

    <div class="row">
        <div class="mb-3 col-md-4">
            <label class="form-label">Voltase *</label>
            <select name="voltage" class="form-select" required>
                <option value="12" <?= $product['voltage'] == '12' ? 'selected' : '' ?>>12V</option>
                <option value="24" <?= $product['voltage'] == '24' ? 'selected' : '' ?>>24V</option>
            </select>
        </div>
        <div class="mb-3 col-md-8">
            <label class="form-label">Harga (per pcs)</label>
            <input type="number" name="price" class="form-control" min="0" value="<?= (int) $product['price'] ?>">
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label">Catatan (opsional)</label>
        <textarea name="notes" rows="3" class="form-control"><?= esc($product['notes']) ?></textarea>
    </div>

    <button class="btn btn-primary">Simpan</button>
    <a href="<?= base_url('admin/products.php') ?>" class="btn btn-secondary">Kembali</a>
</form>

<?php include __DIR__ . '/../partials/footer.php'; ?>