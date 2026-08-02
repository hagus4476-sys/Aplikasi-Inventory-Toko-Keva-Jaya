<?php
session_start();
require_once '../includes/auth.php';
cekLogin();
cekRole('admin');
require_once '../includes/functions.php';

global $conn;

// ========== PROSES INPUT & AJUKAN ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajukan_rusak'])) {
    $barang_id = filter_input(INPUT_POST, 'barang_id', FILTER_VALIDATE_INT);
    $jumlah = filter_input(INPUT_POST, 'jumlah', FILTER_VALIDATE_INT);
    $aksi = $_POST['aksi'] ?? '';
    $keterangan = trim($_POST['keterangan'] ?? '');

    if (!$barang_id || !$jumlah || !in_array($aksi, ['Dibuang', 'Retur Supplier'])) {
        $_SESSION['error'] = "Data tidak lengkap.";
        header("Location: barang_rusak.php");
        exit;
    }

    // Ambil data barang (termasuk satuan_besar)
    $stmt = $conn->prepare("SELECT stok, stok_besar, satuan_besar, satuan FROM barang WHERE id_barang = ?");
    $stmt->bind_param("i", $barang_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $barang = $res->fetch_assoc();
    $stmt->close();

    if (!$barang) {
        $_SESSION['error'] = "Barang tidak ditemukan.";
        header("Location: barang_rusak.php");
        exit;
    }

    $is_olahan = !empty($barang['satuan_besar']);
    $stok_sebelum = 0;
    $stok_sesudah = 0;

    if ($is_olahan) {
        $stok_sebelum = (int)$barang['stok_besar'];
        if ($stok_sebelum < $jumlah) {
            $_SESSION['error'] = "Jumlah rusak melebihi stok karung (tersedia {$stok_sebelum} karung).";
            header("Location: barang_rusak.php");
            exit;
        }
        $stok_sesudah = $stok_sebelum - $jumlah;
        $update = $conn->prepare("UPDATE barang SET stok_besar = ?, stok_rusak = stok_rusak + ? WHERE id_barang = ?");
        $update->bind_param("iii", $stok_sesudah, $jumlah, $barang_id);
    } else {
        $stok_sebelum = (int)$barang['stok'];
        if ($stok_sebelum < $jumlah) {
            $_SESSION['error'] = "Jumlah rusak melebihi stok tersedia (tersedia {$stok_sebelum} {$barang['satuan']}).";
            header("Location: barang_rusak.php");
            exit;
        }
        $stok_sesudah = $stok_sebelum - $jumlah;
        $update = $conn->prepare("UPDATE barang SET stok = ?, stok_rusak = stok_rusak + ? WHERE id_barang = ?");
        $update->bind_param("iii", $stok_sesudah, $jumlah, $barang_id);
    }

    if (!$update->execute()) {
        $_SESSION['error'] = "Gagal mengupdate stok.";
        header("Location: barang_rusak.php");
        exit;
    }
    $update->close();

    // Catat mutasi stok (jika fungsi tersedia)
    if (function_exists('catatMutasiStok')) {
        catatMutasiStok($barang_id, 'RUSAK', $jumlah, $stok_sebelum, $stok_sesudah, $keterangan);
    }

    // Simpan pengajuan ke tabel pengajuan_rusak
    $insert = $conn->prepare("INSERT INTO pengajuan_rusak (barang_id, jumlah, aksi, keterangan, status) VALUES (?, ?, ?, ?, 'Pending')");
    $insert->bind_param("iiss", $barang_id, $jumlah, $aksi, $keterangan);
    if ($insert->execute()) {
        $_SESSION['success'] = "Barang rusak dicatat dan pengajuan penanganan berhasil dikirim ke Owner.";
    } else {
        $_SESSION['error'] = "Gagal menyimpan pengajuan.";
    }
    $insert->close();
    header("Location: barang_rusak.php");
    exit;
}

// ========== AMBIL DATA ==========
// Tampilkan stok yang sesuai: jika ada satuan_besar, tampilkan stok_besar, else stok
$daftar_barang = $conn->query("
    SELECT id_barang, nama_barang, stok, stok_besar, satuan, satuan_besar,
           IF(satuan_besar IS NOT NULL AND satuan_besar != '', stok_besar, stok) AS stok_tampil
    FROM barang
    WHERE status='aktif'
    ORDER BY nama_barang
");

$pengajuan = $conn->query("
    SELECT p.*, b.nama_barang, b.satuan, b.satuan_besar
    FROM pengajuan_rusak p
    JOIN barang b ON p.barang_id = b.id_barang
    ORDER BY p.created_at DESC
");

// Statistik
$total_rusak   = (int)($conn->query("SELECT SUM(stok_rusak) as total FROM barang")->fetch_assoc()['total'] ?? 0);
$total_pending = (int)($conn->query("SELECT COUNT(*) as c FROM pengajuan_rusak WHERE status='Pending'")->fetch_assoc()['c'] ?? 0);
$total_disetujui = (int)($conn->query("SELECT COUNT(*) as c FROM pengajuan_rusak WHERE status='Disetujui'")->fetch_assoc()['c'] ?? 0);
$total_pengajuan = (int)($conn->query("SELECT COUNT(*) as c FROM pengajuan_rusak")->fetch_assoc()['c'] ?? 0);

$pengajuan_rows = [];
while ($p = $pengajuan->fetch_assoc()) {
    $pengajuan_rows[] = $p;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Barang Rusak — Keva Jaya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ========== KEVA JAYA DESIGN SYSTEM ========== */
        :root {
            --bg: #f5f4f0; --surface: #ffffff; --surface-2: #f9f8f5;
            --border: #e8e6e0; --border-strong: #d4d0c8;
            --text-primary: #1a1916; --text-secondary: #6b6860; --text-muted: #9c9890;
            --accent: #2d6a4f; --accent-light: #e8f4ee; --accent-hover: #245a42;
            --danger: #c0392b; --danger-light: #fdecea;
            --warning: #d68910; --warning-light: #fef9e7;
            --info: #1a5276; --info-light: #e8f0f8;
            --sidebar-w: 252px; --radius: 10px; --radius-sm: 6px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06); --shadow: 0 4px 16px rgba(0,0,0,0.06);
            --transition: all 0.2s cubic-bezier(0.4,0,0.2,1);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); color: var(--text-primary); min-height: 100vh; font-size: 14px; line-height: 1.6; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 99px; }

        .sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar-w); height: 100vh; background: var(--text-primary); display: flex; flex-direction: column; z-index: 100; transition: var(--transition); overflow-y: auto; overflow-x: hidden; }
        .sidebar.collapsed { left: calc(-1 * var(--sidebar-w)); }
        .sidebar-brand { padding: 20px 20px 16px; border-bottom: 1px solid rgba(255,255,255,0.08); flex-shrink: 0; }
        .sidebar-brand .brand-logo { display: flex; align-items: center; gap: 10px; }
        .brand-icon { width: 34px; height: 34px; background: var(--accent); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 16px; color: white; flex-shrink: 0; }
        .brand-name { font-size: 15px; font-weight: 700; color: white; letter-spacing: -0.3px; }
        .brand-sub { font-size: 11px; color: rgba(255,255,255,0.38); font-weight: 400; letter-spacing: 0.4px; }
        .sidebar-nav { padding: 12px 10px; flex: 1; }
        .nav-section-label { font-size: 10px; font-weight: 600; letter-spacing: 1.2px; text-transform: uppercase; color: rgba(255,255,255,0.28); padding: 12px 10px 6px; }
        .nav-item { list-style: none; }
        .nav-link { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: var(--radius-sm); color: rgba(255,255,255,0.55); text-decoration: none; font-size: 13.5px; font-weight: 450; transition: var(--transition); cursor: pointer; border: none; background: none; width: 100%; }
        .nav-link:hover { background: rgba(255,255,255,0.07); color: rgba(255,255,255,0.9); }
        .nav-link.active { background: rgba(255,255,255,0.1); color: white; font-weight: 550; }
        .nav-link i { font-size: 16px; flex-shrink: 0; width: 18px; text-align: center; }
        .nav-link .chevron { margin-left: auto; font-size: 12px; transition: transform 0.2s; }
        .nav-link[aria-expanded="true"] .chevron { transform: rotate(180deg); }
        .nav-sub { padding-left: 28px; }
        .nav-sub .nav-link { font-size: 13px; padding: 6px 10px; color: rgba(255,255,255,0.45); }
        .nav-sub .nav-link:hover { color: rgba(255,255,255,0.85); }
        .nav-sub .nav-link.active { color: white; background: rgba(255,255,255,0.08); }
        .sidebar-footer { padding: 12px 14px; border-top: 1px solid rgba(255,255,255,0.08); flex-shrink: 0; }
        .user-card { display: flex; align-items: center; gap: 10px; }
        .user-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: white; flex-shrink: 0; }
        .user-name { font-size: 13px; font-weight: 550; color: rgba(255,255,255,0.8); }
        .user-role { font-size: 11px; color: rgba(255,255,255,0.35); }
        .btn-sm { display: inline-flex; align-items: center; gap: 5px; padding: 5px 11px; border-radius: var(--radius-sm); font-size: 12px; font-weight: 500; font-family: inherit; cursor: pointer; transition: var(--transition); border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary); text-decoration: none; }
        .btn-sm:hover { background: var(--surface-2); }

        .main { margin-left: var(--sidebar-w); transition: margin-left 0.2s cubic-bezier(0.4,0,0.2,1); min-height: 100vh; display: flex; flex-direction: column; }
        .main.expanded { margin-left: 0; }
        .topbar { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0 24px; height: 56px; display: flex; align-items: center; gap: 16px; position: sticky; top: 0; z-index: 50; }
        .btn-toggle { width: 34px; height: 34px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--surface-2); color: var(--text-secondary); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: var(--transition); font-size: 16px; flex-shrink: 0; }
        .btn-toggle:hover { background: var(--border); color: var(--text-primary); }
        .breadcrumb-bar { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-muted); }
        .breadcrumb-bar a { color: inherit; text-decoration: none; }
        .breadcrumb-bar span { color: var(--text-secondary); font-weight: 500; }
        .breadcrumb-bar i { font-size: 11px; }
        .topbar-right { margin-left: auto; }
        .page-body { padding: 24px; flex: 1; }
        .page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
        .page-title { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; }
        .page-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 2px; }

        .alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: var(--radius); font-size: 13.5px; font-weight: 450; margin-bottom: 20px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
        .alert-success { background: var(--accent-light); color: var(--accent); border: 1px solid #b7ddc9; }
        .alert-danger  { background: var(--danger-light); color: var(--danger); border: 1px solid #f5b7b1; }
        .alert-warning { background: var(--warning-light); color: var(--warning); border: 1px solid #f5d5a0; }
        .alert i { font-size: 16px; flex-shrink: 0; }
        .alert-close { margin-left: auto; cursor: pointer; opacity: 0.6; }
        .alert-close:hover { opacity: 1; }

        .stat-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 20px; }
        .stat-mini { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 18px; display: flex; align-items: center; gap: 14px; box-shadow: var(--shadow-sm); }
        .stat-mini-icon { width: 38px; height: 38px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .stat-mini-icon.blue   { background: var(--info-light);    color: var(--info); }
        .stat-mini-icon.green  { background: var(--accent-light);  color: var(--accent); }
        .stat-mini-icon.orange { background: var(--warning-light); color: var(--warning); }
        .stat-mini-icon.red    { background: var(--danger-light);  color: var(--danger); }
        .stat-mini-val  { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; line-height: 1; }
        .stat-mini-lbl  { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }

        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 20px; }
        .card-head { padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); background: var(--surface-2); flex-wrap: wrap; }
        .card-head-title { font-size: 13.5px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .card-head-title i { color: var(--accent); }
        .card-body { padding: 20px; }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; align-items: end; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-label { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); }
        .form-control, .form-select {
            width: 100%; padding: 8px 12px; border: 1px solid var(--border);
            border-radius: var(--radius-sm); font-size: 13.5px; font-family: inherit;
            color: var(--text-primary); background: var(--surface);
            transition: var(--transition); appearance: none;
        }
        .form-control:focus, .form-select:focus {
            outline: none; border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(45,106,79,0.1);
        }
        .form-control::placeholder { color: var(--text-muted); }
        .select-wrap { position: relative; }
        .select-wrap::after { content: '\f282'; font-family: 'Bootstrap-Icons'; position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font-size: 11px; color: var(--text-muted); pointer-events: none; }
        .form-hint { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; }

        .btn-primary { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: var(--accent); color: white; border: none; border-radius: var(--radius-sm); font-size: 13px; font-weight: 600; font-family: inherit; cursor: pointer; transition: var(--transition); text-decoration: none; }
        .btn-primary:hover { background: var(--accent-hover); }

        .search-wrap { position: relative; }
        .search-wrap i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 14px; color: var(--text-muted); pointer-events: none; }
        .search-input { padding: 7px 12px 7px 32px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; font-family: inherit; color: var(--text-primary); background: var(--surface); width: 220px; transition: var(--transition); }
        .search-input:focus { outline: none; border-color: var(--accent); width: 260px; box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }
        .search-input::placeholder { color: var(--text-muted); }
        .row-count { font-size: 12px; color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border); padding: 3px 10px; border-radius: 99px; }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead th { padding: 11px 16px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.4px; text-transform: uppercase; color: var(--text-muted); background: var(--surface-2); border-bottom: 1px solid var(--border); white-space: nowrap; }
        tbody td { padding: 13px 16px; border-bottom: 1px solid var(--border); color: var(--text-primary); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr { transition: background 0.15s; }
        tbody tr:hover { background: var(--surface-2); }

        .badge-status { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 550; }
        .badge-pending    { background: var(--warning-light); color: var(--warning); }
        .badge-disetujui  { background: var(--accent-light);  color: var(--accent); }
        .badge-ditolak    { background: var(--danger-light);  color: var(--danger); }
        .badge-aksi { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 500; }
        .badge-dibuang  { background: var(--danger-light); color: var(--danger); }
        .badge-retur    { background: var(--info-light);   color: var(--info); }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 36px; opacity: 0.4; display: block; margin-bottom: 10px; }

        @media (max-width: 900px) { .stat-row { grid-template-columns: repeat(2,1fr); } }
        @media (max-width: 768px) {
            .sidebar { left: calc(-1 * var(--sidebar-w)); }
            .sidebar.mobile-open { left: 0; }
            .main { margin-left: 0; }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <div class="brand-icon"><i class="bi bi-box-seam"></i></div>
            <div><div class="brand-name">Keva Jaya</div><div class="brand-sub">Inventory System</div></div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <ul style="list-style:none;padding:0;">
            <li class="nav-item"><a href="dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF'])=='dashboard.php'?'active':'' ?>"><i class="bi bi-grid-1x2"></i> Dashboard</a></li>

            <div class="nav-section-label">Master Data</div>
            <li class="nav-item">
                <button class="nav-link" onclick="toggleNav('navMaster',this)" aria-expanded="false">
                    <i class="bi bi-database"></i> Master Data <i class="bi bi-chevron-down chevron"></i>
                </button>
                <ul id="navMaster" class="nav-sub" style="list-style:none;padding:0;display:none;">
                    <li><a href="barang.php" class="nav-link"><i class="bi bi-box"></i> Barang</a></li>
                    <li><a href="master_kategori.php" class="nav-link"><i class="bi bi-tags"></i> Kategori</a></li>
                    <li><a href="master_supplier.php" class="nav-link"><i class="bi bi-building"></i> Supplier</a></li>
                </ul>
            </li>

            <div class="nav-section-label">Transaksi</div>
            <li class="nav-item">
                <button class="nav-link active" onclick="toggleNav('navTransaksi',this)" aria-expanded="true">
                    <i class="bi bi-arrow-left-right"></i> Transaksi <i class="bi bi-chevron-down chevron"></i>
                </button>
                <ul id="navTransaksi" class="nav-sub" style="list-style:none;padding:0;">
                    <li><a href="preorder.php" class="nav-link"><i class="bi bi-cart3"></i> Pre Order</a></li>
                    <li><a href="barang_masuk.php" class="nav-link"><i class="bi bi-arrow-down-circle"></i> Barang Masuk</a></li>
                    <li><a href="barang_keluar.php" class="nav-link"><i class="bi bi-arrow-up-circle"></i> Barang Keluar</a></li>
                    <li><a href="barang_rusak.php" class="nav-link active"><i class="bi bi-slash-circle"></i> Barang Rusak</a></li>
                    <li><a href="koreksi_stok.php" class="nav-link"><i class="bi bi-pencil-square"></i> Koreksi Stok</a></li>
                    <li><a href="olah_stok.php" class="nav-link"><i class="bi bi-recycle"></i> Olah Stok</a></li>
                </ul>
            </li>

            <div class="nav-section-label">Monitoring</div>
            <li class="nav-item">
                <button class="nav-link" onclick="toggleNav('navMonitor',this)" aria-expanded="false">
                    <i class="bi bi-graph-up-arrow"></i> Monitoring <i class="bi bi-chevron-down chevron"></i>
                </button>
                <ul id="navMonitor" class="nav-sub" style="list-style:none;padding:0;display:none;">
                    <li><a href="stok.php" class="nav-link"><i class="bi bi-boxes"></i> Stok Barang</a></li>
                    <li><a href="stock_mutasi.php" class="nav-link"><i class="bi bi-clock-history"></i> Stock Opname</a></li>
                      <li><a href="kadaluarsa.php" class="nav-link"><i class="bi bi-calendar-x"></i> Kadaluarsa</a></li>
                </ul>
            </li>
        </ul>
    </nav>
    <div class="sidebar-footer">
        <div class="user-card">
            <div class="user-avatar">A</div>
            <div>
                <div class="user-name"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></div>
                <div class="user-role">Administrator</div>
            </div>
            <a href="../logout.php" class="btn-sm" style="margin-left:auto;border:none;">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</aside>

<div class="main" id="main">
    <header class="topbar">
        <button class="btn-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="breadcrumb-bar">
            <a href="dashboard.php">Dashboard</a>
            <i class="bi bi-chevron-right"></i>
            <span>Barang Rusak</span>
        </div>
        <div class="topbar-right">
            <span style="font-size:12px;color:var(--text-muted);"><?= date('d M Y') ?></span>
        </div>
    </header>

    <div class="page-body">

        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle-fill"></i>
            <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            <i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i>
        </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-circle-fill"></i>
            <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i>
        </div>
        <?php endif; ?>
        <?php if ($total_pending > 0): ?>
        <div class="alert alert-warning">
            <i class="bi bi-clock-fill"></i>
            <strong><?= $total_pending ?> pengajuan</strong> masih menunggu persetujuan Owner.
            <i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i>
        </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <div class="page-title">Barang Rusak</div>
                <div class="page-subtitle">Catat barang rusak dan ajukan penanganan ke Owner</div>
            </div>
        </div>

        <!-- Statistik -->
        <div class="stat-row">
            <div class="stat-mini">
                <div class="stat-mini-icon blue"><i class="bi bi-clipboard-data"></i></div>
                <div>
                    <div class="stat-mini-val"><?= $total_pengajuan ?></div>
                    <div class="stat-mini-lbl">Total Pengajuan</div>
                </div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-icon red"><i class="bi bi-slash-circle"></i></div>
                <div>
                    <div class="stat-mini-val"><?= $total_rusak ?></div>
                    <div class="stat-mini-lbl">Total Unit Rusak</div>
                </div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-icon orange"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="stat-mini-val"><?= $total_pending ?></div>
                    <div class="stat-mini-lbl">Menunggu Persetujuan</div>
                </div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-icon green"><i class="bi bi-check-circle"></i></div>
                <div>
                    <div class="stat-mini-val"><?= $total_disetujui ?></div>
                    <div class="stat-mini-lbl">Disetujui</div>
                </div>
            </div>
        </div>

        <!-- Form Input Barang Rusak -->
        <div class="card">
            <div class="card-head">
                <div class="card-head-title">
                    <i class="bi bi-pencil-square"></i>
                    Form Pencatatan Barang Rusak
                </div>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="ajukan_rusak" value="1">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Pilih Barang</label>
                            <div class="select-wrap">
                                <select name="barang_id" class="form-select" required>
                                    <option value="">— Pilih Barang —</option>
                                    <?php while ($b = $daftar_barang->fetch_assoc()): 
                                        $stok_tampil = $b['stok_tampil'];
                                        $satuan_tampil = !empty($b['satuan_besar']) ? $b['satuan_besar'] : $b['satuan'];
                                    ?>
                                        <option value="<?= $b['id_barang'] ?>">
                                            <?= htmlspecialchars($b['nama_barang']) ?> (Stok: <?= $stok_tampil ?> <?= htmlspecialchars($satuan_tampil) ?>)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <span class="form-hint">Stok yang tersedia ditampilkan sesuai satuan utama</span>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Jumlah Rusak</label>
                            <input type="number" name="jumlah" class="form-control" min="1" placeholder="Masukkan jumlah" required>
                            <span class="form-hint">Tidak boleh melebihi stok tersedia</span>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tindakan Penanganan</label>
                            <div class="select-wrap">
                                <select name="aksi" class="form-select" required>
                                    <option value="">— Pilih Tindakan —</option>
                                    <option value="Dibuang">Dibuang (Kerugian)</option>
                                    <option value="Retur Supplier">Retur Supplier</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Keterangan</label>
                            <input type="text" name="keterangan" class="form-control" placeholder="Opsional">
                        </div>
                        <div class="form-group" style="justify-content:flex-end;">
                            <button type="submit" class="btn-primary">
                                <i class="bi bi-send"></i> Kirim ke Owner
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabel Riwayat Pengajuan -->
        <div class="card">
            <div class="card-head">
                <div class="card-head-title">
                    <i class="bi bi-clock-history"></i>
                    Riwayat Pengajuan
                    <span class="row-count" id="rowCount"></span>
                </div>
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" class="search-input" id="searchInput" placeholder="Cari barang, tindakan, status...">
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Barang</th>
                            <th>Jumlah</th>
                            <th>Tindakan</th>
                            <th>Status</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php if (!empty($pengajuan_rows)): ?>
                            <?php foreach ($pengajuan_rows as $p):
                                $status_lower = strtolower($p['status']);
                                // tentukan satuan tampilan
                                $satuan = !empty($p['satuan_besar']) ? $p['satuan_besar'] : $p['satuan'];
                            ?>
                            <tr data-id="<?= $p['id'] ?>">
                                <td style="color:var(--text-secondary);white-space:nowrap;font-size:13px;">
                                    <?= date('d M Y', strtotime($p['created_at'])) ?>
                                    <div style="font-size:11.5px;color:var(--text-muted);"><?= date('H:i', strtotime($p['created_at'])) ?></div>
                                </td>
                                <td style="font-weight:500;"><?= htmlspecialchars($p['nama_barang']) ?></td>
                                <td>
                                    <span style="font-weight:600;"><?= $p['jumlah'] ?></span>
                                    <small style="color:var(--text-muted);margin-left:3px;"><?= htmlspecialchars($satuan) ?></small>
                                </td>
                                <td>
                                    <?php if ($p['aksi'] === 'Dibuang'): ?>
                                        <span class="badge-aksi badge-dibuang"><i class="bi bi-trash3"></i> Dibuang</span>
                                    <?php else: ?>
                                        <span class="badge-aksi badge-retur"><i class="bi bi-arrow-repeat"></i> Retur Supplier</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-status badge-<?= $status_lower ?>">
                                        <?php if ($status_lower === 'pending'): ?>
                                            <i class="bi bi-hourglass-split"></i>
                                        <?php elseif ($status_lower === 'disetujui'): ?>
                                            <i class="bi bi-check-circle"></i>
                                        <?php else: ?>
                                            <i class="bi bi-x-circle"></i>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($p['status']) ?>
                                    </span>
                                </td>
                                <td style="color:var(--text-secondary);"><?= htmlspecialchars($p['keterangan'] ?? '—') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6">
                                <div class="empty-state">
                                    <i class="bi bi-inbox"></i>
                                    <p>Belum ada riwayat pengajuan.</p>
                                </div>
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
const sidebar = document.getElementById('sidebar');
const main    = document.getElementById('main');

document.getElementById('sidebarToggle').addEventListener('click', () => {
    if (window.innerWidth <= 768) sidebar.classList.toggle('mobile-open');
    else { sidebar.classList.toggle('collapsed'); main.classList.toggle('expanded'); }
});

function toggleNav(id, btn) {
    const el   = document.getElementById(id);
    const open = el.style.display !== 'none';
    el.style.display = open ? 'none' : 'block';
    btn.setAttribute('aria-expanded', !open);
}

// Search & row count
const searchInput = document.getElementById('searchInput');
const tableBody   = document.getElementById('tableBody');
const rowCountEl  = document.getElementById('rowCount');

function updateRowCount() {
    const rows    = tableBody.querySelectorAll('tr[data-id]');
    const visible = [...rows].filter(tr => tr.style.display !== 'none').length;
    rowCountEl.textContent = visible === rows.length ? `${rows.length} pengajuan` : `${visible} / ${rows.length} pengajuan`;
}

searchInput.addEventListener('input', function () {
    const q = this.value.toLowerCase().trim();
    tableBody.querySelectorAll('tr[data-id]').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
    updateRowCount();
});

updateRowCount();
document.querySelectorAll('.alert').forEach(a => setTimeout(() => a.remove(), 5000));
</script>
</body>
</html>