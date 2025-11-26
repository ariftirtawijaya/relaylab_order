<?php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

$user = current_user();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>RelayLab Order Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- DataTables + Responsive Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">

    <!-- jQuery (dibutuhkan DataTables jQuery) -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

    <!-- Select2 (untuk dropdown produk dengan search) -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css"
        rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>


</head>

<body class="bg-light">

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?= esc(BASE_URL) ?>">RelayLab Order Management System</a>

            <?php if ($user): ?>
                <!-- Tombol menu (hamburger) untuk tampilan mobile -->
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar"
                    aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <!-- Menu yang bisa collapse -->
                <div class="collapse navbar-collapse" id="mainNavbar">
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                        <?php if ($user['role'] === 'admin'): ?>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/dashboard.php') ?>">Dashboard</a>
                            </li>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/products.php') ?>">Produk</a>
                            </li>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/resellers.php') ?>">Reseller</a>
                            </li>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/orders.php') ?>">Order</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/order_new.php') ?>">Buat
                                    Order</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('admin/users.php') ?>">User Login</a>
                            </li>
                        <?php else: ?>
                            <li class="nav-item"><a class="nav-link"
                                    href="<?= base_url('reseller/dashboard.php') ?>">Dashboard</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('reseller/orders.php') ?>">Order
                                    Saya</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('reseller/order_new.php') ?>">Buat
                                    Order</a></li>
                            <li class="nav-item"><a class="nav-link" href="<?= base_url('reseller/profile.php') ?>">Profil</a>
                            </li>
                        <?php endif; ?>

                    </ul>

                    <span class="navbar-text me-3">
                        <?= esc($user['name']) ?> (<?= esc($user['role']) ?>)
                    </span>
                    <a href="<?= base_url('logout.php') ?>" class="btn btn-outline-light btn-sm">Logout</a>
                </div>
            <?php endif; ?>
        </div>
    </nav>


    <div class="container mb-5">