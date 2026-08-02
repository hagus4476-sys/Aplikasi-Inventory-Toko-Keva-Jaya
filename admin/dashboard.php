<?php
session_start();
require_once '../includes/auth.php';
cekLogin();
cekRole('admin');
require_once '../includes/functions.php';

global $conn;

// ========== FUNGSI FORMAT ANGKA ==========
function formatNumber($value) {
    $value = (float)$value;
    return (floor($value) == $value) ? (string)$value : number_format($value, 1);
}

// ========== FUNGSI BANTU: AMBIL STOK UTAMA ==========
function getStokUtama($barang) {
    // $barang adalah array hasil fetch assoc dengan key: stok, stok_besar, satuan_besar
    if (!empty($barang['satuan_besar'])) {
        return (float)($barang['stok_besar'] ?? 0);
    }
    return (float)($barang['stok'] ?? 0);
}

// ========== STATISTIK STOK (SELARAS DENGAN stok.php) ==========
function getTotalBarangDirect() {
    global $conn;
    $res = $conn->query("SELECT COUNT(*) as total FROM barang");
    return (int)$res->fetch_assoc()['total'];
}

function getBarangMenipisDirect() {
    global $conn;
    // Menipis: stok utama > 0 dan < min_stok
    $sql = "SELECT id_barang, stok, stok_besar, satuan_besar, min_stok FROM barang";
    $res = $conn->query($sql);
    $count = 0;
    while ($row = $res->fetch_assoc()) {
        $stok_utama = getStokUtama($row);
        $min = (float)$row['min_stok'];
        if ($stok_utama > 0 && $stok_utama < $min) {
            $count++;
        }
    }
    return $count;
}

function getBarangHabisDirect() {
    global $conn;
    $sql = "SELECT id_barang, stok, stok_besar, satuan_besar FROM barang";
    $res = $conn->query($sql);
    $count = 0;
    while ($row = $res->fetch_assoc()) {
        $stok_utama = getStokUtama($row);
        if ($stok_utama <= 0) {
            $count++;
        }
    }
    return $count;
}

function getBarangMasukHariIniDirect() {
    global $conn;
    $tgl = date('Y-m-d');
    $res = $conn->query("SELECT COALESCE(SUM(i.qty),0) as total FROM transaksi_masuk_item i JOIN transaksi_masuk m ON i.transaksi_masuk_id = m.id WHERE DATE(m.tanggal) = '$tgl'");
    return (float)$res->fetch_assoc()['total'];
}

function getBarangKeluarHariIniDirect() {
    global $conn;
    $tgl = date('Y-m-d');
    $res = $conn->query("SELECT COALESCE(SUM(qty),0) as total FROM barang_keluar WHERE DATE(tanggal) = '$tgl'");
    return (float)$res->fetch_assoc()['total'];
}

// ========== KADALUARSA (sesuai dengan stok utama) ==========
function getKadaluarsaStats() {
    global $conn;
    $result = [
        'kadaluarsa' => 0,
        'segera' => 0,
        'aman' => 0,
        'belum_diatur' => 0
    ];
    $res = $conn->query("
        SELECT 
            tanggal_kadaluarsa,
            stok,
            stok_besar,
            satuan_besar
        FROM barang
        WHERE stok > 0 OR stok_besar > 0
    ");
    while ($row = $res->fetch_assoc()) {
        // Ambil stok utama (jika ada satuan_besar, pakai stok_besar)
        $stok_utama = getStokUtama($row);
        if ($stok_utama <= 0) continue; // tidak mungkin karena filter, tapi amankan

        $sisa = null;
        if (!empty($row['tanggal_kadaluarsa'])) {
            $sisa = (int)((strtotime($row['tanggal_kadaluarsa']) - time()) / 86400);
        }
        if ($sisa === null) {
            $result['belum_diatur']++;
        } elseif ($sisa < 0) {
            $result['kadaluarsa']++;
        } elseif ($sisa <= 30) {
            $result['segera']++;
        } else {
            $result['aman']++;
        }
    }
    return $result;
}

// ========== AMBIL DATA STATISTIK ==========
$totalBarang   = getTotalBarangDirect();
$barangMenipis = getBarangMenipisDirect();
$barangHabis   = getBarangHabisDirect();
$barangMasuk   = getBarangMasukHariIniDirect();
$barangKeluar  = getBarangKeluarHariIniDirect();
$kadaluarsaStats = getKadaluarsaStats();

// ========== PERMINTAAN BARANG DARI POS (PENDING) ==========
$permintaanPending = 0;
$tableCheck = $conn->query("SHOW TABLES LIKE 'permintaan_barang'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $resPermintaan = $conn->query("SELECT COUNT(*) as total FROM permintaan_barang WHERE status = 'pending'");
    if ($resPermintaan) {
        $permintaanPending = $resPermintaan->fetch_assoc()['total'];
    }
}

// ========== DATA GRAFIK BAR (MASUK & KELUAR 7 HARI TERAKHIR) ==========
$labels = [];
$masuk  = [];
$keluar = [];

for ($i = 6; $i >= 0; $i--) {
    $tgl = date('Y-m-d', strtotime("-$i days"));
    $hari = date('D', strtotime($tgl));
    $hariIndo = '';
    switch ($hari) {
        case 'Mon': $hariIndo = 'Sen'; break;
        case 'Tue': $hariIndo = 'Sel'; break;
        case 'Wed': $hariIndo = 'Rab'; break;
        case 'Thu': $hariIndo = 'Kam'; break;
        case 'Fri': $hariIndo = 'Jum'; break;
        case 'Sat': $hariIndo = 'Sab'; break;
        case 'Sun': $hariIndo = 'Min'; break;
        default: $hariIndo = $hari;
    }
    $tgl_format = date('d/m', strtotime($tgl));
    $labels[] = $hariIndo . ' ' . $tgl_format;
    
    // Barang Masuk
    $qMasuk = $conn->query("SELECT COALESCE(SUM(i.qty),0) as total FROM transaksi_masuk_item i JOIN transaksi_masuk m ON i.transaksi_masuk_id = m.id WHERE DATE(m.tanggal) = '$tgl'");
    $masuk[] = (float)$qMasuk->fetch_assoc()['total'];
    
    // Barang Keluar dari tabel barang_keluar
    $qKeluar = $conn->query("SELECT COALESCE(SUM(qty),0) as total FROM barang_keluar WHERE DATE(tanggal) = '$tgl'");
    $keluar[] = (float)$qKeluar->fetch_assoc()['total'];
}

// ========== DATA PIE CHART (DISTRIBUSI KATEGORI) ==========
$kategori = [];
$resKat = $conn->query("
    SELECT k.nama, COUNT(b.id_barang) as jumlah
    FROM kategori k
    INNER JOIN barang b ON b.kategori = k.nama
    GROUP BY k.nama
    ORDER BY jumlah DESC
");
while ($row = $resKat->fetch_assoc()) {
    $kategori[$row['nama']] = (int)$row['jumlah'];
}
$kategoriLabel = array_keys($kategori);
$kategoriData  = array_values($kategori);
if (empty($kategoriLabel)) {
    $kategoriLabel = ['Belum ada data'];
    $kategoriData = [1];
}

// ========== SIDEBAR ACTIVE STATE ==========
$current_file = basename($_SERVER['PHP_SELF']);
$open_master   = in_array($current_file, ['barang.php','master_kategori.php','master_supplier.php']);
$open_transaksi = in_array($current_file, ['preorder.php','barang_masuk.php','barang_keluar.php','barang_rusak.php','koreksi_stok.php','olah_stok.php']);
$open_monitor  = in_array($current_file, ['stok.php','stock_mutasi.php','kadaluarsa.php']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Keva Jaya</title>
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

        /* SIDEBAR */
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

        /* MAIN */
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
        .page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
        .page-title { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; }
        .page-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 2px; }

        /* ALERT */
        .alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: var(--radius); font-size: 13.5px; font-weight: 450; margin-bottom: 20px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
        .alert-info { background: var(--info-light); color: var(--info); border: 1px solid #b8cfea; }
        .alert-warning { background: var(--warning-light); color: var(--warning); border: 1px solid #f5d5a0; }
        .alert-danger { background: var(--danger-light); color: var(--danger); border: 1px solid #f5b7b1; }
        .alert i { font-size: 16px; flex-shrink: 0; }
        .alert-close { margin-left: auto; cursor: pointer; opacity: 0.6; }
        .alert-close:hover { opacity: 1; }

        /* STAT CARDS - Responsif 6 kolom */
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; box-shadow: var(--shadow-sm); display: flex; justify-content: space-between; align-items: center; transition: var(--transition); }
        .stat-card:hover { box-shadow: var(--shadow); transform: translateY(-1px); }
        .stat-label { font-size: 11px; font-weight: 600; letter-spacing: 0.6px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 6px; }
        .stat-value { font-size: 26px; font-weight: 700; color: var(--text-primary); letter-spacing: -0.5px; line-height: 1; }
        .stat-sub { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        .stat-icon { font-size: 28px; opacity: 0.18; }
        .stat-icon.blue { color: #1a5276; }
        .stat-icon.orange { color: #d68910; }
        .stat-icon.green { color: #2d6a4f; }
        .stat-icon.red { color: #c0392b; }
        .stat-icon.purple { color: #6c3483; }

        /* CHART CARDS */
        .chart-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
        .card-head { padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); background: var(--surface-2); }
        .card-head-title { font-size: 13.5px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .card-head-title i { color: var(--accent); }
        .card-head-sub { font-size: 12px; color: var(--text-muted); }
        .card-body { padding: 20px; }

        @media (max-width: 1024px) { .chart-grid { grid-template-columns: 1fr; } }
        @media (max-width: 768px) { .sidebar { left: calc(-1 * var(--sidebar-w)); } .sidebar.mobile-open { left: 0; } .main { margin-left: 0; } }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <div class="brand-icon"><i class="bi bi-box-seam"></i></div>
            <div><div class="brand-name">Keva Jaya</div><div class="brand-sub">Inventory System</div></div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <ul style="list-style:none;padding:0;">
            <li class="nav-item"><a href="dashboard.php" class="nav-link <?= $current_file == 'dashboard.php' ? 'active' : '' ?>"><i class="bi bi-grid-1x2"></i> Dashboard</a></li>

            <div class="nav-section-label">Master Data</div>
            <li class="nav-item">
                <button class="nav-link" onclick="toggleNav('navMaster',this)" aria-expanded="<?= $open_master ? 'true' : 'false' ?>">
                    <i class="bi bi-database"></i> Master Data <i class="bi bi-chevron-down chevron"></i>
                </button>
                <ul id="navMaster" class="nav-sub" style="list-style:none;padding:0;display: <?= $open_master ? 'block' : 'none' ?>;">
                    <li><a href="barang.php" class="nav-link <?= $current_file == 'barang.php' ? 'active' : '' ?>"><i class="bi bi-box"></i> Barang</a></li>
                    <li><a href="master_kategori.php" class="nav-link <?= $current_file == 'master_kategori.php' ? 'active' : '' ?>"><i class="bi bi-tags"></i> Kategori</a></li>
                    <li><a href="master_supplier.php" class="nav-link <?= $current_file == 'master_supplier.php' ? 'active' : '' ?>"><i class="bi bi-building"></i> Supplier</a></li>
                </ul>
            </li>

            <div class="nav-section-label">Transaksi</div>
            <li class="nav-item">
                <button class="nav-link" onclick="toggleNav('navTransaksi',this)" aria-expanded="<?= $open_transaksi ? 'true' : 'false' ?>">
                    <i class="bi bi-arrow-left-right"></i> Transaksi <i class="bi bi-chevron-down chevron"></i>
                </button>
                <ul id="navTransaksi" class="nav-sub" style="list-style:none;padding:0;display: <?= $open_transaksi ? 'block' : 'none' ?>;">
                    <li><a href="preorder.php" class="nav-link <?= $current_file == 'preorder.php' ? 'active' : '' ?>"><i class="bi bi-cart3"></i> Pre Order</a></li>
                    <li><a href="barang_masuk.php" class="nav-link <?= $current_file == 'barang_masuk.php' ? 'active' : '' ?>"><i class="bi bi-arrow-down-circle"></i> Barang Masuk</a></li>
                    <li><a href="barang_keluar.php" class="nav-link <?= $current_file == 'barang_keluar.php' ? 'active' : '' ?>"><i class="bi bi-arrow-up-circle"></i> Barang Keluar</a></li>
                    <li><a href="barang_rusak.php" class="nav-link <?= $current_file == 'barang_rusak.php' ? 'active' : '' ?>"><i class="bi bi-slash-circle"></i> Barang Rusak</a></li>
                    <li><a href="koreksi_stok.php" class="nav-link <?= $current_file == 'koreksi_stok.php' ? 'active' : '' ?>"><i class="bi bi-pencil-square"></i> Koreksi Stok</a></li>
                    <li><a href="olah_stok.php" class="nav-link <?= $current_file == 'olah_stok.php' ? 'active' : '' ?>"><i class="bi bi-recycle"></i> Olah Stok</a></li>
                </ul>
            </li>

            <div class="nav-section-label">Monitoring</div>
            <li class="nav-item">
                <button class="nav-link" onclick="toggleNav('navMonitor',this)" aria-expanded="<?= $open_monitor ? 'true' : 'false' ?>">
                    <i class="bi bi-graph-up-arrow"></i> Monitoring <i class="bi bi-chevron-down chevron"></i>
                </button>
                <ul id="navMonitor" class="nav-sub" style="list-style:none;padding:0;display: <?= $open_monitor ? 'block' : 'none' ?>;">
                    <li><a href="stok.php" class="nav-link <?= $current_file == 'stok.php' ? 'active' : '' ?>"><i class="bi bi-boxes"></i> Stok Barang</a></li>
                    <li><a href="stock_mutasi.php" class="nav-link <?= $current_file == 'stock_mutasi.php' ? 'active' : '' ?>"><i class="bi bi-clock-history"></i> Stock Opname</a></li>
                    <li><a href="kadaluarsa.php" class="nav-link <?= $current_file == 'kadaluarsa.php' ? 'active' : '' ?>"><i class="bi bi-calendar-x"></i> Kadaluarsa</a></li>
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
            <a href="../logout.php" class="btn-sm" style="margin-left:auto;border:none;" data-tooltip="Logout">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</aside>

<div class="main" id="main">
    <header class="topbar">
        <button class="btn-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="breadcrumb-bar">
            <span>Dashboard</span>
        </div>
        <div class="topbar-right">
            <span style="font-size:12px;color:var(--text-muted);"><?= date('d M Y') ?></span>
        </div>
    </header>

    <div class="page-body">
        <!-- ALERTS -->
        <?php if ($permintaanPending > 0): ?>
        <div class="alert alert-info">
            <i class="bi bi-cart-plus"></i>
            <span>Ada <strong><?= $permintaanPending ?></strong> permintaan barang baru dari POS!
            <a href="barang_keluar.php" style="color:inherit;font-weight:600;">Klik di sini untuk memproses</a>.</span>
            <i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i>
        </div>
        <?php endif; ?>
        
        <?php if ($barangMenipis > 0): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>Terdapat <strong><?= $barangMenipis ?> barang</strong> dengan stok di bawah batas minimum (stok > 0). Segera lakukan pengadaan.</span>
            <i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i>
        </div>
        <?php endif; ?>

        <?php if ($barangHabis > 0): ?>
        <div class="alert alert-danger">
            <i class="bi bi-x-circle-fill"></i>
            <span><strong><?= $barangHabis ?> barang</strong> sudah habis (stok = 0). Segera lakukan restock!</span>
            <i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i>
        </div>
        <?php endif; ?>

        <?php if ($kadaluarsaStats['kadaluarsa'] > 0): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-circle-fill"></i>
            <span><strong><?= $kadaluarsaStats['kadaluarsa'] ?> barang</strong> sudah melewati tanggal kadaluarsa! Segera tindak lanjuti di menu <a href="kadaluarsa.php" style="color:inherit;font-weight:600;">Kadaluarsa</a>.</span>
            <i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i>
        </div>
        <?php elseif ($kadaluarsaStats['segera'] > 0): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span><strong><?= $kadaluarsaStats['segera'] ?> barang</strong> akan kadaluarsa dalam 30 hari ke depan. Cek menu <a href="kadaluarsa.php" style="color:inherit;font-weight:600;">Kadaluarsa</a> untuk detail.</span>
            <i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i>
        </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <div class="page-title">Dashboard</div>
                <div class="page-subtitle">Monitoring stok & aktivitas barang harian</div>
            </div>
        </div>

        <!-- STAT CARDS (6 card: Total, Menipis, Habis, Masuk, Keluar, Kadaluarsa) -->
        <div class="stat-grid">
            <div class="stat-card">
                <div>
                    <div class="stat-label">Total Barang</div>
                    <div class="stat-value"><?= $totalBarang ?></div>
                    <div class="stat-sub">Item terdaftar</div>
                </div>
                <i class="bi bi-box-seam stat-icon blue"></i>
            </div>
            <div class="stat-card">
                <div>
                    <div class="stat-label">Stok Menipis</div>
                    <div class="stat-value"><?= $barangMenipis ?></div>
                    <div class="stat-sub">Di bawah minimum</div>
                </div>
                <i class="bi bi-exclamation-triangle stat-icon orange"></i>
            </div>
            <div class="stat-card">
                <div>
                    <div class="stat-label">Stok Habis</div>
                    <div class="stat-value"><?= $barangHabis ?></div>
                    <div class="stat-sub">Stok = 0</div>
                </div>
                <i class="bi bi-x-circle stat-icon red"></i>
            </div>
            <div class="stat-card">
                <div>
                    <div class="stat-label">Barang Masuk</div>
                    <div class="stat-value"><?= formatNumber($barangMasuk) ?></div>
                    <div class="stat-sub">Item hari ini</div>
                </div>
                <i class="bi bi-arrow-down-circle stat-icon green"></i>
            </div>
            <div class="stat-card">
                <div>
                    <div class="stat-label">Barang Keluar</div>
                    <div class="stat-value"><?= formatNumber($barangKeluar) ?></div>
                    <div class="stat-sub">Item hari ini</div>
                </div>
                <i class="bi bi-arrow-up-circle stat-icon red"></i>
            </div>
            <div class="stat-card">
                <div>
                    <div class="stat-label">Kadaluarsa</div>
                    <div class="stat-value"><?= $kadaluarsaStats['kadaluarsa'] + $kadaluarsaStats['segera'] ?></div>
                    <div class="stat-sub"><?= $kadaluarsaStats['kadaluarsa'] ?> kadaluarsa, <?= $kadaluarsaStats['segera'] ?> segera</div>
                </div>
                <i class="bi bi-calendar-x stat-icon purple"></i>
            </div>
        </div>

        <!-- CHARTS -->
        <div class="chart-grid">
            <div class="card">
                <div class="card-head">
                    <div class="card-head-title"><i class="bi bi-graph-up-arrow"></i> Aktivitas Stok</div>
                    <div class="card-head-sub">7 hari terakhir</div>
                </div>
                <div class="card-body">
                    <canvas id="chartAktivitas" style="max-height:260px;width:100%;"></canvas>
                </div>
            </div>
            <div class="card">
                <div class="card-head">
                    <div class="card-head-title"><i class="bi bi-pie-chart"></i> Distribusi Kategori</div>
                </div>
                <div class="card-body" style="display:flex;align-items:center;justify-content:center;">
                    <canvas id="pieChart" style="max-height:220px;width:100%;"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

    new Chart(document.getElementById('chartAktivitas'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($labels) ?>,
            datasets: [
                { label: 'Barang Masuk', data: <?= json_encode($masuk) ?>, backgroundColor: 'rgba(45,106,79,0.75)', borderRadius: 6, barPercentage: 0.55 },
                { label: 'Barang Keluar', data: <?= json_encode($keluar) ?>, backgroundColor: 'rgba(192,57,43,0.65)', borderRadius: 6, barPercentage: 0.55 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: { 
                legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 10, font: { family: 'Plus Jakarta Sans', size: 12 } } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            let val = ctx.raw;
                            return `${ctx.dataset.label}: ${(Number.isInteger(val) ? val : val.toFixed(1))} barang`;
                        }
                    }
                }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: '#eceae4' }, ticks: { font: { family: 'Plus Jakarta Sans' } } },
                x: { grid: { display: false }, ticks: { font: { family: 'Plus Jakarta Sans' }, autoSkip: false, maxRotation: 0 } }
            }
        }
    });

    const pieLabels = <?= json_encode($kategoriLabel) ?>;
    const pieData = <?= json_encode($kategoriData) ?>;
    if (pieLabels.length > 0 && pieLabels[0] !== 'Belum ada data') {
        new Chart(document.getElementById('pieChart'), {
            type: 'pie',
            data: {
                labels: pieLabels,
                datasets: [{ data: pieData, backgroundColor: ['#2d6a4f','#d68910','#1a5276','#c0392b','#6c3483','#0e6655','#784212','#b87333','#4682b4','#9acd32'] }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { font: { family: 'Plus Jakarta Sans', size: 11 } } } } }
        });
    } else {
        document.getElementById('pieChart').parentNode.innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:20px 0;">Belum ada data kategori</div>';
    }
</script>
</body>
</html>