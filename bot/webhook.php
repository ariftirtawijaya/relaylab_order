<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';

$json = file_get_contents('php://input');
$data = json_decode($json, true) ?: [];

// logging sederhana untuk debug
error_log('FONNTE WEBHOOK INPUT: ' . $json);

$sender = $data['sender'] ?? '';
$message = trim($data['message'] ?? '');
$name = $data['name'] ?? '';

if ($message === '' || $sender === '') {
    http_response_code(200);
    echo json_encode(['status' => 'empty']);
    exit;
}

// === HANYA RESPON /cekorder ===
if (stripos($message, '/cekorder') === 0) {
    // parsing: /cekorder RL-091225-0001
    $parts = preg_split('/\s+/', $message);
    $kodeOrder = strtoupper($parts[1] ?? '');

    if ($kodeOrder === '') {
        $msg = "Format salah.\nContoh: /cekorder RL-091225-0001";
        // pakai helper yang sudah terbukti jalan
        send_wa_notification($sender, $msg);

        echo json_encode(['status' => 'ok', 'info' => 'format_salah']);
        exit;
    }

    // --- QUERY DB ORDER + ITEM BERDASARKAN KODE ---
    /** @var PDO $pdo */
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            SELECT o.id, o.code, o.status, o.order_date, r.name AS reseller_name
            FROM orders o
            JOIN resellers r ON r.id = o.reseller_id
            WHERE o.code = ?
            LIMIT 1
        ");
        $stmt->execute([$kodeOrder]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('CEKORDER DB ERROR: ' . $e->getMessage());
        send_wa_notification($sender, "Terjadi kesalahan di server.\nSilakan coba beberapa saat lagi.");
        echo json_encode(['status' => 'error', 'info' => 'db_error']);
        exit;
    }

    if (!$order) {
        $msg = "Order dengan kode *{$kodeOrder}* tidak ditemukan.\n\nCek lagi kodenya ya.";
        send_wa_notification($sender, $msg);
        echo json_encode(['status' => 'ok', 'info' => 'order_not_found']);
        exit;
    }

    // ambil item
    $stmtI = $pdo->prepare("
        SELECT COALESCE(oi.custom_name, p.name) AS name,
               oi.qty_order,
               oi.qty_done,
               oi.qty_shipped
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $stmtI->execute([$order['id']]);
    $items = $stmtI->fetchAll(PDO::FETCH_ASSOC);

    $listProduk = "";
    foreach ($items as $it) {
        $sisa = (int) $it['qty_order'] - (int) $it['qty_shipped'];
        $listProduk .= "- {$it['name']} | Pesan: {$it['qty_order']} | Selesai: {$it['qty_done']} | Terkirim: {$it['qty_shipped']} | Sisa Kirim: {$sisa}\n";
    }

    $statusMap = [
        'menunggu_konfirmasi' => 'Menunggu Konfirmasi',
        'diproses' => 'Sedang Diproses',
        'selesai' => 'Selesai',
    ];
    $statusText = $statusMap[$order['status']] ?? $order['status'];

    $msg =
        "📦 *Status Order Kamu*\n" .
        "---------------------------------\n" .
        "Kode Order : *{$order['code']}*\n" .
        "Reseller   : {$order['reseller_name']}\n" .
        "Tanggal    : {$order['order_date']}\n" .
        "Status     : *{$statusText}*\n\n" .
        "*Detail Produk:*\n{$listProduk}";

    send_wa_notification($sender, $msg);

    echo json_encode(['status' => 'ok', 'info' => 'reply_sent']);
    exit;
}

// Selain /cekorder → diam saja, biar CS yang jawab
http_response_code(200);
echo json_encode(['status' => 'ignored']);
exit;
