<?php
session_start();
require_once '../includes/auth.php';
cekLogin();
cekRole('admin');
require_once '../includes/functions.php';

global $conn;

// Format angka tanpa desimal yang tidak perlu
function fmtQty($val) {
    return rtrim(rtrim(number_format((float)$val, 2, ',', '.'), '0'), ',');
}

$barang_id    = isset($_GET['barang']) ? (int)$_GET['barang'] : 0;
$tgl_awal     = isset($_GET['tgl_awal'])  ? $_GET['tgl_awal']  : date('Y-m-01');
$tgl_akhir    = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-d');
$filter_jenis = isset($_GET['jenis'])     ? $_GET['jenis']      : '';

if ($tgl_awal > $tgl_akhir) { $tmp = $tgl_awal; $tgl_awal = $tgl_akhir; $tgl_akhir = $tmp; }

$mutasi        = [];
$all_mutasi    = [];
$nama_barang   = '';
$stok_akhir    = 0;
$total_masuk   = 0;
$total_keluar  = 0;
$total_rusak   = 0;
$total_koreksi = 0;
$cnt_masuk     = 0;
$cnt_keluar    = 0;
$cnt_rusak     = 0;
$cnt_koreksi   = 0;

// Variabel untuk info stok utama (karung)
$satuan_besar  = '';
$stok_besar    = 0;
$sisa_karung_kg = 0;
$satuan_kecil  = '';
$stok_current  = 0;

if ($barang_id > 0) {
    // Ambil data barang termasuk stok_besar, satuan_besar, sisa_karung_kg
    $stmt = $conn->prepare("
        SELECT 
            nama_barang, 
            stok, 
            stok_besar, 
            sisa_karung_kg, 
            satuan, 
            satuan_besar 
        FROM barang 
        WHERE id_barang = ?
    ");
    $stmt->bind_param("i", $barang_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows) {
        $row_barang = $res->fetch_assoc();
        $nama_barang    = $row_barang['nama_barang'];
        $satuan_besar   = $row_barang['satuan_besar'] ?? '';
        $stok_besar     = (int)$row_barang['stok_besar'];
        $sisa_karung_kg = (float)$row_barang['sisa_karung_kg'];
        $satuan_kecil   = $row_barang['satuan'];
        $stok_current   = (int)$row_barang['stok'];
    }
    $stmt->close();

    // ========== 1. Barang Masuk (transaksi_masuk) ==========
    $sql_masuk = "SELECT 
                    m.tanggal, 
                    'MASUK' as jenis, 
                    i.qty, 
                    NULL as stok_sebelum, 
                    NULL as stok_sesudah, 
                    CONCAT('Barang masuk dari faktur ', m.nomor_faktur) as keterangan
                  FROM transaksi_masuk m
                  JOIN transaksi_masuk_item i ON m.id = i.transaksi_masuk_id
                  WHERE i.barang_id = ? AND m.tanggal BETWEEN ? AND ?";
    $stmt_masuk = $conn->prepare($sql_masuk);
    $stmt_masuk->bind_param("iss", $barang_id, $tgl_awal, $tgl_akhir);
    $stmt_masuk->execute();
    $res_masuk = $stmt_masuk->get_result();
    while ($row = $res_masuk->fetch_assoc()) {
        $all_mutasi[] = $row;
    }

    // ========== 2. Barang Keluar (barang_keluar) ==========
    $sql_keluar = "SELECT 
                    tanggal, 
                    'KELUAR' as jenis, 
                    qty, 
                    NULL as stok_sebelum, 
                    NULL as stok_sesudah, 
                    CONCAT('Barang keluar untuk permintaan ', id_permintaan) as keterangan
                  FROM barang_keluar
                  WHERE barang_id = ? AND DATE(tanggal) BETWEEN ? AND ?";
    $stmt_keluar = $conn->prepare($sql_keluar);
    $stmt_keluar->bind_param("iss", $barang_id, $tgl_awal, $tgl_akhir);
    $stmt_keluar->execute();
    $res_keluar = $stmt_keluar->get_result();
    while ($row = $res_keluar->fetch_assoc()) {
        $all_mutasi[] = $row;
    }

    // ========== 3. Barang Rusak (pengajuan_rusak status Disetujui) ==========
    $sql_rusak = "SELECT 
                    DATE(created_at) as tanggal, 
                    'RUSAK' as jenis, 
                    jumlah as qty, 
                    NULL as stok_sebelum, 
                    NULL as stok_sesudah, 
                    CONCAT('Barang rusak - ', aksi, IF(keterangan!='', CONCAT(': ', keterangan), '')) as keterangan
                  FROM pengajuan_rusak
                  WHERE barang_id = ? AND status = 'Disetujui' AND DATE(created_at) BETWEEN ? AND ?";
    $stmt_rusak = $conn->prepare($sql_rusak);
    $stmt_rusak->bind_param("iss", $barang_id, $tgl_awal, $tgl_akhir);
    $stmt_rusak->execute();
    $res_rusak = $stmt_rusak->get_result();
    while ($row = $res_rusak->fetch_assoc()) {
        $all_mutasi[] = $row;
    }

    // ========== 4. Koreksi Stok (stock_opname status Disetujui) ==========
    $sql_koreksi = "SELECT 
                    o.tanggal, 
                    'KOREKSI' as jenis, 
                    i.selisih as qty, 
                    i.stok_sistem as stok_sebelum, 
                    i.stok_fisik as stok_sesudah, 
                    CONCAT('Koreksi stok: ', o.keterangan) as keterangan
                  FROM stock_opname o
                  JOIN stock_opname_item i ON o.id = i.opname_id
                  WHERE i.barang_id = ? AND o.status = 'Disetujui' AND o.tanggal BETWEEN ? AND ?";
    $stmt_koreksi = $conn->prepare($sql_koreksi);
    $stmt_koreksi->bind_param("iss", $barang_id, $tgl_awal, $tgl_akhir);
    $stmt_koreksi->execute();
    $res_koreksi = $stmt_koreksi->get_result();
    while ($row = $res_koreksi->fetch_assoc()) {
        $all_mutasi[] = $row;
    }

    // Urutkan semua mutasi berdasarkan tanggal (ascending)
    usort($all_mutasi, function($a, $b) {
        return strtotime($a['tanggal']) - strtotime($b['tanggal']);
    });

    // ========== HITUNG STOK AWAL PERIODE (dalam kg) ==========
    $stok_saat_ini = getStokBarang($barang_id); // stok dalam kg (stok biasa)
    $sekarang = date('Y-m-d');
    $net_perubahan = 0;
    
    $q_net = $conn->prepare("SELECT COALESCE(SUM(i.qty),0) as total FROM transaksi_masuk m JOIN transaksi_masuk_item i ON m.id = i.transaksi_masuk_id WHERE i.barang_id = ? AND m.tanggal >= ? AND m.tanggal <= ?");
    $q_net->bind_param("iss", $barang_id, $tgl_awal, $sekarang);
    $q_net->execute();
    $net_perubahan += (int)$q_net->get_result()->fetch_assoc()['total'];
    
    $q_net = $conn->prepare("SELECT COALESCE(SUM(qty),0) as total FROM barang_keluar WHERE barang_id = ? AND DATE(tanggal) >= ? AND DATE(tanggal) <= ?");
    $q_net->bind_param("iss", $barang_id, $tgl_awal, $sekarang);
    $q_net->execute();
    $net_perubahan -= (int)$q_net->get_result()->fetch_assoc()['total'];
    
    $q_net = $conn->prepare("SELECT COALESCE(SUM(jumlah),0) as total FROM pengajuan_rusak WHERE barang_id = ? AND status = 'Disetujui' AND DATE(created_at) >= ? AND DATE(created_at) <= ?");
    $q_net->bind_param("iss", $barang_id, $tgl_awal, $sekarang);
    $q_net->execute();
    $net_perubahan -= (int)$q_net->get_result()->fetch_assoc()['total'];
    
    $q_net = $conn->prepare("SELECT COALESCE(SUM(i.selisih),0) as total FROM stock_opname o JOIN stock_opname_item i ON o.id = i.opname_id WHERE i.barang_id = ? AND o.status = 'Disetujui' AND o.tanggal >= ? AND o.tanggal <= ?");
    $q_net->bind_param("iss", $barang_id, $tgl_awal, $sekarang);
    $q_net->execute();
    $net_perubahan += (int)$q_net->get_result()->fetch_assoc()['total'];
    
    $stok_awal_periode = max(0, $stok_saat_ini - $net_perubahan);
    
    // ========== HITUNG RUNNING STOK (dalam kg) ==========
    $running_stok = $stok_awal_periode;
    foreach ($all_mutasi as &$m) {
        $m['stok_sebelum'] = $running_stok;
        $jenis = $m['jenis'];
        $qty = (int)$m['qty'];
        if ($jenis == 'MASUK') {
            $running_stok += $qty;
        } elseif ($jenis == 'KELUAR' || $jenis == 'RUSAK') {
            $running_stok -= $qty;
        } elseif ($jenis == 'KOREKSI') {
            $running_stok += $qty;
        }
        $m['stok_sesudah'] = $running_stok;
    }
    unset($m);
    
    // Hitung summary
    foreach ($all_mutasi as $m) {
        $jenis = $m['jenis'];
        $qty = (int)$m['qty'];
        if ($jenis === 'MASUK') {
            $total_masuk += $qty;
            $cnt_masuk++;
        } elseif ($jenis === 'KELUAR') {
            $total_keluar += $qty;
            $cnt_keluar++;
        } elseif ($jenis === 'RUSAK') {
            $total_rusak += $qty;
            $cnt_rusak++;
        } elseif ($jenis === 'KOREKSI') {
            $total_koreksi++;
            $cnt_koreksi++;
        }
    }
    
    // Stok akhir periode (kg)
    if (!empty($all_mutasi)) {
        $stok_akhir = end($all_mutasi)['stok_sesudah'];
    } else {
        $stok_akhir = $stok_awal_periode;
    }
    
    // Filter jenis untuk tampilan tabel
    if ($filter_jenis !== '') {
        $mutasi = array_values(array_filter($all_mutasi, fn($m) => $m['jenis'] === $filter_jenis));
    } else {
        $mutasi = $all_mutasi;
    }
}

$daftar_barang = $conn->query("
    SELECT id_barang, nama_barang, satuan_besar, satuan 
    FROM barang 
    WHERE status = 'aktif' 
    ORDER BY nama_barang
");

// ========== SIDEBAR ACTIVE STATE ==========
$current_file = basename($_SERVER['PHP_SELF']);
$open_master   = in_array($current_file, ['barang.php','master_kategori.php','master_supplier.php']);
$open_transaksi= in_array($current_file, ['preorder.php','barang_masuk.php','barang_keluar.php','barang_rusak.php','koreksi_stok.php']);
$open_monitor  = in_array($current_file, ['stok.php','stock_mutasi.php']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Stock Opname — Keva Jaya</title>
    <!-- CSS (sama seperti sebelumnya, tidak diubah) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ========== KEVA JAYA DESIGN SYSTEM (sama seperti owner) ========== */
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

        /* SIDEBAR (sama) */
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

        /* MAIN & TOPBAR */
        .main { margin-left: var(--sidebar-w); transition: margin-left 0.2s cubic-bezier(0.4,0,0.2,1); min-height: 100vh; display: flex; flex-direction: column; }
        .main.expanded { margin-left: 0; }
        .topbar { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0 24px; height: 56px; display: flex; align-items: center; gap: 16px; position: sticky; top: 0; z-index: 50; }
        .btn-toggle { width: 34px; height: 34px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--surface-2); color: var(--text-secondary); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: var(--transition); font-size: 16px; flex-shrink: 0; }
        .btn-toggle:hover { background: var(--border); color: var(--text-primary); }
        .breadcrumb-bar { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-muted); }
        .breadcrumb-bar a { color: inherit; text-decoration: none; }
        .breadcrumb-bar span { color: var(--text-secondary); font-weight: 500; }
        .breadcrumb-bar i { font-size: 11px; }
        .topbar-right { margin-left: auto; display: flex; align-items: center; gap: 10px; }

        /* PAGE BODY */
        .page-body { padding: 24px; flex: 1; }
        .page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
        .page-title { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; }
        .page-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 2px; }

        /* ALERT */
        .alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: var(--radius); font-size: 13.5px; margin-bottom: 20px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
        .alert-success { background: var(--accent-light); color: var(--accent); border: 1px solid #b7ddc9; }
        .alert-danger  { background: var(--danger-light); color: var(--danger); border: 1px solid #f5b7b1; }
        .alert-info    { background: var(--info-light); color: var(--info); border: 1px solid #b8d4e8; }
        .alert-secondary { background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border); }
        .alert i { font-size: 16px; flex-shrink: 0; }
        .alert-close { margin-left: auto; cursor: pointer; opacity: 0.6; }

        /* SUMMARY CARDS */
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .summary-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 18px; display: flex; align-items: center; gap: 14px; box-shadow: var(--shadow-sm); transition: var(--transition); }
        .summary-card:hover { box-shadow: var(--shadow); transform: translateY(-1px); }
        .summary-icon { width: 42px; height: 42px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 19px; flex-shrink: 0; }
        .summary-icon.masuk   { background: var(--accent-light); color: var(--accent); }
        .summary-icon.keluar  { background: var(--danger-light); color: var(--danger); }
        .summary-icon.rusak   { background: #fffbeb; color: #d97706; border: 1px solid #fed7aa; }
        .summary-icon.koreksi { background: var(--info-light); color: var(--info); }
        .summary-icon.all     { background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border); }
        .summary-icon.stok    { background: #f0f0ec; color: var(--text-secondary); border: 1px solid var(--border); }
        .summary-info small   { font-size: 11.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; display: block; }
        .summary-info h5      { font-size: 22px; font-weight: 700; margin: 2px 0 0; line-height: 1.2; }
        .summary-info span    { font-size: 11.5px; color: var(--text-muted); }

        /* CARD */
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
        .card-head { padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); background: var(--surface-2); }
        .card-head-title { font-size: 13.5px; font-weight: 600; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .card-head-title i { color: var(--accent); }

        /* FORM */
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-label { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); letter-spacing: 0.2px; }
        .form-control, .form-select { padding: 9px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13.5px; font-family: inherit; background: var(--surface); transition: var(--transition); width: 100%; }
        .form-control:focus, .form-select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }
        .btn-primary { display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; background: var(--accent); color: white; border: none; border-radius: var(--radius-sm); font-size: 13.5px; font-weight: 600; cursor: pointer; transition: var(--transition); }
        .btn-primary:hover { background: var(--accent-hover); transform: translateY(-1px); }
        .btn-outline { display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; background: transparent; color: var(--text-secondary); border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13.5px; font-weight: 600; cursor: pointer; transition: var(--transition); text-decoration: none; }
        .btn-outline:hover { background: var(--surface-2); border-color: var(--border-strong); }

        /* FILTER TABS */
        .filter-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
        .filter-tab { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 99px; font-size: 13px; font-weight: 550; border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary); cursor: pointer; transition: var(--transition); text-decoration: none; }
        .filter-tab:hover { background: var(--surface-2); border-color: var(--border-strong); }
        .filter-tab.active-all     { background: var(--text-primary); color: white; border-color: var(--text-primary); }
        .filter-tab.active-masuk   { background: var(--accent-light); color: var(--accent); border-color: var(--accent); }
        .filter-tab.active-keluar  { background: var(--danger-light); color: var(--danger); border-color: var(--danger); }
        .filter-tab.active-rusak   { background: #fffbeb; color: #d97706; border-color: #d97706; }
        .filter-tab.active-koreksi { background: var(--info-light); color: var(--info); border-color: var(--info); }

        /* TABLE */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead th { padding: 11px 16px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.4px; text-transform: uppercase; color: var(--text-muted); background: var(--surface-2); border-bottom: 1px solid var(--border); white-space: nowrap; }
        tbody td { padding: 13px 16px; border-bottom: 1px solid var(--border); color: var(--text-primary); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr { transition: background 0.15s; }
        tbody tr:hover { background: var(--surface-2); }

        /* BADGE */
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 550; line-height: 1.3; }
        .badge-masuk   { background: var(--accent-light); color: var(--accent); }
        .badge-keluar  { background: var(--danger-light); color: var(--danger); }
        .badge-rusak   { background: #fffbeb; color: #d97706; border: 1px solid #fed7aa; }
        .badge-koreksi { background: var(--info-light); color: var(--info); }
        .badge-gray    { background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border); }
        .badge-karung  { background: var(--warning-light); color: var(--warning); font-size: 10px; padding: 2px 8px; }
        .text-masuk   { color: var(--accent); font-weight: 600; }
        .text-keluar  { color: var(--danger); font-weight: 600; }
        .text-rusak   { color: #d97706; font-weight: 600; }
        .text-koreksi { color: var(--info); font-weight: 600; }

        /* STOK BAR */
        .stok-bar { padding: 12px 20px; background: var(--surface-2); border-top: 1px solid var(--border); display: flex; align-items: center; gap: 10px; font-size: 13.5px; flex-wrap: wrap; }
        .stok-bar strong { color: var(--text-primary); }
        .delta-positif { color: var(--accent); font-size: 11px; font-weight: 600; background: var(--accent-light); padding: 2px 7px; border-radius: 99px; }
        .delta-negatif { color: var(--danger); font-size: 11px; font-weight: 600; background: var(--danger-light); padding: 2px 7px; border-radius: 99px; }

        .row-count { font-size: 12px; color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border); padding: 3px 10px; border-radius: 99px; }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 42px; opacity: 0.4; display: block; margin-bottom: 12px; }

        @media (max-width: 768px) {
            .sidebar { left: calc(-1 * var(--sidebar-w)); }
            .sidebar.mobile-open { left: 0; }
            .main { margin-left: 0; }
            .form-grid { grid-template-columns: 1fr; }
            .summary-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<!-- SIDEBAR (sama seperti sebelumnya) -->
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
            <a href="dashboard.php">Dashboard</a>
            <i class="bi bi-chevron-right"></i>
            <span>Stock Opname (Mutasi Stok)</span>
        </div>
        <div class="topbar-right">
            <span style="font-size:12px;color:var(--text-muted);"><?= date('d M Y') ?></span>
        </div>
    </header>

    <div class="page-body">
        <?php if(isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?= $_SESSION['success']; unset($_SESSION['success']); ?><i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i></div>
        <?php endif; ?>
        <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?><i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i></div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <div class="page-title">Stock Opname (Mutasi Stok)</div>
                <div class="page-subtitle">Riwayat pergerakan stok per barang: Masuk, Keluar, Rusak, dan Koreksi</div>
            </div>
        </div>

        <!-- Form Filter -->
        <div class="card" style="margin-bottom:24px;">
            <div class="card-head">
                <div class="card-head-title"><i class="bi bi-funnel"></i> Filter Periode &amp; Barang</div>
            </div>
            <form method="GET" style="padding:20px;">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Pilih Barang</label>
                        <select name="barang" class="form-select" required>
                            <option value="">-- Pilih Barang --</option>
                            <?php while($b = $daftar_barang->fetch_assoc()): 
                                $satuan_tampil = !empty($b['satuan_besar']) ? $b['satuan_besar'] : $b['satuan'];
                            ?>
                            <option value="<?= $b['id_barang'] ?>" <?= $barang_id == $b['id_barang'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b['nama_barang']) ?> (<?= htmlspecialchars($satuan_tampil) ?>)
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Dari Tanggal</label>
                        <input type="date" name="tgl_awal" class="form-control" value="<?= $tgl_awal ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sampai Tanggal</label>
                        <input type="date" name="tgl_akhir" class="form-control" value="<?= $tgl_akhir ?>">
                    </div>
                    <input type="hidden" name="jenis" value="<?= htmlspecialchars($filter_jenis) ?>">
                    <div style="display:flex; align-items:flex-end;">
                        <button type="submit" class="btn-primary" style="width:100%;">
                            <i class="bi bi-search"></i> Tampilkan
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($barang_id > 0): ?>

            <?php
                $cnt_all   = $cnt_masuk + $cnt_keluar + $cnt_rusak + $cnt_koreksi;
                $q_all     = http_build_query(['barang'=>$barang_id,'tgl_awal'=>$tgl_awal,'tgl_akhir'=>$tgl_akhir,'jenis'=>'']);
                $q_masuk   = http_build_query(['barang'=>$barang_id,'tgl_awal'=>$tgl_awal,'tgl_akhir'=>$tgl_akhir,'jenis'=>'MASUK']);
                $q_keluar  = http_build_query(['barang'=>$barang_id,'tgl_awal'=>$tgl_awal,'tgl_akhir'=>$tgl_akhir,'jenis'=>'KELUAR']);
                $q_rusak   = http_build_query(['barang'=>$barang_id,'tgl_awal'=>$tgl_awal,'tgl_akhir'=>$tgl_akhir,'jenis'=>'RUSAK']);
                $q_koreksi = http_build_query(['barang'=>$barang_id,'tgl_awal'=>$tgl_awal,'tgl_akhir'=>$tgl_akhir,'jenis'=>'KOREKSI']);

                $is_olahan = !empty($satuan_besar);
            ?>

            <!-- SUMMARY CARDS -->
            <div class="summary-grid">
                <a href="?<?= $q_all ?>" class="summary-card" style="cursor:pointer; text-decoration:none;">
                    <div class="summary-icon all"><i class="bi bi-list-ul"></i></div>
                    <div class="summary-info">
                        <small>Semua Transaksi</small>
                        <h5><?= $cnt_all ?></h5>
                        <span>Total mutasi</span>
                    </div>
                </a>
                <a href="?<?= $q_masuk ?>" class="summary-card" style="cursor:pointer; text-decoration:none;">
                    <div class="summary-icon masuk"><i class="bi bi-arrow-down-circle"></i></div>
                    <div class="summary-info">
                        <small>Barang Masuk</small>
                        <h5 style="color:var(--accent);"><?= $total_masuk ?> kg</h5>
                        <span><?= $cnt_masuk ?> transaksi</span>
                    </div>
                </a>
                <a href="?<?= $q_keluar ?>" class="summary-card" style="cursor:pointer; text-decoration:none;">
                    <div class="summary-icon keluar"><i class="bi bi-arrow-up-circle"></i></div>
                    <div class="summary-info">
                        <small>Barang Keluar</small>
                        <h5 style="color:var(--danger);"><?= $total_keluar ?> kg</h5>
                        <span><?= $cnt_keluar ?> transaksi</span>
                    </div>
                </a>
                <a href="?<?= $q_rusak ?>" class="summary-card" style="cursor:pointer; text-decoration:none;">
                    <div class="summary-icon rusak"><i class="bi bi-slash-circle"></i></div>
                    <div class="summary-info">
                        <small>Barang Rusak</small>
                        <h5 style="color:#d97706;"><?= $total_rusak ?> kg</h5>
                        <span><?= $cnt_rusak ?> transaksi</span>
                    </div>
                </a>
                <a href="?<?= $q_koreksi ?>" class="summary-card" style="cursor:pointer; text-decoration:none;">
                    <div class="summary-icon koreksi"><i class="bi bi-pencil-square"></i></div>
                    <div class="summary-info">
                        <small>Koreksi Stok</small>
                        <h5 style="color:var(--info);"><?= $cnt_koreksi ?> kali</h5>
                        <span><?= $cnt_koreksi ?> transaksi</span>
                    </div>
                </a>
                <div class="summary-card" style="cursor:default;">
                    <div class="summary-icon stok"><i class="bi bi-boxes"></i></div>
                    <div class="summary-info">
                        <small>Stok Akhir Periode</small>
                        <h5><?= fmtQty($stok_akhir) ?> kg</h5>
                        <?php if ($is_olahan): ?>
                            <span>
                                <?= fmtQty($stok_besar) ?> karung utuh
                                <?php if ($sisa_karung_kg > 0): ?>
                                    + <?= fmtQty($sisa_karung_kg) ?> kg sisa terbuka
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($mutasi)): ?>

                <!-- Filter Tabs -->
                <div class="filter-tabs">
                    <a href="?<?= $q_all ?>" class="filter-tab <?= $filter_jenis==='' ? 'active-all' : '' ?>">
                        <i class="bi bi-list-ul"></i> Semua
                        <span style="opacity:0.7;">(<?= $cnt_all ?>)</span>
                    </a>
                    <a href="?<?= $q_masuk ?>" class="filter-tab <?= $filter_jenis==='MASUK' ? 'active-masuk' : '' ?>">
                        <i class="bi bi-arrow-down-circle"></i> Masuk
                        <span style="opacity:0.7;">(<?= $cnt_masuk ?>)</span>
                    </a>
                    <a href="?<?= $q_keluar ?>" class="filter-tab <?= $filter_jenis==='KELUAR' ? 'active-keluar' : '' ?>">
                        <i class="bi bi-arrow-up-circle"></i> Keluar
                        <span style="opacity:0.7;">(<?= $cnt_keluar ?>)</span>
                    </a>
                    <a href="?<?= $q_rusak ?>" class="filter-tab <?= $filter_jenis==='RUSAK' ? 'active-rusak' : '' ?>">
                        <i class="bi bi-slash-circle"></i> Rusak
                        <span style="opacity:0.7;">(<?= $cnt_rusak ?>)</span>
                    </a>
                    <a href="?<?= $q_koreksi ?>" class="filter-tab <?= $filter_jenis==='KOREKSI' ? 'active-koreksi' : '' ?>">
                        <i class="bi bi-pencil-square"></i> Koreksi
                        <span style="opacity:0.7;">(<?= $cnt_koreksi ?>)</span>
                    </a>
                </div>

                <div class="card">
                    <div class="card-head">
                        <div class="card-head-title">
                            <i class="bi bi-box"></i>
                            Riwayat Mutasi:
                            <strong><?= htmlspecialchars($nama_barang) ?></strong>
                            <?php if ($is_olahan): ?>
                                <span class="badge badge-karung"><i class="bi bi-box"></i> Karung</span>
                            <?php endif; ?>
                            <?php if ($filter_jenis !== ''): ?>
                                <span class="badge <?= strtolower($filter_jenis)==='masuk' ? 'badge-masuk' : (strtolower($filter_jenis)==='keluar' ? 'badge-keluar' : (strtolower($filter_jenis)==='rusak' ? 'badge-rusak' : 'badge-koreksi')) ?>" style="font-size:11px;">
                                    Filter: <?= $filter_jenis ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <span class="row-count"><?= count($mutasi) ?> transaksi</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Jenis</th>
                                    <th>Jumlah</th>
                                    <th>Stok Sebelum (kg)</th>
                                    <th>Stok Sesudah (kg)</th>
                                    <th>Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($mutasi as $m):
                                    $jenis = $m['jenis'];
                                    if ($jenis === 'MASUK') {
                                        $badge = 'badge-masuk';
                                        $icon  = '<i class="bi bi-arrow-down-circle"></i>';
                                        $class = 'text-masuk';
                                        $sign  = '+';
                                    } elseif ($jenis === 'KELUAR') {
                                        $badge = 'badge-keluar';
                                        $icon  = '<i class="bi bi-arrow-up-circle"></i>';
                                        $class = 'text-keluar';
                                        $sign  = '-';
                                    } elseif ($jenis === 'RUSAK') {
                                        $badge = 'badge-rusak';
                                        $icon  = '<i class="bi bi-slash-circle"></i>';
                                        $class = 'text-rusak';
                                        $sign  = '-';
                                    } else {
                                        $badge = 'badge-koreksi';
                                        $icon  = '<i class="bi bi-pencil-square"></i>';
                                        $class = 'text-koreksi';
                                        $sign  = '±';
                                    }
                                    $delta = (int)$m['stok_sesudah'] - (int)$m['stok_sebelum'];
                                    $deltaClass = $delta >= 0 ? 'delta-positif' : 'delta-negatif';
                                    $deltaSign  = $delta >= 0 ? '+' : '';
                                ?>
                                <tr>
                                    <td style="white-space:nowrap;color:var(--text-secondary);">
                                        <?= date('d/m/Y', strtotime($m['tanggal'])) ?>
                                        <div style="font-size:11px;color:var(--text-muted);"><?= date('H:i', strtotime($m['tanggal'])) ?></div>
                                    </td>
                                    <td><span class="badge <?= $badge ?>"><?= $icon ?> <?= $jenis ?></span></td>
                                    <td class="<?= $class ?>"><?= $sign . (int)$m['qty'] ?> kg</td>
                                    <td style="color:var(--text-secondary);"><?= (int)$m['stok_sebelum'] ?> kg</td>
                                    <td>
                                        <span style="font-weight:700;"><?= (int)$m['stok_sesudah'] ?> kg</span>
                                        <span class="<?= $deltaClass ?>" style="margin-left:6px;"><?= $deltaSign . $delta ?></span>
                                    </td>
                                    <td style="color:var(--text-secondary);max-width:260px;"><?= htmlspecialchars($m['keterangan'] ?? '-') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="stok-bar">
                        <i class="bi bi-boxes" style="color:var(--text-muted);"></i>
                        <strong>Stok Akhir Periode:</strong>
                        <span style="font-size:15px;font-weight:700;margin-left:4px;"><?= fmtQty($stok_akhir) ?> kg</span>
                        <?php if ($is_olahan): ?>
                            <span style="margin-left:8px;font-size:12px;color:var(--text-muted);">
                                (<?= fmtQty($stok_besar) ?> karung utuh 
                                <?php if ($sisa_karung_kg > 0): ?>
                                    + <?= fmtQty($sisa_karung_kg) ?> kg sisa terbuka
                                <?php endif; ?>)
                            </span>
                        <?php endif; ?>
                        <?php if ($filter_jenis !== ''): ?>
                            <span style="margin-left:8px;font-size:12px;color:var(--text-muted);">
                                — menampilkan <?= count($mutasi) ?> dari <?= $cnt_all ?> total transaksi
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($barang_id > 0 && empty($all_mutasi)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    Tidak ada riwayat mutasi stok untuk <strong><?= htmlspecialchars($nama_barang) ?></strong> pada periode ini.
                    Mutasi dicatat otomatis saat ada transaksi barang masuk, keluar, rusak, atau koreksi stok disetujui.
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    Tidak ada transaksi <strong><?= htmlspecialchars($filter_jenis) ?></strong> untuk barang ini pada periode tersebut.
                    <a href="?<?= $q_all ?>" style="color:inherit;font-weight:600;margin-left:6px;">Lihat semua transaksi →</a>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="alert alert-secondary">
                <i class="bi bi-search"></i> Silakan pilih barang dan periode untuk melihat riwayat mutasi stok.
            </div>
        <?php endif; ?>
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
        const el = document.getElementById(id);
        const isOpen = el.style.display !== 'none';
        el.style.display = isOpen ? 'none' : 'block';
        btn.setAttribute('aria-expanded', !isOpen);
    }
</script>
</body>
</html>