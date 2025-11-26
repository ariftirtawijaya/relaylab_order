<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('admin');

// statistik singkat
$totOrder = $pdo->query("SELECT COUNT(*) c FROM orders")->fetch()['c'];
$totReseller = $pdo->query("SELECT COUNT(*) c FROM resellers")->fetch()['c'];
$totProduk = $pdo->query("SELECT COUNT(*) c FROM products")->fetch()['c'];

include __DIR__ . '/../partials/header.php';
?>
<h3 class="mb-4">Dashboard Admin</h3>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="text-muted">Total Order</h6>
                <h3><?= (int) $totOrder ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="text-muted">Total Reseller</h6>
                <h3><?= (int) $totReseller ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="text-muted">Produk Aktif</h6>
                <h3><?= (int) $totProduk ?></h3>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>