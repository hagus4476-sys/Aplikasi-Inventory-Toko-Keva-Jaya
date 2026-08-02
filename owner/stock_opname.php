<?php
session_start();
require_once '../includes/auth.php';
cekLogin();
cekRole('owner');
require_once '../includes/functions.php';

global $conn;

// ================= HITUNG BADGE UNTUK SIDEBAR =================
$jmlPendingKoreksi = $conn->query("SELECT COUNT(*) as c FROM stock_opname WHERE status='Pending'")->fetch_assoc()['c'] ?? 0;
$jmlPendingRusak   = $conn->query("SELECT COUNT(*) as c FROM pengajuan_rusak WHERE status='Pending'")->fetch_assoc()['c'] ?? 0;
$pendingCount      = $conn->query("SELECT COUNT(*) as c FROM pre_order WHERE status='pending'")->fetch_assoc()['c'] ?? 0;
$current_file = basename($_SERVER['PHP_SELF']);

// Format angka desimal tanpa nol di belakang yang tidak perlu
function fmtQty($val) {
    return rtrim(rtrim(number_format((float)$val, 2, ',', '.'), '0'), ',');
}

$tgl_awal  = isset($_GET['tgl_awal'])  ? $_GET['tgl_awal']  : date('Y-m-01');
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');
$kategori_filter = isset($_GET['kategori']) ? (int)$_GET['kategori'] : 0;
$search    = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($tgl_awal > $tgl_akhir) { $tmp = $tgl_awal; $tgl_awal = $tgl_akhir; $tgl_akhir = $tmp; }

// Ambil semua kategori
$daftar_kategori = $conn->query("SELECT id, nama FROM kategori ORDER BY nama");

// ========== QUERY AMBIL DATA BARANG (DENGAN SATUAN BESAR) ==========
// Catatan: join kategori sekarang pakai kategori_id (FK), bukan cocokkan nama lagi
$sql_barang = "
    SELECT 
        b.id_barang,
        b.nama_barang,
        b.stok AS stok_current,
        b.stok_besar,
        b.sisa_karung_kg,
        b.satuan,
        b.satuan_besar,
        k.nama AS nama_kategori,
        b.min_stok
    FROM barang b
    LEFT JOIN kategori k ON k.id = b.kategori_id
    WHERE 1=1
";
$params = [];
$types = "";

if ($kategori_filter > 0) {
    $sql_barang .= " AND b.kategori_id = ?";
    $params[] = $kategori_filter;
    $types .= "i";
}
if ($search !== '') {
    $sql_barang .= " AND b.nama_barang LIKE ?";
    $params[] = "%$search%";
    $types .= "s";
}
$sql_barang .= " ORDER BY b.nama_barang";

$stmt_barang = $conn->prepare($sql_barang);
if (!empty($params)) {
    $stmt_barang->bind_param($types, ...$params);
}
$stmt_barang->execute();
$result_barang = $stmt_barang->get_result();
$barang_list = $result_barang->fetch_all(MYSQLI_ASSOC);
$stmt_barang->close();

// ========== PROSES DATA PER BARANG ==========
$semua_barang = [];
foreach ($barang_list as $b) {
    $id = $b['id_barang'];
    
    // 1. Total masuk dari transaksi_masuk (stok bertambah)
    $sql_masuk = "SELECT COALESCE(SUM(i.qty), 0) as total 
                  FROM transaksi_masuk m 
                  JOIN transaksi_masuk_item i ON m.id = i.transaksi_masuk_id 
                  WHERE i.barang_id = ? AND m.tanggal BETWEEN ? AND ?";
    $stmt_masuk = $conn->prepare($sql_masuk);
    $stmt_masuk->bind_param("iss", $id, $tgl_awal, $tgl_akhir);
    $stmt_masuk->execute();
    $total_masuk = $stmt_masuk->get_result()->fetch_assoc()['total'];
    $stmt_masuk->close();
    
    // 2. Total keluar dari tabel barang_keluar
    $sql_keluar = "SELECT COALESCE(SUM(qty), 0) as total 
                   FROM barang_keluar 
                   WHERE barang_id = ? AND DATE(tanggal) BETWEEN ? AND ?";
    $stmt_keluar = $conn->prepare($sql_keluar);
    $stmt_keluar->bind_param("iss", $id, $tgl_awal, $tgl_akhir);
    $stmt_keluar->execute();
    $total_keluar = $stmt_keluar->get_result()->fetch_assoc()['total'];
    $stmt_keluar->close();
    
    // 3. Total rusak dari pengajuan_rusak (status Disetujui)
    $sql_rusak = "SELECT COALESCE(SUM(jumlah), 0) as total 
                  FROM pengajuan_rusak 
                  WHERE barang_id = ? AND status = 'Disetujui' AND DATE(created_at) BETWEEN ? AND ?";
    $stmt_rusak = $conn->prepare($sql_rusak);
    $stmt_rusak->bind_param("iss", $id, $tgl_awal, $tgl_akhir);
    $stmt_rusak->execute();
    $total_rusak = $stmt_rusak->get_result()->fetch_assoc()['total'];
    $stmt_rusak->close();
    
    // 4. Total koreksi (jumlah kali koreksi, bukan jumlah selisih)
    $sql_koreksi = "SELECT COUNT(*) as total 
                    FROM stock_opname o 
                    JOIN stock_opname_item i ON o.id = i.opname_id 
                    WHERE i.barang_id = ? AND o.status = 'Disetujui' AND o.tanggal BETWEEN ? AND ?";
    $stmt_koreksi = $conn->prepare($sql_koreksi);
    $stmt_koreksi->bind_param("iss", $id, $tgl_awal, $tgl_akhir);
    $stmt_koreksi->execute();
    $total_koreksi = $stmt_koreksi->get_result()->fetch_assoc()['total'];
    $stmt_koreksi->close();
    
    // Total transaksi = semua jenis yang punya data >0
    $total_transaksi = 0;
    if ($total_masuk > 0) $total_transaksi++;
    if ($total_keluar > 0) $total_transaksi++;
    if ($total_rusak > 0) $total_transaksi++;
    if ($total_koreksi > 0) $total_transaksi++;
    
    // Tentukan stok utama dan satuan utama
    $is_olahan = !empty($b['satuan_besar']);
    if ($is_olahan) {
        $stok_utama = (int)$b['stok_besar'];
        $satuan_utama = $b['satuan_besar'];
        // FIX: sebelumnya $b['stok'] (tidak ada, karena alias-nya stok_current) -> ganti ke stok_current
        $sisa_kg = (float)$b['stok_current'] + (float)$b['sisa_karung_kg'];
    } else {
        $stok_utama = (int)$b['stok_current'];
        $satuan_utama = $b['satuan'];
        $sisa_kg = 0;
    }
    
    $semua_barang[] = [
        'id_barang' => $id,
        'nama_barang' => $b['nama_barang'],
        'nama_kategori' => $b['nama_kategori'] ?? '-',
        'stok_current' => (int)$b['stok_current'],
        'stok_utama' => $stok_utama,
        'satuan_utama' => $satuan_utama,
        'sisa_kg' => $sisa_kg,
        'satuan_kecil' => $b['satuan'],
        'is_olahan' => $is_olahan,
        'min_stok' => (int)$b['min_stok'],
        'total_masuk' => (int)$total_masuk,
        'total_keluar' => (int)$total_keluar,
        'total_rusak' => (int)$total_rusak,
        'total_koreksi' => (int)$total_koreksi,
        'total_transaksi' => $total_transaksi
    ];
}

// Hitung grand total summary (semua dalam satuan kg, karena pergerakan dicatat dalam kg)
$grand_total_masuk   = 0;
$grand_total_keluar  = 0;
$grand_total_rusak   = 0;
$grand_total_koreksi = 0;
$grand_total_aktif   = count($semua_barang);
$barang_bergerak     = 0;
foreach ($semua_barang as $b) {
    $grand_total_masuk   += $b['total_masuk'];
    $grand_total_keluar  += $b['total_keluar'];
    $grand_total_rusak   += $b['total_rusak'];
    $grand_total_koreksi += $b['total_koreksi'];
    if ($b['total_transaksi'] > 0) $barang_bergerak++;
}
$selisih = $grand_total_masuk - $grand_total_keluar - $grand_total_rusak;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Stock Opname — Keva Jaya (Owner)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ========== KEVA JAYA DESIGN SYSTEM (sama seperti sebelumnya) ========== */
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

        /* SIDEBAR STANDAR OWNER */
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
        .nav-badge { margin-left: auto; background: var(--warning); color: white; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 99px; line-height: 1.4; }
        .sidebar-footer { padding: 12px 14px; border-top: 1px solid rgba(255,255,255,0.08); flex-shrink: 0; }
        .user-card { display: flex; align-items: center; gap: 10px; }
        .user-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: white; flex-shrink: 0; }
        .user-name { font-size: 13px; font-weight: 550; color: rgba(255,255,255,0.8); }
        .user-role { font-size: 11px; color: rgba(255,255,255,0.35); }
        .btn-sm-nav { display: inline-flex; align-items: center; gap: 5px; padding: 5px 11px; border-radius: var(--radius-sm); font-size: 12px; font-weight: 500; font-family: inherit; cursor: pointer; transition: var(--transition); border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary); text-decoration: none; }

        /* MAIN */
        .main { margin-left: var(--sidebar-w); transition: margin-left 0.2s; min-height: 100vh; display: flex; flex-direction: column; }
        .main.expanded { margin-left: 0; }
        .topbar { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0 24px; height: 56px; display: flex; align-items: center; gap: 16px; position: sticky; top: 0; z-index: 50; }
        .btn-toggle { width: 34px; height: 34px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--surface-2); color: var(--text-secondary); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: var(--transition); font-size: 16px; flex-shrink: 0; }
        .btn-toggle:hover { background: var(--border); color: var(--text-primary); }
        .breadcrumb-bar { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-muted); }
        .breadcrumb-bar a { color: inherit; text-decoration: none; }
        .breadcrumb-bar span { color: var(--text-secondary); font-weight: 500; }
        .breadcrumb-bar i { font-size: 11px; }
        .topbar-right { margin-left: auto; display: flex; align-items: center; gap: 10px; }

        .page-body { padding: 24px; flex: 1; }
        .page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
        .page-title { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; }
        .page-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 2px; }

        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .summary-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 18px; display: flex; align-items: center; gap: 14px; box-shadow: var(--shadow-sm); transition: var(--transition); }
        .summary-card:hover { box-shadow: var(--shadow); transform: translateY(-1px); }
        .summary-icon { width: 42px; height: 42px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 19px; flex-shrink: 0; }
        .summary-icon.masuk   { background: var(--accent-light); color: var(--accent); }
        .summary-icon.keluar  { background: var(--danger-light); color: var(--danger); }
        .summary-icon.rusak   { background: #fffbeb; color: #d97706; border: 1px solid #fed7aa; }
        .summary-icon.koreksi { background: var(--info-light); color: var(--info); }
        .summary-icon.all     { background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border); }
        .summary-icon.warning { background: var(--warning-light); color: var(--warning); }
        .summary-info small   { font-size: 11.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; display: block; }
        .summary-info h5      { font-size: 22px; font-weight: 700; margin: 2px 0 0; line-height: 1.2; }
        .summary-info span    { font-size: 11.5px; color: var(--text-muted); }

        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
        .card-head { padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); background: var(--surface-2); }
        .card-head-title { font-size: 13.5px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .card-head-title i { color: var(--accent); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-label { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); letter-spacing: 0.2px; }
        .form-control, .form-select { padding: 9px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13.5px; font-family: inherit; background: var(--surface); transition: var(--transition); width: 100%; }
        .form-control:focus, .form-select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }
        .btn-primary { display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; background: var(--accent); color: white; border: none; border-radius: var(--radius-sm); font-size: 13.5px; font-weight: 600; cursor: pointer; transition: var(--transition); }
        .btn-primary:hover { background: var(--accent-hover); transform: translateY(-1px); }
        .btn-outline { display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; background: transparent; color: var(--text-secondary); border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13.5px; font-weight: 600; cursor: pointer; transition: var(--transition); text-decoration: none; }
        .btn-outline:hover { background: var(--surface-2); border-color: var(--border-strong); }

        .table-wrap { overflow-x: auto; border-radius: var(--radius); }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead th { padding: 12px 16px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.4px; text-transform: uppercase; color: var(--text-muted); background: var(--surface-2); border-bottom: 1px solid var(--border); white-space: nowrap; }
        thead th.text-right { text-align: right; }
        tbody td { padding: 14px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; background-color: var(--surface); }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background-color: var(--surface-2); }
        th:first-child, td:first-child { min-width: 180px; }
        th:nth-child(2), td:nth-child(2) { min-width: 120px; }
        th:nth-child(3), td:nth-child(3),
        th:nth-child(4), td:nth-child(4),
        th:nth-child(5), td:nth-child(5),
        th:nth-child(6), td:nth-child(6),
        th:nth-child(7), td:nth-child(7) { min-width: 85px; text-align: right; }
        th:nth-child(8), td:nth-child(8) { min-width: 100px; }
        th:last-child, td:last-child { min-width: 50px; text-align: center; }

        tfoot td { padding: 12px 16px; font-weight: 700; background: var(--surface-2); border-top: 2px solid var(--border); font-size: 13px; }
        tfoot td.text-right { text-align: right; }

        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 550; line-height: 1.3; }
        .badge-kategori { background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border); }
        .badge-koreksi { background: var(--info-light); color: var(--info); font-size: 11px; padding: 2px 8px; }
        .badge-rusak { background: #fffbeb; color: #d97706; border: 1px solid #fed7aa; font-size: 11px; padding: 2px 8px; }
        .badge-karung { background: var(--warning-light); color: var(--warning); font-size: 10px; padding: 2px 8px; }
        .stok-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 99px; font-size: 12px; font-weight: 600; }
        .stok-aman   { background: var(--accent-light); color: var(--accent); }
        .stok-sedang { background: var(--warning-light); color: var(--warning); }
        .stok-habis  { background: var(--danger-light); color: var(--danger); }

        .detail-row { display: none; }
        .detail-row.open { display: table-row; }
        .detail-inner { padding: 16px 20px; background: var(--surface-2); border-bottom: 1px solid var(--border); }
        .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
        .detail-item { display: flex; align-items: center; gap: 12px; padding: 10px 14px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-sm); }
        .detail-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .dot-masuk   { background: var(--accent); }
        .dot-keluar  { background: var(--danger); }
        .dot-rusak   { background: #d97706; }
        .dot-koreksi { background: var(--info); }
        .detail-label { font-size: 12px; color: var(--text-muted); }
        .detail-val   { font-size: 15px; font-weight: 700; }
        .expand-btn { background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: 16px; padding: 5px; transition: var(--transition); border-radius: 99px; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; }
        .expand-btn:hover { color: var(--accent); background: var(--accent-light); }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 42px; opacity: 0.4; display: block; margin-bottom: 12px; }
        .row-count { font-size: 12px; color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border); padding: 4px 12px; border-radius: 99px; }

        @media (max-width: 768px) {
            .sidebar { left: calc(-1 * var(--sidebar-w)); }
            .sidebar.mobile-open { left: 0; }
            .main { margin-left: 0; }
            .form-grid { grid-template-columns: 1fr; }
            .summary-grid { grid-template-columns: repeat(2, 1fr); }
            th:first-child, td:first-child { min-width: 140px; }
        }
    </style>
</head>
<body>

<!-- ===== SIDEBAR STANDAR OWNER ===== -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <div class="brand-icon"><i class="bi bi-box-seam"></i></div>
            <div>
                <div class="brand-name">Keva Jaya</div>
                <div class="brand-sub">Owner Panel</div>
            </div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <ul style="list-style:none;padding:0;">
            <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="bi bi-grid-1x2"></i> Dashboard</a></li>

            <div class="nav-section-label">Konfirmasi</div>
            <li class="nav-item">
                <a href="konfirmasi_preorder.php" class="nav-link">
                    <i class="bi bi-check2-circle"></i> Konfirmasi Pre Order
                    <?php if ($pendingCount > 0): ?>
                        <span class="nav-badge"><?= $pendingCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a href="approval_koreksi.php" class="nav-link">
                    <i class="bi bi-shield-check"></i> Konfirmasi Koreksi Stok
                    <?php if ($jmlPendingKoreksi > 0): ?>
                        <span class="nav-badge"><?= $jmlPendingKoreksi ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a href="konfirmasi_rusak.php" class="nav-link">
                    <i class="bi bi-slash-circle"></i> Konfirmasi Barang Rusak
                    <?php if ($jmlPendingRusak > 0): ?>
                        <span class="nav-badge"><?= $jmlPendingRusak ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <div class="nav-section-label">Laporan</div>
            <li class="nav-item"><a href="laporan.php" class="nav-link"><i class="bi bi-bar-chart"></i> Laporan</a></li>

            <div class="nav-section-label">Monitoring</div>
            <li class="nav-item"><a href="stok.php" class="nav-link"><i class="bi bi-boxes"></i> Stok Barang</a></li>
            <li class="nav-item"><a href="stock_opname.php" class="nav-link active"><i class="bi bi-clock-history"></i> Stock Opname</a></li>
            <li class="nav-item"><a href="kadaluarsa.php" class="nav-link <?= $current_file == 'kadaluarsa.php' ? 'active' : '' ?>"><i class="bi bi-calendar-x"></i> Kadaluarsa</a></li>
        </ul>
    </nav>
    <div class="sidebar-footer">
        <div class="user-card">
            <div class="user-avatar">O</div>
            <div>
                <div class="user-name"><?= htmlspecialchars($_SESSION['username'] ?? 'Owner') ?></div>
                <div class="user-role">Owner</div>
            </div>
            <a href="../logout.php" class="btn-sm-nav" style="margin-left:auto;border:none;" title="Logout">
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
            <span>Stock Opname</span>
        </div>
        <div class="topbar-right">
            <span style="font-size:12px;color:var(--text-muted);"><?= date('d M Y') ?></span>
        </div>
    </header>

    <div class="page-body">
        <div class="page-header">
            <div>
                <div class="page-title">Stock Opname</div>
                <div class="page-subtitle">Ringkasan pergerakan stok seluruh barang (termasuk barang olahan) dalam periode yang dipilih</div>
            </div>
        </div>

        <!-- FILTER -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-head">
                <div class="card-head-title"><i class="bi bi-funnel"></i> Filter Periode</div>
            </div>
            <form method="GET" style="padding:20px;">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Dari Tanggal</label>
                        <input type="date" name="tgl_awal" class="form-control" value="<?= $tgl_awal ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sampai Tanggal</label>
                        <input type="date" name="tgl_akhir" class="form-control" value="<?= $tgl_akhir ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Kategori</label>
                        <select name="kategori" class="form-select">
                            <option value="0">Semua Kategori</option>
                            <?php
                            $daftar_kategori->data_seek(0);
                            while ($kat = $daftar_kategori->fetch_assoc()): ?>
                            <option value="<?= $kat['id'] ?>" <?= $kategori_filter == $kat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($kat['nama']) ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Cari Barang</label>
                        <input type="text" name="search" class="form-control" placeholder="Nama barang..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div style="display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;">
                        <button type="submit" class="btn-primary"><i class="bi bi-search"></i> Tampilkan</button>
                        <a href="stock_opname.php" class="btn-outline"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- SUMMARY CARDS -->
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-icon all"><i class="bi bi-boxes"></i></div>
                <div class="summary-info">
                    <small>Total Barang Aktif</small>
                    <h5><?= $grand_total_aktif ?></h5>
                    <span><?= $barang_bergerak ?> bergerak di periode ini</span>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon masuk"><i class="bi bi-arrow-down-circle"></i></div>
                <div class="summary-info">
                    <small>Total Masuk (kg)</small>
                    <h5 style="color:var(--accent);"><?= number_format($grand_total_masuk) ?></h5>
                    <span>item diterima</span>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon keluar"><i class="bi bi-arrow-up-circle"></i></div>
                <div class="summary-info">
                    <small>Total Keluar (kg)</small>
                    <h5 style="color:var(--danger);"><?= number_format($grand_total_keluar) ?></h5>
                    <span>item terpakai</span>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon rusak"><i class="bi bi-slash-circle"></i></div>
                <div class="summary-info">
                    <small>Total Rusak (kg)</small>
                    <h5 style="color:#d97706;"><?= number_format($grand_total_rusak) ?></h5>
                    <span>item rusak</span>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon koreksi"><i class="bi bi-pencil-square"></i></div>
                <div class="summary-info">
                    <small>Total Koreksi</small>
                    <h5 style="color:var(--info);"><?= number_format($grand_total_koreksi) ?></h5>
                    <span>penyesuaian stok</span>
                </div>
            </div>
            <div class="summary-card">
                <div class="summary-icon warning"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="summary-info">
                    <small>Selisih Bersih (kg)</small>
                    <h5 style="color:<?= $selisih >= 0 ? 'var(--accent)' : 'var(--danger)' ?>;">
                        <?= ($selisih >= 0 ? '+' : '') . number_format($selisih) ?>
                    </h5>
                    <span>masuk - (keluar+rusak)</span>
                </div>
            </div>
        </div>

        <!-- TABEL RINGKASAN PER BARANG -->
        <div class="card">
            <div class="card-head">
                <div class="card-head-title">
                    <i class="bi bi-table"></i>
                    Ringkasan Per Barang
                    <span style="font-size:12px;color:var(--text-muted);font-weight:400;">
                        <?= date('d/m/Y', strtotime($tgl_awal)) ?> — <?= date('d/m/Y', strtotime($tgl_akhir)) ?>
                    </span>
                </div>
                <span class="row-count"><?= count($semua_barang) ?> barang</span>
            </div>
            <div class="table-wrap">
                <?php if (!empty($semua_barang)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Nama Barang</th>
                            <th>Kategori</th>
                            <th class="text-right">Masuk (kg)</th>
                            <th class="text-right">Keluar (kg)</th>
                            <th class="text-right">Rusak (kg)</th>
                            <th class="text-right">Koreksi (kali)</th>
                            <th class="text-right">Stok Utama</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($semua_barang as $idx => $b):
                            $stok = (int)$b['stok_utama'];
                            $satuan_utama = $b['satuan_utama'];
                            $is_olahan = $b['is_olahan'];
                            $sisa_kg = $b['sisa_kg'];
                            $min_stok = $b['min_stok'];

                            // Tentukan status berdasarkan stok utama
                            if ($stok <= 0 && $is_olahan && $sisa_kg > 0) {
                                $stok_class = 'stok-sedang';
                                $stok_label = 'Karung Habis, Sisa Kg';
                                $stok_icon = 'bi-exclamation-triangle';
                            } elseif ($stok <= 0) {
                                $stok_class = 'stok-habis';
                                $stok_label = 'Habis';
                                $stok_icon = 'bi-x-circle';
                            } elseif ($stok < $min_stok) {
                                $stok_class = 'stok-sedang';
                                $stok_label = 'Menipis';
                                $stok_icon = 'bi-exclamation-circle';
                            } else {
                                $stok_class = 'stok-aman';
                                $stok_label = 'Aman';
                                $stok_icon = 'bi-check-circle';
                            }
                        ?>
                        <tr>
                            <td style="font-weight:600;">
                                <?= htmlspecialchars($b['nama_barang']) ?>
                                <?php if ($is_olahan): ?>
                                    <span class="badge badge-karung"><i class="bi bi-box"></i> Karung</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-kategori"><?= htmlspecialchars($b['nama_kategori']) ?></span></td>
                            <td class="text-right" style="color:var(--accent); font-weight:600;">+<?= number_format($b['total_masuk']) ?></td>
                            <td class="text-right" style="color:var(--danger); font-weight:600;">-<?= number_format($b['total_keluar']) ?></td>
                            <td class="text-right" style="color:#d97706; font-weight:600;">-<?= number_format($b['total_rusak']) ?></td>
                            <td class="text-right">
                                <?php if ($b['total_koreksi'] > 0): ?>
                                <span class="badge badge-koreksi"><i class="bi bi-pencil-square"></i> <?= $b['total_koreksi'] ?>x</span>
                                <?php else: ?>
                                <span style="color:var(--text-muted);">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right" style="font-weight:700; font-size:15px;">
                                <?= fmtQty($stok) ?>
                                <small style="color:var(--text-muted);font-weight:400;font-size:12px;"><?= htmlspecialchars($satuan_utama) ?></small>
                                <?php if ($is_olahan && $sisa_kg > 0): ?>
                                    <div style="font-size:11px;color:var(--info);">Sisa kg: <?= fmtQty($sisa_kg) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="stok-badge <?= $stok_class ?>"><i class="bi <?= $stok_icon ?>"></i> <?= $stok_label ?></span></td>
                            <td>
                                <?php if ($b['total_transaksi'] > 0): ?>
                                <button class="expand-btn" onclick="toggleDetail(<?= $idx ?>)" id="btn-<?= $idx ?>" title="Lihat detail">
                                    <i class="bi bi-chevron-down" id="icon-<?= $idx ?>"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr class="detail-row" id="detail-<?= $idx ?>">
                            <td colspan="9" style="padding:0;">
                                <div class="detail-inner">
                                    <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">
                                        Rincian Mutasi — <?= htmlspecialchars($b['nama_barang']) ?>
                                    </div>
                                    <div class="detail-grid">
                                        <div class="detail-item">
                                            <div class="detail-dot dot-masuk"></div>
                                            <div>
                                                <div class="detail-label">Total Masuk</div>
                                                <div class="detail-val" style="color:var(--accent);">+<?= number_format($b['total_masuk']) ?> kg</div>
                                            </div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-dot dot-keluar"></div>
                                            <div>
                                                <div class="detail-label">Total Keluar</div>
                                                <div class="detail-val" style="color:var(--danger);">-<?= number_format($b['total_keluar']) ?> kg</div>
                                            </div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-dot dot-rusak"></div>
                                            <div>
                                                <div class="detail-label">Total Rusak</div>
                                                <div class="detail-val" style="color:#d97706;">-<?= number_format($b['total_rusak']) ?> kg</div>
                                            </div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-dot dot-koreksi"></div>
                                            <div>
                                                <div class="detail-label">Jumlah Koreksi</div>
                                                <div class="detail-val" style="color:var(--info);"><?= $b['total_koreksi'] ?> kali</div>
                                            </div>
                                        </div>
                                        <div class="detail-item">
                                            <div class="detail-dot" style="background:var(--text-muted);"></div>
                                            <div>
                                                <div class="detail-label">Stok Utama (<?= htmlspecialchars($satuan_utama) ?>)</div>
                                                <div class="detail-val"><?= number_format($stok) ?></div>
                                            </div>
                                        </div>
                                        <?php if ($is_olahan && $sisa_kg > 0): ?>
                                        <div class="detail-item">
                                            <div class="detail-dot" style="background:var(--info);"></div>
                                            <div>
                                                <div class="detail-label">Sisa Kg</div>
                                                <div class="detail-val" style="color:var(--info);"><?= fmtQty($sisa_kg) ?> kg</div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2"><i class="bi bi-calculator" style="margin-right:6px;"></i>Total Periode</td>
                            <td class="text-right" style="color:var(--accent); font-weight:700;">+<?= number_format($grand_total_masuk) ?></td>
                            <td class="text-right" style="color:var(--danger); font-weight:700;">-<?= number_format($grand_total_keluar) ?></td>
                            <td class="text-right" style="color:#d97706; font-weight:700;">-<?= number_format($grand_total_rusak) ?></td>
                            <td class="text-right"><?= number_format($grand_total_koreksi) ?> kali</td>
                            <td colspan="3" style="color:var(--text-muted); font-size:12px; font-weight:400;">
                                Selisih bersih (kg):
                                <strong style="color:<?= $selisih >= 0 ? 'var(--accent)' : 'var(--danger)' ?>;">
                                    <?= ($selisih >= 0 ? '+' : '') . number_format($selisih) ?>
                                </strong>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <p>Tidak ada data barang ditemukan.</p>
                </div>
                <?php endif; ?>
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

    function toggleDetail(idx) {
        const row  = document.getElementById('detail-' + idx);
        const icon = document.getElementById('icon-'   + idx);
        const open = row.classList.toggle('open');
        icon.style.transform = open ? 'rotate(180deg)' : 'rotate(0deg)';
        icon.style.transition = 'transform 0.2s';
    }
</script>
</body>
</html>