<?php
require_once '../includes/auth.php';
cekLogin();
cekRole('owner');
require_once '../includes/functions.php';

global $conn;

// ========== HANDLER AJAX: AMBIL DATA PRE ORDER UNTUK DETAIL ==========
if (isset($_GET['get_data'])) {
    header('Content-Type: application/json');
    ob_start();

    try {
        $id = (int)$_GET['get_data'];

        $po = getPreOrderById($id);
        if (!$po) {
            ob_end_clean();
            echo json_encode(['error' => 'Data tidak ditemukan']);
            exit;
        }

        // Ambil nama supplier
        $supplierNama = '-';
        if (!empty($po['supplier_id'])) {
            $stmtSup = $conn->prepare("SELECT nama FROM supplier WHERE id = ?");
            $stmtSup->bind_param("i", $po['supplier_id']);
            $stmtSup->execute();
            $supplierRow = $stmtSup->get_result()->fetch_assoc();
            $supplierNama = $supplierRow['nama'] ?? '-';
        }

        // Ambil nama approved_by dari users (jika ada dan berupa ID)
        $approvedByName = '-';
        if (!empty($po['approved_by']) && is_numeric($po['approved_by'])) {
            $stmtUser = $conn->prepare("SELECT username FROM users WHERE id = ?");
            $stmtUser->bind_param("i", $po['approved_by']);
            $stmtUser->execute();
            $userRow = $stmtUser->get_result()->fetch_assoc();
            $approvedByName = $userRow['username'] ?? 'User #' . $po['approved_by'];
        } elseif (!empty($po['approved_by'])) {
            // Jika approved_by berupa string (legacy), tampilkan apa adanya
            $approvedByName = $po['approved_by'];
        }

        // Ambil item
        $items = getPreOrderItems($conn, $id);
        $itemsData = [];
        foreach ($items as $item) {
            $itemsData[] = [
                'barang_id' => $item['barang_id'],
                'nama'      => $item['nama_barang'] ?? $item['temp_nama_barang'] ?? 'Barang tidak dikenal',
                'qty'       => $item['qty']
            ];
        }

        $response = [
            'id'            => $po['id'],
            'tanggal'       => $po['tanggal'],
            'supplier_nama' => $supplierNama,
            'total_item'    => $po['total_item'],
            'status'        => $po['status'],
            'catatan'       => $po['catatan'],
            'approved_by'   => $approvedByName, // kirim nama, bukan ID
            'items'         => $itemsData
        ];

        ob_end_clean();
        echo json_encode($response);
    } catch (Throwable $e) {
        ob_end_clean();
        error_log('konfirmasi_preorder get_data error: ' . $e->getMessage());
        echo json_encode(['error' => 'Terjadi kesalahan saat mengambil data: ' . $e->getMessage()]);
    }
    exit;
}

// ========== PROSES APPROVE / REJECT ==========
$action = filter_input(INPUT_GET, 'action', FILTER_SANITIZE_STRING);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($action && $id) {
    // AMBIL ID USER YANG LOGIN (bukan username)
    $userId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
    if (!$userId) {
        // fallback: ambil dari database berdasarkan username
        $username = $_SESSION['username'] ?? 'owner';
        $u = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $u->bind_param("s", $username);
        $u->execute();
        $u_res = $u->get_result();
        if ($u_res->num_rows) {
            $userId = $u_res->fetch_assoc()['id'];
        } else {
            $_SESSION['error'] = "User tidak ditemukan di database.";
            header("Location: konfirmasi_preorder.php");
            exit;
        }
    }

    $po = getPreOrderWithSupplier($conn, $id);

    if (!$po) {
        $_SESSION['error'] = "Pre order tidak ditemukan.";
        header("Location: konfirmasi_preorder.php");
        exit;
    }

    if ($po['status'] !== 'pending') {
        $_SESSION['error'] = "Pre order ini sudah diproses sebelumnya.";
        header("Location: konfirmasi_preorder.php");
        exit;
    }

    try {
        if ($action === 'approve') {
            $items = getPreOrderItems($conn, $id);
            if (empty($items)) {
                throw new Exception("Tidak ada item dalam pre order.");
            }

            $conn->begin_transaction();
            try {
                foreach ($items as $item) {
                    if ($item['barang_id'] === null && !empty($item['temp_nama_barang'])) {
                        // Buat barang baru dengan kategori_id = NULL (belum ditentukan)
                        $kode_barang = generateKodeBarang($item['temp_kategori'] ?? null);
                        $stmt_insert = $conn->prepare("INSERT INTO barang (kode_barang, nama_barang, kategori, satuan, min_stok, supplier_id) VALUES (?, ?, ?, ?, 0, NULL)");
                        $stmt_insert->bind_param("ssss", $kode_barang, $item['temp_nama_barang'], $item['temp_kategori'], $item['temp_satuan']);
                        if (!$stmt_insert->execute()) {
                            throw new Exception("Gagal membuat barang baru: " . $conn->error);
                        }
                        $new_barang_id = $conn->insert_id;
                        $stmt_update = $conn->prepare("UPDATE pre_order_item SET barang_id = ? WHERE id = ?");
                        $stmt_update->bind_param("ii", $new_barang_id, $item['id']);
                        $stmt_update->execute();
                        catatLog($username, 'Tambah', 'barang', $new_barang_id, "Barang baru dari pre order #$id: {$item['temp_nama_barang']}");
                    } elseif ($item['barang_id'] === null) {
                        throw new Exception("Item pre order tidak valid (data barang baru tidak lengkap).");
                    }
                }
                // UPDATE status dan approved_by dengan ID user (integer)
                $stmt_status = $conn->prepare("UPDATE pre_order SET status = 'disetujui', approved_by = ? WHERE id = ?");
                $stmt_status->bind_param("ii", $userId, $id);
                $stmt_status->execute();
                $conn->commit();
                catatLog($_SESSION['username'] ?? 'owner', 'Approve', 'pre_order', $id, "Pre order disetujui oleh user ID $userId");
                $_SESSION['success'] = "Pre order #$id berhasil disetujui. Barang baru telah ditambahkan ke master barang.";
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
        } elseif ($action === 'reject') {
            $stmt = $conn->prepare("UPDATE pre_order SET status = 'ditolak', approved_by = ? WHERE id = ?");
            $stmt->bind_param("ii", $userId, $id);
            if ($stmt->execute()) {
                catatLog($_SESSION['username'] ?? 'owner', 'Reject', 'pre_order', $id, "Pre order ditolak oleh user ID $userId");
                $_SESSION['success'] = "Pre order #$id ditolak.";
            } else {
                throw new Exception("Gagal memperbarui status pre order.");
            }
        } else {
            $_SESSION['error'] = "Aksi tidak valid.";
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    header("Location: konfirmasi_preorder.php");
    exit;
}

// ========== AMBIL DATA DENGAN FILTER ==========
$status_filter = $_GET['filter'] ?? 'semua';
$allowed_filters = ['semua', 'pending', 'disetujui', 'ditolak'];
if (!in_array($status_filter, $allowed_filters)) $status_filter = 'semua';

if ($status_filter !== 'semua') {
    $stmtList = $conn->prepare("
        SELECT po.*, s.nama as supplier_nama
        FROM pre_order po
        LEFT JOIN supplier s ON po.supplier_id = s.id
        WHERE po.status = ?
        ORDER BY po.tanggal DESC, po.id DESC
    ");
    $stmtList->bind_param("s", $status_filter);
    $stmtList->execute();
    $result = $stmtList->get_result();
} else {
    $sql = "SELECT po.*, s.nama as supplier_nama
            FROM pre_order po
            LEFT JOIN supplier s ON po.supplier_id = s.id
            ORDER BY po.tanggal DESC, po.id DESC";
    $result = $conn->query($sql);
}

// Hitung jumlah pending untuk badge dan topbar
$pendingCount = $conn->query("SELECT COUNT(*) as c FROM pre_order WHERE status='pending'")->fetch_assoc()['c'] ?? 0;

// Hitung badge pending untuk sidebar (koreksi stok dan barang rusak)
$jmlPendingKoreksi = $conn->query("SELECT COUNT(*) as c FROM stock_opname WHERE status='Pending'")->fetch_assoc()['c'] ?? 0;
$jmlPendingRusak   = $conn->query("SELECT COUNT(*) as c FROM pengajuan_rusak WHERE status='Pending'")->fetch_assoc()['c'] ?? 0;

// Siapkan data untuk modal detail (tidak perlu query ulang, nanti via AJAX)
$allPreorder = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $allPreorder[$row['id']] = [
            'id' => $row['id'],
            'tanggal' => $row['tanggal'],
            'supplier_nama' => $row['supplier_nama'],
            'total_item' => $row['total_item'],
            'status' => $row['status'],
            'catatan' => $row['catatan'],
            'approved_by' => $row['approved_by'] // ini bisa ID atau string, nanti di detail kita tampilkan nama
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Konfirmasi Pre Order — Keva Jaya</title>
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

        /* SIDEBAR (konsisten) */
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
        .page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
        .page-title { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; }
        .page-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 2px; }

        /* ALERT */
        .alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: var(--radius); font-size: 13.5px; margin-bottom: 20px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
        .alert-success { background: var(--accent-light); color: var(--accent); border: 1px solid #b7ddc9; }
        .alert-danger  { background: var(--danger-light); color: var(--danger); border: 1px solid #f5b7b1; }
        .alert-warning { background: var(--warning-light); color: var(--warning); border: 1px solid #f5d5a0; }
        .alert i { font-size: 16px; flex-shrink: 0; }
        .alert-close { margin-left: auto; cursor: pointer; opacity: 0.6; }

        /* FILTER TABS */
        .filter-tabs { display: flex; gap: 4px; }
        .filter-tab { padding: 6px 14px; border-radius: 99px; font-size: 12.5px; font-weight: 550; cursor: pointer; border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary); transition: var(--transition); }
        .filter-tab.active { background: var(--accent); color: white; border-color: var(--accent); }
        .filter-tab:hover:not(.active) { background: var(--surface-2); }

        /* CARD */
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
        .card-head { padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); background: var(--surface-2); flex-wrap: wrap; }
        .card-head-title { font-size: 13.5px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .card-head-title i { color: var(--accent); }
        .search-wrap { position: relative; }
        .search-wrap i.si { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 14px; color: var(--text-muted); pointer-events: none; }
        .search-input { padding: 7px 12px 7px 32px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; font-family: inherit; color: var(--text-primary); background: var(--surface); width: 210px; transition: var(--transition); }
        .search-input:focus { outline: none; border-color: var(--accent); width: 250px; box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }

        /* TABLE */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead th { padding: 11px 16px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.4px; text-transform: uppercase; color: var(--text-muted); background: var(--surface-2); border-bottom: 1px solid var(--border); white-space: nowrap; }
        tbody td { padding: 13px 16px; border-bottom: 1px solid var(--border); color: var(--text-primary); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr { transition: background 0.15s; }
        tbody tr:hover { background: var(--surface-2); }
        tr.row-pending { border-left: 3px solid var(--warning); }

        /* BADGE */
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 550; }
        .badge-disetujui { background: var(--accent-light); color: var(--accent); }
        .badge-pending  { background: var(--pending-light); color: var(--pending); border: 1px solid #ffe082; }
        .badge-ditolak  { background: var(--danger-light); color: var(--danger); border: 1px solid #f5b7b1; }
        .badge-gray    { background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border); }

        /* BUTTONS */
        .btn-action-table { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary); cursor: pointer; transition: var(--transition); text-decoration: none; font-size: 14px; }
        .btn-action-table:hover { background: var(--surface-2); border-color: var(--border-strong); color: var(--text-primary); }
        .btn-success-sm { background: var(--accent); color: white; border: none; }
        .btn-success-sm:hover { background: var(--accent-hover); }
        .btn-danger-sm { background: var(--danger); color: white; border: none; }
        .btn-danger-sm:hover { background: #a93226; }
        .row-count { font-size: 12px; color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border); padding: 3px 10px; border-radius: 99px; }

        /* MODAL */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); backdrop-filter: blur(4px); z-index: 200; display: none; align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay.show { display: flex; }
        .modal-box { background: var(--surface); border-radius: 14px; width: 100%; max-width: 700px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); animation: slideUp 0.2s cubic-bezier(0.34,1.56,0.64,1); max-height: 90vh; display: flex; flex-direction: column; }
        @keyframes slideUp { from { opacity:0; transform:translateY(20px) scale(0.98); } to { opacity:1; transform:translateY(0) scale(1); } }
        .modal-header { padding: 20px 24px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
        .modal-title { font-size: 16px; font-weight: 700; letter-spacing: -0.3px; }
        .modal-close { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius-sm); border: 1px solid var(--border); background: none; cursor: pointer; color: var(--text-muted); font-size: 16px; }
        .modal-close:hover { background: var(--surface-2); color: var(--text-primary); }
        .modal-body { padding: 20px 24px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
        .form-label { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); letter-spacing: 0.2px; margin-bottom: 4px; }
        .section-label { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 36px; opacity: 0.4; display: block; margin-bottom: 10px; }
        .btn-cancel { padding: 8px 16px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text-secondary); font-size: 13.5px; font-weight: 500; font-family: inherit; cursor: pointer; transition: var(--transition); }
        .btn-cancel:hover { background: var(--surface-2); }
        [data-tooltip] { position: relative; }
        [data-tooltip]:hover::after { content: attr(data-tooltip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: var(--text-primary); color: white; padding: 4px 10px; border-radius: 5px; font-size: 11.5px; white-space: nowrap; pointer-events: none; z-index: 99; }
        .pending-indicator { width: 8px; height: 8px; border-radius: 50%; background: var(--warning); display: inline-block; margin-right: 4px; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.6;transform:scale(1.2)} }
        @media (max-width: 768px) { .sidebar { left: calc(-1 * var(--sidebar-w)); } .sidebar.mobile-open { left: 0; } .main { margin-left: 0; } .form-grid { grid-template-columns: 1fr; } .modal-box { max-width: 95%; } }
    </style>
</head>
<body>

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
                <a href="konfirmasi_preorder.php" class="nav-link active">
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
            <li class="nav-item"><a href="stock_opname.php" class="nav-link"><i class="bi bi-clock-history"></i> Stock Opname</a></li>
                      <li><a href="kadaluarsa.php" class="nav-link <?= $current_file == 'kadaluarsa.php' ? 'active' : '' ?>"><i class="bi bi-calendar-x"></i> Kadaluarsa</a></li>
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
            <span>Konfirmasi Pre Order</span>
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

        <?php if ($pendingCount > 0): ?>
        <div class="alert alert-warning">
            <i class="bi bi-hourglass-split"></i>
            <strong><?= $pendingCount ?> pre order</strong> menunggu keputusan Anda — tinjau dan berikan persetujuan.
            <i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i>
        </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <div class="page-title">Konfirmasi Pre Order</div>
                <div class="page-subtitle">Setujui atau tolak permintaan pre order dari supplier</div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div class="card-head-title"><i class="bi bi-cart-check"></i> Daftar Pre Order</div>
                    <div class="filter-tabs">
                        <a href="?filter=semua" class="filter-tab <?= $status_filter=='semua'?'active':'' ?>">Semua</a>
                        <a href="?filter=pending" class="filter-tab <?= $status_filter=='pending'?'active':'' ?>">
                            Pending <?php if($pendingCount>0) echo "<span style='background:var(--warning);color:white;font-size:10px;padding:1px 6px;border-radius:99px;margin-left:2px;'>$pendingCount</span>"; ?>
                        </a>
                        <a href="?filter=disetujui" class="filter-tab <?= $status_filter=='disetujui'?'active':'' ?>">Disetujui</a>
                        <a href="?filter=ditolak" class="filter-tab <?= $status_filter=='ditolak'?'active':'' ?>">Ditolak</a>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="row-count" id="rowCount"></span>
                    <div class="search-wrap">
                        <i class="bi bi-search si"></i>
                        <input type="text" class="search-input" id="searchInput" placeholder="Cari ID, supplier...">
                    </div>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>NO.PO</th>
                            <th>Tanggal</th>
                            <th>Supplier</th>
                            <th>Total Item</th>
                            <th>Status</th>
                            <th style="text-align:center;width:140px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php if (!empty($allPreorder)): ?>
                            <?php 
                            $no_po = 1; // Nomor urut PO (bukan ID)
                            foreach ($allPreorder as $po):
                                $badgeClass = match($po['status']) {
                                    'disetujui' => 'badge-disetujui',
                                    'ditolak'   => 'badge-ditolak',
                                    default     => 'badge-pending'
                                };
                                $badgeIcon = match($po['status']) {
                                    'disetujui' => '<i class="bi bi-check-circle"></i>',
                                    'ditolak'   => '<i class="bi bi-x-circle"></i>',
                                    default     => '<i class="bi bi-clock"></i>'
                                };
                                $status_text = match($po['status']) {
                                    'disetujui' => 'Disetujui',
                                    'ditolak'   => 'Ditolak',
                                    default     => 'Pending'
                                };
                            ?>
                            <tr data-status="<?= $po['status'] ?>" <?= $po['status']==='pending'?'class="row-pending"':'' ?>>
                                <td><span style="font-family:'JetBrains Mono',monospace;font-size:12.5px;">PO-<?= $no_po++ ?></span></td>
                                <td><?= date('d/m/Y', strtotime($po['tanggal'])) ?></td>
                                <td><?= htmlspecialchars($po['supplier_nama'] ?? '-') ?></td>
                                <td><span class="badge badge-gray"><?= (int)$po['total_item'] ?> item</span></td>
                                <td><span class="badge <?= $badgeClass ?>"><?= $badgeIcon ?> <?= $status_text ?></span></td>
                                <td>
                                    <div style="display:flex;gap:6px;justify-content:center;">
                                        <button class="btn-action-table btn-detail" data-id="<?= $po['id'] ?>" data-tooltip="Detail">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <?php if ($po['status'] === 'pending'): ?>
                                        <a href="?action=approve&id=<?= $po['id'] ?>" class="btn-action-table btn-success-sm" data-tooltip="Setujui" onclick="return confirm('Setujui pre order ini? Barang baru akan otomatis ditambahkan.')">
                                            <i class="bi bi-check-lg"></i>
                                        </a>
                                        <a href="?action=reject&id=<?= $po['id'] ?>" class="btn-action-table btn-danger-sm" data-tooltip="Tolak" onclick="return confirm('Tolak pre order ini?')">
                                            <i class="bi bi-x-lg"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6"><div class="empty-state"><i class="bi bi-inbox"></i><p>Belum ada data Pre Order</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL DETAIL -->
<div class="modal-overlay" id="detailModal">
    <div class="modal-box" style="max-width:700px;">
        <div class="modal-header">
            <div class="modal-title">Detail Pre Order</div>
            <button type="button" class="modal-close" onclick="closeModal('detailModal')"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body" id="detailContent">
            <!-- akan diisi via JS -->
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('detailModal')">Tutup</button>
        </div>
    </div>
</div>

<script>
    // Sidebar toggle
    const sidebar = document.getElementById('sidebar');
    const main = document.getElementById('main');
    document.getElementById('sidebarToggle').addEventListener('click', () => {
        if (window.innerWidth <= 768) sidebar.classList.toggle('mobile-open');
        else { sidebar.classList.toggle('collapsed'); main.classList.toggle('expanded'); }
    });

    function openModal(id) { document.getElementById(id).classList.add('show'); document.body.style.overflow = 'hidden'; }
    function closeModal(id) { document.getElementById(id).classList.remove('show'); document.body.style.overflow = ''; }
    document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => { if(e.target === o) closeModal(o.id); }));
    document.addEventListener('keydown', e => { if(e.key === 'Escape') document.querySelectorAll('.modal-overlay.show').forEach(m => closeModal(m.id)); });

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }
    function formatDate(dateStr) {
        if (!dateStr) return '-';
        const d = new Date(dateStr);
        if (isNaN(d.getTime())) return '-';
        return `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')}/${d.getFullYear()}`;
    }

    // Detail via AJAX
    document.querySelectorAll('.btn-detail').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.dataset.id;

            document.getElementById('detailContent').innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-arrow-repeat"></i>
                    <p>Memuat data...</p>
                </div>`;
            openModal('detailModal');

            fetch(`?get_data=${encodeURIComponent(id)}`)
                .then(res => {
                    if (!res.ok) throw new Error(`Server merespon dengan status ${res.status}`);
                    return res.text();
                })
                .then(text => {
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (parseErr) {
                        console.error('Response bukan JSON valid:', text);
                        throw new Error('Server mengembalikan data tidak valid. Cek console/log server untuk detail.');
                    }

                    if (data.error) {
                        document.getElementById('detailContent').innerHTML = `
                            <div class="empty-state">
                                <i class="bi bi-exclamation-triangle"></i>
                                <p>${escapeHtml(data.error)}</p>
                            </div>`;
                        return;
                    }

                    let itemsHtml = '';
                    if (data.items && data.items.length > 0) {
                        itemsHtml = `<div class="table-wrap"><table style="width:100%"><thead><tr><th>Barang</th><th>Qty</th></tr></thead><tbody>`;
                        data.items.forEach(item => {
                            itemsHtml += `<tr><td>${escapeHtml(item.nama)}</td><td>${escapeHtml(item.qty)}</td></tr>`;
                        });
                        itemsHtml += `</tbody></table></div>`;
                    } else {
                        itemsHtml = '<div class="empty-state"><i class="bi bi-box"></i><p>Tidak ada item</p></div>';
                    }

                    const statusBadge = {
                        'pending': 'badge-pending',
                        'disetujui': 'badge-disetujui',
                        'ditolak': 'badge-ditolak'
                    }[data.status] || 'badge-gray';
                    const statusIcon = {
                        'pending': '<i class="bi bi-clock"></i>',
                        'disetujui': '<i class="bi bi-check-circle"></i>',
                        'ditolak': '<i class="bi bi-x-circle"></i>'
                    }[data.status] || '';
                    const statusText = data.status ? (data.status.charAt(0).toUpperCase() + data.status.slice(1)) : '-';

                    const html = `
                        <div class="form-grid">
                            <div><div class="form-label">Nomor PO</div><div style="font-weight:600;">PO-${escapeHtml(data.id)}</div></div>
                            <div><div class="form-label">Tanggal</div><div>${formatDate(data.tanggal)}</div></div>
                            <div><div class="form-label">Supplier</div><div>${escapeHtml(data.supplier_nama)}</div></div>
                            <div><div class="form-label">Status</div><div><span class="badge ${statusBadge}">${statusIcon} ${statusText}</span></div></div>
                            <div><div class="form-label">Total Item</div><div>${escapeHtml(data.total_item)} item</div></div>
                            ${data.approved_by ? `<div><div class="form-label">Diproses Oleh</div><div>${escapeHtml(data.approved_by)}</div></div>` : ''}
                            <div class="span-2" style="grid-column:1/-1;"><div class="form-label">Catatan</div><div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 12px;">${data.catatan ? escapeHtml(data.catatan) : '<span style="color:var(--text-muted);">—</span>'}</div></div>
                        </div>
                        <div class="section-label">Daftar Barang</div>
                        ${itemsHtml}
                    `;
                    document.getElementById('detailContent').innerHTML = html;
                })
                .catch(err => {
                    console.error(err);
                    document.getElementById('detailContent').innerHTML = `
                        <div class="empty-state">
                            <i class="bi bi-exclamation-triangle"></i>
                            <p>Gagal mengambil data detail.<br><small>${escapeHtml(err.message)}</small></p>
                        </div>`;
                });
        });
    });

    // Search & row count
    const searchInput = document.getElementById('searchInput');
    const tableBody   = document.getElementById('tableBody');
    const rowCountEl  = document.getElementById('rowCount');
    function updateRowCount() {
        const rows = tableBody.querySelectorAll('tr');
        const visible = [...rows].filter(r => r.style.display !== 'none').length;
        const total = rows.length;
        rowCountEl.textContent = visible === total ? `${total} item` : `${visible} / ${total} item`;
    }
    searchInput.addEventListener('input', function() {
        const q = this.value.toLowerCase().trim();
        tableBody.querySelectorAll('tr').forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(q) ? '' : 'none';
        });
        updateRowCount();
    });
    updateRowCount();

    // Auto dismiss alert
    const alertEl = document.getElementById('alert-msg');
    if (alertEl) setTimeout(() => alertEl.remove(), 5000);
</script>
</body>
</html>