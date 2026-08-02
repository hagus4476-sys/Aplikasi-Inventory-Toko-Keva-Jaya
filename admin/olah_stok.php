<?php
session_start();
require_once '../includes/auth.php';
cekLogin();
cekRole('admin');
require_once '../includes/functions.php';
date_default_timezone_set('Asia/Jakarta');

global $conn;

// ================= PROSES BUKA KARUNG =================
// Ambil sejumlah karung utuh dari stok_besar, timbang hasil aktualnya.
// Bagian yang "langsung_kg" masuk ke stok siap jual, sisanya disimpan ke sisa_karung_kg.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['buka_karung'])) {
    $id = (int)$_POST['id_barang'];
    $jumlah_karung = (int)$_POST['jumlah_karung'];
    $total_berat = (float)str_replace(',', '.', $_POST['total_berat']);
    $langsung_kg = (float)str_replace(',', '.', $_POST['langsung_kg']);

    $barang = $conn->query("SELECT * FROM barang WHERE id_barang = $id")->fetch_assoc();

    if (!$barang || empty($barang['satuan_besar'])) {
        $_SESSION['error'] = "Barang tidak valid atau tidak memiliki satuan besar.";
        header("Location: olah_stok.php");
        exit;
    }
    if ($jumlah_karung < 1) {
        $_SESSION['error'] = "Jumlah karung yang dibuka minimal 1.";
        header("Location: olah_stok.php");
        exit;
    }
    if ($jumlah_karung > (float)$barang['stok_besar']) {
        $_SESSION['error'] = "Stok karung tidak mencukupi. Sisa stok: {$barang['stok_besar']} {$barang['satuan_besar']}.";
        header("Location: olah_stok.php");
        exit;
    }
    if ($total_berat <= 0) {
        $_SESSION['error'] = "Total berat hasil timbangan harus lebih dari 0.";
        header("Location: olah_stok.php");
        exit;
    }
    if ($langsung_kg < 0 || $langsung_kg > $total_berat) {
        $_SESSION['error'] = "Jumlah yang langsung masuk stok siap jual tidak boleh melebihi total berat timbangan.";
        header("Location: olah_stok.php");
        exit;
    }

    $sisa = $total_berat - $langsung_kg;

    $stmt = $conn->prepare("UPDATE barang SET stok_besar = stok_besar - ?, stok = stok + ?, sisa_karung_kg = sisa_karung_kg + ? WHERE id_barang = ?");
    $stmt->bind_param("dddi", $jumlah_karung, $langsung_kg, $sisa, $id);
    $stmt->execute();

    $ket = "Buka {$jumlah_karung} {$barang['satuan_besar']} {$barang['nama_barang']}, hasil timbang {$total_berat}{$barang['satuan']}, langsung masuk stok {$langsung_kg}{$barang['satuan']}, sisa di karung {$sisa}{$barang['satuan']}";
    catatLog($_SESSION['username'], 'Olah Stok', 'barang', $id, $ket);

    $_SESSION['success'] = "Berhasil membuka {$jumlah_karung} {$barang['satuan_besar']} {$barang['nama_barang']}.";
    header("Location: olah_stok.php");
    exit;
}

// ================= PROSES PINDAHKAN SISA KE STOK SIAP JUAL =================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['pindah_sisa'])) {
    $id = (int)$_POST['id_barang'];
    $jumlah_pindah = (float)str_replace(',', '.', $_POST['jumlah_pindah']);

    $barang = $conn->query("SELECT * FROM barang WHERE id_barang = $id")->fetch_assoc();

    if (!$barang) {
        $_SESSION['error'] = "Barang tidak ditemukan.";
        header("Location: olah_stok.php");
        exit;
    }
    if ($jumlah_pindah <= 0 || $jumlah_pindah > (float)$barang['sisa_karung_kg']) {
        $_SESSION['error'] = "Jumlah yang dipindahkan tidak valid. Sisa tersedia: {$barang['sisa_karung_kg']} {$barang['satuan']}.";
        header("Location: olah_stok.php");
        exit;
    }

    $stmt = $conn->prepare("UPDATE barang SET sisa_karung_kg = sisa_karung_kg - ?, stok = stok + ? WHERE id_barang = ?");
    $stmt->bind_param("ddi", $jumlah_pindah, $jumlah_pindah, $id);
    $stmt->execute();

    $ket = "Pindahkan {$jumlah_pindah}{$barang['satuan']} sisa karung {$barang['nama_barang']} ke stok siap jual";
    catatLog($_SESSION['username'], 'Olah Stok', 'barang', $id, $ket);

    $_SESSION['success'] = "Berhasil memindahkan {$jumlah_pindah} {$barang['satuan']} ke stok siap jual.";
    header("Location: olah_stok.php");
    exit;
}

// ================= QUERY DATA =================
// Hanya tampilkan barang yang punya satuan besar (barang yang memang diolah/dipecah)
$barang_olahan = $conn->query("
    SELECT id_barang, kode_barang, nama_barang, kategori, satuan, satuan_besar, stok, stok_besar, sisa_karung_kg
    FROM barang
    WHERE satuan_besar IS NOT NULL AND satuan_besar != ''
    ORDER BY nama_barang ASC
");

$total_jenis   = 0;
$total_karung  = 0;
$total_sisa    = 0;
$rows_data = [];
while ($r = $barang_olahan->fetch_assoc()) {
    $total_jenis++;
    $total_karung += (float)$r['stok_besar'];
    if ((float)$r['sisa_karung_kg'] > 0) $total_sisa++;
    $rows_data[] = $r;
}

$current_file = basename($_SERVER['PHP_SELF']);
$open_transaksi = true;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Olah Stok — Keva Jaya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
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
        .alert i { font-size: 16px; flex-shrink: 0; }
        .alert-close { margin-left: auto; cursor: pointer; opacity: 0.6; }
        .alert-close:hover { opacity: 1; }
        .stat-row { display: grid; grid-template-columns: repeat(3,1fr); gap: 14px; margin-bottom: 20px; }
        .stat-mini { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 18px; display: flex; align-items: center; gap: 14px; box-shadow: var(--shadow-sm); }
        .stat-mini-icon { width: 38px; height: 38px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .stat-mini-icon.blue   { background: var(--info-light);    color: var(--info); }
        .stat-mini-icon.green  { background: var(--accent-light);  color: var(--accent); }
        .stat-mini-icon.orange { background: var(--warning-light); color: var(--warning); }
        .stat-mini-val  { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; line-height: 1; }
        .stat-mini-lbl  { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
        .card-head { padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); background: var(--surface-2); flex-wrap: wrap; gap: 8px; }
        .card-head-title { font-size: 13.5px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .card-head-title i { color: var(--accent); }
        .search-wrap { position: relative; }
        .search-wrap i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 14px; color: var(--text-muted); pointer-events: none; }
        .search-input { padding: 7px 12px 7px 32px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; font-family: inherit; color: var(--text-primary); background: var(--surface); width: 220px; transition: var(--transition); }
        .search-input:focus { outline: none; border-color: var(--accent); width: 260px; box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }
        .row-count { font-size: 12px; color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border); padding: 3px 10px; border-radius: 99px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead th { padding: 11px 16px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.4px; text-transform: uppercase; color: var(--text-muted); background: var(--surface-2); border-bottom: 1px solid var(--border); white-space: nowrap; }
        tbody td { padding: 13px 16px; border-bottom: 1px solid var(--border); color: var(--text-primary); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: var(--surface-2); }
        .kode-text { font-family: 'JetBrains Mono', monospace; font-size: 12px; font-weight: 500; color: var(--text-secondary); background: var(--surface-2); border: 1px solid var(--border); padding: 2px 8px; border-radius: 4px; }
        .badge-sisa { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 550; background: var(--warning-light); color: var(--warning); }
        .btn-primary { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: var(--accent); color: white; border: none; border-radius: var(--radius-sm); font-size: 12.5px; font-weight: 600; font-family: inherit; cursor: pointer; transition: var(--transition); text-decoration: none; }
        .btn-primary:hover { background: var(--accent-hover); }
        .btn-primary:disabled { background: var(--border-strong); cursor: not-allowed; }
        .btn-secondary { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: var(--surface); color: var(--warning); border: 1px solid #f5d5a0; border-radius: var(--radius-sm); font-size: 12.5px; font-weight: 600; font-family: inherit; cursor: pointer; transition: var(--transition); text-decoration: none; }
        .btn-secondary:hover { background: var(--warning-light); }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 36px; opacity: 0.4; display: block; margin-bottom: 10px; }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); backdrop-filter: blur(4px); z-index: 200; display: none; align-items: center; justify-content: center; padding: 20px; animation: fadeIn 0.15s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .modal-overlay.show { display: flex; }
        .modal-box { background: var(--surface); border-radius: 14px; width: 100%; max-width: 460px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); animation: slideUp 0.2s cubic-bezier(0.34,1.56,0.64,1); max-height: 90vh; display: flex; flex-direction: column; }
        @keyframes slideUp { from { opacity:0; transform:translateY(20px) scale(0.98); } to { opacity:1; transform:translateY(0) scale(1); } }
        .modal-header { padding: 20px 24px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .modal-title { font-size: 16px; font-weight: 700; letter-spacing: -0.3px; }
        .modal-close { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius-sm); border: 1px solid var(--border); background: none; cursor: pointer; color: var(--text-muted); font-size: 16px; transition: var(--transition); }
        .modal-close:hover { background: var(--surface-2); color: var(--text-primary); }
        .modal-body { padding: 20px 24px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; flex-shrink: 0; }
        .form-group { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
        .form-label { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); letter-spacing: 0.2px; }
        .form-control { padding: 9px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13.5px; font-family: inherit; color: var(--text-primary); background: var(--surface); transition: var(--transition); width: 100%; }
        .form-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }
        .form-control:disabled, .form-control[readonly] { background: var(--surface-2); color: var(--text-muted); cursor: not-allowed; }
        .form-hint { font-size: 11.5px; color: var(--text-muted); }
        .info-box { background: var(--info-light); color: var(--info); border-radius: var(--radius-sm); padding: 10px 12px; font-size: 12.5px; margin-bottom: 14px; }
        .btn-cancel { padding: 8px 16px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text-secondary); font-size: 13.5px; font-weight: 500; font-family: inherit; cursor: pointer; transition: var(--transition); }
        .btn-cancel:hover { background: var(--surface-2); }
        @media (max-width: 900px) { .stat-row { grid-template-columns: repeat(1,1fr); } }
        @media (max-width: 768px) { .sidebar { left: calc(-1 * var(--sidebar-w)); } .sidebar.mobile-open { left: 0; } .main { margin-left: 0; } }
        [data-tooltip] { position: relative; }
        [data-tooltip]:hover::after { content: attr(data-tooltip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: var(--text-primary); color: white; padding: 4px 10px; border-radius: 5px; font-size: 11.5px; white-space: nowrap; pointer-events: none; z-index: 99; }
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
            <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="bi bi-grid-1x2"></i> Dashboard</a></li>

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
                    <li><a href="barang_rusak.php" class="nav-link"><i class="bi bi-slash-circle"></i> Barang Rusak</a></li>
                    <li><a href="koreksi_stok.php" class="nav-link"><i class="bi bi-pencil-square"></i> Koreksi Stok</a></li>
                    <li><a href="olah_stok.php" class="nav-link active"><i class="bi bi-recycle"></i> Olah Stok</a></li>
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
            <a href="dashboard.php">Dashboard</a>
            <i class="bi bi-chevron-right"></i>
            <span>Olah Stok</span>
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

        <div class="page-header">
            <div>
                <div class="page-title">Olah Stok</div>
                <div class="page-subtitle">Buka karung/sak dari supplier menjadi stok kiloan siap jual</div>
            </div>
        </div>

        <div class="stat-row">
            <div class="stat-mini">
                <div class="stat-mini-icon blue"><i class="bi bi-box-seam"></i></div>
                <div>
                    <div class="stat-mini-val"><?= $total_jenis ?></div>
                    <div class="stat-mini-lbl">Jenis Barang yang Diolah</div>
                </div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-icon green"><i class="bi bi-boxes"></i></div>
                <div>
                    <div class="stat-mini-val"><?= rtrim(rtrim(number_format($total_karung, 2, ',', '.'), '0'), ',') ?></div>
                    <div class="stat-mini-lbl">Total Karung Utuh Tersisa</div>
                </div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-icon orange"><i class="bi bi-exclamation-triangle"></i></div>
                <div>
                    <div class="stat-mini-val"><?= $total_sisa ?></div>
                    <div class="stat-mini-lbl">Barang dengan Karung Terbuka</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div class="card-head-title">
                    <i class="bi bi-recycle"></i>
                    Daftar Barang Olahan
                    <span class="row-count" id="rowCount"></span>
                </div>
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" class="search-input" id="searchInput" placeholder="Cari kode, nama, kategori...">
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Nama Barang</th>
                            <th>Kategori</th>
                            <th>Stok Karung</th>
                            <th>Sisa Karung Terbuka</th>
                            <th>Stok Siap Jual</th>
                            <th style="text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php if (!empty($rows_data)): ?>
                            <?php foreach ($rows_data as $row):
                                $stok_besar_fmt = rtrim(rtrim(number_format((float)$row['stok_besar'], 2, ',', '.'), '0'), ',');
                                $sisa_fmt = rtrim(rtrim(number_format((float)$row['sisa_karung_kg'], 2, ',', '.'), '0'), ',');
                                $stok_fmt = rtrim(rtrim(number_format((float)$row['stok'], 2, ',', '.'), '0'), ',');
                            ?>
                            <tr data-id="<?= $row['id_barang'] ?>">
                                <td><span class="kode-text"><?= htmlspecialchars($row['kode_barang']) ?></span></td>
                                <td style="font-weight:500;"><?= htmlspecialchars($row['nama_barang']) ?></td>
                                <td><span style="color:var(--text-secondary);"><?= htmlspecialchars($row['kategori'] ?? '—') ?></span></td>
                                <td><?= $stok_besar_fmt ?> <small style="color:var(--text-muted);"><?= htmlspecialchars($row['satuan_besar']) ?></small></td>
                                <td>
                                    <?php if ((float)$row['sisa_karung_kg'] > 0): ?>
                                    <span class="badge-sisa"><?= $sisa_fmt ?> <?= htmlspecialchars($row['satuan']) ?></span>
                                    <?php else: ?>
                                    <span style="color:var(--text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $stok_fmt ?> <small style="color:var(--text-muted);"><?= htmlspecialchars($row['satuan']) ?></small></td>
                                <td>
                                    <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                                        <button class="btn-primary btn-buka"
                                            data-id="<?= $row['id_barang'] ?>"
                                            data-nama="<?= htmlspecialchars($row['nama_barang']) ?>"
                                            data-satuan="<?= htmlspecialchars($row['satuan']) ?>"
                                            data-satuan-besar="<?= htmlspecialchars($row['satuan_besar']) ?>"
                                            data-stok-besar="<?= (float)$row['stok_besar'] ?>"
                                            <?= (float)$row['stok_besar'] < 1 ? 'disabled' : '' ?>>
                                            <i class="bi bi-box-arrow-up"></i> Buka Karung
                                        </button>
                                        <?php if ((float)$row['sisa_karung_kg'] > 0): ?>
                                        <button class="btn-secondary btn-pindah"
                                            data-id="<?= $row['id_barang'] ?>"
                                            data-nama="<?= htmlspecialchars($row['nama_barang']) ?>"
                                            data-satuan="<?= htmlspecialchars($row['satuan']) ?>"
                                            data-sisa="<?= (float)$row['sisa_karung_kg'] ?>">
                                            <i class="bi bi-arrow-right-circle"></i> Pindah Sisa
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7">
                                <div class="empty-state">
                                    <i class="bi bi-inbox"></i>
                                    <p>Belum ada barang dengan satuan besar. Atur "Satuan Beli (Besar)" di halaman Master Barang terlebih dahulu.</p>
                                </div>
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL BUKA KARUNG -->
<div class="modal-overlay" id="bukaModal">
    <div class="modal-box">
        <form method="POST">
            <input type="hidden" name="id_barang" id="buka_id">
            <div class="modal-header">
                <div class="modal-title">Buka Karung</div>
                <button type="button" class="modal-close" onclick="closeModal('bukaModal')"><i class="bi bi-x"></i></button>
            </div>
            <div class="modal-body">
                <div class="info-box" id="buka_info"></div>
                <div class="form-group">
                    <label class="form-label">Jumlah Karung Dibuka</label>
                    <input type="number" name="jumlah_karung" id="buka_jumlah" class="form-control" min="1" step="1" value="1" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Total Berat Hasil Timbangan (<span id="buka_satuan_lbl1"></span>)</label>
                    <input type="text" name="total_berat" id="buka_total_berat" class="form-control" placeholder="" required>
                    <span class="form-hint">Timbang total isi karung yang baru dibuka, sesuai berat aktual</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Langsung Masuk Stok Siap Jual (<span id="buka_satuan_lbl2"></span>)</label>
                    <input type="text" name="langsung_kg" id="buka_langsung" class="form-control" placeholder="" required>
                    <span class="form-hint">Boleh kurang dari total berat — sisanya otomatis tersimpan sebagai "sisa karung terbuka"</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('bukaModal')">Batal</button>
                <button type="submit" name="buka_karung" class="btn-primary"><i class="bi bi-check2"></i> Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL PINDAH SISA -->
<div class="modal-overlay" id="pindahModal">
    <div class="modal-box">
        <form method="POST">
            <input type="hidden" name="id_barang" id="pindah_id">
            <div class="modal-header">
                <div class="modal-title">Pindahkan Sisa ke Stok Siap Jual</div>
                <button type="button" class="modal-close" onclick="closeModal('pindahModal')"><i class="bi bi-x"></i></button>
            </div>
            <div class="modal-body">
                <div class="info-box" id="pindah_info"></div>
                <div class="form-group">
                    <label class="form-label">Jumlah yang Dipindahkan (<span id="pindah_satuan_lbl"></span>)</label>
                    <input type="text" name="jumlah_pindah" id="pindah_jumlah" class="form-control" required>
                    <span class="form-hint">Maksimal sesuai sisa karung terbuka yang tersedia</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('pindahModal')">Batal</button>
                <button type="submit" name="pindah_sisa" class="btn-primary"><i class="bi bi-check2"></i> Pindahkan</button>
            </div>
        </form>
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
function openModal(id) { document.getElementById(id).classList.add('show'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('show'); document.body.style.overflow = ''; }
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(overlay.id); });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.show').forEach(m => closeModal(m.id)); });

document.querySelectorAll('.btn-buka').forEach(btn => {
    btn.addEventListener('click', function () {
        document.getElementById('buka_id').value = this.dataset.id;
        document.getElementById('buka_satuan_lbl1').textContent = this.dataset.satuan;
        document.getElementById('buka_satuan_lbl2').textContent = this.dataset.satuan;
        document.getElementById('buka_info').innerHTML =
            `<strong>${this.dataset.nama}</strong><br>Stok karung tersedia: ${this.dataset.stokBesar} ${this.dataset.satuanBesar}`;
        document.getElementById('buka_jumlah').max = this.dataset.stokBesar;
        document.getElementById('buka_total_berat').value = '';
        document.getElementById('buka_langsung').value = '';
        openModal('bukaModal');
    });
});

document.querySelectorAll('.btn-pindah').forEach(btn => {
    btn.addEventListener('click', function () {
        document.getElementById('pindah_id').value = this.dataset.id;
        document.getElementById('pindah_satuan_lbl').textContent = this.dataset.satuan;
        document.getElementById('pindah_info').innerHTML =
            `<strong>${this.dataset.nama}</strong><br>Sisa karung terbuka: ${this.dataset.sisa} ${this.dataset.satuan}`;
        document.getElementById('pindah_jumlah').value = this.dataset.sisa;
        openModal('pindahModal');
    });
});

const searchInput = document.getElementById('searchInput');
const tableBody   = document.getElementById('tableBody');
const rowCountEl  = document.getElementById('rowCount');
function updateRowCount() {
    const total   = tableBody.querySelectorAll('tr[data-id]').length;
    const visible = [...tableBody.querySelectorAll('tr[data-id]')].filter(tr => tr.style.display !== 'none').length;
    rowCountEl.textContent = visible === total ? `${total} item` : `${visible} / ${total} item`;
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