<?php
// Cek apakah session sudah dimulai, jika belum mulai session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function cekLogin() {
    if (!isset($_SESSION['user'])) {
        header('Location: ../login.php');
        exit;
    }
}

function cekRole($role) {
    if ($_SESSION['user']['role'] != $role) {
        if ($_SESSION['user']['role'] == 'admin') {
            header('Location: ../admin/dashboard.php');
        } else {
            header('Location: ../owner/dashboard.php');
        }
        exit;
    }
}

function user() {
    return $_SESSION['user'] ?? null;
}
?>