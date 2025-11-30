<?php
// app/helpers.php

function base_url(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

function redirect(string $path)
{
    header('Location: ' . base_url($path));
    exit;
}

function esc($str)
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Generate kode order unik per hari, format: RL-DDMMYY-0001
 * Contoh: RL-271125-0001
 */
function generate_order_code(PDO $pdo): string
{
    // Format tanggal: DDMMYY
    $datePart = date('dmy');
    $prefix   = 'RL-' . $datePart . '-';

    // Ambil nomor urut terakhir untuk hari ini
    // Contoh code di DB: RL-271125-0003 -> ambil 3
    $sql = "
        SELECT MAX(CAST(SUBSTRING_INDEX(code, '-', -1) AS UNSIGNED)) AS max_seq
        FROM orders
        WHERE code LIKE :prefix
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':prefix' => $prefix . '%']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $last = isset($row['max_seq']) ? (int)$row['max_seq'] : 0;
    $next = $last + 1;

    // Pad jadi 4 digit: 1 -> 0001, 12 -> 0012, dst.
    $seq = str_pad((string)$next, 4, '0', STR_PAD_LEFT);

    return $prefix . $seq;
}

function badge_status($status)
{
    switch ($status) {
        case 'menunggu_konfirmasi':
            return 'warning';  // kuning
        case 'diproses':
            return 'primary';  // biru
        case 'selesai':
            return 'success';  // hijau
        default:
            return 'secondary'; // abu-abu
    }
}

function format_status($status)
{
    // contoh: "menunggu_konfirmasi" → ["menunggu", "konfirmasi"]
    $parts = explode('_', $status);

    // ubah setiap kata menjadi Huruf Besar di depan
    $parts = array_map('ucfirst', $parts);

    // gabungkan lagi dengan spasi
    return implode(' ', $parts);
}

function addNewLine($string)
{
    $parts = explode(' ', $string);
    $parts = array_map('ucfirst', $parts);
    return implode('<br>', $parts);
}

function generate_product_code(PDO $pdo): string
{
    // Hitung berapa produk yang sudah ada
    $stmt = $pdo->query("SELECT COUNT(*) AS c FROM products");
    $row = $stmt->fetch();
    $num = (int) $row['c'] + 1;

    // Format: RL-P-0001, RL-P-0002, dst
    return sprintf('RL-P-%04d', $num);
}
