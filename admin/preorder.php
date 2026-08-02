<?php
require_once '../includes/auth.php';
cekLogin();
cekRole('admin');
require_once '../includes/functions.php';

global $conn;

// ========== PROSES SIMPAN (TAMBAH / EDIT) DENGAN TRANSACTION ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan'])) {
    $id = (int)($_POST['id'] ?? 0);
    $supplier_id = (int)$_POST['supplier_id'];
    $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
    $catatan = trim($_POST['catatan']);
    $status = $_POST['status'] ?? 'pending';
    $items = json_decode($_POST['items_json'], true);

    if (empty($items)) { $_SESSION['error'] = "Tidak ada barang yang ditambahkan."; header("Location: preorder.php"); exit; }
    if ($supplier_id <= 0) { $_SESSION['error'] = "Supplier harus dipilih."; header("Location: preorder.php"); exit; }

    foreach ($items as $item) {
        $cek = $conn->prepare("SELECT id_barang FROM barang WHERE id_barang = ?");
        $cek->bind_param("i", $item['barang_id']); $cek->execute();
        if ($cek->get_result()->num_rows == 0) { $_SESSION['error'] = "Barang dengan ID {$item['barang_id']} tidak ditemukan!"; header("Location: preorder.php"); exit; }
    }

    $total_item = 0;
    foreach ($items as $item) { $total_item += $item['qty']; }
    $username = $_SESSION['username'] ?? 'admin';
    
    // ===== PERBAIKAN 1: created_by = ID user (integer), bukan username =====
    $created_by = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
    if (!$created_by) {
        // fallback: ambil dari database berdasarkan username
        $u = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $u->bind_param("s", $username);
        $u->execute();
        $u_res = $u->get_result();
        if ($u_res->num_rows) {
            $created_by = $u_res->fetch_assoc()['id'];
        } else {
            // jika tidak ada, biarkan NULL (tapi FK akan error kalau NULL tidak diizinkan)
            $_SESSION['error'] = "User tidak ditemukan di database.";
            header("Location: preorder.php"); exit;
        }
    }

    $conn->begin_transaction();
    try {
        if ($id > 0) {
            $po = getPreOrderById($id);
            if (!$po || $po['status'] != 'pending') throw new Exception("Pre Order sudah diproses, tidak bisa diedit!");
            $stmtDel = $conn->prepare("DELETE FROM pre_order_item WHERE pre_order_id = ?");
            $stmtDel->bind_param("i", $id); $stmtDel->execute();
            $stmt = $conn->prepare("UPDATE pre_order SET tanggal=?, supplier_id=?, status=?, total_item=?, catatan=? WHERE id=?");
            $stmt->bind_param("sisisi", $tanggal, $supplier_id, $status, $total_item, $catatan, $id); $stmt->execute();
            catatLog($username, 'Edit', 'pre_order', $id, "Supplier ID: $supplier_id");
        } else {
            $stmt = $conn->prepare("INSERT INTO pre_order (tanggal, supplier_id, status, total_item, catatan, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            // parameter: string, integer, string, integer, string, integer (created_by)
            $stmt->bind_param("sisisi", $tanggal, $supplier_id, $status, $total_item, $catatan, $created_by);
            $stmt->execute();
            $id = $conn->insert_id;
            catatLog($username, 'Tambah', 'pre_order', $id, "Supplier ID: $supplier_id");
        }
        $stmt_item = $conn->prepare("INSERT INTO pre_order_item (pre_order_id, barang_id, qty) VALUES (?, ?, ?)");
        foreach ($items as $item) { $stmt_item->bind_param("iii", $id, $item['barang_id'], $item['qty']); $stmt_item->execute(); }
        $conn->commit();
        $_SESSION['success'] = $id > 0 ? "Pre Order berhasil diperbarui." : "Pre Order berhasil ditambahkan.";
    } catch (Exception $e) { $conn->rollback(); $_SESSION['error'] = "Gagal menyimpan data: " . $e->getMessage(); }
    header("Location: preorder.php"); exit;
}

if (isset($_GET['hapus'])) {
    $id = (int)$_GET['hapus'];
    $po = getPreOrderById($id);
    if (!$po || $po['status'] != 'pending') { $_SESSION['error'] = "Pre Order sudah diproses, tidak bisa dihapus!"; }
    else {
        $stmt = $conn->prepare("DELETE FROM pre_order_item WHERE pre_order_id = ?"); $stmt->bind_param("i", $id); $stmt->execute();
        $stmt = $conn->prepare("DELETE FROM pre_order WHERE id = ?"); $stmt->bind_param("i", $id); $stmt->execute();
        catatLog($_SESSION['username'], 'Hapus', 'pre_order', $id, "Pre Order ID: $id");
        $_SESSION['success'] = "Pre Order berhasil dihapus.";
    }
    header("Location: preorder.php"); exit;
}

if (isset($_GET['get_data'])) {
    $id = (int)$_GET['get_data'];
    $stmt = $conn->prepare("SELECT po.*, s.nama as supplier_nama FROM pre_order po LEFT JOIN supplier s ON po.supplier_id = s.id WHERE po.id = ?");
    $stmt->bind_param("i", $id); $stmt->execute();
    $transaksi = $stmt->get_result()->fetch_assoc();
    if (!$transaksi) { echo json_encode(['error' => 'Data tidak ditemukan']); exit; }
    $items = [];
    $stmt_item = $conn->prepare("SELECT i.*, b.nama_barang FROM pre_order_item i JOIN barang b ON i.barang_id = b.id_barang WHERE i.pre_order_id = ?");
    $stmt_item->bind_param("i", $id); $stmt_item->execute();
    $res_item = $stmt_item->get_result();
    while ($row = $res_item->fetch_assoc()) { $items[] = ['barang_id' => $row['barang_id'], 'nama' => $row['nama_barang'], 'qty' => $row['qty']]; }
    echo json_encode(['id' => $transaksi['id'], 'tanggal' => $transaksi['tanggal'], 'supplier_id' => $transaksi['supplier_id'], 'supplier_nama' => $transaksi['supplier_nama'], 'status' => $transaksi['status'], 'catatan' => $transaksi['catatan'], 'items' => $items]);
    exit;
}

$status_filter = isset($_GET['filter']) ? $_GET['filter'] : 'semua';
$allowed_filters = ['semua', 'pending', 'disetujui', 'ditolak'];
if (!in_array($status_filter, $allowed_filters)) $status_filter = 'semua';
$where = ($status_filter != 'semua') ? "WHERE po.status = ?" : "";
$sql = "SELECT po.*, s.nama as supplier_nama FROM pre_order po LEFT JOIN supplier s ON po.supplier_id = s.id $where ORDER BY po.tanggal DESC";
if ($status_filter != 'semua') { $stmt = $conn->prepare($sql); $stmt->bind_param("s", $status_filter); $stmt->execute(); $list = $stmt->get_result(); }
else { $list = $conn->query($sql); }

$barang_list = $conn->query("SELECT id_barang, nama_barang FROM barang ORDER BY nama_barang");
$supplier_list = $conn->query("SELECT id, nama FROM supplier ORDER BY nama");

// ========== SIDEBAR ACTIVE STATE (SAMA DENGAN DASHBOARD) ==========
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
    <title>Pre Order — Keva Jaya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ========== STYLE LENGKAP (TIDAK DIUBAH) ========== */
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

        /* SIDEBAR (VERSI DASHBOARD) */
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

        /* ===== DI BAWAH INI CSS ASLI DARI PREORDER (TIDAK DIUBAH) ===== */
        .page-body { padding: 24px; flex: 1; }
        .page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
        .page-title { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; }
        .page-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
        .alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: var(--radius); font-size: 13.5px; margin-bottom: 20px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
        .alert-success { background: var(--accent-light); color: var(--accent); border: 1px solid #b7ddc9; }
        .alert-danger { background: var(--danger-light); color: var(--danger); border: 1px solid #f5b7b1; }
        .alert i { font-size: 16px; flex-shrink: 0; }
        .alert-close { margin-left: auto; cursor: pointer; opacity: 0.6; }
        .filter-bar { display: flex; align-items: center; gap: 6px; margin-bottom: 16px; flex-wrap: wrap; }
        .filter-tab { display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 99px; font-size: 13px; font-weight: 500; text-decoration: none; border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary); transition: var(--transition); }
        .filter-tab:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-light); }
        .filter-tab.active { background: var(--text-primary); color: white; border-color: var(--text-primary); }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
        .card-head { padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); background: var(--surface-2); }
        .card-head-title { font-size: 13.5px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .card-head-title i { color: var(--accent); }
        .search-wrap { position: relative; }
        .search-wrap i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 14px; color: var(--text-muted); pointer-events: none; }
        .search-input { padding: 7px 12px 7px 32px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; font-family: inherit; color: var(--text-primary); background: var(--surface); width: 220px; transition: var(--transition); }
        .search-input:focus { outline: none; border-color: var(--accent); width: 260px; box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }
        .search-input::placeholder { color: var(--text-muted); }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead th { padding: 11px 16px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.4px; text-transform: uppercase; color: var(--text-muted); background: var(--surface-2); border-bottom: 1px solid var(--border); white-space: nowrap; }
        tbody td { padding: 13px 16px; border-bottom: 1px solid var(--border); color: var(--text-primary); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr { transition: background 0.15s; }
        tbody tr:hover { background: var(--surface-2); }
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 550; }
        .badge-pending { background: var(--warning-light); color: var(--warning); }
        .badge-disetujui { background: var(--accent-light); color: var(--accent); }
        .badge-ditolak { background: var(--danger-light); color: var(--danger); }
        .badge-gray { background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border); }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 36px; opacity: 0.4; display: block; margin-bottom: 10px; }
        .btn-action { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary); cursor: pointer; transition: var(--transition); text-decoration: none; font-size: 14px; }
        .btn-action:hover { background: var(--surface-2); border-color: var(--border-strong); color: var(--text-primary); }
        .btn-action.danger:hover { background: var(--danger-light); border-color: #f5b7b1; color: var(--danger); }
        .btn-action.success:hover { background: var(--accent-light); border-color: #b7ddc9; color: var(--accent); }
        .btn-primary { display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; background: var(--accent); color: white; border: none; border-radius: var(--radius-sm); font-size: 13.5px; font-weight: 600; font-family: inherit; cursor: pointer; transition: var(--transition); text-decoration: none; }
        .btn-primary:hover { background: var(--accent-hover); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(45,106,79,0.25); }
        .btn-primary i { font-size: 15px; }
        .row-count { font-size: 12px; color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border); padding: 3px 10px; border-radius: 99px; }

        /* MODAL */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); backdrop-filter: blur(4px); z-index: 200; display: none; align-items: center; justify-content: center; padding: 20px; animation: fadeIn 0.15s ease; }
        @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
        .modal-overlay.show { display: flex; }
        .modal-box { background: var(--surface); border-radius: 14px; width: 100%; max-width: 600px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); animation: slideUp 0.2s cubic-bezier(0.34,1.56,0.64,1); max-height: 90vh; display: flex; flex-direction: column; }
        @keyframes slideUp { from { opacity:0; transform:translateY(20px) scale(0.98); } to { opacity:1; transform:translateY(0) scale(1); } }
        .modal-header { padding: 20px 24px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .modal-title { font-size: 16px; font-weight: 700; letter-spacing: -0.3px; }
        .modal-close { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius-sm); border: 1px solid var(--border); background: none; cursor: pointer; color: var(--text-muted); font-size: 16px; transition: var(--transition); }
        .modal-close:hover { background: var(--surface-2); color: var(--text-primary); }
        .modal-body { padding: 20px 24px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; flex-shrink: 0; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group.span-2 { grid-column: 1/-1; }
        .form-label { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); letter-spacing: 0.2px; }
        .form-control { padding: 9px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13.5px; font-family: inherit; color: var(--text-primary); background: var(--surface); transition: var(--transition); width: 100%; }
        .form-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }
        .form-control::placeholder { color: var(--text-muted); }
        .input-group { display: flex; gap: 6px; }
        .input-group .form-control { flex: 1; }
        .item-list { border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; margin-top: 4px; }
        .item-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-bottom: 1px solid var(--border); font-size: 13px; }
        .item-row:last-child { border-bottom: none; }
        .item-row:hover { background: var(--surface-2); }
        .item-empty { padding: 16px; text-align: center; color: var(--text-muted); font-size: 13px; }
        .btn-cancel { padding: 8px 16px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text-secondary); font-size: 13.5px; font-weight: 500; font-family: inherit; cursor: pointer; transition: var(--transition); }
        .btn-cancel:hover { background: var(--surface-2); }
        .btn-sm { padding: 5px 10px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-2); color: var(--text-secondary); font-size: 12px; font-family: inherit; cursor: pointer; transition: var(--transition); display: inline-flex; align-items: center; gap: 4px; }
        .btn-sm:hover { background: var(--border); }
        .section-label { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }

        [data-tooltip] { position: relative; }
        [data-tooltip]:hover::after { content: attr(data-tooltip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: var(--text-primary); color: white; padding: 4px 10px; border-radius: 5px; font-size: 11.5px; white-space: nowrap; pointer-events: none; z-index: 99; }

        @media (max-width: 768px) { .sidebar { left: calc(-1 * var(--sidebar-w)); } .sidebar.mobile-open { left: 0; } .main { margin-left: 0; } .form-grid { grid-template-columns: 1fr; } .form-group.span-2 { grid-column: 1; } }
    </style>
</head>
<body>

<!-- SIDEBAR (VERSI DASHBOARD DENGAN ACTIVE STATE) -->
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
            <a href="dashboard.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Pre Order</span>
        </div>
        <div class="topbar-right"><span style="font-size:12px;color:var(--text-muted);"><?= date('d M Y') ?></span></div>
    </header>

    <div class="page-body">
        <?php if(isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i><?= $_SESSION['success']; unset($_SESSION['success']); ?><i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i></div>
        <?php endif; ?>
        <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?><i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i></div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <div class="page-title">Pre Order</div>
                <div class="page-subtitle">Kelola pengajuan pembelian barang ke supplier</div>
            </div>
            <button class="btn-primary" onclick="openModal('tambahModal')"><i class="bi bi-plus"></i> Buat Pre Order</button>
        </div>

        <div class="filter-bar">
            <a href="preorder.php" class="filter-tab <?= $status_filter=='semua'?'active':'' ?>">Semua</a>
            <a href="?filter=pending" class="filter-tab <?= $status_filter=='pending'?'active':'' ?>">Pending</a>
            <a href="?filter=disetujui" class="filter-tab <?= $status_filter=='disetujui'?'active':'' ?>">Disetujui</a>
            <a href="?filter=ditolak" class="filter-tab <?= $status_filter=='ditolak'?'active':'' ?>">Ditolak</a>
        </div>

        <div class="card">
            <div class="card-head">
                <div class="card-head-title"><i class="bi bi-cart3"></i> Daftar Pre Order <span class="row-count" id="rowCount"></span></div>
                <div class="search-wrap"><i class="bi bi-search"></i><input type="text" class="search-input" id="searchInput" placeholder="Cari PO..."></div>
            </div>
            <div class="table-wrap">
                <table id="tablePreOrder">
                    <thead><tr><th>No. PO</th><th>Tanggal</th><th>Supplier</th><th>Total Item</th><th>Status</th><th style="text-align:center;width:120px;">Aksi</th></tr></thead>
                    <tbody id="tableBody">
                        <?php if ($list->num_rows > 0): 
                            $no_po = 1; // ===== PERBAIKAN 2: nomor urut PO =====
                            while($row = $list->fetch_assoc()):
                                $badgeClass = $row['status']=='pending' ? 'badge-pending' : ($row['status']=='disetujui' ? 'badge-disetujui' : 'badge-ditolak');
                        ?>
                        <tr>
                            <td style="font-weight:600;">PO-<?= $no_po++ ?></td>
                            <td style="color:var(--text-secondary);"><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                            <td><?= htmlspecialchars($row['supplier_nama'] ?? '-') ?></td>
                            <td><span class="badge badge-gray"><?= (int)$row['total_item'] ?> item</span></td>
                            <td><span class="badge <?= $badgeClass ?>"><?= ucfirst($row['status']) ?></span></td>
                            <td>
                                <div style="display:flex;gap:5px;justify-content:center;">
                                    <button class="btn-action btn-detail" data-id="<?= $row['id'] ?>" data-tooltip="Detail"><i class="bi bi-eye"></i></button>
                                    <?php if($row['status']=='pending'): ?>
                                    <button class="btn-action btn-edit" data-id="<?= $row['id'] ?>" data-tooltip="Edit"><i class="bi bi-pencil"></i></button>
                                    <a href="?hapus=<?= $row['id'] ?>" class="btn-action danger" data-tooltip="Hapus" onclick="return confirm('Yakin hapus?')"><i class="bi bi-trash"></i></a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="6"><div class="empty-state"><i class="bi bi-inbox"></i><p>Belum ada data Pre Order</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL TAMBAH (KONTEN ASLI, TIDAK DIUBAH) -->
<div class="modal-overlay" id="tambahModal">
    <div class="modal-box">
        <form method="POST">
            <div class="modal-header">
                <div class="modal-title">Buat Pre Order Baru</div>
                <button type="button" class="modal-close" onclick="closeModal('tambahModal')"><i class="bi bi-x"></i></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="0">
                <input type="hidden" name="items_json" id="itemsJsonTambah">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" class="form-control" required>
                            <option value="">— Pilih Supplier —</option>
                            <?php $supplier_list->data_seek(0); while($s=$supplier_list->fetch_assoc()): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nama']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal</label>
                        <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div style="margin-top:16px;">
                    <div class="section-label">Tambah Barang</div>
                    <div style="display:grid;grid-template-columns:1fr 80px auto;gap:8px;align-items:end;">
                        <div>
                            <select id="barangSelectTambah" class="form-control">
                                <option value="">— Pilih Barang —</option>
                                <?php $barang_list->data_seek(0); while($b=$barang_list->fetch_assoc()): ?>
                                <option value="<?= $b['id_barang'] ?>"><?= htmlspecialchars($b['nama_barang']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <input type="number" id="qtyTambah" class="form-control" value="1" min="1" placeholder="Qty">
                        <button type="button" id="btnTambahItem" class="btn-primary" style="white-space:nowrap;"><i class="bi bi-plus"></i></button>
                    </div>
                    <div class="item-list" style="margin-top:10px;" id="daftarListTambah">
                        <div class="item-empty">Belum ada barang ditambahkan</div>
                    </div>
                </div>
                <div class="form-group" style="margin-top:16px;">
                    <label class="form-label">Catatan</label>
                    <textarea name="catatan" class="form-control" rows="2" style="resize:vertical;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('tambahModal')">Batal</button>
                <button type="submit" name="simpan" class="btn-primary"><i class="bi bi-check2"></i> Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDIT -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <form method="POST">
            <div class="modal-header">
                <div class="modal-title">Edit Pre Order</div>
                <button type="button" class="modal-close" onclick="closeModal('editModal')"><i class="bi bi-x"></i></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="items_json" id="itemsJsonEdit">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Supplier</label>
                        <select name="supplier_id" id="edit_supplier" class="form-control" required>
                            <option value="">— Pilih Supplier —</option>
                            <?php $supplier_list->data_seek(0); while($s=$supplier_list->fetch_assoc()): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nama']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal</label>
                        <input type="date" name="tanggal" id="edit_tanggal" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" id="edit_status" class="form-control">
                            <option value="pending">Pending</option>
                            <option value="disetujui">Disetujui</option>
                            <option value="ditolak">Ditolak</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top:16px;">
                    <div class="section-label">Tambah Barang</div>
                    <div style="display:grid;grid-template-columns:1fr 80px auto;gap:8px;align-items:end;">
                        <select id="barangSelectEdit" class="form-control">
                            <option value="">— Pilih Barang —</option>
                            <?php $barang_list->data_seek(0); while($b=$barang_list->fetch_assoc()): ?>
                            <option value="<?= $b['id_barang'] ?>"><?= htmlspecialchars($b['nama_barang']) ?></option>
                            <?php endwhile; ?>
                        </select>
                        <input type="number" id="qtyEdit" class="form-control" value="1" min="1">
                        <button type="button" id="btnTambahItemEdit" class="btn-primary"><i class="bi bi-plus"></i></button>
                    </div>
                    <div class="item-list" style="margin-top:10px;" id="daftarListEdit">
                        <div class="item-empty">Belum ada barang</div>
                    </div>
                </div>
                <div class="form-group" style="margin-top:16px;">
                    <label class="form-label">Catatan</label>
                    <textarea name="catatan" id="edit_catatan" class="form-control" rows="2" style="resize:vertical;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Batal</button>
                <button type="submit" name="simpan" class="btn-primary"><i class="bi bi-check2"></i> Update</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL DETAIL -->
<div class="modal-overlay" id="detailModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Detail Pre Order</div>
            <button type="button" class="modal-close" onclick="closeModal('detailModal')"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-grid" style="margin-bottom:16px;">
                <div><div class="form-label" style="margin-bottom:4px;">Nomor PO</div><div style="font-weight:600;" id="detail_nomor">-</div></div>
                <div><div class="form-label" style="margin-bottom:4px;">Tanggal</div><div id="detail_tanggal">-</div></div>
                <div><div class="form-label" style="margin-bottom:4px;">Supplier</div><div id="detail_supplier">-</div></div>
                <div><div class="form-label" style="margin-bottom:4px;">Status</div><div id="detail_status">-</div></div>
            </div>
            <div class="section-label">Daftar Barang</div>
            <div class="item-list" id="detail_items"><div class="item-empty">-</div></div>
            <div style="margin-top:16px;">
                <div class="form-label" style="margin-bottom:4px;">Catatan</div>
                <div id="detail_catatan" style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 12px;font-size:13.5px;color:var(--text-secondary);">-</div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('detailModal')">Tutup</button></div>
    </div>
</div>

<script>
const sidebar = document.getElementById('sidebar');
const main = document.getElementById('main');
document.getElementById('sidebarToggle').addEventListener('click', () => {
    const isMobile = window.innerWidth <= 768;
    if (isMobile) sidebar.classList.toggle('mobile-open');
    else { sidebar.classList.toggle('collapsed'); main.classList.toggle('expanded'); }
});
function toggleNav(id, btn) {
    const el = document.getElementById(id);
    const isOpen = el.style.display !== 'none';
    el.style.display = isOpen ? 'none' : 'block';
    btn.setAttribute('aria-expanded', !isOpen);
}
function openModal(id) { document.getElementById(id).classList.add('show'); document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('show'); document.body.style.overflow=''; }
document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => { if(e.target===o) closeModal(o.id); }));
document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.modal-overlay.show').forEach(m => closeModal(m.id)); });

function escapeHtml(str) { if(!str) return ''; return str.replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

function initItemManager(listId, jsonId, selectId, qtyId, btnId) {
    let items = [];
    const container = document.getElementById(listId);
    const jsonField = document.getElementById(jsonId);
    const sel = document.getElementById(selectId);
    const qty = document.getElementById(qtyId);
    const btn = document.getElementById(btnId);
    function render() {
        if (items.length === 0) { container.innerHTML = '<div class="item-empty">Belum ada barang ditambahkan</div>'; jsonField.value = ''; return; }
        container.innerHTML = '';
        items.forEach((it, idx) => {
            const div = document.createElement('div');
            div.className = 'item-row';
            div.innerHTML = `<span style="font-weight:500;">${escapeHtml(it.nama)}</span><div style="display:flex;align-items:center;gap:8px;"><span class="badge badge-gray">${it.qty} item</span><button type="button" class="btn-action danger" onclick="removeItem_${listId}(${idx})" style="width:24px;height:24px;"><i class="bi bi-x"></i></button></div>`;
            container.appendChild(div);
        });
        jsonField.value = JSON.stringify(items.map(i=>({barang_id:i.barang_id,qty:i.qty})));
    }
    window[`removeItem_${listId}`] = (idx) => { items.splice(idx,1); render(); };
    btn.addEventListener('click', () => {
        if (!sel.value) { alert('Pilih barang'); return; }
        const barangId = parseInt(sel.value);
        const nama = sel.options[sel.selectedIndex].text;
        const q = parseInt(qty.value);
        if (isNaN(q)||q<=0) { alert('Qty harus > 0'); return; }
        if (items.some(i=>i.barang_id===barangId)) { alert('Barang sudah ada!'); return; }
        items.push({barang_id:barangId,nama:nama,qty:q});
        render(); qty.value=1; sel.value='';
    });
    return { setItems: (newItems) => { items = newItems; render(); } };
}

const tambahMgr = initItemManager('daftarListTambah','itemsJsonTambah','barangSelectTambah','qtyTambah','btnTambahItem');
const editMgr   = initItemManager('daftarListEdit','itemsJsonEdit','barangSelectEdit','qtyEdit','btnTambahItemEdit');

document.querySelectorAll('.btn-edit').forEach(btn => {
    btn.addEventListener('click', function() {
        fetch(`?get_data=${this.dataset.id}`).then(r=>r.json()).then(d=>{
            if(d.error){alert(d.error);return;}
            document.getElementById('edit_id').value=d.id;
            document.getElementById('edit_tanggal').value=d.tanggal;
            document.getElementById('edit_supplier').value=d.supplier_id;
            document.getElementById('edit_catatan').value=d.catatan||'';
            document.getElementById('edit_status').value=d.status;
            editMgr.setItems(d.items.map(i=>({barang_id:i.barang_id,nama:i.nama,qty:i.qty})));
            openModal('editModal');
        }).catch(alert);
    });
});

document.querySelectorAll('.btn-detail').forEach(btn => {
    btn.addEventListener('click', function() {
        fetch(`?get_data=${this.dataset.id}`).then(r=>r.json()).then(d=>{
            if(d.error){alert(d.error);return;}
            document.getElementById('detail_nomor').textContent='PO-'+d.id;
            document.getElementById('detail_tanggal').textContent=d.tanggal.split('-').reverse().join('/');
            document.getElementById('detail_supplier').textContent=d.supplier_nama;
            const badgeClass = d.status==='pending'?'badge-pending':(d.status==='disetujui'?'badge-disetujui':'badge-ditolak');
            document.getElementById('detail_status').innerHTML=`<span class="badge ${badgeClass}">${d.status.charAt(0).toUpperCase()+d.status.slice(1)}</span>`;
            document.getElementById('detail_catatan').textContent=d.catatan||'-';
            const cont = document.getElementById('detail_items');
            if (d.items.length===0){cont.innerHTML='<div class="item-empty">Tidak ada barang</div>';}
            else {
                cont.innerHTML='';
                d.items.forEach(it=>{
                    const div=document.createElement('div');div.className='item-row';
                    div.innerHTML=`<span style="font-weight:500;">${escapeHtml(it.nama)}</span><span class="badge badge-gray">${it.qty} item</span>`;
                    cont.appendChild(div);
                });
            }
            openModal('detailModal');
        }).catch(alert);
    });
});

const searchInput = document.getElementById('searchInput');
const tableBody   = document.getElementById('tableBody');
const rowCountEl  = document.getElementById('rowCount');
function updateCount(){
    const v=tableBody.querySelectorAll('tr:not([style*="display: none"])').length;
    const t=tableBody.querySelectorAll('tr').length;
    rowCountEl.textContent=v===t?`${t} item`:`${v}/${t} item`;
}
searchInput.addEventListener('input', function(){
    const q=this.value.toLowerCase().trim();
    tableBody.querySelectorAll('tr').forEach(r=>r.style.display=r.textContent.toLowerCase().includes(q)?'':'none');
    updateCount();
});
updateCount();
const alertEls = document.querySelectorAll('.alert');
alertEls.forEach(a=>setTimeout(()=>a.remove(),4500));
</script>
</body>
</html>