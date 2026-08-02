<?php
require_once '../includes/auth.php';
cekLogin();
cekRole('owner');   // Hanya Owner yang bisa akses halaman ini
require_once '../includes/functions.php';

global $conn;

// ========== PROSES APPROVE ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve'])) {
    $no_opname      = $_POST['no_opname'];
    $catatan_owner  = trim($_POST['catatan_owner'] ?? '');

    $stmt = $conn->prepare("SELECT id, status FROM stock_opname WHERE no_opname = ?");
    $stmt->bind_param("s", $no_opname);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row || $row['status'] !== 'Pending') {
        $_SESSION['error'] = "Pengajuan tidak ditemukan atau sudah diproses.";
        header("Location: approval_koreksi.php"); exit;
    }

    $opname_id = $row['id'];
    $conn->begin_transaction();
    try {
        // ambil item + data barang (termasuk satuan_besar)
        $get_items = $conn->query("
            SELECT oi.barang_id, oi.stok_fisik, oi.stok_sistem, oi.selisih,
                   b.satuan_besar, b.satuan
            FROM stock_opname_item oi
            JOIN barang b ON oi.barang_id = b.id_barang
            WHERE oi.opname_id = $opname_id
        ");

        while ($it = $get_items->fetch_assoc()) {
            $stok_sebelum = (int)$it['stok_sistem'];
            $stok_sesudah = (int)$it['stok_fisik'];
            $selisih      = abs((int)$it['selisih']);

            // tentukan kolom yang diupdate
            if (!empty($it['satuan_besar'])) {
                // barang olahan → update stok_besar
                $update = $conn->prepare("UPDATE barang SET stok_besar = ? WHERE id_barang = ?");
                $update->bind_param("ii", $stok_sesudah, $it['barang_id']);
            } else {
                // barang biasa → update stok
                $update = $conn->prepare("UPDATE barang SET stok = ? WHERE id_barang = ?");
                $update->bind_param("ii", $stok_sesudah, $it['barang_id']);
            }
            $update->execute();

            catatMutasiStok(
                $it['barang_id'],
                'KOREKSI',
                $selisih,
                $stok_sebelum,
                $stok_sesudah,
                "Koreksi stok disetujui Owner — Opname: $no_opname",
                $opname_id
            );
        }

        // update status stock_opname
        $upd = $conn->prepare("UPDATE stock_opname SET status = 'Disetujui', catatan_owner = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
        $owner = $_SESSION['username'];
        $upd->bind_param("ssi", $catatan_owner, $owner, $opname_id);
        $upd->execute();

        $conn->commit();
        catatLog($owner, 'Approve', 'stock_opname', $opname_id, "Owner menyetujui koreksi stok $no_opname. Stok barang telah diperbarui.");
        $_SESSION['success'] = "Koreksi stok <strong>$no_opname</strong> disetujui. Stok barang berhasil diperbarui.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Gagal menyetujui: " . $e->getMessage();
    }
    header("Location: approval_koreksi.php"); exit;
}

// ========== PROSES REJECT ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject'])) {
    $no_opname     = $_POST['no_opname'];
    $catatan_owner = trim($_POST['catatan_owner'] ?? '');

    $stmt = $conn->prepare("SELECT id, status FROM stock_opname WHERE no_opname = ?");
    $stmt->bind_param("s", $no_opname);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row || $row['status'] !== 'Pending') {
        $_SESSION['error'] = "Pengajuan tidak ditemukan atau sudah diproses.";
        header("Location: approval_koreksi.php"); exit;
    }

    $opname_id = $row['id'];
    $upd = $conn->prepare("UPDATE stock_opname SET status = 'Ditolak', catatan_owner = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
    $owner = $_SESSION['username'];
    $upd->bind_param("ssi", $catatan_owner, $owner, $opname_id);
    if ($upd->execute()) {
        catatLog($owner, 'Reject', 'stock_opname', $opname_id, "Owner menolak koreksi stok $no_opname. Alasan: $catatan_owner");
        $_SESSION['success'] = "Pengajuan <strong>$no_opname</strong> ditolak. Stok barang tidak berubah.";
    } else {
        $_SESSION['error'] = "Gagal menolak pengajuan.";
    }
    header("Location: approval_koreksi.php"); exit;
}

// ========== AMBIL SEMUA DATA ==========
$allOpname = [];
$res = $conn->query("SELECT * FROM stock_opname ORDER BY
    CASE status WHEN 'Pending' THEN 0 WHEN 'Disetujui' THEN 1 ELSE 2 END,
    tanggal DESC, id DESC");
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
        'id'            => $row['no_opname'],
        'periode'       => $row['periode'],
        'tanggal'       => $row['tanggal'],
        'total_item'    => $row['total_item'],
        'status'        => $row['status'],
        'items'         => $items,
        'keterangan'    => $row['keterangan'],
        'catatan_owner' => $row['catatan_owner'] ?? '',
        'created_by'    => $row['created_by'],
        'approved_by'   => $row['approved_by'] ?? '',
        'approved_at'   => $row['approved_at'] ?? ''
    ];
}

$pendingCount   = count(array_filter($allOpname, fn($o) => $o['status'] === 'Pending'));
$countApproved  = count(array_filter($allOpname, fn($o) => $o['status'] === 'Disetujui'));
$countRejected  = count(array_filter($allOpname, fn($o) => $o['status'] === 'Ditolak'));

// Hitung badge untuk sidebar (pre order pending & barang rusak)
$pendingPreorder = $conn->query("SELECT COUNT(*) as c FROM pre_order WHERE status='pending'")->fetch_assoc()['c'] ?? 0;
$jmlPendingRusak = $conn->query("SELECT COUNT(*) as c FROM pengajuan_rusak WHERE status='Pending'")->fetch_assoc()['c'] ?? 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Konfirmasi Koreksi Stok — Keva Jaya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ========== CSS (sama seperti sebelumnya) ========== */
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
        .nav-badge { margin-left: auto; background: var(--warning); color: white; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 99px; line-height: 1.4; }
        .sidebar-footer { padding: 12px 14px; border-top: 1px solid rgba(255,255,255,0.08); flex-shrink: 0; }
        .user-card { display: flex; align-items: center; gap: 10px; }
        .user-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: white; flex-shrink: 0; }
        .user-name { font-size: 13px; font-weight: 550; color: rgba(255,255,255,0.8); }
        .user-role { font-size: 11px; color: rgba(255,255,255,0.35); }
        .btn-action { background: transparent; border: none; border-radius: var(--radius-sm); width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; font-size: 16px; color: rgba(255,255,255,0.55); cursor: pointer; transition: var(--transition); text-decoration: none; }
        .btn-action:hover { background: rgba(255,255,255,0.1); color: white; }

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

        .alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: var(--radius); font-size: 13.5px; margin-bottom: 20px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
        .alert-success { background: var(--accent-light); color: var(--accent); border: 1px solid #b7ddc9; }
        .alert-danger  { background: var(--danger-light); color: var(--danger); border: 1px solid #f5b7b1; }
        .alert-warning { background: var(--warning-light); color: var(--warning); border: 1px solid #f5d5a0; }
        .alert i { font-size: 16px; flex-shrink: 0; }
        .alert-close { margin-left: auto; cursor: pointer; opacity: 0.6; }

        .stats-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 22px; }
        .stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 20px; display: flex; align-items: center; gap: 14px; box-shadow: var(--shadow-sm); }
        .stat-icon { width: 40px; height: 40px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .stat-icon.pending  { background: var(--pending-light); color: var(--pending); }
        .stat-icon.approved { background: var(--accent-light); color: var(--accent); }
        .stat-icon.rejected { background: var(--danger-light); color: var(--danger); }
        .stat-val { font-size: 22px; font-weight: 700; line-height: 1.2; }
        .stat-lbl { font-size: 12px; color: var(--text-muted); }

        .filter-tabs { display: flex; gap: 4px; }
        .filter-tab { padding: 6px 14px; border-radius: 99px; font-size: 12.5px; font-weight: 550; cursor: pointer; border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary); transition: var(--transition); }
        .filter-tab.active { background: var(--accent); color: white; border-color: var(--accent); }
        .filter-tab:hover:not(.active) { background: var(--surface-2); }

        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
        .card-head { padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); background: var(--surface-2); flex-wrap: wrap; }
        .card-head-title { font-size: 13.5px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .card-head-title i { color: var(--accent); }
        .search-wrap { position: relative; }
        .search-wrap i.si { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 14px; color: var(--text-muted); pointer-events: none; }
        .search-input { padding: 7px 12px 7px 32px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; font-family: inherit; color: var(--text-primary); background: var(--surface); width: 210px; transition: var(--transition); }
        .search-input:focus { outline: none; border-color: var(--accent); width: 250px; box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead th { padding: 11px 16px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.4px; text-transform: uppercase; color: var(--text-muted); background: var(--surface-2); border-bottom: 1px solid var(--border); white-space: nowrap; }
        tbody td { padding: 13px 16px; border-bottom: 1px solid var(--border); color: var(--text-primary); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr { transition: background 0.15s; }
        tbody tr:hover { background: var(--surface-2); }
        tr.row-pending { border-left: 3px solid var(--warning); }

        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 550; }
        .badge-green   { background: var(--accent-light); color: var(--accent); }
        .badge-gray    { background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border); }
        .badge-pending { background: var(--pending-light); color: var(--pending); border: 1px solid #ffe082; }
        .badge-danger  { background: var(--danger-light); color: var(--danger); border: 1px solid #f5b7b1; }

        .btn-action { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary); cursor: pointer; transition: var(--transition); text-decoration: none; font-size: 14px; }
        .btn-action:hover { background: var(--surface-2); border-color: var(--border-strong); color: var(--text-primary); }
        .btn-approve { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; background: var(--accent); color: white; border: none; border-radius: var(--radius-sm); font-size: 13px; font-weight: 600; font-family: inherit; cursor: pointer; transition: var(--transition); }
        .btn-approve:hover { background: var(--accent-hover); }
        .btn-reject  { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; background: var(--danger-light); color: var(--danger); border: 1px solid #f5b7b1; border-radius: var(--radius-sm); font-size: 13px; font-weight: 600; font-family: inherit; cursor: pointer; transition: var(--transition); }
        .btn-reject:hover  { background: #f8d7d4; }
        .btn-cancel  { padding: 8px 16px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text-secondary); font-size: 13.5px; font-weight: 500; font-family: inherit; cursor: pointer; transition: var(--transition); }
        .btn-cancel:hover { background: var(--surface-2); }
        .row-count { font-size: 12px; color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border); padding: 3px 10px; border-radius: 99px; }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); backdrop-filter: blur(4px); z-index: 200; display: none; align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay.show { display: flex; }
        .modal-box { background: var(--surface); border-radius: 14px; width: 100%; max-width: 760px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); animation: slideUp 0.2s cubic-bezier(0.34,1.56,0.64,1); max-height: 90vh; display: flex; flex-direction: column; }
        @keyframes slideUp { from { opacity:0; transform:translateY(20px) scale(0.98); } to { opacity:1; transform:translateY(0) scale(1); } }
        .modal-header { padding: 20px 24px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
        .modal-title { font-size: 16px; font-weight: 700; letter-spacing: -0.3px; }
        .modal-close { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; border-radius: var(--radius-sm); border: 1px solid var(--border); background: none; cursor: pointer; color: var(--text-muted); font-size: 16px; }
        .modal-close:hover { background: var(--surface-2); color: var(--text-primary); }
        .modal-body { padding: 20px 24px; overflow-y: auto; flex: 1; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
        .modal-footer-right { display: flex; gap: 10px; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-label { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); letter-spacing: 0.2px; }
        .form-control { padding: 9px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13.5px; font-family: inherit; background: var(--surface); width: 100%; }
        .form-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }

        .section-label { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .selisih-negatif { color: var(--danger); font-weight: 600; }
        .selisih-positif { color: var(--accent); font-weight: 600; }
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 36px; opacity: 0.4; display: block; margin-bottom: 10px; }
        [data-tooltip] { position: relative; }
        [data-tooltip]:hover::after { content: attr(data-tooltip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: var(--text-primary); color: white; padding: 4px 10px; border-radius: 5px; font-size: 11.5px; white-space: nowrap; pointer-events: none; z-index: 99; }
        .pending-indicator { width: 8px; height: 8px; border-radius: 50%; background: var(--warning); display: inline-block; margin-right: 4px; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.6;transform:scale(1.2)} }
        @media (max-width: 768px) { .sidebar { left: calc(-1 * var(--sidebar-w)); } .sidebar.mobile-open { left: 0; } .main { margin-left: 0; } .form-grid { grid-template-columns: 1fr; } .stats-row { grid-template-columns: 1fr; } .modal-box { max-width: 95%; } }
    </style>
</head>
<body>

<!-- SIDEBAR -->
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
                    <?php if ($pendingPreorder > 0): ?>
                        <span class="nav-badge"><?= $pendingPreorder ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item"><a href="approval_koreksi.php" class="nav-link active"><i class="bi bi-shield-check"></i> Konfirmasi Koreksi Stok <?php if($pendingCount>0) echo "<span class='nav-badge'>$pendingCount</span>"; ?></a></li>
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
            <a href="../logout.php" class="btn-action" style="margin-left:auto;" title="Logout">
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
            <span>Konfirmasi Koreksi Stok</span>
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
            <strong><?= $pendingCount ?> pengajuan</strong> menunggu keputusan Anda — tinjau dan berikan persetujuan.
            <i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i>
        </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <div class="page-title">Konfirmasi Koreksi Stok</div>
                <div class="page-subtitle">Tinjau dan setujui/tolak pengajuan koreksi stok dari Admin</div>
            </div>
        </div>

        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon pending"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="stat-val" style="color:var(--pending);"><?= $pendingCount ?></div>
                    <div class="stat-lbl">Menunggu Persetujuan</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon approved"><i class="bi bi-check-circle"></i></div>
                <div>
                    <div class="stat-val" style="color:var(--accent);"><?= $countApproved ?></div>
                    <div class="stat-lbl">Disetujui</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon rejected"><i class="bi bi-x-circle"></i></div>
                <div>
                    <div class="stat-val" style="color:var(--danger);"><?= $countRejected ?></div>
                    <div class="stat-lbl">Ditolak</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div class="card-head-title"><i class="bi bi-shield-check"></i> Daftar Pengajuan</div>
                    <div class="filter-tabs">
                        <button class="filter-tab active" onclick="filterTable('all',this)">Semua</button>
                        <button class="filter-tab" onclick="filterTable('Pending',this)">
                            Pending <?php if($pendingCount>0) echo "<span style='background:var(--warning);color:white;font-size:10px;padding:1px 6px;border-radius:99px;margin-left:2px;'>$pendingCount</span>"; ?>
                        </button>
                        <button class="filter-tab" onclick="filterTable('Disetujui',this)">Disetujui</button>
                        <button class="filter-tab" onclick="filterTable('Ditolak',this)">Ditolak</button>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="row-count" id="rowCount"></span>
                    <div class="search-wrap">
                        <i class="bi bi-search si"></i>
                        <input type="text" class="search-input" id="searchInput" placeholder="Cari ID atau periode...">
                    </div>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID Pengajuan</th>
                            <th>Diajukan Oleh</th>
                            <th>Periode</th>
                            <th>Tanggal</th>
                            <th>Total Item</th>
                            <th>Status</th>
                            <th style="text-align:center;width:120px;">Aksi</th>
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
                        <tr data-status="<?= $row['status'] ?>" <?= $row['status']==='Pending'?'class="row-pending"':'' ?>>
                            <td><span style="font-family:'JetBrains Mono',monospace;font-size:12.5px;"><?= htmlspecialchars($row['id']) ?></span></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:7px;">
                                    <div style="width:26px;height:26px;border-radius:50%;background:var(--accent-light);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">
                                        <?= strtoupper(substr($row['created_by'],0,1)) ?>
                                    </div>
                                    <span><?= htmlspecialchars($row['created_by']) ?></span>
                                </div>
                            </td>
                            <td style="font-weight:500;"><?= htmlspecialchars($row['periode']) ?></td>
                            <td style="color:var(--text-secondary);"><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                            <td><span class="badge badge-gray"><?= (int)$row['total_item'] ?> barang</span></td>
                            <td><span class="badge <?= $badgeClass ?>"><?= $badgeIcon ?> <?= htmlspecialchars($row['status']) ?></span></td>
                            <td>
                                <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                                    <button class="btn-action btn-detail" data-id="<?= htmlspecialchars($row['id']) ?>" data-tooltip="Lihat Detail">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                    <?php if ($row['status'] === 'Pending'): ?>
                                    <button class="btn-action" onclick="openApproval('<?= htmlspecialchars($row['id']) ?>')" data-tooltip="Proses Persetujuan"
                                        style="color:var(--accent);border-color:var(--accent-light);background:var(--accent-light);">
                                        <i class="bi bi-shield-check"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <td><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i><p>Belum ada pengajuan koreksi stok</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL DETAIL -->
<div class="modal-overlay" id="detailModal">
    <div class="modal-box" style="max-width:800px;">
        <div class="modal-header">
            <div class="modal-title">Detail Pengajuan Koreksi Stok</div>
            <button type="button" class="modal-close" onclick="closeModal('detailModal')"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body" id="detailContent"></div>
        <div class="modal-footer">
            <div></div>
            <button type="button" class="btn-cancel" onclick="closeModal('detailModal')">Tutup</button>
        </div>
    </div>
</div>

<!-- MODAL APPROVAL -->
<div class="modal-overlay" id="approvalModal">
    <div class="modal-box" style="max-width:520px;">
        <div class="modal-header">
            <div>
                <div class="modal-title" id="approvalTitle">Proses Persetujuan Koreksi</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px;" id="approvalSubtitle"></div>
            </div>
            <button type="button" class="modal-close" onclick="closeModal('approvalModal')"><i class="bi bi-x"></i></button>
        </div>
        <div class="modal-body">
            <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;margin-bottom:16px;" id="approvalSummary"></div>
            <div class="form-group">
                <label class="form-label">Catatan Owner <span style="color:var(--text-muted);font-weight:400;">(opsional)</span></label>
                <textarea id="catatan_owner" class="form-control" rows="3" placeholder="Tuliskan catatan atau alasan keputusan Anda..."></textarea>
            </div>
            <div id="approvalWarning" style="margin-top:14px;padding:12px 14px;border-radius:var(--radius-sm);font-size:13px;display:none;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('approvalModal')">Batal</button>
            <div class="modal-footer-right">
                <form method="POST" id="formReject" style="display:inline;">
                    <input type="hidden" name="no_opname" id="reject_no_opname">
                    <input type="hidden" name="catatan_owner" id="reject_catatan">
                    <input type="hidden" name="reject" value="1">
                    <button type="button" class="btn-reject" onclick="submitAction('reject')">
                        <i class="bi bi-x-circle"></i> Tolak
                    </button>
                </form>
                <form method="POST" id="formApprove" style="display:inline;">
                    <input type="hidden" name="no_opname" id="approve_no_opname">
                    <input type="hidden" name="catatan_owner" id="approve_catatan">
                    <input type="hidden" name="approve" value="1">
                    <button type="button" class="btn-approve" onclick="submitAction('approve')">
                        <i class="bi bi-check-circle"></i> Setujui Koreksi
                    </button>
                </form>
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
    function openModal(id)  { document.getElementById(id).classList.add('show'); document.body.style.overflow='hidden'; }
    function closeModal(id) { document.getElementById(id).classList.remove('show'); document.body.style.overflow=''; }
    document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => { if(e.target===o) closeModal(o.id); }));
    document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.modal-overlay.show').forEach(m => closeModal(m.id)); });

    function escapeHtml(str) {
        if(!str) return '';
        return str.replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
    }
    function formatDate(dateStr) {
        if (!dateStr) return '—';
        const d = new Date(dateStr);
        return `${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')}/${d.getFullYear()}`;
    }

    const opnameData = <?= json_encode($allOpname) ?>;

    function showDetail(id) {
        const data = opnameData[id]; if (!data) return;
        const badgeClass = {'Disetujui':'badge-green','Ditolak':'badge-danger','Pending':'badge-pending'}[data.status] || 'badge-gray';
        let rows = '';
        data.items.forEach(item => {
            const sel    = parseInt(item.selisih);
            const cls    = sel<0 ? 'selisih-negatif' : (sel>0 ? 'selisih-positif' : '');
            const tampil = sel>0 ? '+'+sel : sel;
            const foto   = item.bukti_foto
                ? `<a href="../${item.bukti_foto}" target="_blank" class="btn-action" data-tooltip="Lihat Foto" style="border:1px solid var(--border);background:var(--surface);color:var(--text-secondary);"><i class="bi bi-file-image"></i></a>`
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
        const catatanBagian = data.catatan_owner
            ? `<div style="grid-column:1/-1;"><div class="form-label">Catatan Owner</div><div style="margin-top:4px;background:var(--warning-light);padding:10px 14px;border-radius:var(--radius-sm);border:1px solid #ffe082;font-size:13px;">${escapeHtml(data.catatan_owner)}</div></div>`
            : '';
        const approvedBagian = data.approved_by
            ? `<div><div class="form-label">Diproses Oleh</div><div style="margin-top:4px;">${escapeHtml(data.approved_by)} — ${formatDate(data.approved_at)}</div></div>`
            : '';
        document.getElementById('detailContent').innerHTML = `
            <div class="form-grid" style="margin-bottom:20px;">
                <div><div class="form-label">ID Pengajuan</div><div style="margin-top:4px;font-family:'JetBrains Mono',monospace;font-size:12.5px;">${escapeHtml(data.id)}</div></div>
                <div><div class="form-label">Diajukan Oleh</div><div style="margin-top:4px;">${escapeHtml(data.created_by)}</div></div>
                <div><div class="form-label">Periode</div><div style="margin-top:4px;font-weight:500;">${escapeHtml(data.periode)}</div></div>
                <div><div class="form-label">Tanggal</div><div style="margin-top:4px;">${formatDate(data.tanggal)}</div></div>
                <div><div class="form-label">Status</div><div style="margin-top:4px;"><span class="badge ${badgeClass}">${escapeHtml(data.status)}</span></div></div>
                ${approvedBagian}
                <div style="grid-column:1/-1;"><div class="form-label">Keterangan Admin</div><div style="margin-top:4px;background:var(--surface-2);padding:10px 14px;border-radius:var(--radius-sm);border:1px solid var(--border);font-size:13px;">${escapeHtml(data.keterangan)||'<span style="color:var(--text-muted);">—</span>'}</div></div>
                ${catatanBagian}
            </div>
            <div class="section-label">Rincian Barang (${data.items.length} item)</div>
            <div class="table-wrap">
                <table><thead><tr><th>Nama Barang</th><th>Stok Sistem</th><th>Stok Fisik</th><th>Selisih</th><th>Bukti Foto</th></tr></thead>
                <tbody>${rows}</tbody></table>
            </div>`;
        openModal('detailModal');
    }

    let currentApprovalId = null;
    function openApproval(id) {
        const data = opnameData[id]; if (!data) return;
        currentApprovalId = id;
        document.getElementById('approvalSubtitle').textContent = id;
        document.getElementById('catatan_owner').value = '';
        let selSummary = '';
        data.items.forEach(it => {
            const sel = parseInt(it.selisih);
            const cls = sel<0 ? 'color:var(--danger)' : (sel>0 ? 'color:var(--accent)' : '');
            const tampil = sel>0 ? '+'+sel : sel;
            const satuan = it.satuan || 'kg';
            selSummary += `<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--border);font-size:13px;">
                <span>${escapeHtml(it.nama)}</span>
                <span>Sistem: ${it.stok_sistem} ${satuan} → Fisik: ${it.stok_fisik} ${satuan} <strong style="${cls}">(${tampil})</strong></span>
            </div>`;
        });
        document.getElementById('approvalSummary').innerHTML = `
            <div style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Ringkasan Koreksi — ${escapeHtml(data.periode)}</div>
            ${selSummary}
            <div style="margin-top:8px;font-size:12px;color:var(--text-secondary);">Diajukan oleh: <strong>${escapeHtml(data.created_by)}</strong> · ${formatDate(data.tanggal)}</div>`;
        const warn = document.getElementById('approvalWarning');
        warn.style.display = 'block';
        warn.style.background = 'var(--info-light)';
        warn.style.border = '1px solid #b8d4e8';
        warn.style.color = 'var(--info)';
        warn.innerHTML = '<i class="bi bi-info-circle"></i> <strong>Jika disetujui</strong>, stok barang akan langsung diperbarui dan mutasi KOREKSI akan dicatat ke riwayat. Tindakan ini tidak dapat dibatalkan.';
        openModal('approvalModal');
    }

    function submitAction(action) {
        const catatan = document.getElementById('catatan_owner').value;
        if (action === 'reject' && !catatan.trim()) {
            if (!confirm('Anda tidak memberikan catatan penolakan. Lanjutkan menolak tanpa catatan?')) return;
        }
        if (action === 'approve') {
            if (!confirm(`Setujui koreksi stok ${currentApprovalId}?\n\nStok barang akan langsung diperbarui sesuai data fisik dan tercatat sebagai mutasi KOREKSI.`)) return;
            document.getElementById('approve_no_opname').value = currentApprovalId;
            document.getElementById('approve_catatan').value   = catatan;
            document.getElementById('formApprove').submit();
        } else {
            if (!confirm(`Tolak pengajuan koreksi ${currentApprovalId}?`)) return;
            document.getElementById('reject_no_opname').value = currentApprovalId;
            document.getElementById('reject_catatan').value   = catatan;
            document.getElementById('formReject').submit();
        }
    }

    document.getElementById('tableBody').addEventListener('click', e => {
        const btn = e.target.closest('.btn-detail');
        if (btn) showDetail(btn.getAttribute('data-id'));
    });

    function filterTable(status, btn) {
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('#tableBody tr').forEach(row => {
            if (status === 'all') { row.style.display = ''; return; }
            row.style.display = row.getAttribute('data-status') === status ? '' : 'none';
        });
        updateRowCount();
    }

    const searchInput = document.getElementById('searchInput');
    const tableBody   = document.getElementById('tableBody');
    const rowCountEl  = document.getElementById('rowCount');
    function updateRowCount() {
        const visible = [...tableBody.querySelectorAll('tr')].filter(r=>r.style.display!=='none').length;
        const total   = tableBody.querySelectorAll('tr').length;
        rowCountEl.textContent = visible===total ? `${total} item` : `${visible} / ${total} item`;
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
    if (alertEl) setTimeout(()=>alertEl.remove(), 5000);
</script>
</body>
</html>