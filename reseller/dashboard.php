<?php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('reseller');

$user = current_user();
$resellerId = $user['reseller_id'];

$stmt = $pdo->prepare("SELECT 
    SUM(status = 'menunggu_konfirmasi') AS pending,
    SUM(status = 'diproses') AS diproses,
    SUM(status = 'selesai') AS selesai
  FROM orders WHERE reseller_id = ?");
$stmt->execute([$resellerId]);
$stat = $stmt->fetch();

include __DIR__ . '/../partials/header.php';
?>
<h3 class="mb-4">Dashboard Reseller</h3>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="text-muted">Menunggu Konfirmasi</h6>
                <h3><?= (int) $stat['pending'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="text-muted">Sedang Diproses</h6>
                <h3><?= (int) $stat['diproses'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="text-muted">Selesai</h6>
                <h3><?= (int) $stat['selesai'] ?></h3>
            </div>
        </div>
    </div>
</div>

<p>Silakan buka menu <strong>Order Saya</strong> untuk melihat detail atau <strong>Buat Order</strong> untuk membuat
    pesanan baru.</p>

<?php include __DIR__ . '/../partials/footer.php'; ?>