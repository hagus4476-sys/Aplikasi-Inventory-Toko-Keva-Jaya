<?php
require_once '../includes/auth.php';
cekLogin();
cekRole('admin');
require_once '../includes/functions.php';
date_default_timezone_set('Asia/Jakarta');

global $conn;

// ================= PROSES TAMBAH (DIPERBAIKI) =================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['tambah'])) {
    $kategori = trim($_POST['kategori']) ?: null;
    $nama = trim($_POST['nama_barang']);
    $satuan = trim($_POST['satuan']);
    $satuan_besar = trim($_POST['satuan_besar'] ?? '') ?: null;
    $min_stok = (int)$_POST['min_stok'];
    $supplier_id = (int)$_POST['supplier_id'];
    $tanggal_kadaluarsa = !empty($_POST['tanggal_kadaluarsa']) ? $_POST['tanggal_kadaluarsa'] : null;

    // Catatan: min_stok sekarang diinterpretasikan dalam satuan_besar (mis. karung)
    // jika barang punya satuan_besar, atau dalam satuan (kg) jika tidak. Ini murni
    // interpretasi tampilan/pemakaian di halaman Barang & Stok, tidak butuh kolom baru.

    // GENERATE KODE DI SERVER BERDASARKAN KATEGORI
    $kode = generateKodeBarang($kategori);

    // Cegah duplikasi (jaga-jaga race condition)
    $max_attempt = 3;
    for ($i = 0; $i < $max_attempt; $i++) {
        $cek = $conn->prepare("SELECT id_barang FROM barang WHERE kode_barang = ?");
        $cek->bind_param("s", $kode);
        $cek->execute();
        if ($cek->get_result()->num_rows == 0) break;
        $kode = generateKodeBarang($kategori);
    }

    // Jika masih bentrok setelah retry, beri error
    $cek = $conn->prepare("SELECT id_barang FROM barang WHERE kode_barang = ?");
    $cek->bind_param("s", $kode);
    $cek->execute();
    if ($cek->get_result()->num_rows > 0) {
        $_SESSION['error'] = "Gagal generate kode unik. Silakan coba lagi.";
        header("Location: barang.php");
        exit;
    }

    // CEK DUPLIKASI NAMA BARANG (baru)
    $cekNama = $conn->prepare("SELECT id_barang FROM barang WHERE LOWER(nama_barang) = LOWER(?)");
    $cekNama->bind_param("s", $nama);
    $cekNama->execute();
    if ($cekNama->get_result()->num_rows > 0) {
        $_SESSION['error'] = "Barang sudah terdaftar!";
        header("Location: barang.php");
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO barang (kode_barang, nama_barang, kategori, satuan, satuan_besar, min_stok, supplier_id, tanggal_kadaluarsa) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssiis", $kode, $nama, $kategori, $satuan, $satuan_besar, $min_stok, $supplier_id, $tanggal_kadaluarsa);
    $stmt->execute();
    $new_id = $conn->insert_id;
    $sup = $conn->query("SELECT nama FROM supplier WHERE id = $supplier_id")->fetch_assoc();
    catatLog($_SESSION['username'], 'Tambah', 'barang', $new_id, "Kode: $kode, Nama: $nama, Supplier: {$sup['nama']}");
    $_SESSION['success'] = "Barang berhasil ditambahkan dengan kode $kode.";
    header("Location: barang.php");
    exit;
}

// ================= PROSES EDIT (DIPERBAIKI) =================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit'])) {
    $id = (int)$_POST['id_barang'];
    $kode = trim($_POST['kode_barang']);
    $nama = trim($_POST['nama_barang']);
    $kategori = trim($_POST['kategori']) ?: null;
    $satuan = trim($_POST['satuan']);
    $satuan_besar = trim($_POST['satuan_besar'] ?? '') ?: null;
    $min_stok = (int)$_POST['min_stok'];
    $supplier_id = (int)$_POST['supplier_id'];
    $tanggal_kadaluarsa = !empty($_POST['tanggal_kadaluarsa']) ? $_POST['tanggal_kadaluarsa'] : null;

    // Cek duplikasi kode (tidak berubah)
    $cek = $conn->prepare("SELECT id_barang FROM barang WHERE kode_barang = ? AND id_barang != ?");
    $cek->bind_param("si", $kode, $id);
    $cek->execute();
    if ($cek->get_result()->num_rows > 0) {
        $_SESSION['error'] = "Kode barang sudah digunakan oleh barang lain!";
        header("Location: barang.php");
        exit;
    }

    // CEK DUPLIKASI NAMA BARANG (baru, exclude id sendiri)
    $cekNama = $conn->prepare("
        SELECT id_barang
        FROM barang
        WHERE LOWER(nama_barang) = LOWER(?)
        AND id_barang != ?
    ");
    $cekNama->bind_param("si", $nama, $id);
    $cekNama->execute();
    if ($cekNama->get_result()->num_rows > 0) {
        $_SESSION['error'] = "Nama barang sudah digunakan!";
        header("Location: barang.php");
        exit;
    }

    $stmt = $conn->prepare("UPDATE barang SET kode_barang=?, nama_barang=?, kategori=?, satuan=?, satuan_besar=?, min_stok=?, supplier_id=?, tanggal_kadaluarsa=? WHERE id_barang=?");
    $stmt->bind_param("sssssiisi", $kode, $nama, $kategori, $satuan, $satuan_besar, $min_stok, $supplier_id, $tanggal_kadaluarsa, $id);
    $stmt->execute();
    $sup = $conn->query("SELECT nama FROM supplier WHERE id = $supplier_id")->fetch_assoc();
    catatLog($_SESSION['username'], 'Edit', 'barang', $id, "Kode: $kode, Nama: $nama, Supplier: {$sup['nama']}");
    $_SESSION['success'] = "Barang berhasil diperbarui.";
    header("Location: barang.php");
    exit;
}

// ================= PROSES HAPUS =================
if (isset($_GET['hapus'])) {
    $id = (int)$_GET['hapus'];
    $data = $conn->query("SELECT kode_barang, nama_barang FROM barang WHERE id_barang = $id")->fetch_assoc();
    $stmt = $conn->prepare("DELETE FROM barang WHERE id_barang = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    catatLog($_SESSION['username'], 'Hapus', 'barang', $id, "Kode: {$data['kode_barang']}, Nama: {$data['nama_barang']}");
    $_SESSION['success'] = "Barang berhasil dihapus.";
    header("Location: barang.php");
    exit;
}

// ================= FILTER & QUERY =================
$filter_kategori = isset($_GET['kategori']) ? $_GET['kategori'] : '';
if ($filter_kategori) {
    $stmt = $conn->prepare("SELECT b.*, s.nama as supplier_nama FROM barang b LEFT JOIN supplier s ON b.supplier_id = s.id WHERE b.kategori = ? ORDER BY b.nama_barang ASC");
    $stmt->bind_param("s", $filter_kategori);
    $stmt->execute();
    $barang = $stmt->get_result();
} else {
    $barang = $conn->query("SELECT b.*, s.nama as supplier_nama FROM barang b LEFT JOIN supplier s ON b.supplier_id = s.id ORDER BY b.nama_barang ASC");
}

$kategori_list = $conn->query("SELECT nama FROM kategori ORDER BY nama");
$supplier_list = $conn->query("SELECT id, nama FROM supplier ORDER BY nama");

// Id barang yang harus otomatis dibuka modal edit-nya (misal datang dari halaman Kadaluarsa)
$auto_edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;

function getKategoriOptionsText($selected = null) {
    global $conn;
    $result = $conn->query("SELECT nama FROM kategori ORDER BY nama");
    $options = '<option value="">— Pilih Kategori —</option>';
    while ($row = $result->fetch_assoc()) {
        $nama = htmlspecialchars($row['nama']);
        $selectedAttr = ($selected == $row['nama']) ? 'selected' : '';
        $options .= "<option value=\"$nama\" $selectedAttr>$nama</option>";
    }
    return $options;
}

// ========== SIDEBAR ACTIVE STATE ==========
$current_file = basename($_SERVER['PHP_SELF']);
$open_master   = in_array($current_file, ['barang.php','master_kategori.php','master_supplier.php']);
$open_transaksi= in_array($current_file, ['preorder.php','barang_masuk.php','barang_keluar.php','barang_rusak.php','koreksi_stok.php','olah_stok.php']);
$open_monitor  = in_array($current_file, ['stok.php','stock_mutasi.php']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Master Barang — Keva Jaya</title>
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
        .alert-success { background: var(--accent-light); color: var(--accent); border: 1px solid #b7ddc9; }
        .alert-danger  { background: var(--danger-light); color: var(--danger); border: 1px solid #f5b7b1; }
        .alert i { font-size: 16px; flex-shrink: 0; }
        .alert-close { margin-left: auto; cursor: pointer; opacity: 0.6; }
        .alert-close:hover { opacity: 1; }

        /* FILTER TABS */
        .filter-bar { display: flex; align-items: center; gap: 6px; margin-bottom: 16px; flex-wrap: wrap; }
        .filter-tab { display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 99px; font-size: 13px; font-weight: 500; text-decoration: none; border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary); transition: var(--transition); }
        .filter-tab:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-light); }
        .filter-tab.active { background: var(--text-primary); color: white; border-color: var(--text-primary); }

        /* CARD */
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
        .card-head { padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); background: var(--surface-2); }
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
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 550; }
        .badge-green { background: var(--accent-light); color: var(--accent); }
        .badge-blue { background: var(--info-light); color: var(--info); }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 36px; opacity: 0.4; display: block; margin-bottom: 10px; }
        .btn-action { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary); cursor: pointer; transition: var(--transition); text-decoration: none; font-size: 14px; }
        .btn-action:hover { background: var(--surface-2); border-color: var(--border-strong); color: var(--text-primary); }
        .btn-action.danger:hover { background: var(--danger-light); border-color: #f5b7b1; color: var(--danger); }
        .btn-primary { display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; background: var(--accent); color: white; border: none; border-radius: var(--radius-sm); font-size: 13.5px; font-weight: 600; font-family: inherit; cursor: pointer; transition: var(--transition); text-decoration: none; }
        .btn-primary:hover { background: var(--accent-hover); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(45,106,79,0.25); }

        /* MODAL */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); backdrop-filter: blur(4px); z-index: 200; display: none; align-items: center; justify-content: center; padding: 20px; animation: fadeIn 0.15s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .modal-overlay.show { display: flex; }
        .modal-box { background: var(--surface); border-radius: 14px; width: 100%; max-width: 560px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); animation: slideUp 0.2s cubic-bezier(0.34,1.56,0.64,1); max-height: 90vh; display: flex; flex-direction: column; }
        @keyframes slideUp { from { opacity:0; transform:translateY(20px) scale(0.98); } to { opacity:1; transform:translateY(0) scale(1); } }
        .modal-header { padding: 20px 24px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .modal-title { font-size: 16px; font-weight: 700; letter-spacing: -0.3px; }
        .modal-close { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius-sm); border: 1px solid var(--border); background: none; cursor: pointer; color: var(--text-muted); font-size: 16px; transition: var(--transition); }
        .modal-close:hover { background: var(--surface-2); color: var(--text-primary); }
        .modal-body { padding: 20px 24px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; flex-shrink: 0; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group.span-2 { grid-column: 1 / -1; }
        .form-label { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); letter-spacing: 0.2px; }
        .form-control { padding: 9px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13.5px; font-family: inherit; color: var(--text-primary); background: var(--surface); transition: var(--transition); width: 100%; }
        .form-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }
        .form-control:disabled, .form-control[readonly] { background: var(--surface-2); color: var(--text-muted); cursor: not-allowed; }
        .form-hint { font-size: 11.5px; color: var(--text-muted); }
        .input-group { display: flex; gap: 6px; }
        .btn-ghost { padding: 9px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-2); color: var(--text-secondary); cursor: pointer; font-size: 14px; transition: var(--transition); display: flex; align-items: center; white-space: nowrap; }
        .btn-ghost:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-light); }
        .btn-cancel { padding: 8px 16px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text-secondary); font-size: 13.5px; font-weight: 500; font-family: inherit; cursor: pointer; transition: var(--transition); }
        .btn-cancel:hover { background: var(--surface-2); }
        @media (max-width: 768px) { .sidebar { left: calc(-1 * var(--sidebar-w)); } .sidebar.mobile-open { left: 0; } .main { margin-left: 0; } .page-header { flex-direction: column; align-items: stretch; } .form-grid { grid-template-columns: 1fr; } }
        [data-tooltip] { position: relative; }
        [data-tooltip]:hover::after { content: attr(data-tooltip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: var(--text-primary); color: white; padding: 4px 10px; border-radius: 5px; font-size: 11.5px; white-space: nowrap; pointer-events: none; z-index: 99; }
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
            <span>Master Barang</span>
        </div>
        <div class="topbar-right">
            <span style="font-size:12px;color:var(--text-muted);"><?= date('d M Y') ?></span>
        </div>
    </header>

    <div class="page-body">
        <?php if(isset($_SESSION['success'])): ?>
        <div class="alert alert-success" id="alert-msg">
            <i class="bi bi-check-circle-fill"></i> <?= $_SESSION['success']; unset($_SESSION['success']); ?>
            <i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i>
        </div>
        <?php endif; ?>
        <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger" id="alert-msg">
            <i class="bi bi-exclamation-circle-fill"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?>
            <i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i>
        </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <div class="page-title">Master Barang</div>
                <div class="page-subtitle">Kelola data barang, kategori, dan supplier toko</div>
            </div>
            <button class="btn-primary" onclick="openModal('tambahModal')"><i class="bi bi-plus"></i> Tambah Barang</button>
        </div>

        <!-- FILTER TABS -->
        <div class="filter-bar">
            <a href="barang.php" class="filter-tab <?= !$filter_kategori ? 'active' : '' ?>">Semua</a>
            <?php 
            $kategori_list->data_seek(0);
            while($kat = $kategori_list->fetch_assoc()): 
            ?>
            <a href="?kategori=<?= urlencode($kat['nama']) ?>" class="filter-tab <?= $filter_kategori == $kat['nama'] ? 'active' : '' ?>">
                <?= htmlspecialchars($kat['nama']) ?>
            </a>
            <?php endwhile; ?>
        </div>

        <div class="card">
            <div class="card-head">
                <div class="card-head-title"><i class="bi bi-box"></i> Daftar Barang <span class="row-count" id="rowCount"></span></div>
                <div class="search-wrap"><i class="bi bi-search"></i><input type="text" class="search-input" id="searchInput" placeholder="Cari barang..."></div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Kode</th><th>Nama Barang</th><th>Kategori</th><th>Supplier</th><th>Satuan Jual</th><th>Satuan Beli</th><th>Min Stok</th><th>Kadaluarsa</th><th style="text-align:center;">Aksi</th></tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php if($barang->num_rows > 0): ?>
                            <?php while($row = $barang->fetch_assoc()): ?>
                            <tr>
                                <td><span class="kode-text"><?= htmlspecialchars($row['kode_barang']) ?></span></td>
                                <td style="font-weight:500;"><?= htmlspecialchars($row['nama_barang']) ?></td>
                                <td><span class="badge badge-blue"><?= htmlspecialchars($row['kategori'] ?? '—') ?></span></td>
                                <td><span class="badge badge-green"><?= htmlspecialchars($row['supplier_nama'] ?? '—') ?></span></td>
                                <td><?= htmlspecialchars($row['satuan']) ?></td>
                                <td><?= !empty($row['satuan_besar']) ? htmlspecialchars($row['satuan_besar']) : '<span style="color:var(--text-muted);">—</span>' ?></td>
                                <td>
                                    <?= (int)$row['min_stok'] ?>
                                    <?= htmlspecialchars(!empty($row['satuan_besar']) ? $row['satuan_besar'] : $row['satuan']) ?>
                                    <?php if (!empty($row['satuan_besar'])): ?>
                                        <span class="form-hint" style="display:block;">satuan besar</span>
                                    <?php endif; ?>
                                </td>
                                <td><span style="color:var(--text-secondary);"><?= !empty($row['tanggal_kadaluarsa']) ? date('d M Y', strtotime($row['tanggal_kadaluarsa'])) : '—' ?></span></td>
                                <td>
                                    <div style="display:flex;gap:6px;justify-content:center;">
                                        <button class="btn-action btn-edit" data-tooltip="Edit"
                                            data-id="<?= $row['id_barang'] ?>"
                                            data-kode="<?= htmlspecialchars($row['kode_barang']) ?>"
                                            data-nama="<?= htmlspecialchars($row['nama_barang']) ?>"
                                            data-kategori="<?= htmlspecialchars($row['kategori'] ?? '') ?>"
                                            data-satuan="<?= htmlspecialchars($row['satuan']) ?>"
                                            data-satuan-besar="<?= htmlspecialchars($row['satuan_besar'] ?? '') ?>"
                                            data-minstok="<?= (int)$row['min_stok'] ?>"
                                            data-supplier-id="<?= (int)$row['supplier_id'] ?>"
                                            data-tanggal="<?= htmlspecialchars($row['tanggal_kadaluarsa'] ?? '') ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <a href="?hapus=<?= $row['id_barang'] ?>" class="btn-action danger" data-tooltip="Hapus" onclick="return confirm('Yakin ingin menghapus barang ini?')"><i class="bi bi-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="9"><div class="empty-state"><i class="bi bi-inbox"></i><p>Belum ada data barang</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL TAMBAH BARANG -->
<div class="modal-overlay" id="tambahModal">
    <div class="modal-box">
        <form method="POST">
            <div class="modal-header"><div class="modal-title">Tambah Barang Baru</div><button type="button" class="modal-close" onclick="closeModal('tambahModal')"><i class="bi bi-x"></i></button></div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Kategori</label>
                        <div class="input-group">
                            <select name="kategori" id="tambah_kategori" class="form-control" required>
                                <?= getKategoriOptionsText() ?>
                            </select>
                            <button type="button" class="btn-ghost" onclick="openModal('kategoriModal')" title="Tambah kategori baru"><i class="bi bi-plus"></i></button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Kode Barang</label>
                        <input type="text" name="kode_barang" id="tambah_kode" class="form-control" value="" readonly placeholder="dibuat otomatis">
                        <span class="form-hint">Dibuat otomatis oleh sistem</span>
                    </div>
                    <div class="form-group span-2">
                        <label class="form-label">Nama Barang</label>
                        <input type="text" name="nama_barang" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" class="form-control" required>
                            <option value="">— Pilih Supplier —</option>
                            <?php $supplier_list->data_seek(0); while($sup = $supplier_list->fetch_assoc()): ?>
                            <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['nama']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Satuan Jual (Kecil)</label>
                        <input type="text" name="satuan" id="tambah_satuan" class="form-control" value="" required placeholder="">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Satuan Beli (Besar)</label>
                        <input type="text" name="satuan_besar" id="tambah_satuan_besar" class="form-control" placeholder="">
                        <span class="form-hint">Kosongkan jika barang tidak diolah/dipecah</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Minimum Stok <span id="tambah_minstok_unit_label" style="font-weight:400;color:var(--text-muted);"></span></label>
                        <input type="number" name="min_stok" id="tambah_minstok" class="form-control" value="" min="0" required>
                        <span class="form-hint" id="tambah_minstok_hint">Peringatan stok menipis (dalam satuan jual)</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal Kadaluarsa</label>
                        <input type="date" name="tanggal_kadaluarsa" class="form-control">
                        <span class="form-hint">Kosongkan jika tidak berlaku kadaluarsa</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('tambahModal')">Batal</button>
                <button type="submit" name="tambah" class="btn-primary"><i class="bi bi-check2"></i> Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDIT -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <form method="POST">
            <input type="hidden" name="id_barang" id="edit_id">
            <div class="modal-header"><div class="modal-title">Edit Barang</div><button type="button" class="modal-close" onclick="closeModal('editModal')"><i class="bi bi-x"></i></button></div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Kategori</label>
                        <div class="input-group">
                            <select name="kategori" id="edit_kategori" class="form-control" required>
                                <?= getKategoriOptionsText() ?>
                            </select>
                            <button type="button" class="btn-ghost" onclick="openModal('kategoriModal')" title="Tambah kategori"><i class="bi bi-plus"></i></button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Kode Barang</label>
                        <input type="text" name="kode_barang" id="edit_kode" class="form-control" readonly>
                        <span class="form-hint">Dibuat otomatis</span>
                    </div>
                    <div class="form-group span-2">
                        <label class="form-label">Nama Barang</label>
                        <input type="text" name="nama_barang" id="edit_nama" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" id="edit_supplier" class="form-control" required>
                            <option value="">— Pilih Supplier —</option>
                            <?php $supplier_list->data_seek(0); while($sup = $supplier_list->fetch_assoc()): ?>
                            <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['nama']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Satuan Jual (Kecil)</label>
                        <input type="text" name="satuan" id="edit_satuan" class="form-control" required placeholder="mis. Kg">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Satuan Beli (Besar)</label>
                        <input type="text" name="satuan_besar" id="edit_satuan_besar" class="form-control" placeholder="mis. Karung">
                        <span class="form-hint">Kosongkan jika barang tidak diolah/dipecah</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Minimum Stok <span id="edit_minstok_unit_label" style="font-weight:400;color:var(--text-muted);"></span></label>
                        <input type="number" name="min_stok" id="edit_minstok" class="form-control" required min="0">
                        <span class="form-hint" id="edit_minstok_hint">Peringatan stok menipis (dalam satuan jual)</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal Kadaluarsa</label>
                        <input type="date" name="tanggal_kadaluarsa" id="edit_tanggal" class="form-control">
                        <span class="form-hint">Kosongkan jika tidak berlaku kadaluarsa</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Batal</button>
                <button type="submit" name="edit" class="btn-primary"><i class="bi bi-check2"></i> Update</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL TAMBAH KATEGORI CEPAT -->
<div class="modal-overlay" id="kategoriModal">
    <div class="modal-box" style="max-width:380px;">
        <form method="POST" action="master_kategori.php" target="_blank">
            <div class="modal-header"><div class="modal-title">Tambah Kategori Baru</div><button type="button" class="modal-close" onclick="closeModal('kategoriModal')"><i class="bi bi-x"></i></button></div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Nama Kategori</label>
                    <input type="text" name="nama" class="form-control" placeholder="" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('kategoriModal')">Batal</button>
                <button type="submit" name="tambah" class="btn-primary"><i class="bi bi-check2"></i> Simpan & Tutup</button>
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
        const el = document.getElementById(id);
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

    // ===== Unit Minimum Stok mengikuti Satuan Besar (jika ada) =====
    // Jika barang punya Satuan Beli (Besar) mis. "Karung", maka Minimum Stok
    // diartikan dalam satuan besar tsb (mis. minimal 5 Karung), bukan kg.
    // Jika Satuan Besar kosong, Minimum Stok tetap memakai Satuan Jual (kg).
    function updateMinStokUnit(prefix) {
        const satuanEl      = document.getElementById(prefix + '_satuan');
        const satuanBesarEl = document.getElementById(prefix + '_satuan_besar');
        const labelEl       = document.getElementById(prefix + '_minstok_unit_label');
        const hintEl        = document.getElementById(prefix + '_minstok_hint');
        if (!satuanEl || !satuanBesarEl || !labelEl || !hintEl) return;
        const satuan = satuanEl.value.trim();
        const satuanBesar = satuanBesarEl.value.trim();
        if (satuanBesar) {
            labelEl.textContent = '(' + satuanBesar + ')';
            hintEl.textContent  = 'Peringatan stok menipis dihitung dari satuan besar (' + satuanBesar + '), bukan satuan jual';
        } else {
            labelEl.textContent = satuan ? '(' + satuan + ')' : '';
            hintEl.textContent  = 'Peringatan stok menipis (dalam satuan jual)';
        }
    }
    ['tambah_satuan', 'tambah_satuan_besar'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', () => updateMinStokUnit('tambah'));
    });
    ['edit_satuan', 'edit_satuan_besar'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', () => updateMinStokUnit('edit'));
    });
    updateMinStokUnit('tambah');
    updateMinStokUnit('edit');

    document.querySelectorAll('.btn-edit').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit_id').value       = this.dataset.id;
            document.getElementById('edit_kode').value     = this.dataset.kode;
            document.getElementById('edit_nama').value     = this.dataset.nama;
            document.getElementById('edit_kategori').value = this.dataset.kategori;
            document.getElementById('edit_satuan').value   = this.dataset.satuan;
            document.getElementById('edit_satuan_besar').value = this.dataset.satuanBesar;
            document.getElementById('edit_minstok').value  = this.dataset.minstok;
            document.getElementById('edit_supplier').value = this.dataset.supplierId;
            document.getElementById('edit_tanggal').value  = this.dataset.tanggal;
            updateMinStokUnit('edit');
            openModal('editModal');
        });
    });

    const searchInput = document.getElementById('searchInput');
    const tableBody   = document.getElementById('tableBody');
    const rowCountEl  = document.getElementById('rowCount');
    function updateRowCount() {
        const visible = tableBody.querySelectorAll('tr:not([style*="display: none"])').length;
        const total   = tableBody.querySelectorAll('tr').length;
        rowCountEl.textContent = visible === total ? `${total} item` : `${visible} / ${total} item`;
    }
    searchInput.addEventListener('input', function() {
        const q = this.value.toLowerCase().trim();
        tableBody.querySelectorAll('tr').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
        updateRowCount();
    });
    updateRowCount();
    const alertEl = document.getElementById('alert-msg');
    if (alertEl) setTimeout(() => alertEl.remove(), 4500);

    // Auto-buka modal edit jika datang dari halaman lain (mis. Kadaluarsa) dengan ?edit_id=
    <?php if ($auto_edit_id > 0): ?>
    (function() {
        const targetBtn = document.querySelector('.btn-edit[data-id="<?= $auto_edit_id ?>"]');
        if (targetBtn) targetBtn.click();
    })();
    <?php endif; ?>
</script>
</body>
</html>