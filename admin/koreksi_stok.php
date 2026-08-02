<?php
require_once '../includes/auth.php';
cekLogin();
cekRole('admin');
require_once '../includes/functions.php';

global $conn;

// ========== FUNGSI BANTU ==========
function getStokRealTime($barang_id) {
    global $conn;
    $barang_id = (int)$barang_id;
    $stmt = $conn->prepare("SELECT stok, stok_besar, satuan_besar, satuan, sisa_karung_kg FROM barang WHERE id_barang = ?");
    $stmt->bind_param("i", $barang_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    if (!empty($row['satuan_besar'])) {
        // barang olahan → stok utama = karung (stok_besar)
        return [
            'stok'      => (int)($row['stok_besar'] ?? 0),
            'unit'      => $row['satuan_besar'],
            'sisa'      => (float)($row['stok'] + $row['sisa_karung_kg'] ?? 0),
            'sisa_unit' => $row['satuan']
        ];
    } else {
        return [
            'stok'      => (int)($row['stok'] ?? 0),
            'unit'      => $row['satuan'],
            'sisa'      => 0,
            'sisa_unit' => $row['satuan']
        ];
    }
}

function uploadBuktiFoto($file, $no_opname, $barang_id) {
    $target_dir = __DIR__ . '/../uploads/koreksi/';
    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 2 * 1024 * 1024) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
    if (!in_array($ext, $allowed_ext)) return null;
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
    if (!in_array($mime, $allowed_mime)) return null;
    $filename = $no_opname . '_' . $barang_id . '_' . time() . '.' . $ext;
    $target_file = $target_dir . $filename;
    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        return 'uploads/koreksi/' . $filename;
    }
    return null;
}

function hapusFoto($path) {
    $full = __DIR__ . '/../' . $path;
    if (file_exists($full)) unlink($full);
}

// ========== HAPUS PENGAJUAN (hanya jika masih Pending) ==========
if (isset($_GET['hapus_opname'])) {
    $no_opname = $_GET['hapus_opname'];
    $stmt = $conn->prepare("SELECT id, status FROM stock_opname WHERE no_opname = ?");
    $stmt->bind_param("s", $no_opname);
    $stmt->execute();
    $res_id = $stmt->get_result();
    if ($res_id && $row_id = $res_id->fetch_assoc()) {
        if ($row_id['status'] !== 'Pending') {
            $_SESSION['error'] = "Hanya pengajuan berstatus Pending yang dapat dihapus.";
            header("Location: koreksi_stok.php"); exit;
        }
        $opname_id = $row_id['id'];
        $conn->begin_transaction();
        try {
            $foto_items = $conn->query("SELECT bukti_foto FROM stock_opname_item WHERE opname_id = $opname_id");
            while ($f = $foto_items->fetch_assoc()) {
                if (!empty($f['bukti_foto'])) hapusFoto($f['bukti_foto']);
            }
            $conn->query("DELETE FROM stock_opname_item WHERE opname_id = $opname_id");
            $conn->query("DELETE FROM stock_opname WHERE id = $opname_id");
            $conn->commit();
            catatLog($_SESSION['username'], 'Hapus', 'stock_opname', $opname_id, "Hapus pengajuan koreksi $no_opname (status Pending).");
            $_SESSION['success'] = "Pengajuan koreksi berhasil dihapus.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Gagal menghapus: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Data tidak ditemukan.";
    }
    header("Location: koreksi_stok.php"); exit;
}

// ========== AJAX: Stok real-time ==========
if (isset($_GET['get_stok'])) {
    $id_barang = (int)$_GET['get_stok'];
    $data = getStokRealTime($id_barang);
    echo json_encode([
        'stok'      => $data['stok'],
        'unit'      => $data['unit'],
        'sisa'      => $data['sisa'],
        'sisa_unit' => $data['sisa_unit']
    ]);
    exit;
}

// ========== PROSES SIMPAN PENGAJUAN (Status: Pending) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_koreksi'])) {
    $periode        = $_POST['periode'];
    $tanggal_opname = $_POST['tanggal_opname'];
    $keterangan     = $_POST['keterangan'];
    $items_json     = $_POST['items_json'];
    $items          = json_decode($items_json, true);
    $username       = $_SESSION['username'] ?? 'admin';

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada barang']);
        exit;
    }

    // ===== PERBAIKAN: created_by = ID user (integer), bukan username =====
    $userId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
    if (!$userId) {
        // fallback: ambil dari database berdasarkan username
        $u = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $u->bind_param("s", $username);
        $u->execute();
        $u_res = $u->get_result();
        if ($u_res->num_rows) {
            $userId = $u_res->fetch_assoc()['id'];
        } else {
            echo json_encode(['success' => false, 'message' => 'User tidak ditemukan di database.']);
            exit;
        }
    }

    // Generate no_opname unik
    $no_opname = 'SO-' . date('Ymd') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    $cek = $conn->query("SELECT id FROM stock_opname WHERE no_opname = '$no_opname'");
    while ($cek->num_rows > 0) {
        $no_opname = 'SO-' . date('Ymd') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        $cek = $conn->query("SELECT id FROM stock_opname WHERE no_opname = '$no_opname'");
    }

    $total_item = count($items);
    $conn->begin_transaction();
    try {
        // INSERT dengan created_by = integer
        $stmt = $conn->prepare("INSERT INTO stock_opname (no_opname, periode, tanggal, total_item, status, keterangan, created_by) VALUES (?, ?, ?, ?, 'Pending', ?, ?)");
        $stmt->bind_param("sssisi", $no_opname, $periode, $tanggal_opname, $total_item, $keterangan, $userId);
        $stmt->execute();
        $opname_id = $conn->insert_id;

        $stmt_item = $conn->prepare("INSERT INTO stock_opname_item (opname_id, barang_id, stok_sistem, stok_fisik, selisih, keterangan_item, bukti_foto) VALUES (?, ?, ?, ?, ?, ?, ?)");

        foreach ($items as $idx => $item) {
            if (!isset($_FILES['foto_' . $idx]) || $_FILES['foto_' . $idx]['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Bukti foto wajib diupload untuk barang {$item['nama']}");
            }
            $foto_path = uploadBuktiFoto($_FILES['foto_' . $idx], $no_opname, $item['barang_id']);
            if (!$foto_path) {
                throw new Exception("Gagal upload foto untuk barang {$item['nama']}");
            }
            $stmt_item->bind_param("iiiiiss", $opname_id, $item['barang_id'], $item['stok_sistem'], $item['stok_fisik'], $item['selisih'], $keterangan, $foto_path);
            $stmt_item->execute();
        }

        $conn->commit();
        catatLog($username, 'Tambah', 'stock_opname', $opname_id, "Pengajuan koreksi $no_opname (Pending) — $total_item item, menunggu persetujuan Owner.");
        echo json_encode(['success' => true, 'id' => $no_opname]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()]);
    }
    exit;
}

// ========== AMBIL DATA KOREKSI MILIK ADMIN INI ==========
$allOpname = [];
$res = $conn->query("SELECT * FROM stock_opname ORDER BY tanggal DESC, id DESC");
while ($row = $res->fetch_assoc()) {
    $items = [];
    $res_item = $conn->query("
        SELECT i.*, b.nama_barang, b.satuan, b.satuan_besar
        FROM stock_opname_item i
        JOIN barang b ON i.barang_id = b.id_barang
        WHERE i.opname_id = {$row['id']}
    ");
    while ($item = $res_item->fetch_assoc()) {
        $items[] = [
            'nama'        => $item['nama_barang'],
            'stok_sistem' => $item['stok_sistem'],
            'stok_fisik'  => $item['stok_fisik'],
            'selisih'     => $item['selisih'],
            'bukti_foto'  => $item['bukti_foto'] ?? null,
            'satuan'      => !empty($item['satuan_besar']) ? $item['satuan_besar'] : $item['satuan']
        ];
    }
    $allOpname[$row['no_opname']] = [
        'id'          => $row['no_opname'],
        'periode'     => $row['periode'],
        'tanggal'     => $row['tanggal'],
        'total_item'  => $row['total_item'],
        'status'      => $row['status'],
        'items'       => $items,
        'keterangan'  => $row['keterangan'],
        'catatan_owner' => $row['catatan_owner'] ?? ''
    ];
}

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
    <title>Koreksi Stok — Keva Jaya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ========== CSS (sama seperti sebelumnya, tidak diubah) ========== */
        :root {
            --bg: #f5f4f0; --surface: #ffffff; --surface-2: #f9f8f5;
            --border: #e8e6e0; --border-strong: #d4d0c8;
            --text-primary: #1a1916; --text-secondary: #6b6860; --text-muted: #9c9890;
            --accent: #2d6a4f; --accent-light: #e8f4ee; --accent-hover: #245a42;
            --danger: #c0392b; --danger-light: #fdecea;
            --warning: #d68910; --warning-light: #fef9e7;
            --info: #1a5276; --info-light: #e8f0f8;
            --pending: #7d5a00; --pending-light: #fff8e1;
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
        .topbar-right { margin-left: auto; display: flex; align-items: center; gap: 10px; }

        .page-body { padding: 24px; flex: 1; }
        .page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
        .page-title { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; }
        .page-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 2px; }

        .info-banner { background: var(--info-light); border: 1px solid #b8d4e8; border-radius: var(--radius); padding: 12px 16px; display: flex; align-items: flex-start; gap: 10px; margin-bottom: 20px; font-size: 13px; color: var(--info); }
        .info-banner i { font-size: 16px; flex-shrink: 0; margin-top: 1px; }

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

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead th { padding: 11px 16px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.4px; text-transform: uppercase; color: var(--text-muted); background: var(--surface-2); border-bottom: 1px solid var(--border); white-space: nowrap; }
        tbody td { padding: 13px 16px; border-bottom: 1px solid var(--border); color: var(--text-primary); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr { transition: background 0.15s; }
        tbody tr:hover { background: var(--surface-2); }

        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 550; }
        .badge-green  { background: var(--accent-light); color: var(--accent); }
        .badge-gray   { background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border); }
        .badge-pending { background: var(--pending-light); color: var(--pending); border: 1px solid #ffe082; }
        .badge-danger  { background: var(--danger-light); color: var(--danger); border: 1px solid #f5b7b1; }

        .btn-action { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary); cursor: pointer; transition: var(--transition); text-decoration: none; font-size: 14px; }
        .btn-action:hover { background: var(--surface-2); border-color: var(--border-strong); color: var(--text-primary); }
        .btn-action.danger:hover { background: var(--danger-light); border-color: #f5b7b1; color: var(--danger); }
        .btn-primary { display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; background: var(--accent); color: white; border: none; border-radius: var(--radius-sm); font-size: 13.5px; font-weight: 600; font-family: inherit; cursor: pointer; transition: var(--transition); text-decoration: none; }
        .btn-primary:hover { background: var(--accent-hover); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(45,106,79,0.25); }
        .btn-primary i { font-size: 15px; }
        .btn-cancel { padding: 8px 16px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text-secondary); font-size: 13.5px; font-weight: 500; font-family: inherit; cursor: pointer; transition: var(--transition); }
        .btn-cancel:hover { background: var(--surface-2); }
        .row-count { font-size: 12px; color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border); padding: 3px 10px; border-radius: 99px; }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); backdrop-filter: blur(4px); z-index: 200; display: none; align-items: center; justify-content: center; padding: 20px; animation: fadeIn 0.15s ease; }
        @keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
        .modal-overlay.show { display: flex; }
        .modal-box { background: var(--surface); border-radius: 14px; width: 100%; max-width: 780px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); animation: slideUp 0.2s cubic-bezier(0.34,1.56,0.64,1); max-height: 90vh; display: flex; flex-direction: column; }
        @keyframes slideUp { from { opacity:0; transform:translateY(20px) scale(0.98); } to { opacity:1; transform:translateY(0) scale(1); } }
        .modal-header { padding: 20px 24px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
        .modal-title { font-size: 16px; font-weight: 700; letter-spacing: -0.3px; }
        .modal-close { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius-sm); border: 1px solid var(--border); background: none; cursor: pointer; color: var(--text-muted); font-size: 16px; }
        .modal-close:hover { background: var(--surface-2); color: var(--text-primary); }
        .modal-body { padding: 20px 24px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-label { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); letter-spacing: 0.2px; }
        .form-control, .form-select { padding: 9px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13.5px; font-family: inherit; background: var(--surface); width: 100%; }
        .form-control:focus, .form-select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }

        .item-list { border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; margin-top: 8px; max-height: 250px; overflow-y: auto; }
        .item-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-bottom: 1px solid var(--border); font-size: 13px; flex-wrap: wrap; gap: 8px; }
        .item-row:last-child { border-bottom: none; }
        .item-row:hover { background: var(--surface-2); }
        .item-empty { padding: 16px; text-align: center; color: var(--text-muted); }
        .section-label { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .selisih-negatif { color: var(--danger); font-weight: 600; }
        .selisih-positif { color: var(--accent); font-weight: 600; }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 36px; opacity: 0.4; display: block; margin-bottom: 10px; }
        [data-tooltip] { position: relative; }
        [data-tooltip]:hover::after { content: attr(data-tooltip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: var(--text-primary); color: white; padding: 4px 10px; border-radius: 5px; font-size: 11.5px; white-space: nowrap; pointer-events: none; z-index: 99; }
        @media (max-width: 768px) { .sidebar { left: calc(-1 * var(--sidebar-w)); } .sidebar.mobile-open { left: 0; } .main { margin-left: 0; } .form-grid { grid-template-columns: 1fr; } .modal-box { max-width: 95%; } }
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
            <div><div class="user-name"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></div><div class="user-role">Administrator</div></div>
            <a href="../logout.php" class="btn-sm" style="margin-left:auto;border:none;" data-tooltip="Logout"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </div>
</aside>

<!-- MAIN -->
<div class="main" id="main">
    <header class="topbar">
        <button class="btn-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="breadcrumb-bar">
            <a href="dashboard.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Koreksi Stok</span>
        </div>
        <div class="topbar-right"><span style="font-size:12px;color:var(--text-muted);"><?= date('d M Y') ?></span></div>
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
                <div class="page-title">Koreksi Stok</div>
                <div class="page-subtitle">Ajukan koreksi stok fisik — persetujuan Owner diperlukan</div>
            </div>
            <button class="btn-primary" onclick="openModal('tambahModal')">
                <i class="bi bi-plus"></i> Buat Pengajuan Koreksi
            </button>
        </div>

        <!-- INFO BANNER -->
        <div class="info-banner">
            <i class="bi bi-info-circle-fill"></i>
            <div>
                <strong>Alur Koreksi Stok Bertingkat:</strong>
                Admin mengajukan koreksi → status <strong>Pending</strong> → Owner menyetujui/menolak → stok baru diperbarui jika <strong>Disetujui</strong>.
                Stok tidak berubah hingga ada persetujuan Owner.
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div class="card-head-title">
                    <i class="bi bi-pencil-square"></i> Riwayat Pengajuan Koreksi
                    <span class="row-count" id="rowCount"></span>
                </div>
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input type="text" class="search-input" id="searchInput" placeholder="Cari ID atau periode...">
                </div>
            </div>
            <div class="table-wrap">
                <table id="opnameTable">
                    <thead>
                        <tr>
                            <th>ID Pengajuan</th>
                            <th>Periode</th>
                            <th>Tanggal</th>
                            <th>Total Item</th>
                            <th>Status</th>
                            <th style="text-align:center;width:100px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php if (!empty($allOpname)): ?>
                            <?php foreach ($allOpname as $row): ?>
                            <?php
                                $badgeClass = match($row['status']) {
                                    'Disetujui' => 'badge-green',
                                    'Ditolak'   => 'badge-danger',
                                    default     => 'badge-pending'
                                };
                                $badgeIcon = match($row['status']) {
                                    'Disetujui' => '<i class="bi bi-check-circle"></i>',
                                    'Ditolak'   => '<i class="bi bi-x-circle"></i>',
                                    default     => '<i class="bi bi-clock"></i>'
                                };
                            ?>
                            <tr data-id="<?= htmlspecialchars($row['id']) ?>">
                                <td><span style="font-family:'JetBrains Mono',monospace;font-size:12.5px;"><?= htmlspecialchars($row['id']) ?></span></td>
                                <td style="font-weight:500;"><?= htmlspecialchars($row['periode']) ?></td>
                                <td style="color:var(--text-secondary);"><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                                <td><span class="badge badge-gray"><?= (int)$row['total_item'] ?> barang</span></td>
                                <td><span class="badge <?= $badgeClass ?>"><?= $badgeIcon ?> <?= htmlspecialchars($row['status']) ?></span></td>
                                <td>
                                    <div style="display:flex;gap:6px;justify-content:center;">
                                        <button class="btn-action btn-detail" data-id="<?= htmlspecialchars($row['id']) ?>" data-tooltip="Detail">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <?php if ($row['status'] === 'Pending'): ?>
                                        <a href="?hapus_opname=<?= urlencode($row['id']) ?>" class="btn-action danger" data-tooltip="Batalkan"
                                           onclick="return confirm('Batalkan pengajuan koreksi ini?')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                        <?php else: ?>
                                        <button class="btn-action" disabled style="opacity:0.3;cursor:not-allowed;" data-tooltip="Tidak dapat dihapus">
                                            <i class="bi bi-lock"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6"><div class="empty-state"><i class="bi bi-inbox"></i><p>Belum ada pengajuan koreksi stok</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL TAMBAH PENGAJUAN -->
<div class="modal-overlay" id="tambahModal">
    <div class="modal-box" style="max-width:780px;">
        <div class="modal-header">
            <div>
                <div class="modal-title">Buat Pengajuan Koreksi Stok</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px;">Pengajuan akan dikirim ke Owner untuk disetujui</div>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('tambahModal')"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body">
            <form id="formOpname" enctype="multipart/form-data">
                <div class="form-grid" style="margin-bottom:20px;">
                    <div class="form-group">
                        <label class="form-label">Bulan</label>
                        <select id="bulan" class="form-select">
                            <?php for($m=1;$m<=12;$m++) echo "<option value='".date('F', mktime(0,0,0,$m,1))."'".($m==date('n')?' selected':'').">".date('F', mktime(0,0,0,$m,1))."</option>"; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Minggu Ke-</label>
                        <select id="minggu" class="form-select">
                            <option>1</option><option>2</option><option>3</option><option>4</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tahun</label>
                        <input type="number" id="tahun" class="form-control" value="<?= date('Y') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal Koreksi</label>
                        <input type="date" id="tanggal_opname" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>

                <div class="card" style="margin-bottom:16px;">
                    <div class="card-head"><div class="card-head-title"><i class="bi bi-search"></i> Cek &amp; Tambah Barang</div></div>
                    <div style="padding:16px;">
                        <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:10px;margin-bottom:10px;">
                            <div class="form-group">
                                <label class="form-label">Barang</label>
                                <select id="barangSelect" class="form-select">
                                    <option value="">-- Pilih Barang --</option>
                                    <?php $barang_list->data_seek(0); while($b = $barang_list->fetch_assoc()): ?>
                                    <option value="<?= $b['id_barang'] ?>"><?= htmlspecialchars($b['nama_barang']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Stok Sistem</label>
                                <div style="display:flex;gap:8px;align-items:center;">
                                    <input type="text" id="stokSistem" class="form-control" readonly placeholder="—" style="flex:1;">
                                    <span id="unitSistem" style="font-size:13px;color:var(--text-secondary);">kg</span>
                                </div>
                                <div id="infoSisa" style="font-size:12px;color:var(--info);margin-top:4px;"></div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Stok Fisik</label>
                                <input type="number" id="stokFisik" class="form-control" value="0" min="0">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Selisih</label>
                                <input type="text" id="selisih" class="form-control" readonly placeholder="—">
                            </div>
                        </div>
                        <div class="form-group" style="margin-bottom:12px;">
                            <label class="form-label">Bukti Foto <span style="color:var(--danger);">*</span></label>
                            <input type="file" id="fotoTemp" class="form-control" accept="image/*,application/pdf">
                            <span style="font-size:11px;color:var(--text-muted);">Maks. 2MB · JPG, PNG, PDF</span>
                        </div>
                        <button type="button" id="tambahBtn" class="btn-primary" style="width:100%;">
                            <i class="bi bi-plus"></i> Tambah ke Daftar
                        </button>
                    </div>
                </div>

                <div class="section-label">Daftar Barang yang Dikoreksi</div>
                <div class="item-list" id="daftarList"><div class="item-empty">Belum ada barang ditambahkan</div></div>
                <input type="hidden" id="itemsJson">

                <div class="form-group" style="margin-top:16px;">
                    <label class="form-label">Keterangan / Alasan Koreksi</label>
                    <textarea id="keterangan" class="form-control" rows="2" placeholder=""></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('tambahModal')">Batal</button>
            <button type="button" class="btn-primary" id="simpanOpnameBtn">
                <i class="bi bi-send"></i> Ajukan ke Owner
            </button>
        </div>
    </div>
</div>

<!-- MODAL DETAIL -->
<div class="modal-overlay" id="detailModal">
    <div class="modal-box" style="max-width:800px;">
        <div class="modal-header">
            <div class="modal-title">Detail Pengajuan Koreksi</div>
            <button type="button" class="modal-close" onclick="closeModal('detailModal')"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body" id="detailContent"></div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('detailModal')">Tutup</button>
        </div>
    </div>
</div>

<script>
    // Sidebar
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
    function openModal(id)  { document.getElementById(id).classList.add('show'); document.body.style.overflow='hidden'; }
    function closeModal(id) { document.getElementById(id).classList.remove('show'); document.body.style.overflow=''; }
    document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => { if(e.target===o) closeModal(o.id); }));
    document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.modal-overlay.show').forEach(m => closeModal(m.id)); });
    function escapeHtml(str) {
        if(!str) return '';
        return str.replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }

    // Item management
    let items = [];
    const barangSelect  = document.getElementById('barangSelect');
    const stokSistemEl  = document.getElementById('stokSistem');
    const stokFisikEl   = document.getElementById('stokFisik');
    const selisihEl     = document.getElementById('selisih');
    const fotoTempEl    = document.getElementById('fotoTemp');
    const daftarList    = document.getElementById('daftarList');
    const itemsJsonEl   = document.getElementById('itemsJson');
    const unitSistem    = document.getElementById('unitSistem');
    const infoSisa      = document.getElementById('infoSisa');

    function hitungSelisih() {
        const sistem = parseInt(stokSistemEl.value)||0;
        const fisik  = parseInt(stokFisikEl.value)||0;
        const diff   = fisik - sistem;
        selisihEl.value = diff;
        selisihEl.classList.toggle('selisih-negatif', diff<0);
        selisihEl.classList.toggle('selisih-positif', diff>0);
        selisihEl.style.color = diff<0 ? 'var(--danger)' : (diff>0 ? 'var(--accent)' : '');
    }
    async function loadStokSistem(barangId) {
        if (!barangId) { stokSistemEl.value=''; unitSistem.textContent='kg'; infoSisa.textContent=''; return; }
        try {
            const res = await fetch(`?get_stok=${barangId}`);
            const data = await res.json();
            stokSistemEl.value = data.stok;
            stokFisikEl.value  = data.stok;
            unitSistem.textContent = data.unit;
            if (data.sisa > 0) {
                infoSisa.textContent = `Sisa ${data.sisa} ${data.sisa_unit}`;
            } else {
                infoSisa.textContent = '';
            }
            hitungSelisih();
        } catch(e) { stokSistemEl.value='Error'; }
    }
    barangSelect.addEventListener('change', function() {
        if (this.value) loadStokSistem(this.value);
        else { stokSistemEl.value=''; stokFisikEl.value=''; selisihEl.value=''; unitSistem.textContent='kg'; infoSisa.textContent=''; }
    });
    stokFisikEl.addEventListener('input', hitungSelisih);

    function updateList() {
        daftarList.innerHTML = '';
        if (items.length === 0) {
            daftarList.innerHTML = '<div class="item-empty">Belum ada barang ditambahkan</div>';
            itemsJsonEl.value = '';
            return;
        }
        items.forEach((item, idx) => {
            const cls = item.selisih<0 ? 'selisih-negatif' : (item.selisih>0 ? 'selisih-positif' : '');
            const tampil = item.selisih>0 ? '+' + item.selisih : item.selisih;
            const fileName = item.file ? item.file.name : '—';
            const div = document.createElement('div');
            div.className = 'item-row';
            div.innerHTML = `
                <div style="flex:1;">
                    <strong>${escapeHtml(item.nama)}</strong>
                    <div style="margin-top:4px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                        <span class="badge badge-gray">Sistem: ${item.stok_sistem} ${item.unit}</span>
                        <span class="badge badge-gray">Fisik: ${item.stok_fisik} ${item.unit}</span>
                        <span class="${cls}" style="font-size:13px;">Selisih: ${tampil}</span>
                    </div>
                    <div style="margin-top:4px;font-size:12px;color:var(--text-muted);">
                        <i class="bi bi-paperclip"></i> ${escapeHtml(fileName)}
                    </div>
                </div>
                <button type="button" class="btn-action danger" onclick="hapusItem(${idx})" data-tooltip="Hapus">
                    <i class="bi bi-trash"></i>
                </button>`;
            daftarList.appendChild(div);
        });
        itemsJsonEl.value = JSON.stringify(items.map(({barang_id, nama, stok_sistem, stok_fisik, selisih}) =>
            ({barang_id, nama, stok_sistem, stok_fisik, selisih})));
    }
    window.hapusItem = (idx) => { items.splice(idx,1); updateList(); };

    document.getElementById('tambahBtn').addEventListener('click', () => {
        const barangId = barangSelect.value;
        if (!barangId)       { alert('Pilih barang terlebih dahulu'); return; }
        const nama   = barangSelect.options[barangSelect.selectedIndex].text;
        const sistem = parseInt(stokSistemEl.value)||0;
        const fisik  = parseInt(stokFisikEl.value)||0;
        const diff   = parseInt(selisihEl.value)||0;
        const unit   = unitSistem.textContent;
        if (fisik < 0)                       { alert('Stok fisik tidak boleh negatif'); return; }
        if (items.some(i=>i.barang_id==barangId)) { alert('Barang ini sudah ditambahkan!'); return; }
        const file = fotoTempEl.files[0];
        if (!file) { alert('Bukti foto wajib diupload!'); return; }
        items.push({ barang_id: barangId, nama, stok_sistem: sistem, stok_fisik: fisik, selisih: diff, file, unit });
        updateList();
        barangSelect.value=''; stokSistemEl.value=''; stokFisikEl.value='0'; selisihEl.value=''; unitSistem.textContent='kg'; infoSisa.textContent=''; fotoTempEl.value='';
    });

    // Data dari PHP
    let opnameData = <?= json_encode($allOpname) ?>;

    // Simpan pengajuan
    document.getElementById('simpanOpnameBtn').addEventListener('click', async () => {
        if (items.length === 0) { alert('Minimal tambahkan satu barang'); return; }
        const bulan   = document.getElementById('bulan').value;
        const minggu  = document.getElementById('minggu').value;
        const tahun   = document.getElementById('tahun').value;
        const periode = `${bulan} ${tahun} - Minggu ${minggu}`;
        const tgl     = document.getElementById('tanggal_opname').value;
        const ket     = document.getElementById('keterangan').value;

        const btn = document.getElementById('simpanOpnameBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Mengirim...';

        const formData = new FormData();
        formData.append('simpan_koreksi', '1');
        formData.append('periode', periode);
        formData.append('tanggal_opname', tgl);
        formData.append('keterangan', ket);
        formData.append('items_json', JSON.stringify(items.map(({barang_id,nama,stok_sistem,stok_fisik,selisih}) =>
            ({barang_id,nama,stok_sistem,stok_fisik,selisih}))));
        items.forEach((item, idx) => { if(item.file) formData.append(`foto_${idx}`, item.file); });

        try {
            const response = await fetch(window.location.href, { method: 'POST', body: formData });
            const result   = await response.json();
            if (result.success) {
                const newId = result.id;
                opnameData[newId] = {
                    id: newId, periode, tanggal: tgl, total_item: items.length,
                    status: 'Pending', keterangan: ket, catatan_owner: '',
                    items: items.map(({nama,stok_sistem,stok_fisik,selisih,unit}) =>
                        ({nama, stok_sistem, stok_fisik, selisih, bukti_foto: null, satuan: unit}))
                };
                // Tambah baris ke tabel
                const tBody = document.getElementById('tableBody');
                const emptyRow = tBody.querySelector('.empty-state');
                if (emptyRow) emptyRow.closest('tr').remove();
                const newRow = document.createElement('tr');
                newRow.setAttribute('data-id', newId);
                newRow.innerHTML = `
                    <td><span style="font-family:'JetBrains Mono',monospace;font-size:12.5px;">${escapeHtml(newId)}</span></td>
                    <td style="font-weight:500;">${escapeHtml(periode)}</td>
                    <td style="color:var(--text-secondary);">${new Date(tgl).toLocaleDateString('id-ID')}</td>
                    <td><span class="badge badge-gray">${items.length} barang</span></td>
                    <td><span class="badge badge-pending"><i class="bi bi-clock"></i> Pending</span></td>
                    <td>
                        <div style="display:flex;gap:6px;justify-content:center;">
                            <button class="btn-action btn-detail" data-id="${escapeHtml(newId)}" data-tooltip="Detail"><i class="bi bi-eye"></i></button>
                            <a href="?hapus_opname=${encodeURIComponent(newId)}" class="btn-action danger" data-tooltip="Batalkan"
                               onclick="return confirm('Batalkan pengajuan ini?')"><i class="bi bi-trash"></i></a>
                        </div>
                    </td>
                `;
                tBody.insertBefore(newRow, tBody.firstChild);
                // Reset form
                items = []; updateList();
                document.getElementById('keterangan').value = '';
                barangSelect.value=''; stokSistemEl.value=''; stokFisikEl.value='0'; selisihEl.value=''; unitSistem.textContent='kg'; infoSisa.textContent=''; fotoTempEl.value='';
                closeModal('tambahModal');
                updateRowCount();
                // Tampilkan alert
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-success';
                alertDiv.innerHTML = `<i class="bi bi-check-circle-fill"></i> Pengajuan koreksi <strong>${escapeHtml(newId)}</strong> berhasil dikirim. Menunggu persetujuan Owner. <i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i>`;
                document.querySelector('.page-body').insertBefore(alertDiv, document.querySelector('.info-banner'));
                setTimeout(()=>alertDiv.remove(), 5000);
            } else {
                alert('Gagal: ' + result.message);
            }
        } catch(e) {
            alert('Terjadi kesalahan jaringan');
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send"></i> Ajukan ke Owner';
    });

    // Detail
    function formatDate(dateStr) {
        const d = new Date(dateStr);
        return `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')}/${d.getFullYear()}`;
    }
    function showDetail(id) {
        const data = opnameData[id];
        if (!data) { alert('Data tidak ditemukan'); return; }
        const badgeClass = {'Disetujui':'badge-green','Ditolak':'badge-danger','Pending':'badge-pending'}[data.status] || 'badge-gray';
        let rows = '';
        data.items.forEach(item => {
            const sel  = parseInt(item.selisih);
            const cls  = sel<0 ? 'selisih-negatif' : (sel>0 ? 'selisih-positif' : '');
            const tampil = sel>0 ? '+'+sel : sel;
            const foto   = item.bukti_foto
                ? `<a href="../${item.bukti_foto}" target="_blank" class="btn-action" data-tooltip="Lihat Foto"><i class="bi bi-file-image"></i></a>`
                : '<span style="color:var(--text-muted);font-size:12px;">—</span>';
            const satuan = item.satuan || 'kg';
            rows += `<tr>
                <td style="font-weight:500;">${escapeHtml(item.nama)}</td>
                <td>${item.stok_sistem} ${satuan}</td>
                <td>${item.stok_fisik} ${satuan}</td>
                <td class="${cls}">${tampil}</td>
                <td>${foto}</td>
            </tr>`;
        });
        const catatanOwner = data.catatan_owner
            ? `<div style="grid-column:1/-1;"><div class="form-label">Catatan Owner</div><div style="margin-top:4px;background:var(--warning-light);padding:10px 14px;border-radius:var(--radius-sm);border:1px solid #ffe082;font-size:13px;">${escapeHtml(data.catatan_owner)}</div></div>`
            : '';
        document.getElementById('detailContent').innerHTML = `
            <div class="form-grid" style="margin-bottom:20px;">
                <div><div class="form-label">ID Pengajuan</div><div style="margin-top:4px;font-family:'JetBrains Mono',monospace;font-size:12.5px;">${escapeHtml(data.id)}</div></div>
                <div><div class="form-label">Periode</div><div style="margin-top:4px;font-weight:500;">${escapeHtml(data.periode)}</div></div>
                <div><div class="form-label">Tanggal</div><div style="margin-top:4px;">${formatDate(data.tanggal)}</div></div>
                <div><div class="form-label">Status</div><div style="margin-top:4px;"><span class="badge ${badgeClass}">${escapeHtml(data.status)}</span></div></div>
                <div style="grid-column:1/-1;"><div class="form-label">Keterangan</div><div style="margin-top:4px;background:var(--surface-2);padding:10px 14px;border-radius:var(--radius-sm);border:1px solid var(--border);font-size:13px;">${escapeHtml(data.keterangan)||'<span style="color:var(--text-muted);">—</span>'}</div></div>
                ${catatanOwner}
            </div>
            <div class="section-label">Rincian Barang (${data.items.length} item)</div>
            <div class="table-wrap">
                <table class="table table-sm"> <thead> <tr> <th>Nama Barang</th> <th>Stok Sistem</th> <th>Stok Fisik</th> <th>Selisih</th> <th>Bukti Foto</th> </tr> </thead>
                <tbody>${rows}</tbody>
                </table>
            </div>`;
        openModal('detailModal');
    }

    document.getElementById('tableBody').addEventListener('click', e => {
        const btn = e.target.closest('.btn-detail');
        if (btn) showDetail(btn.getAttribute('data-id'));
    });

    // Search & row count
    const searchInput = document.getElementById('searchInput');
    const tableBody   = document.getElementById('tableBody');
    const rowCountEl  = document.getElementById('rowCount');
    function updateRowCount() {
        const rows    = tableBody.querySelectorAll('tr');
        const visible = [...rows].filter(r=>r.style.display!=='none').length;
        rowCountEl.textContent = visible === rows.length ? `${rows.length} item` : `${visible} / ${rows.length} item`;
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
    if (alertEl) setTimeout(()=>alertEl.remove(), 4500);
</script>
</body>
</html>