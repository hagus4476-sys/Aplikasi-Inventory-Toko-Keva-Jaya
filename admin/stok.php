<?php
session_start();
require_once '../includes/auth.php';
cekLogin();
cekRole('admin');
require_once '../includes/functions.php';

global $conn;

// Format angka desimal tanpa nol di belakang yang tidak perlu (24.50 -> 24,5 ; 24.00 -> 24)
function fmtQty($val) {
    return rtrim(rtrim(number_format((float)$val, 2, ',', '.'), '0'), ',');
}

// ================= LOGIKA STATUS STOK =================
// Barang yang punya "satuan_besar" (mis. Karung) dianggap barang olahan:
//   - Stok UTAMA yang dipakai untuk status & tampilan = stok_besar (jumlah karung utuh)
//   - Minimum Stok juga diartikan dalam satuan_besar tsb (bukan kg lagi)
//   - Sisa kiloan (stok siap jual + sisa karung terbuka yang belum dipindah)
//     ditampilkan sebagai keterangan "Sisa X kg" di bawah jumlah karung.
// Barang biasa (tanpa satuan_besar) tetap memakai kg seperti biasa.
function hitungStatusBarang($row) {
    $stok        = (float)$row['stok'];
    $min         = (float)$row['min_stok'];
    $stok_besar  = (float)$row['stok_besar'];
    $sisa_karung = (float)$row['sisa_karung_kg'];
    $is_olahan   = !empty($row['satuan_besar']);

    if ($is_olahan) {
        $main_qty  = $stok_besar;
        $main_unit = $row['satuan_besar'];
        $sisa_kg   = $stok + $sisa_karung; // semua kiloan di luar karung utuh

        if ($main_qty <= 0) {
            if ($sisa_kg > 0) {
                $status = 'sisa_kg';
                $label  = 'Karung Habis, Ada Sisa';
                $badge  = 'badge-terjebak';
            } else {
                $status = 'habis';
                $label  = 'Habis';
                $badge  = 'badge-habis';
            }
            $tr      = ($status === 'sisa_kg') ? 'tr-info' : 'tr-danger';
            $pbClass = 'pb-red';
        } elseif ($main_qty < $min) {
            $status  = 'menipis';
            $label   = 'Menipis';
            $badge   = 'badge-tipis';
            $tr      = 'tr-warning';
            $pbClass = 'pb-orange';
        } else {
            $status  = 'aman';
            $label   = 'Aman';
            $badge   = 'badge-aman';
            $tr      = '';
            $pbClass = 'pb-green';
        }
    } else {
        $main_qty  = $stok;
        $main_unit = $row['satuan'];
        $sisa_kg   = 0;

        if ($main_qty <= 0) {
            $status  = 'habis';
            $label   = 'Habis';
            $badge   = 'badge-habis';
            $tr      = 'tr-danger';
            $pbClass = 'pb-red';
        } elseif ($main_qty < $min) {
            $status  = 'menipis';
            $label   = 'Menipis';
            $badge   = 'badge-tipis';
            $tr      = 'tr-warning';
            $pbClass = 'pb-orange';
        } else {
            $status  = 'aman';
            $label   = 'Aman';
            $badge   = 'badge-aman';
            $tr      = '';
            $pbClass = 'pb-green';
        }
    }

    $pct = $min > 0 ? min(100, round(($main_qty / $min) * 100)) : 100;

    return [
        'status'    => $status,
        'label'     => $label,
        'badge'     => $badge,
        'tr'        => $tr,
        'pbClass'   => $pbClass,
        'pct'       => $pct,
        'is_olahan' => $is_olahan,
        'main_qty'  => $main_qty,
        'main_unit' => $main_unit,
        'min'       => $min,
        'min_unit'  => $is_olahan ? $row['satuan_besar'] : $row['satuan'],
        'sisa_kg'   => $sisa_kg,
        'sisa_unit' => $row['satuan'],
        'ada_karung'=> $is_olahan && ($stok_besar > 0 || $sisa_karung > 0),
    ];
}

// ================= FILTER =================
$filter = $_GET['filter'] ?? 'semua';

// ================= QUERY STOK =================
// Ambil semua data, filter & statistik dihitung di PHP karena aturan status
// berbeda tergantung barang punya satuan_besar atau tidak.
$query = "
    SELECT
        b.id_barang,
        b.kode_barang,
        b.nama_barang,
        b.kategori,
        b.satuan,
        b.satuan_besar,
        b.min_stok,
        b.stok,
        b.stok_besar,
        b.sisa_karung_kg
    FROM barang b
    ORDER BY b.nama_barang ASC
";
$result = $conn->query($query);
if (!$result) die("Query error: " . $conn->error);

$total_item     = 0;
$total_aman     = 0;
$total_menipis  = 0;
$total_habis    = 0;
$total_terjebak = 0; // karung utuh habis, tapi masih ada sisa kg (belum terjual/dipindah)
$all_rows       = [];

while ($row = $result->fetch_assoc()) {
    $total_item++;
    $st = hitungStatusBarang($row);
    $row['_status'] = $st;
    $all_rows[] = $row;

    if ($st['status'] === 'aman') {
        $total_aman++;
    } elseif ($st['status'] === 'menipis') {
        $total_menipis++;
    } else {
        // 'habis' dan 'sisa_kg' sama-sama berarti stok utama (karung/kg) = 0
        $total_habis++;
        if ($st['status'] === 'sisa_kg') $total_terjebak++;
    }
}

// Terapkan filter tab untuk data yang ditampilkan di tabel
$rows_data = array_values(array_filter($all_rows, function($row) use ($filter) {
    $status = $row['_status']['status'];
    if ($filter === 'aman')    return $status === 'aman';
    if ($filter === 'menipis') return $status === 'menipis';
    if ($filter === 'habis')   return in_array($status, ['habis', 'sisa_kg']);
    return true; // semua
}));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Stok Barang — Keva Jaya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ========== KEVA JAYA DESIGN SYSTEM (TIDAK DIUBAH) ========== */
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

        /* ── SIDEBAR ── */
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

        /* ── MAIN ── */
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
        .alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: var(--radius); font-size: 13.5px; font-weight: 450; margin-bottom: 20px; animation: slideDown 0.3s ease; flex-wrap: wrap; }
        @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
        .alert-success { background: var(--accent-light); color: var(--accent); border: 1px solid #b7ddc9; }
        .alert-danger  { background: var(--danger-light); color: var(--danger); border: 1px solid #f5b7b1; }
        .alert-warning { background: var(--warning-light); color: var(--warning); border: 1px solid #f5d5a0; }
        .alert-info    { background: var(--info-light); color: var(--info); border: 1px solid #b8d3e8; }
        .alert i { font-size: 16px; flex-shrink: 0; }
        .alert-close { margin-left: auto; cursor: pointer; opacity: 0.6; }
        .alert-close:hover { opacity: 1; }
        .alert .alert-action { margin-left: auto; flex-shrink: 0; }
        .stat-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 20px; }
        .stat-mini { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 18px; display: flex; align-items: center; gap: 14px; box-shadow: var(--shadow-sm); }
        .stat-mini-icon { width: 38px; height: 38px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .stat-mini-icon.blue   { background: var(--info-light);    color: var(--info); }
        .stat-mini-icon.green  { background: var(--accent-light);  color: var(--accent); }
        .stat-mini-icon.orange { background: var(--warning-light); color: var(--warning); }
        .stat-mini-icon.red    { background: var(--danger-light);  color: var(--danger); }
        .stat-mini-val  { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; line-height: 1; }
        .stat-mini-lbl  { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
        .card-head { padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); background: var(--surface-2); flex-wrap: wrap; gap: 8px; }
        .card-head-title { font-size: 13.5px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .card-head-title i { color: var(--accent); }
        .filter-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
        .filter-tab { display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 99px; font-size: 13px; font-weight: 500; text-decoration: none; border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary); transition: var(--transition); }
        .filter-tab:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-light); }
        .filter-tab.active { background: var(--text-primary); color: white; border-color: var(--text-primary); }
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
        .tr-warning { background: var(--warning-light) !important; }
        .tr-danger  { background: var(--danger-light) !important; }
        .tr-info    { background: var(--info-light) !important; }
        .kode-text { font-family: 'JetBrains Mono', monospace; font-size: 12px; font-weight: 500; color: var(--text-secondary); background: var(--surface-2); border: 1px solid var(--border); padding: 2px 8px; border-radius: 4px; }
        .badge-stok { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 550; }
        .badge-aman   { background: var(--accent-light);  color: var(--accent); }
        .badge-tipis  { background: var(--warning-light); color: var(--warning); }
        .badge-habis  { background: var(--danger-light);  color: var(--danger); }
        .badge-terjebak { background: var(--info-light); color: var(--info); }
        .sub-note { display: block; font-size: 11px; color: var(--info); margin-top: 2px; }
        .progress { height: 5px; background: var(--border); border-radius: 99px; overflow: hidden; margin-top: 5px; width: 90px; }
        .progress-bar { height: 100%; border-radius: 99px; }
        .pb-green { background: var(--accent); }
        .pb-orange{ background: var(--warning); }
        .pb-red   { background: var(--danger); }
        .btn-primary { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: var(--accent); color: white; border: none; border-radius: var(--radius-sm); font-size: 12.5px; font-weight: 600; font-family: inherit; cursor: pointer; transition: var(--transition); text-decoration: none; }
        .btn-primary:hover { background: var(--accent-hover); }
        .btn-info-outline { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: var(--surface); color: var(--info); border: 1px solid #b8d3e8; border-radius: var(--radius-sm); font-size: 12.5px; font-weight: 600; font-family: inherit; cursor: pointer; transition: var(--transition); text-decoration: none; }
        .btn-info-outline:hover { background: var(--info-light); }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 36px; opacity: 0.4; display: block; margin-bottom: 10px; }
        @media (max-width: 900px) { .stat-row { grid-template-columns: repeat(2,1fr); } }
        @media (max-width: 768px) { .sidebar { left: calc(-1 * var(--sidebar-w)); } .sidebar.mobile-open { left: 0; } .main { margin-left: 0; } }
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
            <!-- Dashboard -->
            <li class="nav-item"><a href="dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF'])=='dashboard.php'?'active':'' ?>"><i class="bi bi-grid-1x2"></i> Dashboard</a></li>

            <!-- MASTER DATA -->
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

            <!-- TRANSAKSI -->
            <div class="nav-section-label">Transaksi</div>
            <li class="nav-item">
                <button class="nav-link" onclick="toggleNav('navTransaksi',this)" aria-expanded="false">
                    <i class="bi bi-arrow-left-right"></i> Transaksi <i class="bi bi-chevron-down chevron"></i>
                </button>
                <ul id="navTransaksi" class="nav-sub" style="list-style:none;padding:0;display:none;">
                    <li><a href="preorder.php" class="nav-link"><i class="bi bi-cart3"></i> Pre Order</a></li>
                    <li><a href="barang_masuk.php" class="nav-link"><i class="bi bi-arrow-down-circle"></i> Barang Masuk</a></li>
                    <li><a href="barang_keluar.php" class="nav-link"><i class="bi bi-arrow-up-circle"></i> Barang Keluar</a></li>
                    <li><a href="barang_rusak.php" class="nav-link"><i class="bi bi-slash-circle"></i> Barang Rusak</a></li>
                    <li><a href="koreksi_stok.php" class="nav-link"><i class="bi bi-pencil-square"></i> Koreksi Stok</a></li>
                    <li><a href="olah_stok.php" class="nav-link"><i class="bi bi-recycle"></i> Olah Stok</a></li>
                </ul>
            </li>

            <!-- MONITORING -->
            <div class="nav-section-label">Monitoring</div>
            <li class="nav-item">
                <button class="nav-link active" onclick="toggleNav('navMonitor',this)" aria-expanded="true">
                    <i class="bi bi-graph-up-arrow"></i> Monitoring <i class="bi bi-chevron-down chevron"></i>
                </button>
                <ul id="navMonitor" class="nav-sub" style="list-style:none;padding:0;">
                    <li><a href="stok.php" class="nav-link active"><i class="bi bi-boxes"></i> Stok Barang</a></li>
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
            <span>Stok Barang</span>
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
        <?php if ($total_terjebak > 0): ?>
        <div class="alert alert-info">
            <i class="bi bi-box-seam"></i>
            <strong><?= $total_terjebak ?> barang</strong> karung utuhnya sudah habis, tapi masih ada sisa kiloan (dari karung yang sudah dibuka namun belum habis terjual/dipindahkan) — segera habiskan sisanya atau tambah stok karung baru.
            <a href="olah_stok.php" class="btn-info-outline alert-action"><i class="bi bi-recycle"></i> Lihat di Olah Stok</a>
            <i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i>
        </div>
        <?php endif; ?>
        <?php if ($total_menipis > 0): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <strong><?= $total_menipis ?> barang</strong> stoknya di bawah minimum — segera lakukan pengadaan.
            <i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i>
        </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <div class="page-title">Stok Barang</div>
                <div class="page-subtitle">Monitor stok barang yang tersedia untuk dijual (barang olahan ditampilkan per karung)</div>
            </div>
            <div class="filter-tabs">
                <a href="?filter=semua" class="filter-tab <?= $filter==='semua' ? 'active' : '' ?>">
                    <i class="bi bi-grid-3x3-gap-fill"></i> Semua Stok
                </a>
                <a href="?filter=aman" class="filter-tab <?= $filter==='aman' ? 'active' : '' ?>">
                    <i class="bi bi-check-circle"></i> Stok Aman
                </a>
                <a href="?filter=menipis" class="filter-tab <?= $filter==='menipis' ? 'active' : '' ?>">
                    <i class="bi bi-exclamation-triangle"></i> Stok Menipis
                </a>
                <a href="?filter=habis" class="filter-tab <?= $filter==='habis' ? 'active' : '' ?>">
                    <i class="bi bi-x-circle"></i> Stok Habis
                </a>
            </div>
        </div>

        <div class="stat-row">
            <div class="stat-mini">
                <div class="stat-mini-icon blue"><i class="bi bi-box-seam"></i></div>
                <div>
                    <div class="stat-mini-val"><?= $total_item ?></div>
                    <div class="stat-mini-lbl">Total Jenis Barang</div>
                </div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-icon green"><i class="bi bi-check-circle"></i></div>
                <div>
                    <div class="stat-mini-val"><?= $total_aman ?></div>
                    <div class="stat-mini-lbl">Stok Aman</div>
                </div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-icon orange"><i class="bi bi-exclamation-triangle"></i></div>
                <div>
                    <div class="stat-mini-val"><?= $total_menipis ?></div>
                    <div class="stat-mini-lbl">Stok Menipis</div>
                </div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-icon red"><i class="bi bi-x-circle"></i></div>
                <div>
                    <div class="stat-mini-val"><?= $total_habis ?></div>
                    <div class="stat-mini-lbl">Stok Habis<?= $total_terjebak > 0 ? " ({$total_terjebak} masih ada sisa kg)" : '' ?></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div class="card-head-title">
                    <i class="bi bi-boxes"></i>
                    Daftar Stok Barang
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
                            <th>Stok</th>
                            <th>Min Stok</th>
                            <th>Status</th>
                            <th style="text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php if (!empty($rows_data)): ?>
                            <?php foreach ($rows_data as $row):
                                $st = $row['_status'];
                            ?>
                            <tr class="<?= $st['tr'] ?>" data-id="<?= $row['id_barang'] ?>">
                                <td><span class="kode-text"><?= htmlspecialchars($row['kode_barang']) ?></span></td>
                                <td style="font-weight:500;"><?= htmlspecialchars($row['nama_barang']) ?></td>
                                <td><span style="color:var(--text-secondary);"><?= htmlspecialchars($row['kategori'] ?? '—') ?></span></td>
                                <td>
                                    <span class="stok-num"><?= fmtQty($st['main_qty']) ?></span>
                                    <small style="color:var(--text-muted);margin-left:3px;"><?= htmlspecialchars($st['main_unit']) ?></small>
                                    <div class="progress">
                                        <div class="progress-bar <?= $st['pbClass'] ?>" style="width:<?= $st['pct'] ?>%"></div>
                                    </div>
                                    <?php if ($st['is_olahan'] && $st['sisa_kg'] > 0): ?>
                                        <span class="sub-note">
                                            <i class="bi bi-box-seam"></i>
                                            Sisa <?= fmtQty($st['sisa_kg']) ?> <?= htmlspecialchars($st['sisa_unit']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="color:var(--text-secondary);"><?= fmtQty($st['min']) ?></span>
                                    <small style="color:var(--text-muted);"> <?= htmlspecialchars($st['min_unit']) ?></small>
                                </td>
                                <td><span class="badge-stok <?= $st['badge'] ?>"><?= $st['label'] ?></span></td>
                                <td>
                                    <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                                        <?php if ($st['status'] === 'aman'): ?>
                                            <a href="olah_stok.php?id=<?= $row['id_barang'] ?>" class="btn-info-outline" style="font-size:12px;padding:5px 10px;">
                                                <i class="bi bi-recycle"></i> Olah Stok
                                            </a>
                                        <?php else: ?>
                                            <a href="barang_masuk.php?barang_id=<?= $row['id_barang'] ?>" class="btn-primary" style="font-size:12px;padding:5px 10px;">
                                                <i class="bi bi-plus-circle"></i> Tambah Stok
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7">
                                <div class="empty-state">
                                    <i class="bi bi-inbox"></i>
                                    <p><?= $filter === 'semua' ? 'Tidak ada data barang.' : 'Tidak ada barang dengan status ini.' ?></p>
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