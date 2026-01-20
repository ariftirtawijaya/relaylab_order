<?php
// reseller/report.php
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('reseller');
$user = current_user();

if (!function_exists('format_rupiah_new')) {
    function format_rupiah_new(int $v): string
    {
        return number_format($v, 0, ',', '.');
    }
}

$resellerId = (int) $user['reseller_id'];

// =====================
// FILTER: GROUP & RANGE
// =====================
$groupBy = $_GET['group'] ?? 'month'; // 'week' / 'month'
if (!in_array($groupBy, ['week', 'month'], true)) {
    $groupBy = 'month';
}

$startDate = $_GET['start'] ?? '';
$endDate = $_GET['end'] ?? '';

$today = new DateTime('today');

// default: bulan ini
if ($startDate === '' || $endDate === '') {
    $startDate = $today->format('Y-m-01'); // tgl 1 bulan ini
    $endDate = $today->format('Y-m-d');  // hari ini
}

// validasi sederhana format tanggal (Y-m-d)
$startDateObj = DateTime::createFromFormat('Y-m-d', $startDate);
$endDateObj = DateTime::createFromFormat('Y-m-d', $endDate);
if (!$startDateObj || !$endDateObj) {
    // kalau format aneh, fallback ke bulan ini
    $startDate = $today->format('Y-m-01');
    $endDate = $today->format('Y-m-d');
    $startDateObj = DateTime::createFromFormat('Y-m-d', $startDate);
    $endDateObj = DateTime::createFromFormat('Y-m-d', $endDate);
}

// supaya endDate selalu >= startDate
if ($endDateObj < $startDateObj) {
    $endDateObj = clone $startDateObj;
    $endDate = $endDateObj->format('Y-m-d');
}

// ========================
// AMBIL DATA ORDER RESELLER
// ========================
$sql = "
    SELECT 
        o.id,
        o.code,
        o.order_date,
        o.status,
        SUM(oi.qty_order) AS total_qty,
        SUM(oi.qty_order * COALESCE(p.price, 0)) AS order_total,
        COALESCE(pay.total_paid, 0) AS total_paid
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN products p ON p.id = oi.product_id
    LEFT JOIN (
        SELECT order_id, SUM(amount) AS total_paid
        FROM order_payments
        GROUP BY order_id
    ) AS pay ON pay.order_id = o.id
    WHERE 
        o.reseller_id = ?
        AND o.order_date >= ?
        AND o.order_date <= ?
    GROUP BY o.id
    ORDER BY o.order_date DESC
";

$params = [
    $resellerId,
    $startDate . ' 00:00:00',
    $endDate . ' 23:59:59',
];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =====================
// HITUNG GROUP (MINGGU/BULAN)
// =====================
$groups = [];
$grandOrders = 0;
$grandTotalQty = 0;
$grandOrderValue = 0;
$grandTotalPaid = 0;

foreach ($orders as $row) {
    $grandOrders++;
    $grandTotalQty += (int) $row['total_qty'];
    $grandOrderValue += (int) $row['order_total'];
    $grandTotalPaid += (int) $row['total_paid'];

    $ts = strtotime($row['order_date']);

    if ($groupBy === 'week') {
        // pakai ISO week (minggu ke-1,2,...)
        $isoYear = (int) date('o', $ts); // ISO year
        $isoWeek = (int) date('W', $ts); // ISO week number

        $groupKey = $isoYear . '-W' . str_pad((string) $isoWeek, 2, '0', STR_PAD_LEFT);

        // hitung rentang minggu (Senin–Minggu)
        $startWeek = new DateTime();
        $startWeek->setISODate($isoYear, $isoWeek); // Senin
        $endWeek = clone $startWeek;
        $endWeek->modify('+6 days'); // Minggu

        $label = sprintf(
            'Minggu %d (%s – %s)',
            $isoWeek,
            $startWeek->format('d/m'),
            $endWeek->format('d/m')
        );
    } else {
        // group bulanan
        $groupKey = date('Y-m', $ts);
        // contoh label: "Jan 2026"
        $label = date('m/y', $ts);
    }

    if (!isset($groups[$groupKey])) {
        $groups[$groupKey] = [
            'label' => $label,
            'total_orders' => 0,
            'total_qty' => 0,
            'order_value' => 0,
            'total_paid' => 0,
        ];
    }

    $groups[$groupKey]['total_orders']++;
    $groups[$groupKey]['total_qty'] += (int) $row['total_qty'];
    $groups[$groupKey]['order_value'] += (int) $row['order_total'];
    $groups[$groupKey]['total_paid'] += (int) $row['total_paid'];
}

// supaya urut terbaru di atas (berdasarkan key)
if ($groupBy === 'week') {
    krsort($groups); // key format: YYYY-Wxx
} else {
    krsort($groups); // key format: YYYY-mm
}

$grandBalance = $grandOrderValue - $grandTotalPaid;

include __DIR__ . '/../partials/header.php';
?>

<h3 class="mb-3">Rekap Order Saya</h3>

<!-- FILTER -->
<form method="get" class="card mb-3">
    <div class="card-body row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label mb-1">Group Berdasarkan</label>
            <select name="group" class="form-select form-select-sm">
                <option value="month" <?= $groupBy === 'month' ? 'selected' : '' ?>>Bulanan</option>
                <option value="week" <?= $groupBy === 'week' ? 'selected' : '' ?>>Mingguan</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label mb-1">Dari Tanggal</label>
            <input type="date" name="start" value="<?= esc($startDate) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-md-3">
            <label class="form-label mb-1">Sampai Tanggal</label>
            <input type="date" name="end" value="<?= esc($endDate) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-md-3">
            <button class="btn btn-sm btn-primary w-100">Terapkan</button>
        </div>
    </div>
</form>

<!-- RINGKASAN GLOBAL -->
<div class="row mb-3">
    <div class="col-md-3 col-6 mb-2">
        <div class="card h-100">
            <div class="card-body py-2">
                <div class="text-muted small">Jumlah Order</div>
                <div class="fw-bold fs-5">
                    <?= (int) $grandOrders ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card h-100">
            <div class="card-body py-2">
                <div class="text-muted small">Total Qty Produk</div>
                <div class="fw-bold fs-5">
                    <?= (int) $grandTotalQty ?> pcs
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card h-100">
            <div class="card-body py-2">
                <div class="text-muted small">Total Nilai Order</div>
                <div class="fw-bold fs-6">
                    <?= format_rupiah_new((int) $grandOrderValue) ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6 mb-2">
        <div class="card h-100">
            <div class="card-body py-2">
                <div class="text-muted small">Total Pembayaran</div>
                <div class="fw-bold fs-6">
                    <?= format_rupiah_new((int) $grandTotalPaid) ?>
                </div>
                <div class="small <?= $grandBalance > 0 ? 'text-danger' : 'text-success' ?>">
                    Sisa:
                    <?= format_rupiah_new((int) $grandBalance) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- TABEL REKAP PER PERIODE -->
<div class="card mb-4">
    <div class="card-header">
        <strong>Ringkasan per
            <?= $groupBy === 'week' ? 'Minggu' : 'Bulan' ?>
        </strong>
    </div>
    <div class="card-body">
        <?php if (!$groups): ?>
            <p class="text-muted mb-0">Belum ada order di rentang tanggal ini.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-center align-middle">Periode</th>
                            <th class="text-center align-middle">Jumlah Order</th>
                            <th class="text-center align-middle">Total Qty</th>
                            <th class="text-center align-middle">Nilai Order</th>
                            <th class="text-center align-middle">Pembayaran</th>
                            <th class="text-center align-middle">Sisa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groups as $g): ?>
                            <?php
                            $sisa = $g['order_value'] - $g['total_paid'];
                            ?>
                            <tr>
                                <td class="text-center text-nowrap">
                                    <?= esc($g['label']) ?>
                                </td>
                                <td class="text-center">
                                    <?= (int) $g['total_orders'] ?>
                                </td>
                                <td class="text-center  text-nowrap">
                                    <?= (int) $g['total_qty'] ?> pcs
                                </td>
                                <td class="text-center">
                                    <?= format_rupiah_new((int) $g['order_value']) ?>
                                </td>
                                <td class="text-center">
                                    <?= format_rupiah_new((int) $g['total_paid']) ?>
                                </td>
                                <td class="text-center <?= $sisa > 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= format_rupiah_new((int) $sisa) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- DAFTAR ORDER DETAIL -->
<div class="card mb-4">
    <div class="card-header">
        <strong>Detail Order di Rentang Tanggal Ini</strong>
    </div>
    <div class="card-body">
        <?php if (!$orders): ?>
            <p class="text-muted mb-0">Belum ada order.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle">
                    <thead>
                        <tr>
                            <th class="text-center align-middle">Tanggal</th>
                            <th class="text-center align-middle">Kode Order</th>
                            <th class="text-center align-middle">Status</th>
                            <th class="text-center align-middle">Total Qty</th>
                            <th class="text-center align-middle">Nilai Order</th>
                            <th class="text-center align-middle">Total Bayar</th>
                            <th class="text-center align-middle">Sisa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o): ?>
                            <?php
                            $tgl = date('d/m/y', strtotime($o['order_date']));
                            $sisa = (int) $o['order_total'] - (int) $o['total_paid'];
                            $statusLabel = function_exists('format_status')
                                ? format_status($o['status'])
                                : $o['status'];
                            ?>
                            <tr>
                                <td class="text-nowrap">
                                    <?= esc($tgl) ?>
                                </td>
                                <td class="text-nowrap">
                                    <a href="<?= base_url('reseller/order_view.php?id=' . (int) $o['id']) ?>">
                                        <?= esc($o['code']) ?>
                                    </a>
                                </td>
                                <td>
                                    <?= esc($statusLabel) ?>
                                </td>
                                <td class="text-center text-nowrap">
                                    <?= (int) $o['total_qty'] ?> pcs
                                </td>
                                <td class="text-center text-nowrap">
                                    <?= format_rupiah_new((int) $o['order_total']) ?>
                                </td>
                                <td class="text-center text-nowrap">
                                    <?= format_rupiah_new((int) $o['total_paid']) ?>
                                </td>
                                <td class="text-center text-nowrap <?= $sisa > 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= format_rupiah_new((int) $sisa) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<a href="<?= base_url('reseller/orders.php') ?>" class="btn btn-secondary">Kembali ke Daftar Order</a>

<?php include __DIR__ . '/../partials/footer.php'; ?>