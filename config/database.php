<?php

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'keva_db';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    error_log("Koneksi DB keva_db gagal: " . $conn->connect_error);
    die("Koneksi database gagal. Silakan hubungi administrator.");
}
$conn->set_charset('utf8mb4');


$host_pos = 'localhost'; 
$user_pos = 'root';      
$pass_pos = '';
$db_pos   = 'db_pos_keva';

$conn_pos = new mysqli($host_pos, $user_pos, $pass_pos, $db_pos);
if ($conn_pos->connect_error) {

    error_log("Koneksi DB POS gagal: " . $conn_pos->connect_error);
    $conn_pos = null;
} else {
    $conn_pos->set_charset('utf8mb4');
}


if (!function_exists('posTersedia')) {
    function posTersedia(): bool {
        global $conn_pos;
        return $conn_pos instanceof mysqli;
    }
}