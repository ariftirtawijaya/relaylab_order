<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../app/db.php';      // kalau perlu akses DB
require_once __DIR__ . '/../app/helpers.php'; // kalau mau pakai helper dll

$json = file_get_contents('php://input');
$data = json_decode($json, true);

$sender = $data['sender'] ?? '';
$message = trim($data['message'] ?? '');
$name = $data['name'] ?? '';

// Kalau pesan kosong, ya udah, diam saja
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
        $reply = [
            'message' => "Format salah.\nContoh: /cekorder RL-091225-0001",
        ];
        sendFonnte($sender, $reply);
        exit;
    }

    // --- QUERY DB ORDER + ITEM BERDASARKAN KODE ---
    // ini contoh, sesuaikan nama tabel/kolom dengan schema OMS kamu
    $pdo = $GLOBALS['pdo'] ?? null; // kalau dari db.php
    $stmt = $pdo->prepare("
        SELECT o.id, o.code, o.status, o.order_date, r.name AS reseller_name
        FROM orders o
        JOIN resellers r ON r.id = o.reseller_id
        WHERE o.code = ?
        LIMIT 1
    ");
    $stmt->execute([$kodeOrder]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $reply = [
            'message' => "Order dengan kode *{$kodeOrder}* tidak ditemukan.\n\nCek lagi kodenya ya.",
        ];
        sendFonnte($sender, $reply);
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

    // status text rapi dikit
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

    $reply = ['message' => $msg];
    sendFonnte($sender, $reply);
    exit;
}

// === SELAIN /cekorder → JANGAN RESPON APAPUN ===
// CS kamu bebas balas manual dari WA
http_response_code(200);
echo json_encode(['status' => 'ignored']);
exit;


// ===== FUNGSI KIRIM WA KE FONNTE =====
function sendFonnte($target, $data)
{
    $token = 'TOKEN_FONNTE_KAMU';

    $payload = [
        'target' => $target,
        'message' => $data['message'] ?? '',
    ];

    // kalau mau pakai url/file juga di webhook, bisa tambahkan:
    if (!empty($data['url'])) {
        $payload['url'] = $data['url'];
    }
    if (!empty($data['filename'])) {
        $payload['filename'] = $data['filename'];
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.fonnte.com/send",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Authorization: {$token}",
        ],
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    return $response;
}
