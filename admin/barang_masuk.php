<?php
require_once '../includes/auth.php';
cekLogin();
cekRole('admin');
require_once '../includes/functions.php';

global $conn;

// Fungsi helper untuk mendapatkan atau membuat supplier
function getOrCreateSupplier($nama_supplier) {
    global $conn;
    $nama = trim($nama_supplier);
    if (empty($nama)) return null;
    
    $stmt = $conn->prepare("SELECT id FROM supplier WHERE LOWER(nama) = LOWER(?)");
    $stmt->bind_param("s", $nama);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        return $row['id'];
    }
    
    $stmt = $conn->prepare("INSERT INTO supplier (nama) VALUES (?)");
    $stmt->bind_param("s", $nama);
    $stmt->execute();
    return $conn->insert_id;
}

// Helper: ambil nilai stok_besar (karung) barang saat ini, mirror dari getStokBarang() yang ada di functions.php
function getStokBesarBarang($barang_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT stok_besar FROM barang WHERE id_barang = ?");
    $stmt->bind_param("i", $barang_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (float)$row['stok_besar'] : 0;
}

// ========== PROSES SIMPAN (TAMBAH / EDIT) ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan'])) {
    $id = (int)($_POST['id'] ?? 0);
    $isEdit = ($id > 0);
    $nomor_faktur = trim($_POST['nomor_faktur']);
    $tanggal = $_POST['tanggal'] ?? date('Y-m-d');
    $supplier_nama = trim($_POST['supplier']);
    $catatan = trim($_POST['catatan'] ?? '');
    $items = json_decode($_POST['items_json'], true);
    $po_id = isset($_POST['po_id']) ? (int)$_POST['po_id'] : 0;

    if (empty($items)) {
        $_SESSION['error'] = "Tidak ada barang yang ditambahkan.";
        header("Location: barang_masuk.php");
        exit;
    }

    foreach ($items as &$item) {
        if ($item['harga'] <= 0) {
            $_SESSION['error'] = "Harga beli harus lebih dari 0!";
            header("Location: barang_masuk.php");
            exit;
        }
        $cek = $conn->prepare("SELECT id_barang, satuan_besar FROM barang WHERE id_barang = ?");
        $cek->bind_param("i", $item['barang_id']);
        $cek->execute();
        $barangRow = $cek->get_result()->fetch_assoc();
        if (!$barangRow) {
            $_SESSION['error'] = "Barang dengan ID {$item['barang_id']} tidak ditemukan!";
            header("Location: barang_masuk.php");
            exit;
        }
        // Satuan item: 'besar' (karung/sak) atau 'kg'. Default 'kg' jika tidak dikirim (kompatibilitas lama).
        $item['satuan'] = in_array($item['satuan'] ?? '', ['kg', 'besar'], true) ? $item['satuan'] : 'kg';
        if ($item['satuan'] === 'besar' && empty($barangRow['satuan_besar'])) {
            $namaLabel = $item['nama'] ?? ('ID ' . $item['barang_id']);
            $_SESSION['error'] = "Barang \"{$namaLabel}\" tidak punya satuan besar (karung), tidak bisa disimpan sebagai karung.";
            header("Location: barang_masuk.php");
            exit;
        }
    }
    unset($item);

    if ($po_id > 0) {
        $po = getPreOrderById($po_id);
        if (!$po || $po['status'] != 'disetujui') {
            $_SESSION['error'] = "Pre Order belum disetujui oleh Owner!";
            header("Location: barang_masuk.php");
            exit;
        }
        if ($po['is_used'] == 1) {
            $_SESSION['error'] = "Pre Order ini sudah pernah dipakai untuk transaksi masuk!";
            header("Location: barang_masuk.php");
            exit;
        }
        $supplier_id = $po['supplier_id'];
    } else {
        $supplier_id = getOrCreateSupplier($supplier_nama);
        if (!$supplier_id) {
            $_SESSION['error'] = "Supplier tidak valid.";
            header("Location: barang_masuk.php");
            exit;
        }
    }

    $total_item = 0;
    $total_biaya = 0;
    foreach ($items as $item) {
        $total_item += $item['qty'];
        $total_biaya += $item['qty'] * $item['harga'];
    }

    $conn->begin_transaction();
    try {
        if ($id > 0) {
            // EDIT: kembalikan stok dari item lama sesuai satuan aslinya (kg atau besar/karung), sebelum item lama dihapus
            $stmt_old = $conn->prepare("SELECT barang_id, qty, satuan FROM transaksi_masuk_item WHERE transaksi_masuk_id = ?");
            $stmt_old->bind_param("i", $id);
            $stmt_old->execute();
            $old_items = $stmt_old->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($old_items as $oi) {
                $satuanLama = $oi['satuan'] ?? 'kg';
                if ($satuanLama === 'besar') {
                    $stok_sebelum = getStokBesarBarang($oi['barang_id']);
                    $rev = $conn->prepare("UPDATE barang SET stok_besar = stok_besar - ? WHERE id_barang = ?");
                    $rev->bind_param("di", $oi['qty'], $oi['barang_id']);
                    $rev->execute();
                    $stok_sesudah = getStokBesarBarang($oi['barang_id']);
                } else {
                    $stok_sebelum = getStokBarang($oi['barang_id']);
                    $rev = $conn->prepare("UPDATE barang SET stok = stok - ? WHERE id_barang = ?");
                    $rev->bind_param("di", $oi['qty'], $oi['barang_id']);
                    $rev->execute();
                    $stok_sesudah = getStokBarang($oi['barang_id']);
                }
                catatMutasiStok(
                    $oi['barang_id'],
                    'KELUAR',
                    $oi['qty'],
                    $stok_sebelum,
                    $stok_sesudah,
                    "Koreksi stok karena edit transaksi masuk ID #$id",
                    $id
                );
            }

            // hapus item lama
            $stmt_del = $conn->prepare("DELETE FROM transaksi_masuk_item WHERE transaksi_masuk_id = ?");
            $stmt_del->bind_param("i", $id);
            $stmt_del->execute();
            // Update header
            $stmt = $conn->prepare("UPDATE transaksi_masuk SET nomor_faktur=?, tanggal=?, supplier_id=?, total_item=?, total_biaya=?, catatan=? WHERE id=?");
            $stmt->bind_param("ssiidsi", $nomor_faktur, $tanggal, $supplier_id, $total_item, $total_biaya, $catatan, $id);
            $stmt->execute();
        } else {
            // TAMBAH
            $stmt = $conn->prepare("INSERT INTO transaksi_masuk (nomor_faktur, tanggal, supplier_id, total_item, total_biaya, catatan) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiids", $nomor_faktur, $tanggal, $supplier_id, $total_item, $total_biaya, $catatan);
            $stmt->execute();
            $id = $conn->insert_id;
        }

        // Insert item baru dan update stok barang sesuai satuan masing-masing item
        $stmt_item = $conn->prepare("INSERT INTO transaksi_masuk_item (transaksi_masuk_id, barang_id, qty, harga_beli, satuan) VALUES (?, ?, ?, ?, ?)");
        $update_stok_kg = $conn->prepare("UPDATE barang SET stok = stok + ? WHERE id_barang = ?");
        $update_stok_besar = $conn->prepare("UPDATE barang SET stok_besar = stok_besar + ? WHERE id_barang = ?");
        foreach ($items as $item) {
            $satuanItem = $item['satuan'];

            // Simpan item transaksi (termasuk satuan yang dipilih: kg atau besar/karung)
            $stmt_item->bind_param("iiids", $id, $item['barang_id'], $item['qty'], $item['harga'], $satuanItem);
            $stmt_item->execute();

            // Update stok pada kolom yang sesuai, lalu catat mutasi
            if ($satuanItem === 'besar') {
                $stok_sebelum = getStokBesarBarang($item['barang_id']);
                $update_stok_besar->bind_param("di", $item['qty'], $item['barang_id']);
                $update_stok_besar->execute();
                $stok_sesudah = getStokBesarBarang($item['barang_id']);
                $satuan_label = 'karung';
            } else {
                $stok_sebelum = getStokBarang($item['barang_id']);
                $update_stok_kg->bind_param("di", $item['qty'], $item['barang_id']);
                $update_stok_kg->execute();
                $stok_sesudah = getStokBarang($item['barang_id']);
                $satuan_label = 'kg';
            }
            catatMutasiStok(
                $item['barang_id'],
                'MASUK',
                $item['qty'],
                $stok_sebelum,
                $stok_sesudah,
                "Barang masuk ($satuan_label) dari " . ($po_id ? "PO #$po_id" : "faktur $nomor_faktur"),
                $id
            );
        }

        // Jika dari PO, tandai PO sudah digunakan
        if ($po_id > 0) {
            markPreOrderUsed($po_id);
        }

        $conn->commit();
        $_SESSION['success'] = $isEdit ? "Transaksi berhasil diperbarui." : "Barang masuk berhasil ditambahkan.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Gagal menyimpan transaksi: " . $e->getMessage();
    }
    header("Location: barang_masuk.php");
    exit;
}

// ========== PROSES HAPUS ==========
if (isset($_GET['hapus'])) {
    $id = (int)$_GET['hapus'];
    // Ambil semua item untuk mengembalikan stok
    $items = $conn->query("SELECT barang_id, qty, satuan FROM transaksi_masuk_item WHERE transaksi_masuk_id = $id");
    $conn->begin_transaction();
    try {
        while ($it = $items->fetch_assoc()) {
            $satuanItem = $it['satuan'] ?? 'kg';
            if ($satuanItem === 'besar') {
                $stok_sebelum = getStokBesarBarang($it['barang_id']);
                $update_stok = $conn->prepare("UPDATE barang SET stok_besar = stok_besar - ? WHERE id_barang = ?");
                $update_stok->bind_param("di", $it['qty'], $it['barang_id']);
                $update_stok->execute();
                $stok_sesudah = getStokBesarBarang($it['barang_id']);
            } else {
                $stok_sebelum = getStokBarang($it['barang_id']);
                $update_stok = $conn->prepare("UPDATE barang SET stok = stok - ? WHERE id_barang = ?");
                $update_stok->bind_param("di", $it['qty'], $it['barang_id']);
                $update_stok->execute();
                $stok_sesudah = getStokBarang($it['barang_id']);
            }
            catatMutasiStok(
                $it['barang_id'],
                'KELUAR', // karena dihapus, stok berkurang
                $it['qty'],
                $stok_sebelum,
                $stok_sesudah,
                "Pembatalan transaksi masuk ID #$id",
                $id
            );
        }
        $conn->query("DELETE FROM transaksi_masuk_item WHERE transaksi_masuk_id = $id");
        $conn->query("DELETE FROM transaksi_masuk WHERE id = $id");
        $conn->commit();
        $_SESSION['success'] = "Transaksi berhasil dihapus, stok dikembalikan.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Gagal menghapus transaksi: " . $e->getMessage();
    }
    header("Location: barang_masuk.php");
    exit;
}

// ========== AJAX: ambil item dari PO ==========
if (isset($_GET['get_po_items'])) {
    $po_id = (int)$_GET['get_po_items'];
    $po = getPreOrderById($po_id);
    if (!$po || $po['status'] != 'disetujui') {
        echo json_encode(['error' => 'PO tidak valid atau belum disetujui']);
        exit;
    }
    if ($po['is_used'] == 1) {
        echo json_encode(['error' => 'PO sudah pernah dipakai']);
        exit;
    }
    $supplier = $conn->query("SELECT nama FROM supplier WHERE id = {$po['supplier_id']}")->fetch_assoc();
    $items = [];
    $res = $conn->query("SELECT i.*, b.nama_barang, b.satuan, b.satuan_besar
                         FROM pre_order_item i 
                         JOIN barang b ON i.barang_id = b.id_barang 
                         WHERE i.pre_order_id = $po_id");
    while ($row = $res->fetch_assoc()) {
        $adaSatuanBesar = !empty($row['satuan_besar']);
        $items[] = [
            'barang_id' => $row['barang_id'],
            'nama' => $row['nama_barang'],
            'qty' => $row['qty'],
            'harga' => 0,
            // Default: pilih karung/sak (besar) jika barang punya satuan besar, kalau tidak default kg
            'satuan' => $adaSatuanBesar ? 'besar' : 'kg',
            'has_satuan_besar' => $adaSatuanBesar,
            'satuan_kg_label' => $row['satuan'],
            'satuan_besar_label' => $row['satuan_besar']
        ];
    }
    echo json_encode([
        'items' => $items,
        'supplier_nama' => $supplier['nama'] ?? '',
        'supplier_id' => $po['supplier_id']
    ]);
    exit;
}

// ========== AMBIL DATA UNTUK MODAL EDIT & DETAIL ==========
if (isset($_GET['get_data'])) {
    $id = (int)$_GET['get_data'];
    $stmt = $conn->prepare("SELECT tm.*, s.nama as supplier_nama FROM transaksi_masuk tm LEFT JOIN supplier s ON tm.supplier_id = s.id WHERE tm.id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $transaksi = $stmt->get_result()->fetch_assoc();
    if (!$transaksi) {
        echo json_encode(['error' => 'Data tidak ditemukan']);
        exit;
    }
    $items = [];
    $stmt_item = $conn->prepare("SELECT i.*, b.nama_barang, b.satuan, b.satuan_besar FROM transaksi_masuk_item i JOIN barang b ON i.barang_id = b.id_barang WHERE i.transaksi_masuk_id = ?");
    $stmt_item->bind_param("i", $id);
    $stmt_item->execute();
    $res_item = $stmt_item->get_result();
    while ($row = $res_item->fetch_assoc()) {
        $items[] = [
            'barang_id' => $row['barang_id'],
            'nama' => $row['nama_barang'],
            'qty' => $row['qty'],
            'harga' => (float)$row['harga_beli'],
            'satuan' => $row['satuan'] ?? 'kg',
            'has_satuan_besar' => !empty($row['satuan_besar']),
            'satuan_kg_label' => $row['satuan'],
            'satuan_besar_label' => $row['satuan_besar']
        ];
    }
    echo json_encode([
        'id' => $transaksi['id'],
        'nomor_faktur' => $transaksi['nomor_faktur'],
        'tanggal' => $transaksi['tanggal'],
        'supplier_id' => $transaksi['supplier_id'],
        'supplier_nama' => $transaksi['supplier_nama'],
        'catatan' => $transaksi['catatan'] ?? '',
        'items' => $items
    ]);
    exit;
}

// ========== HALAMAN DETAIL ==========
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$transaksi = null;
$items_detail = [];
if ($action == 'detail' && $id > 0) {
    $stmt = $conn->prepare("SELECT tm.*, s.nama as supplier_nama FROM transaksi_masuk tm LEFT JOIN supplier s ON tm.supplier_id = s.id WHERE tm.id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $transaksi = $stmt->get_result()->fetch_assoc();
    if (!$transaksi) { header("Location: barang_masuk.php"); exit; }
    $stmt_item = $conn->prepare("SELECT i.*, b.nama_barang as barang_nama, b.satuan as satuan_kg_label, b.satuan_besar as satuan_besar_label FROM transaksi_masuk_item i JOIN barang b ON i.barang_id = b.id_barang WHERE i.transaksi_masuk_id = ?");
    $stmt_item->bind_param("i", $id);
    $stmt_item->execute();
    $items_detail = $stmt_item->get_result();
}

$po_list = $conn->query("
    SELECT po.id, po.supplier_id, s.nama as supplier_nama 
    FROM pre_order po 
    JOIN supplier s ON po.supplier_id = s.id 
    WHERE po.status = 'disetujui' AND po.is_used = 0
    ORDER BY po.id DESC
");

$barang_list = $conn->query("SELECT id_barang, nama_barang FROM barang ORDER BY nama_barang");

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
    <title>Barang Masuk — Keva Jaya</title>
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

        /* BUTTON SMALL (untuk logout konsisten dengan dashboard) */
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

        /* ===== DI BAWAH INI CSS ASLI KONTEN BARANG MASUK (TIDAK DIUBAH) ===== */
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
        .badge-gray { background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border); }
        .badge-warning { background: var(--warning-light); color: var(--warning); }
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
        .btn-cancel { padding: 8px 16px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text-secondary); font-size: 13.5px; font-weight: 500; font-family: inherit; cursor: pointer; transition: var(--transition); }
        .btn-cancel:hover { background: var(--surface-2); }

        /* MODAL */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); backdrop-filter: blur(4px); z-index: 200; display: none; align-items: center; justify-content: center; padding: 20px; animation: fadeIn 0.15s ease; }
        @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
        .modal-overlay.show { display: flex; }
        .modal-box { background: var(--surface); border-radius: 14px; width: 100%; max-width: 680px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); animation: slideUp 0.2s cubic-bezier(0.34,1.56,0.64,1); max-height: 90vh; display: flex; flex-direction: column; }
        @keyframes slideUp { from { opacity:0; transform:translateY(20px) scale(0.98); } to { opacity:1; transform:translateY(0) scale(1); } }
        .modal-header { padding: 20px 24px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .modal-title { font-size: 16px; font-weight: 700; letter-spacing: -0.3px; }
        .modal-close { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius-sm); border: 1px solid var(--border); background: none; cursor: pointer; color: var(--text-muted); font-size: 16px; transition: var(--transition); }
        .modal-close:hover { background: var(--surface-2); color: var(--text-primary); }
        .modal-body { padding: 20px 24px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; flex-shrink: 0; }

        /* FORM */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group.span-2 { grid-column: 1/-1; }
        .form-label { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); letter-spacing: 0.2px; }
        .form-control { padding: 9px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13.5px; font-family: inherit; color: var(--text-primary); background: var(--surface); transition: var(--transition); width: 100%; }
        .form-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }
        .form-control::placeholder { color: var(--text-muted); }
        .input-group { display: flex; gap: 6px; }
        .item-list { border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; margin-top: 8px; }
        .item-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-bottom: 1px solid var(--border); font-size: 13px; flex-wrap: wrap; gap: 8px; }
        .item-row:last-child { border-bottom: none; }
        .item-row:hover { background: var(--surface-2); }
        .item-empty { padding: 16px; text-align: center; color: var(--text-muted); font-size: 13px; }
        .section-label { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }

        [data-tooltip] { position: relative; }
        [data-tooltip]:hover::after { content: attr(data-tooltip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: var(--text-primary); color: white; padding: 4px 10px; border-radius: 5px; font-size: 11.5px; white-space: nowrap; pointer-events: none; z-index: 99; }

        @media (max-width: 768px) { .sidebar { left: calc(-1 * var(--sidebar-w)); } .sidebar.mobile-open { left: 0; } .main { margin-left: 0; } .form-grid { grid-template-columns: 1fr; } .modal-box { max-width: 95%; } .search-input { width: 160px; } }
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
            <div><div class="user-name"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></div><div class="user-role">Administrator</div></div>
            <a href="../logout.php" class="btn-sm" style="margin-left:auto;border:none;" data-tooltip="Logout"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </div>
</aside>

<div class="main" id="main">
    <header class="topbar">
        <button class="btn-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="breadcrumb-bar">
            <a href="dashboard.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Barang Masuk</span>
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

        <?php if ($action == 'detail'): ?>
            <!-- Halaman Detail -->
            <div style="max-width:800px; margin:0 auto;">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <a href="barang_masuk.php" class="btn-primary" style="background:var(--surface);color:var(--text-secondary);border:1px solid var(--border);"><i class="bi bi-arrow-left"></i> Kembali</a>
                </div>
                <div class="card">
                    <div class="card-head"><div class="card-head-title"><i class="bi bi-receipt"></i> Detail Transaksi Masuk</div></div>
                    <div style="padding:20px;">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px;">
                            <div><div class="form-label">Nomor Faktur</div><div style="font-weight:600;"><?= htmlspecialchars($transaksi['nomor_faktur'] ?? '-') ?></div></div>
                            <div><div class="form-label">Tanggal</div><div><?= date('d/m/Y', strtotime($transaksi['tanggal'])) ?></div></div>
                            <div><div class="form-label">Supplier</div><div><?= htmlspecialchars($transaksi['supplier_nama'] ?? '-') ?></div></div>
                            <div><div class="form-label">Total Biaya</div><div>Rp <?= number_format($transaksi['total_biaya'],0,',','.') ?></div></div>
                            <div style="grid-column:1/-1;"><div class="form-label">Catatan</div><div style="background:var(--surface-2);padding:10px;border-radius:var(--radius-sm);"><?= nl2br(htmlspecialchars($transaksi['catatan'] ?? '-')) ?></div></div>
                        </div>
                        <div class="section-label">Daftar Barang</div>
                        <div class="table-wrap">
                            <table style="width:100%">
                                <thead><tr><th>Barang</th><th>Qty</th><th>Satuan</th><th>Harga Beli</th><th>Subtotal</th></tr></thead>
                                <tbody>
                                    <?php while($it = $items_detail->fetch_assoc()):
                                        $satuanLbl = ($it['satuan'] ?? 'kg') === 'besar' ? ($it['satuan_besar_label'] ?: 'Karung') : ($it['satuan_kg_label'] ?: 'Kg');
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($it['barang_nama']) ?></td>
                                        <td><?= $it['qty'] ?></td>
                                        <td><?= htmlspecialchars($satuanLbl) ?></td>
                                        <td>Rp <?= number_format($it['harga_beli'],0,',','.') ?></td>
                                        <td>Rp <?= number_format($it['qty'] * $it['harga_beli'],0,',','.') ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Halaman List -->
            <div class="page-header">
                <div>
                    <div class="page-title">Barang Masuk (Pembelian)</div>
                    <div class="page-subtitle">Kelola transaksi barang masuk dari supplier</div>
                </div>
                <button class="btn-primary" onclick="openModal('tambahModal')"><i class="bi bi-plus"></i> Input Barang Masuk</button>
            </div>

            <div class="card">
                <div class="card-head">
                    <div class="card-head-title"><i class="bi bi-arrow-down-circle"></i> Daftar Transaksi Masuk <span class="row-count" id="rowCount"></span></div>
                    <div class="search-wrap"><i class="bi bi-search"></i><input type="text" class="search-input" id="searchInput" placeholder="Cari faktur, supplier..."></div>
                </div>
                <div class="table-wrap">
                    <table id="tableTransaksi">
                        <thead>
                            <tr><th>No</th><th>No. Faktur</th><th>Tanggal</th><th>Supplier</th><th>Total Item</th><th>Total Biaya</th><th style="width:100px;">Aksi</th></tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php
                            $list = $conn->query("SELECT tm.*, s.nama as supplier_nama FROM transaksi_masuk tm LEFT JOIN supplier s ON tm.supplier_id = s.id ORDER BY tm.tanggal DESC, tm.id DESC");
                            $no = 1;
                            if ($list && $list->num_rows > 0):
                                while($row = $list->fetch_assoc()):
                                    $hasNote = !empty(trim($row['catatan'] ?? ''));
                            ?>
                            <tr>
                                <td style="font-weight:600; font-family:'JetBrains Mono', monospace;"><?= $no++ ?></td>
                                <td>
                                    <?= htmlspecialchars($row['nomor_faktur']) ?>
                                    <?php if($hasNote): ?> <i class="bi bi-paperclip" style="color:var(--text-muted); margin-left:4px;" data-tooltip="Ada catatan"></i><?php endif; ?>
                                </td>
                                <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                                <td><?= htmlspecialchars($row['supplier_nama'] ?? '-') ?></td>
                                <td><span class="badge badge-gray"><?= (int)$row['total_item'] ?> item</span></td>
                                <td>
                                    Rp <?= number_format($row['total_biaya'],0,',','.') ?>
                                    <?php if($row['total_biaya'] == 0 && $row['total_item'] > 0): ?>
                                        <span class="badge badge-warning" style="margin-left:6px;">⚠️ periksa harga</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex;gap:5px;justify-content:center;">
                                        <button class="btn-action btn-detail" data-id="<?= $row['id'] ?>" data-tooltip="Detail"><i class="bi bi-eye"></i></button>
                                        <button class="btn-action btn-edit" data-id="<?= $row['id'] ?>" data-tooltip="Edit"><i class="bi bi-pencil"></i></button>
                                        <a href="?hapus=<?= $row['id'] ?>" class="btn-action danger" data-tooltip="Hapus" onclick="return confirm('Yakin hapus transaksi ini?')"><i class="bi bi-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i><p>Belum ada transaksi barang masuk</p></div></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL TAMBAH BARANG MASUK (KONTEN ASLI, TIDAK DIUBAH) -->
<div class="modal-overlay" id="tambahModal">
    <div class="modal-box">
        <form method="POST" id="formTambah">
            <div class="modal-header">
                <div class="modal-title">Input Barang Masuk (dari Pre Order)</div>
                <button type="button" class="modal-close" onclick="closeModal('tambahModal')"><i class="bi bi-x"></i></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="0">
                <input type="hidden" name="items_json" id="itemsJsonTambah">
                <input type="hidden" name="supplier" id="supplierTambah">
                <input type="hidden" name="po_id" id="po_id_tambah" value="0">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Nomor Faktur</label>
                        <input type="text" name="nomor_faktur" class="form-control" value="<?= htmlspecialchars(generateNomorFaktur()) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal</label>
                        <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <div class="card" style="margin-top:16px; border:1px solid var(--border);">
                    <div class="card-head"><div class="card-head-title">Pilih Pre Order (Sudah Disetujui)</div></div>
                    <div style="padding:16px;">
                        <div class="form-group">
                            <label class="form-label">Pilih PO</label>
                            <select id="pilihPO" class="form-control" required>
                                <option value="">-- Pilih Nomor PO --</option>
                                <?php if($po_list && $po_list->num_rows > 0):
                                    $po_list->data_seek(0);
                                    while($po = $po_list->fetch_assoc()):
                                        $supplier_nama_opt = htmlspecialchars($po['supplier_nama'], ENT_QUOTES, 'UTF-8');
                                ?>
                                <option value="<?= (int)$po['id'] ?>" data-supplier="<?= $supplier_nama_opt ?>" data-supplier-id="<?= (int)$po['supplier_id'] ?>">
                                    PO-<?= (int)$po['id'] ?> - <?= $supplier_nama_opt ?>
                                </option>
                                <?php endwhile; else: ?>
                                <option value="" disabled>Tidak ada PO yang tersedia</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-group mt-2">
                            <label class="form-label">Supplier</label>
                            <input type="text" id="supplierDisplay" class="form-control" readonly placeholder="Supplier akan otomatis terisi">
                        </div>
                        <button type="button" id="ambilPO" class="btn-primary" style="margin-top:12px; width:100%;"><i class="bi bi-cart-plus"></i> Ambil Item dari PO</button>
                    </div>
                </div>

                <div style="margin-top:16px;">
                    <div class="section-label">Daftar Barang (isi harga beli sesuai faktur)</div>
                    <div class="item-list" id="daftarListTambah">
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

<!-- MODAL EDIT BARANG MASUK (KONTEN ASLI) -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <form method="POST" id="formEdit">
            <div class="modal-header">
                <div class="modal-title">Edit Barang Masuk</div>
                <button type="button" class="modal-close" onclick="closeModal('editModal')"><i class="bi bi-x"></i></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="items_json" id="itemsJsonEdit">
                <input type="hidden" name="supplier" id="edit_supplier_hidden">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Nomor Faktur</label>
                        <input type="text" name="nomor_faktur" id="edit_nomor_faktur" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal</label>
                        <input type="date" name="tanggal" id="edit_tanggal" class="form-control" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Supplier</label>
                    <input type="text" id="edit_supplier_display" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">Catatan</label>
                    <textarea name="catatan" id="edit_catatan" class="form-control" rows="2" style="resize:vertical;"></textarea>
                </div>
                <div style="margin-top:16px;">
                    <div class="section-label">Daftar Barang (isi harga beli)</div>
                    <div class="item-list" id="daftarListEdit">
                        <div class="item-empty">Belum ada barang</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Batal</button>
                <button type="submit" name="simpan" class="btn-primary"><i class="bi bi-check2"></i> Update</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL DETAIL (KONTEN ASLI) -->
<div class="modal-overlay" id="detailModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Detail Barang Masuk</div>
            <button type="button" class="modal-close" onclick="closeModal('detailModal')"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-grid" style="margin-bottom:16px;">
                <div><div class="form-label">ID Transaksi</div><div id="detail_id_transaksi">-</div></div>
                <div><div class="form-label">Nomor Faktur</div><div id="detail_nomor_faktur" style="font-weight:600;">-</div></div>
                <div><div class="form-label">Tanggal</div><div id="detail_tanggal">-</div></div>
                <div><div class="form-label">Supplier</div><div id="detail_supplier">-</div></div>
            </div>
            <div class="section-label">Daftar Barang</div>
            <div class="item-list" id="detail_items"><div class="item-empty">-</div></div>
            <div style="margin-top:16px;">
                <div class="form-label">Catatan</div>
                <div id="detail_catatan" style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 12px;font-size:13.5px;">-</div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('detailModal')">Tutup</button></div>
    </div>
</div>

<script>
function escapeHtml(str) { if(!str) return ''; return str.replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

const sidebar = document.getElementById('sidebar');
const main = document.getElementById('main');
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
function openModal(id) { document.getElementById(id).classList.add('show'); document.body.style.overflow='hidden'; }
function closeModal(id) { document.getElementById(id).classList.remove('show'); document.body.style.overflow=''; }
document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => { if(e.target===o) closeModal(o.id); }));
document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.modal-overlay.show').forEach(m => closeModal(m.id)); });

function initBarangMasukItemManager(listId, jsonId) {
    let items = [];
    const container = document.getElementById(listId);
    const jsonField = document.getElementById(jsonId);
    function render() {
        if (!container) return;
        if (items.length === 0) {
            container.innerHTML = '<div class="item-empty">Belum ada barang ditambahkan</div>';
            if (jsonField) jsonField.value = '';
            return;
        }
        container.innerHTML = '';
        items.forEach((it, idx) => {
            const div = document.createElement('div');
            div.className = 'item-row';
            const satuanSelectHtml = it.has_satuan_besar
                ? `<select class="form-control satuan-input" data-index="${idx}" style="width:110px;">
                        <option value="besar" ${it.satuan === 'besar' ? 'selected' : ''}>${escapeHtml(it.satuan_besar_label || 'Karung')}</option>
                        <option value="kg" ${it.satuan === 'kg' ? 'selected' : ''}>${escapeHtml(it.satuan_kg_label || 'Kg')}</option>
                   </select>`
                : `<span class="badge badge-gray">${escapeHtml(it.satuan_kg_label || 'Kg')}</span>`;
            div.innerHTML = `
                <div style="flex:2; font-weight:500;">${escapeHtml(it.nama)} <span class="badge badge-gray">Qty: ${it.qty}</span></div>
                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <div class="form-group" style="margin:0; flex-direction:row; align-items:center; gap:4px;">
                        <label class="form-label" style="margin:0;">Satuan</label>
                        ${satuanSelectHtml}
                    </div>
                    <div class="form-group" style="margin:0; flex-direction:row; align-items:center; gap:4px;">
                        <label class="form-label" style="margin:0;">Harga</label>
                        <input type="number" class="form-control harga-input" data-index="${idx}" value="${it.harga || 0}" style="width:130px;" placeholder="Harga beli" step="100">
                    </div>
                    <button type="button" class="btn-action danger" onclick="removeItem_${listId}(${idx})" data-tooltip="Hapus"><i class="bi bi-trash"></i></button>
                </div>
            `;
            container.appendChild(div);
        });
        document.querySelectorAll(`#${listId} .harga-input`).forEach(inp => {
            inp.removeEventListener('input', handleHarga);
            inp.addEventListener('input', handleHarga);
        });
        document.querySelectorAll(`#${listId} .satuan-input`).forEach(sel => {
            sel.removeEventListener('change', handleSatuan);
            sel.addEventListener('change', handleSatuan);
        });
        function handleHarga(e) {
            let idx = parseInt(e.target.dataset.index);
            if (items[idx]) items[idx].harga = parseFloat(e.target.value) || 0;
            syncJson();
        }
        function handleSatuan(e) {
            let idx = parseInt(e.target.dataset.index);
            if (items[idx]) items[idx].satuan = e.target.value;
            syncJson();
        }
        function syncJson() {
            if (jsonField) jsonField.value = JSON.stringify(items.map(i => ({ barang_id: i.barang_id, qty: i.qty, harga: i.harga, satuan: i.satuan || 'kg' })));
        }
        syncJson();
    }
    window[`removeItem_${listId}`] = (idx) => { items.splice(idx,1); render(); };
    return {
        setItems: (newItems) => { items = newItems.map(i => ({ ...i, harga: i.harga || 0, satuan: i.satuan || 'kg' })); render(); },
        sync: () => { if (jsonField) jsonField.value = JSON.stringify(items.map(i => ({ barang_id: i.barang_id, qty: i.qty, harga: i.harga, satuan: i.satuan || 'kg' }))); }
    };
}

const tambahManager = initBarangMasukItemManager('daftarListTambah', 'itemsJsonTambah');
const editManager   = initBarangMasukItemManager('daftarListEdit', 'itemsJsonEdit');

document.getElementById('formTambah')?.addEventListener('submit', () => tambahManager.sync());
document.getElementById('formEdit')?.addEventListener('submit', () => editManager.sync());

document.getElementById('ambilPO')?.addEventListener('click', function() {
    const select = document.getElementById('pilihPO');
    const poId = select.value;
    if (!poId) { alert('Pilih nomor PO terlebih dahulu'); return; }
    const supplierNama = select.options[select.selectedIndex]?.getAttribute('data-supplier');
    if (!supplierNama) { alert('Supplier tidak valid'); return; }
    fetch(`?get_po_items=${poId}`)
        .then(res => res.json())
        .then(data => {
            if (data.error) { alert(data.error); return; }
            if (data.items.length === 0) { alert('PO tidak memiliki item yang valid'); return; }
            document.getElementById('supplierDisplay').value = supplierNama;
            document.getElementById('supplierTambah').value = supplierNama;
            document.getElementById('po_id_tambah').value = poId;
            const newItems = data.items.map(item => ({
                barang_id: item.barang_id,
                nama: item.nama,
                qty: item.qty,
                harga: 0,
                satuan: item.satuan,
                has_satuan_besar: item.has_satuan_besar,
                satuan_kg_label: item.satuan_kg_label,
                satuan_besar_label: item.satuan_besar_label
            }));
            tambahManager.setItems(newItems);
            alert("Silakan periksa satuan (karung/kg) dan isi harga beli sesuai faktur sebelum simpan!");
        })
        .catch(err => alert('Gagal mengambil data PO: ' + err));
});

document.querySelectorAll('.btn-edit').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        fetch(`?get_data=${id}`)
            .then(res => res.json())
            .then(data => {
                if (data.error) { alert(data.error); return; }
                document.getElementById('edit_id').value = data.id;
                document.getElementById('edit_nomor_faktur').value = data.nomor_faktur;
                document.getElementById('edit_tanggal').value = data.tanggal;
                document.getElementById('edit_supplier_display').value = data.supplier_nama;
                document.getElementById('edit_supplier_hidden').value = data.supplier_nama;
                document.getElementById('edit_catatan').value = data.catatan || '';
                const items = data.items.map(item => ({
                    barang_id: item.barang_id,
                    nama: item.nama,
                    qty: item.qty,
                    harga: item.harga,
                    satuan: item.satuan,
                    has_satuan_besar: item.has_satuan_besar,
                    satuan_kg_label: item.satuan_kg_label,
                    satuan_besar_label: item.satuan_besar_label
                }));
                editManager.setItems(items);
                openModal('editModal');
            })
            .catch(err => alert('Gagal mengambil data: ' + err));
    });
});

document.querySelectorAll('.btn-detail').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        fetch(`?get_data=${id}`)
            .then(res => res.json())
            .then(data => {
                if (data.error) { alert(data.error); return; }
                document.getElementById('detail_id_transaksi').innerText = '#' + data.id;
                document.getElementById('detail_nomor_faktur').innerText = data.nomor_faktur;
                let tglSplit = data.tanggal.split('-');
                document.getElementById('detail_tanggal').innerText = tglSplit.reverse().join('/');
                document.getElementById('detail_supplier').innerText = data.supplier_nama;
                document.getElementById('detail_catatan').innerText = data.catatan || '-';
                const container = document.getElementById('detail_items');
                if (!data.items.length) {
                    container.innerHTML = '<div class="item-empty">Tidak ada barang</div>';
                } else {
                    container.innerHTML = '';
                    let totalBiaya = 0;
                    data.items.forEach(item => {
                        let subtotal = item.qty * item.harga;
                        totalBiaya += subtotal;
                        const satuanLbl = item.satuan === 'besar' ? (item.satuan_besar_label || 'Karung') : (item.satuan_kg_label || 'Kg');
                        const div = document.createElement('div');
                        div.className = 'item-row';
                        div.innerHTML = `
                            <div><strong>${escapeHtml(item.nama)}</strong> <span class="badge badge-gray">Qty: ${item.qty} ${escapeHtml(satuanLbl)}</span></div>
                            <div>Rp ${item.harga.toLocaleString()} <span style="color:var(--text-muted);">/ ${escapeHtml(satuanLbl)}</span> → Rp ${subtotal.toLocaleString()}</div>
                        `;
                        container.appendChild(div);
                    });
                    const totalDiv = document.createElement('div');
                    totalDiv.className = 'item-row';
                    totalDiv.style.background = 'var(--surface-2)';
                    totalDiv.style.fontWeight = '600';
                    totalDiv.innerHTML = `<span>Total Biaya</span><span>Rp ${totalBiaya.toLocaleString()}</span>`;
                    container.appendChild(totalDiv);
                }
                openModal('detailModal');
            })
            .catch(err => alert('Gagal mengambil data: ' + err));
    });
});

const searchInput = document.getElementById('searchInput');
const tableBody = document.getElementById('tableBody');
const rowCountSpan = document.getElementById('rowCount');
function updateRowCount() {
    if (!tableBody || !rowCountSpan) return;
    const total = tableBody.querySelectorAll('tr').length;
    const visible = tableBody.querySelectorAll('tr:not([style*="display: none"])').length;
    rowCountSpan.innerText = visible === total ? `${total} item` : `${visible}/${total} item`;
}
if (searchInput) {
    searchInput.addEventListener('input', function() {
        const q = this.value.toLowerCase().trim();
        tableBody.querySelectorAll('tr').forEach(tr => {
            tr.style.display = tr.innerText.toLowerCase().includes(q) ? '' : 'none';
        });
        updateRowCount();
    });
}
updateRowCount();
document.querySelectorAll('.alert').forEach(a => setTimeout(() => a.remove(), 4500));
</script>
</body>
</html>