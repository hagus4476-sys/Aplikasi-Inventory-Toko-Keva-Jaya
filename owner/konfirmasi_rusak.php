<?php
session_start();
require_once '../includes/auth.php';
cekLogin();
cekRole('owner');
require_once '../includes/functions.php';

global $conn;

// Proses approve / reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $action = $_POST['action'];
    $owner_note = trim($_POST['owner_note'] ?? '');

    if (!$id || !in_array($action, ['approve', 'reject'])) {
        $_SESSION['error'] = "Permintaan tidak valid.";
        header("Location: konfirmasi_rusak.php");
        exit;
    }

    $stmt = $conn->prepare("SELECT barang_id, jumlah, status FROM pengajuan_rusak WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $pengajuan = $result->fetch_assoc();
    $stmt->close();

    if (!$pengajuan || $pengajuan['status'] != 'Pending') {
        $_SESSION['error'] = "Pengajuan tidak ditemukan atau sudah diproses.";
        header("Location: konfirmasi_rusak.php");
        exit;
    }

    if ($action == 'approve') {
        // Kurangi stok_rusak (penanda "masih pending") — tidak berubah dari sebelumnya
        $updateStok = $conn->prepare("UPDATE barang SET stok_rusak = stok_rusak - ? WHERE id_barang = ? AND stok_rusak >= ?");
        $updateStok->bind_param("iii", $pengajuan['jumlah'], $pengajuan['barang_id'], $pengajuan['jumlah']);
        $updateStok->execute();
        if ($updateStok->affected_rows == 0) {
            $_SESSION['error'] = "Gagal mengurangi stok rusak. Stok tidak mencukupi.";
            header("Location: konfirmasi_rusak.php");
            exit;
        }
        $updateStok->close();

        // BARU: tambahkan ke total_rusak (akumulasi historis, tidak pernah di-reset)
        $updateTotal = $conn->prepare("UPDATE barang SET total_rusak = total_rusak + ? WHERE id_barang = ?");
        $updateTotal->bind_param("ii", $pengajuan['jumlah'], $pengajuan['barang_id']);
        $updateTotal->execute();
        $updateTotal->close();

        $update = $conn->prepare("UPDATE pengajuan_rusak SET status = 'Disetujui', owner_note = ? WHERE id = ?");
        $update->bind_param("si", $owner_note, $id);
        $update->execute();
        $update->close();
        $_SESSION['success'] = "Pengajuan disetujui. Stok rusak telah dikurangi.";
    } else {
        $update = $conn->prepare("UPDATE pengajuan_rusak SET status = 'Ditolak', owner_note = ? WHERE id = ?");
        $update->bind_param("si", $owner_note, $id);
        $update->execute();
        $update->close();
        $_SESSION['success'] = "Pengajuan ditolak. Stok rusak tidak berubah.";
    }
    header("Location: konfirmasi_rusak.php");
    exit;
}

// ========== HITUNG BADGE UNTUK SIDEBAR ==========
$jmlPendingKoreksi = (int)($conn->query("SELECT COUNT(*) as c FROM stock_opname WHERE status='Pending'")->fetch_assoc()['c'] ?? 0);
$pendingPreorder   = (int)($conn->query("SELECT COUNT(*) as c FROM pre_order WHERE status='pending'")->fetch_assoc()['c'] ?? 0);

// Ambil data pending rusak beserta satuan
$pending = $conn->query("
    SELECT p.*, b.nama_barang, b.satuan, b.satuan_besar
    FROM pengajuan_rusak p
    JOIN barang b ON p.barang_id = b.id_barang
    WHERE p.status = 'Pending'
    ORDER BY p.created_at ASC
");

$pending_rows = [];
while ($row = $pending->fetch_assoc()) {
    $pending_rows[] = $row;
}

$jmlPendingRusak = count($pending_rows);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Konfirmasi Barang Rusak — Keva Jaya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ========== KEVA JAYA DESIGN SYSTEM (sama seperti sebelumnya, tidak diubah) ========== */
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
        .nav-badge { margin-left: auto; background: var(--warning); color: white; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 99px; line-height: 1.4; }
        .sidebar-footer { padding: 12px 14px; border-top: 1px solid rgba(255,255,255,0.08); flex-shrink: 0; }
        .user-card { display: flex; align-items: center; gap: 10px; }
        .user-avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: white; flex-shrink: 0; }
        .user-name { font-size: 13px; font-weight: 550; color: rgba(255,255,255,0.8); }
        .user-role { font-size: 11px; color: rgba(255,255,255,0.35); }
        .btn-sm-nav { display: inline-flex; align-items: center; gap: 5px; padding: 5px 11px; border-radius: var(--radius-sm); font-size: 12px; font-weight: 500; font-family: inherit; cursor: pointer; transition: var(--transition); border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary); text-decoration: none; }

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
        .alert-warning { background: var(--warning-light); color: var(--warning); border: 1px solid #f5d5a0; }
        .alert i { font-size: 16px; flex-shrink: 0; }
        .alert-close { margin-left: auto; cursor: pointer; opacity: 0.6; }

        .stat-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 20px; }
        .stat-mini { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 18px; display: flex; align-items: center; gap: 14px; box-shadow: var(--shadow-sm); }
        .stat-mini-icon { width: 38px; height: 38px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .stat-mini-icon.orange { background: var(--warning-light); color: var(--warning); }
        .stat-mini-icon.green  { background: var(--accent-light);  color: var(--accent); }
        .stat-mini-icon.blue   { background: var(--info-light);    color: var(--info); }
        .stat-mini-val  { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; line-height: 1; }
        .stat-mini-lbl  { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }

        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 20px; }
        .card-head { padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); background: var(--surface-2); flex-wrap: wrap; }
        .card-head-title { font-size: 13.5px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .card-head-title i { color: var(--accent); }
        .row-count { font-size: 12px; color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border); padding: 3px 10px; border-radius: 99px; }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead th { padding: 11px 16px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.4px; text-transform: uppercase; color: var(--text-muted); background: var(--surface-2); border-bottom: 1px solid var(--border); white-space: nowrap; }
        tbody td { padding: 13px 16px; border-bottom: 1px solid var(--border); color: var(--text-primary); vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr { transition: background 0.15s; }
        tbody tr:hover { background: var(--surface-2); }

        .badge-aksi { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 500; }
        .badge-dibuang { background: var(--danger-light); color: var(--danger); }
        .badge-retur   { background: var(--info-light);   color: var(--info); }

        .btn-approve { display: inline-flex; align-items: center; gap: 5px; padding: 6px 13px; background: var(--accent); color: white; border: none; border-radius: var(--radius-sm); font-size: 12.5px; font-weight: 600; font-family: inherit; cursor: pointer; transition: var(--transition); }
        .btn-approve:hover { background: var(--accent-hover); }
        .btn-reject  { display: inline-flex; align-items: center; gap: 5px; padding: 6px 13px; background: var(--surface); color: var(--danger); border: 1px solid var(--danger); border-radius: var(--radius-sm); font-size: 12.5px; font-weight: 600; font-family: inherit; cursor: pointer; transition: var(--transition); }
        .btn-reject:hover  { background: var(--danger-light); }
        .btn-cancel  { display: inline-flex; align-items: center; gap: 5px; padding: 7px 14px; background: var(--surface); color: var(--text-secondary); border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; font-weight: 500; font-family: inherit; cursor: pointer; transition: var(--transition); }
        .btn-cancel:hover  { background: var(--surface-2); }
        .btn-confirm { display: inline-flex; align-items: center; gap: 5px; padding: 7px 16px; border: none; border-radius: var(--radius-sm); font-size: 13px; font-weight: 600; font-family: inherit; cursor: pointer; transition: var(--transition); }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 36px; opacity: 0.4; display: block; margin-bottom: 10px; }
        .empty-state p { font-size: 13.5px; }

        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.35); z-index: 200; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
        .modal-overlay.open { display: flex; }
        .modal-box { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); width: 420px; max-width: 92vw; box-shadow: var(--shadow); animation: modalIn 0.2s ease; }
        @keyframes modalIn { from { opacity:0; transform:scale(0.96) translateY(8px); } to { opacity:1; transform:scale(1) translateY(0); } }
        .modal-head { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
        .modal-head-icon { width: 34px; height: 34px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
        .modal-head-icon.approve { background: var(--accent-light); color: var(--accent); }
        .modal-head-icon.reject  { background: var(--danger-light); color: var(--danger); }
        .modal-title { font-size: 15px; font-weight: 700; }
        .modal-subtitle { font-size: 12px; color: var(--text-muted); }
        .modal-body { padding: 20px; }
        .modal-info-box { background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 12px 14px; margin-bottom: 16px; font-size: 13px; }
        .modal-info-row { display: flex; justify-content: space-between; padding: 3px 0; }
        .modal-info-row .lbl { color: var(--text-muted); }
        .modal-info-row .val { font-weight: 600; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-label { font-size: 12.5px; font-weight: 600; color: var(--text-secondary); }
        .form-control { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13.5px; font-family: inherit; color: var(--text-primary); background: var(--surface); transition: var(--transition); resize: vertical; }
        .form-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }
        .form-control::placeholder { color: var(--text-muted); }
        .modal-foot { padding: 14px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px; background: var(--surface-2); }

        @media (max-width: 900px) { .stat-row { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) {
            .sidebar { left: calc(-1 * var(--sidebar-w)); }
            .sidebar.mobile-open { left: 0; }
            .main { margin-left: 0; }
        }
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
                <a href="konfirmasi_preorder.php" class="nav-link">
                    <i class="bi bi-check2-circle"></i> Konfirmasi Pre Order
                    <?php if ($pendingPreorder > 0): ?>
                        <span class="nav-badge"><?= $pendingPreorder ?></span>
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
                <a href="konfirmasi_rusak.php" class="nav-link active">
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
            <span>Konfirmasi Barang Rusak</span>
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
        <?php if ($jmlPendingRusak > 0): ?>
        <div class="alert alert-warning">
            <i class="bi bi-hourglass-split"></i>
            <strong><?= $jmlPendingRusak ?> pengajuan</strong> menunggu keputusan Anda — tinjau dan berikan persetujuan.
            <i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i>
        </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <div class="page-title">Konfirmasi Barang Rusak</div>
                <div class="page-subtitle">Setujui atau tolak pengajuan penanganan barang rusak dari Admin</div>
            </div>
        </div>

        <!-- Statistik -->
        <div class="stat-row">
            <div class="stat-mini">
                <div class="stat-mini-icon orange"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="stat-mini-val"><?= $jmlPendingRusak ?></div>
                    <div class="stat-mini-lbl">Menunggu Konfirmasi</div>
                </div>
            </div>
            <?php
                $total_disetujui = (int)($conn->query("SELECT COUNT(*) as c FROM pengajuan_rusak WHERE status='Disetujui'")->fetch_assoc()['c'] ?? 0);
                $total_ditolak   = (int)($conn->query("SELECT COUNT(*) as c FROM pengajuan_rusak WHERE status='Ditolak'")->fetch_assoc()['c'] ?? 0);
            ?>
            <div class="stat-mini">
                <div class="stat-mini-icon green"><i class="bi bi-check-circle"></i></div>
                <div>
                    <div class="stat-mini-val"><?= $total_disetujui ?></div>
                    <div class="stat-mini-lbl">Disetujui</div>
                </div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-icon blue"><i class="bi bi-x-circle"></i></div>
                <div>
                    <div class="stat-mini-val"><?= $total_ditolak ?></div>
                    <div class="stat-mini-lbl">Ditolak</div>
                </div>
            </div>
        </div>

        <!-- Tabel Pengajuan Pending -->
        <div class="card">
            <div class="card-head">
                <div class="card-head-title">
                    <i class="bi bi-inbox"></i>
                    Daftar Pengajuan Pending
                    <span class="row-count"><?= $jmlPendingRusak ?> pengajuan</span>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Barang</th>
                            <th>Jumlah</th>
                            <th>Tindakan</th>
                            <th>Keterangan Admin</th>
                            <th style="text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pending_rows)): ?>
                        <tr><td colspan="6">
                            <div class="empty-state">
                                <i class="bi bi-inbox"></i>
                                <p>Tidak ada pengajuan yang menunggu konfirmasi.</p>
                            </div>
                        </td></tr>
                        <?php else: ?>
                            <?php foreach ($pending_rows as $row):
                                // tentukan satuan tampilan
                                $satuan = !empty($row['satuan_besar']) ? $row['satuan_besar'] : $row['satuan'];
                            ?>
                            <tr>
                                <td style="color:var(--text-secondary);white-space:nowrap;font-size:13px;">
                                    <?= date('d M Y', strtotime($row['created_at'])) ?>
                                    <div style="font-size:11.5px;color:var(--text-muted);"><?= date('H:i', strtotime($row['created_at'])) ?></div>
                                </td>
                                <td style="font-weight:500;"><?= htmlspecialchars($row['nama_barang']) ?></td>
                                <td>
                                    <span style="font-weight:600;"><?= $row['jumlah'] ?></span>
                                    <small style="color:var(--text-muted);margin-left:3px;"><?= htmlspecialchars($satuan) ?></small>
                                </td>
                                <td>
                                    <?php if ($row['aksi'] === 'Dibuang'): ?>
                                        <span class="badge-aksi badge-dibuang"><i class="bi bi-trash3"></i> Dibuang</span>
                                    <?php else: ?>
                                        <span class="badge-aksi badge-retur"><i class="bi bi-arrow-repeat"></i> Retur Supplier</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:var(--text-secondary);"><?= htmlspecialchars($row['keterangan'] ?? '—') ?></td>
                                <td>
                                    <div style="display:flex;gap:6px;justify-content:center;">
                                        <button class="btn-approve"
                                            data-id="<?= $row['id'] ?>"
                                            data-nama="<?= htmlspecialchars($row['nama_barang']) ?>"
                                            data-jumlah="<?= $row['jumlah'] ?> <?= htmlspecialchars($satuan) ?>"
                                            data-aksi="<?= htmlspecialchars($row['aksi']) ?>"
                                            onclick="openModal(this, 'approve')">
                                            <i class="bi bi-check-circle"></i> Setujui
                                        </button>
                                        <button class="btn-reject"
                                            data-id="<?= $row['id'] ?>"
                                            data-nama="<?= htmlspecialchars($row['nama_barang']) ?>"
                                            data-jumlah="<?= $row['jumlah'] ?> <?= htmlspecialchars($satuan) ?>"
                                            data-aksi="<?= htmlspecialchars($row['aksi']) ?>"
                                            onclick="openModal(this, 'reject')">
                                            <i class="bi bi-x-circle"></i> Tolak
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- Modal Konfirmasi -->
<div class="modal-overlay" id="modalAction">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-head-icon" id="modalIcon">
                <i class="bi bi-check-circle" id="modalIconEl"></i>
            </div>
            <div>
                <div class="modal-title" id="modalTitle">Setujui Penanganan</div>
                <div class="modal-subtitle" id="modalSubtitle">Tindakan ini tidak dapat dibatalkan</div>
            </div>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="id" id="actionId">
                <input type="hidden" name="action" id="actionType">

                <div class="modal-info-box" id="modalInfoBox">
                    <div class="modal-info-row">
                        <span class="lbl">Barang</span>
                        <span class="val" id="infoNama">—</span>
                    </div>
                    <div class="modal-info-row">
                        <span class="lbl">Jumlah</span>
                        <span class="val" id="infoJumlah">—</span>
                    </div>
                    <div class="modal-info-row">
                        <span class="lbl">Tindakan</span>
                        <span class="val" id="infoAksi">—</span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Catatan Owner <span style="font-weight:400;color:var(--text-muted);">(opsional)</span></label>
                    <textarea name="owner_note" class="form-control" rows="3" placeholder="Tulis catatan atau alasan keputusan..."></textarea>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn-cancel" onclick="closeModal()">
                    <i class="bi bi-x"></i> Batal
                </button>
                <button type="submit" class="btn-confirm" id="submitBtn">
                    <i class="bi bi-check-circle" id="submitIcon"></i>
                    <span id="submitLabel">Konfirmasi</span>
                </button>
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

const modal = document.getElementById('modalAction');

function openModal(btn, action) {
    document.getElementById('actionId').value    = btn.dataset.id;
    document.getElementById('actionType').value  = action;
    document.getElementById('infoNama').textContent   = btn.dataset.nama;
    document.getElementById('infoJumlah').textContent = btn.dataset.jumlah;
    document.getElementById('infoAksi').textContent   = btn.dataset.aksi;

    const isApprove = action === 'approve';
    document.getElementById('modalTitle').textContent    = isApprove ? 'Setujui Penanganan' : 'Tolak Penanganan';
    document.getElementById('modalSubtitle').textContent = isApprove
        ? 'Stok rusak akan dikurangi setelah disetujui'
        : 'Stok rusak tidak akan berubah jika ditolak';

    const iconEl = document.getElementById('modalIconEl');
    const iconBox = document.getElementById('modalIcon');
    iconEl.className = isApprove ? 'bi bi-check-circle' : 'bi bi-x-circle';
    iconBox.className = 'modal-head-icon ' + (isApprove ? 'approve' : 'reject');

    const submitBtn = document.getElementById('submitBtn');
    const submitIcon = document.getElementById('submitIcon');
    document.getElementById('submitLabel').textContent = isApprove ? 'Ya, Setujui' : 'Ya, Tolak';
    submitIcon.className = isApprove ? 'bi bi-check-circle' : 'bi bi-x-circle';
    submitBtn.style.background = isApprove ? 'var(--accent)' : 'var(--danger)';
    submitBtn.style.color = 'white';

    modal.classList.add('open');
}

function closeModal() {
    modal.classList.remove('open');
}

modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

document.querySelectorAll('.alert').forEach(a => setTimeout(() => a.remove(), 5000));
</script>
</body>
</html>