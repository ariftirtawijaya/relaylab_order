<?php
// admin/reseller_form.php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('admin');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$reseller = [
    'name' => '',
    'whatsapp' => '',
    'address' => '',
];

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM resellers WHERE id = ?");
    $stmt->execute([$id]);
    $reseller = $stmt->fetch();
    if (!$reseller)
        die("Reseller tidak ditemukan");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($name === '') {
        $error = "Nama reseller wajib diisi";
    } else {
        if ($id) {
            $stmt = $pdo->prepare("UPDATE resellers 
                                   SET name = ?, whatsapp = ?, address = ?
                                   WHERE id = ?");
            $stmt->execute([$name, $whatsapp, $address, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO resellers (name, whatsapp, address)
                                   VALUES (?,?,?)");
            $stmt->execute([$name, $whatsapp, $address]);
        }

        redirect('admin/resellers.php');
    }
}

include __DIR__ . '/../partials/header.php';
?>
<h3 class="mb-3"><?= $id ? 'Edit' : 'Tambah' ?> Reseller</h3>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
<?php endif; ?>

<form method="post" class="card card-body shadow-sm border-0">
    <div class="mb-3">
        <label class="form-label">Nama *</label>
        <input type="text" name="name" class="form-control" required value="<?= esc($reseller['name']) ?>">
    </div>

    <div class="mb-3">
        <label class="form-label">WhatsApp</label>
        <input type="text" name="whatsapp" class="form-control" value="<?= esc($reseller['whatsapp']) ?>">
    </div>

    <div class="mb-3">
        <label class="form-label">Alamat</label>
        <textarea name="address" rows="3" class="form-control"><?= esc($reseller['address']) ?></textarea>
    </div>

    <button class="btn btn-primary">Simpan</button>
    <a href="<?= base_url('admin/resellers.php') ?>" class="btn btn-secondary">Kembali</a>
</form>

<?php include __DIR__ . '/../partials/footer.php'; ?>