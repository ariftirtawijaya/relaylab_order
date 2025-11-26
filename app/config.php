<?php
// app/config.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// SESUAIKAN dengan URL kamu
// contoh lokal:  http://localhost/relaylab_order
// contoh hosting: https://namadomain.com/relaylab_order
define('BASE_URL', 'http://localhost/oms');

define('DB_HOST', 'localhost');
define('DB_NAME', 'relaylab_oms');
define('DB_USER', 'root');
define('DB_PASS', ''); // sesuaikan
