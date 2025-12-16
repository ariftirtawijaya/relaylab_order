<?php
// webhook.php
header('Content-Type: application/json; charset=utf-8');

// === SESUAIKAN PATH DENGAN PROJECT OMS KAMU ===
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php'; // supaya bisa pakai format_status, dll

// --- Fungsi kirim WA via Fonnte (pakai contoh dari dokumentasi) ---
function sendFonnte($target, array $data)
{
    $token = 'yNuNwRkmU8L4YDyF1NQi'; // TOKEN Fonnte kamu

    $postfields = [
        'target' => $target,
        'message' => $data['message'] ?? '',
    ];

    // opsional: kalau mau kirim file/url bisa ditambah di sini
    if (!empty($data['url'])) {
        $postfields['url'] = $data['url'];
    }
    if (!empty($data['filename'])) {
        $postfields['filename'] = $data['filename'];
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $postfields,
        CURLOPT_HTTPHEADER => [
            "Authorization: {$token}",
        ],
    ]);

    $response = curl_exec($curl);
    if (curl_errno($curl)) {
        error_log('FONNTE WEBHOOK CURL ERROR: ' . curl_error($curl));
    }
    curl_close($curl);

    error_log('FONNTE WEBHOOK RESPONSE: ' . $response);
    return $response;
}

// --- Kirim pesan ke grup ---
function whatsapp_send_group(string $text): void
{
    try {
        $token = "yNuNwRkmU8L4YDyF1NQi";   // Ganti dengan token Fonnte Anda
        $group_id = "120363422006298260@g.us";    // Ganti dengan ID Group WhatsApp, format: 628xxxxxxx-xxxxx@g.us

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://api.fonnte.com/send',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => array(
                'target' => $group_id,
                'message' => $text,
            ),
            CURLOPT_HTTPHEADER => array(
                'Authorization: ' . $token
            ),
        ));

        $response = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);
    } catch (Throwable $e) {
        error_log('Whatsapp exception: ' . $e->getMessage());
    }

}

// --- Helper: normalisasi nomor WA ke format lokal (0895...) ---
function normalize_wa_to_local(string $wa): string
{
    // buang non-digit
    $wa = preg_replace('/\D+/', '', $wa ?? '');

    // kalau mulai 62, ubah ke 0xx
    if (strpos($wa, '62') === 0) {
        $wa = '0' . substr($wa, 2);
    }

    return $wa;
}

// --- Baca payload dari Fonnte ---
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// optional: log untuk debug
// file_put_contents(__DIR__ . '/webhook_log.txt', date('Y-m-d H:i:s') . ' ' . $raw . PHP_EOL, FILE_APPEND);

if (!$data) {
    http_response_code(400);
    echo json_encode(['status' => false, 'reason' => 'invalid json']);
    exit;
}

$sender = $data['sender'] ?? ''; // format: 628xxxx
$message = trim($data['message'] ?? '');
$name = $data['name'] ?? '';

// kalau kosong → diam saja
if ($sender === '' || $message === '') {
    http_response_code(200);
    echo json_encode(['status' => true, 'reason' => 'empty']);
    exit;
}

// Normalisasi ke format lokal (0895...) untuk dicocokkan dengan resellers.whatsapp
$senderLocal = normalize_wa_to_local($sender);

// ====== FITUR /pengumuman ======
$announceFile = __DIR__ . '/announce_state.json';
$announceState = file_exists($announceFile) ? json_decode(file_get_contents($announceFile), true) : [];

// Jika user kirim "/pengumuman"
if (preg_match('/^\/pengumuman$/i', $message)) {
    $announceState[$sender] = true; // aktifkan mode menulis
    file_put_contents($announceFile, json_encode($announceState));
    sendFonnte($sender, ['message' => "Silahkan isi pesan pengumuman."]);
    echo json_encode(['status' => true]);
    exit;
}

// Jika sedang dalam mode pengumuman
if (!empty($announceState[$sender])) {
    unset($announceState[$sender]);
    file_put_contents($announceFile, json_encode($announceState));

    // Kirim ke grup
    whatsapp_send_group("📢 $message");
    sendFonnte($sender, ['message' => "Pesan pengumuman sudah dikirim ke grup. ✅"]);
    echo json_encode(['status' => true]);
    exit;
}

// =============== ROUTING PERINTAH ===============

// 1) Kalau hanya tulis "/cekorder" → kirim cara pakai
if (preg_match('/^\/cekorder\s*$/i', $message)) {
    $reply = [
        'message' =>
            "Halo *{$name}* 👋\n" .
            "Untuk cek status order, gunakan format:\n\n" .
            "/cekorder KODE_ORDER\n\n" .
            "Contoh:\n/cekorder RL-091225-0001",
    ];
    sendFonnte($sender, $reply);
    echo json_encode(['status' => true]);
    exit;
}

// 2) Format lengkap: "/cekorder RL-091225-0001"
if (preg_match('/^\/cekorder\s+([A-Za-z0-9\-]+)/i', $message, $m)) {
    $orderCode = strtoupper(trim($m[1]));

    /** @var PDO $pdo */
    // --- Cari reseller berdasarkan nomor WA ---
    $stmt = $pdo->prepare("SELECT id, name FROM resellers WHERE whatsapp = ? LIMIT 1");
    $stmt->execute([$senderLocal]);
    $reseller = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reseller) {
        $reply = [
            'message' =>
                "Maaf, nomor ini belum terdaftar sebagai reseller di sistem RelayLab.\n\n" .
                "Kalau kamu reseller, hubungi admin untuk update nomor WhatsApp di OMS ya. 🙏",
        ];
        sendFonnte($sender, $reply);
        echo json_encode(['status' => true]);
        exit;
    }

    $resellerId = (int) $reseller['id'];

    // --- Cari order berdasarkan kode + reseller_id (biar aman) ---
    $stmt = $pdo->prepare("
        SELECT o.*
        FROM orders o
        WHERE o.code = ? AND o.reseller_id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderCode, $resellerId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $reply = [
            'message' =>
                "Kode order *{$orderCode}* tidak ditemukan untuk nomor ini.\n\n" .
                "Pastikan kode benar dan order memang dibuat dari akun reseller kamu.",
        ];
        sendFonnte($sender, $reply);
        echo json_encode(['status' => true]);
        exit;
    }

    $orderId = (int) $order['id'];

    // --- Ambil item order ---
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(oi.custom_name, p.name) AS name,
            oi.qty_order,
            oi.qty_done,
            oi.qty_shipped
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        $reply = [
            'message' =>
                "Order *{$orderCode}* tidak memiliki item.\n" .
                "Silakan konfirmasi ke admin RelayLab.",
        ];
        sendFonnte($sender, $reply);
        echo json_encode(['status' => true]);
        exit;
    }

    // --- Bagi jadi: sudah dikirim & belum selesai ---
    $sentText = "";
    $pendingText = "";

    foreach ($items as $it) {
        $pName = $it['name'];
        $qtyOrder = (int) $it['qty_order'];
        $qtyDone = (int) $it['qty_done'];
        $qtyShipped = (int) $it['qty_shipped'];

        // Sudah dikirim (minimal 1 pcs)
        if ($qtyShipped > 0) {
            $sentText .= "- {$pName} ({$qtyShipped}/{$qtyOrder} pcs terkirim)\n";
        }

        // Belum selesai (belum terkirim penuh)
        if ($qtyShipped < $qtyOrder) {
            $statusProgress = $qtyDone <= 0
                ? "belum dikerjakan"
                : "sedang diproses ({$qtyDone}/{$qtyOrder} selesai)";

            $pendingText .= "- {$pName} ({$qtyOrder} pcs) → {$statusProgress}\n";
        }
    }

    // --- Ambil riwayat pengiriman ---
    $stmt = $pdo->prepare("
        SELECT ship_date, courier, tracking_number
        FROM shipments
        WHERE order_id = ?
        ORDER BY ship_date
    ");
    $stmt->execute([$orderId]);
    $ships = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $shipText = "";
    if ($ships) {
        $shipText .= "🚚 *Riwayat Pengiriman:*\n";
        foreach ($ships as $s) {
            $tgl = $s['ship_date']
                ? date('d-m-Y H:i', strtotime($s['ship_date']))
                : '-';
            $kurir = $s['courier'] ?: '-';
            $resi = $s['tracking_number'] ?: '-';

            $shipText .= "- {$tgl} | {$kurir} | Resi: {$resi}\n";
        }
        $shipText .= "\n";
    }

    // --- Format header order ---
    $statusLabel = function_exists('format_status')
        ? format_status($order['status'])
        : $order['status'];

    $tglOrder = $order['order_date']
        ? date('d-m-Y H:i', strtotime($order['order_date']))
        : '-';

    $msg = "📄 *Detail Order {$order['code']}*\n";
    $msg .= "Tanggal Order: {$tglOrder}\n";
    $msg .= "Status: {$statusLabel}\n\n";

    if ($sentText !== "") {
        $msg .= "✅ *Produk yang sudah dikirim:*\n{$sentText}\n";
    }

    if ($pendingText !== "") {
        $msg .= "⏳ *Produk yang belum selesai / dalam proses:*\n{$pendingText}\n";
    }

    if ($shipText !== "") {
        $msg .= $shipText;
    }

    $msg .= "Terima kasih sudah order di RelayLab 🙏";

    sendFonnte($sender, ['message' => $msg]);
    echo json_encode(['status' => true]);
    exit;
}

// === SELAIN /cekorder → JANGAN RESPON APAPUN (biar CS bebas jawab) ===
http_response_code(200);
echo json_encode(['status' => true, 'reason' => 'ignored']);
exit;
