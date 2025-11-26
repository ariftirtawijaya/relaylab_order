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

// Generate kode order sederhana: RL-2025-00001
function generate_order_code(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->query("SELECT COUNT(*) AS c FROM orders WHERE YEAR(order_date) = {$year}");
    $row = $stmt->fetch();
    $num = (int) $row['c'] + 1;
    return sprintf('RL-%s-%05d', $year, $num);
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
