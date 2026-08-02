<?php
date_default_timezone_set('Asia/Makassar');

require_once __DIR__ . '/../includes/auth.php';
cekLogin();
cekRole('admin');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

global $conn, $conn_pos;

// ========== PROSES SUPPLY DARI PERMINTAAN ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_dari_permintaan'])) {
    $permintaan_id  = (int)$_POST['permintaan_id'];
    $tanggal        = $_POST['tanggal'] ?? date('Y-m-d H:i:s');
    $user_pos       = trim($_POST['user_pos']);
    $diproses_oleh  = null; // TIDAK PAKAI USER ID, karena hanya ambil dari permintaan teman

    $items = json_decode($_POST['items_json'], true);

    if (empty($items)) {
        $_SESSION['error'] = "Tidak ada barang yang diproses.";
        header("Location: barang_keluar.php");
        exit;
    }

    if (!posTersedia()) {
        $_SESSION['error'] = "Koneksi ke database POS tidak tersedia.";
        header("Location: barang_keluar.php");
        exit;
    }

    $conn->begin_transaction();
    try {
        // Validasi permintaan dari POS teman
        $permStatus = getPermintaanById($permintaan_id, true);
        if (!$permStatus || !in_array($permStatus['status'], ['Pending', 'Diproses'])) {
            throw new Exception("Permintaan sudah diproses atau tidak ditemukan.");
        }

        // Validasi stok
        foreach ($items as $item) {
            if ($item['qty'] <= 0) throw new Exception("Jumlah barang harus > 0!");
            if (empty($item['barang_id']) || $item['barang_id'] <= 0) {
                throw new Exception("Barang '{$item['nama']}' tidak ditemukan di master stok. Periksa penulisan nama.");
            }
            $cekBarang = $conn->prepare("SELECT id_barang, stok FROM barang WHERE id_barang = ?");
            $cekBarang->bind_param("i", $item['barang_id']);
            $cekBarang->execute();
            $barang = $cekBarang->get_result()->fetch_assoc();
            if (!$barang) throw new Exception("Barang ID {$item['barang_id']} tidak ditemukan!");
            if ($barang['stok'] < $item['qty']) {
                throw new Exception("Stok tidak mencukupi untuk {$item['nama']} (stok: {$barang['stok']})");
            }
        }

        $status = 'Diproses';
        foreach ($items as $item) {
            // Insert ke tabel barang_keluar (diproses_oleh = NULL)
            $inserted = insertBarangKeluar(
                $permintaan_id,
                $tanggal,
                $user_pos,
                $item['barang_id'],
                $item['qty'],
                $status,
                $diproses_oleh // NULL
            );
            if (!$inserted) throw new Exception("Gagal menyimpan barang keluar untuk {$item['nama']}");

            // Kurangi stok
            $stok_sebelum = getStokBarang($item['barang_id']);
            $stok_baru = $stok_sebelum - $item['qty'];
            updateStokBarang($item['barang_id'], $stok_baru);
            $stok_sesudah = getStokBarang($item['barang_id']);

            // Catat mutasi
            catatMutasiStok(
                $item['barang_id'],
                'KELUAR',
                $item['qty'],
                $stok_sebelum,
                $stok_sesudah,
                "Barang keluar untuk permintaan #$permintaan_id",
                null
            );
        }

        $conn->commit();

        // Sesuai activity diagram: setelah supply berhasil dibuat,
        // status permintaan di POS langsung diubah menjadi 'Diterima'.
        $jumlah_diterima_str = implode(',', array_column($items, 'qty'));
        if (updatePermintaanDiterima($permintaan_id, $jumlah_diterima_str)) {
            $_SESSION['success'] = "Supply berhasil dibuat dan permintaan telah ditandai 'Diterima'.";
        } else {
            error_log("Gagal update status permintaan di POS (id=$permintaan_id)");
            $_SESSION['success'] = "Supply berhasil dibuat, tapi status di sistem POS gagal terupdate otomatis.";
        }

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Gagal memproses: " . $e->getMessage();
    }
    header("Location: barang_keluar.php");
    exit;
}

// ========== PROSES HAPUS / BATALKAN SUPPLY ==========
// CATATAN PENTING:
// Saat supply dibatalkan, stok dikembalikan (MASUK) dan status permintaan
// di POS di-set kembali ke 'Diproses' -> ini SENGAJA, supaya permintaan
// tersebut otomatis muncul lagi di panel "Permintaan Masuk" untuk diproses ulang.
// Kalau kamu MAU permintaan itu balik ke 'Pending' (dianggap belum pernah
// disentuh sama sekali), ganti baris updateStatusPermintaan() di bawah
// dari 'Diproses' menjadi 'Pending'.
if (isset($_GET['hapus'])) {
    $id = (int)$_GET['hapus'];
    $data = getBarangKeluarById($id);
    if (!$data) {
        $_SESSION['error'] = "Data tidak ditemukan.";
        header("Location: barang_keluar.php");
        exit;
    }

    $conn->begin_transaction();
    try {
        $barang_id = $data['barang_id'];
        $qty = $data['qty'];
        $permintaan_id = (int)$data['id_permintaan'];

        $stok_sebelum = getStokBarang($barang_id);
        $stok_baru = $stok_sebelum + $qty;
        updateStokBarang($barang_id, $stok_baru);
        $stok_sesudah = getStokBarang($barang_id);
        catatMutasiStok(
            $barang_id,
            'MASUK',
            $qty,
            $stok_sebelum,
            $stok_sesudah,
            "Pembatalan supply ID #$id",
            null
        );

        $berhasilHapus = deleteBarangKeluar($id);
        if (!$berhasilHapus) {
            throw new Exception("Gagal menghapus data barang keluar.");
        }

        $conn->commit();

        // Ganti 'Diproses' -> 'Pending' di sini kalau ingin permintaan
        // dianggap belum pernah diproses sama sekali.
        if (updateStatusPermintaan($permintaan_id, 'Diproses')) {
            $_SESSION['success'] = "Supply dibatalkan, stok dikembalikan. Permintaan muncul kembali di panel untuk diproses ulang.";
        } else {
            $_SESSION['warning'] = "Supply dibatalkan, tapi status di POS gagal diupdate.";
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Gagal membatalkan: " . $e->getMessage();
    }
    header("Location: barang_keluar.php");
    exit;
}

// ========== PROSES TOLAK PERMINTAAN ==========
if (isset($_GET['tolak_permintaan'])) {
    $pid = (int)$_GET['tolak_permintaan'];
    if (updateStatusPermintaan($pid, 'Ditolak')) {
        $_SESSION['success'] = "Permintaan berhasil ditolak.";
    } else {
        $_SESSION['error'] = "Tidak bisa menolak permintaan.";
    }
    header("Location: barang_keluar.php");
    exit;
}

// ========== AJAX: Ambil data detail barang keluar (FIXED) ==========
if (isset($_GET['get_data'])) {
    // Buang output apapun yang mungkin sudah tercetak (warning/notice dll)
    // supaya response yang dikirim ke JS selalu JSON murni.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    $id = (int)$_GET['get_data'];

    try {
        $data = getBarangKeluarById($id);
        if (!$data) {
            echo json_encode(['error' => 'Data tidak ditemukan']);
            exit;
        }

        $data['nama_barang'] = 'Tidak diketahui';
        if (!empty($data['barang_id'])) {
            $stmt = $conn->prepare("SELECT nama_barang FROM barang WHERE id_barang = ?");
            $stmt->bind_param("i", $data['barang_id']);
            $stmt->execute();
            $barang = $stmt->get_result()->fetch_assoc();
            $data['nama_barang'] = $barang['nama_barang'] ?? 'Tidak diketahui';
        }

        echo json_encode($data);
    } catch (\Throwable $e) {
        // Tangkap SEMUA jenis error (termasuk mysqli_sql_exception) supaya
        // response tetap berupa JSON valid, bukan halaman fatal-error HTML.
        error_log("get_data barang_keluar error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Terjadi kesalahan saat mengambil data: ' . $e->getMessage()]);
    }
    exit;
}

// ========== AMBIL SEMUA DATA BARANG KELUAR (tanpa filter) ==========
$list = getAllBarangKeluar();

// ========== STATISTIK ==========
$totalTrans = $conn->query("SELECT COUNT(*) as total FROM barang_keluar")->fetch_assoc()['total'] ?? 0;
$totalItem  = $conn->query("SELECT COALESCE(SUM(qty),0) as total FROM barang_keluar")->fetch_assoc()['total'] ?? 0;

// ========== PERMINTAAN AKTIF DARI POS ==========
// Sesuai activity diagram: hanya permintaan berstatus 'Diproses' yang ditampilkan.
$permintaan_panel = getPermintaanAktif();
$jumlahPermintaanAktif = $permintaan_panel ? $permintaan_panel->num_rows : 0;

// ========== STOK SEMUA BARANG (untuk modal) ==========
$stokSemuaBarang = [];
$sqStok = $conn->query("SELECT id_barang, nama_barang, stok FROM barang ORDER BY nama_barang");
while ($sb = $sqStok->fetch_assoc()) {
    $stokSemuaBarang[] = ['id'=>(int)$sb['id_barang'], 'nama'=>$sb['nama_barang'], 'stok'=>(int)$sb['stok']];
}

// ========== SIDEBAR ACTIVE ==========
$current_file = basename($_SERVER['PHP_SELF']);
$open_master   = in_array($current_file, ['barang.php','master_kategori.php','master_supplier.php']);
$open_transaksi= in_array($current_file, ['preorder.php','barang_masuk.php','barang_keluar.php','barang_rusak.php','koreksi_stok.php']);
$open_monitor  = in_array($current_file, ['stok.php','stock_mutasi.php']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barang Keluar — Keva Jaya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ===== CSS SAMA SEPERTI SEBELUMNYA (tidak diubah) ===== */
        :root {
            --bg: #f5f4f0;
            --surface: #ffffff;
            --surface-2: #f9f8f5;
            --border: #e8e6e0;
            --border-strong: #d4d0c8;
            --text-primary: #1a1916;
            --text-secondary: #6b6860;
            --text-muted: #9c9890;
            --accent: #2d6a4f;
            --accent-light: #e8f4ee;
            --accent-hover: #245a42;
            --danger: #c0392b;
            --danger-light: #fdecea;
            --warning: #d68910;
            --warning-light: #fef9e7;
            --info: #1a5276;
            --info-light: #e8f0f8;
            --sidebar-w: 252px;
            --radius: 10px;
            --radius-sm: 6px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
            --shadow: 0 4px 16px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.04);
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); color: var(--text-primary); min-height: 100vh; font-size: 14px; line-height: 1.6; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 99px; }

        .sidebar { position: fixed; top: 0; left: 0; width: var(--sidebar-w); height: 100vh; background: var(--text-primary); display: flex; flex-direction: column; z-index: 100; overflow-y: auto; }
        .sidebar-brand { padding: 20px 20px 16px; border-bottom: 1px solid rgba(255,255,255,0.08); flex-shrink: 0; }
        .sidebar-brand .brand-logo { display: flex; align-items: center; gap: 10px; }
        .brand-icon { width: 34px; height: 34px; background: var(--accent); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; font-size: 16px; color: white; flex-shrink: 0; }
        .brand-name { font-size: 15px; font-weight: 700; color: white; letter-spacing: -0.3px; }
        .brand-sub { font-size: 11px; color: rgba(255,255,255,0.38); font-weight: 400; }
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

        .main { margin-left: var(--sidebar-w); min-height: 100vh; display: flex; flex-direction: column; }
        .topbar { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0 24px; height: 56px; display: flex; align-items: center; gap: 16px; position: sticky; top: 0; z-index: 50; }
        .btn-toggle { width: 34px; height: 34px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--surface-2); color: var(--text-secondary); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: var(--transition); font-size: 16px; flex-shrink: 0; }
        .btn-toggle:hover { background: var(--border); color: var(--text-primary); }
        .breadcrumb-bar { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-muted); }
        .breadcrumb-bar a { color: inherit; text-decoration: none; }
        .breadcrumb-bar span { color: var(--text-secondary); font-weight: 500; }
        .topbar-right { margin-left: auto; display: flex; align-items: center; gap: 10px; }
        .page-body { padding: 24px; flex: 1; }
        .page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
        .page-title { font-size: 22px; font-weight: 700; letter-spacing: -0.5px; }
        .page-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 2px; }

        .alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: var(--radius); font-size: 13.5px; margin-bottom: 20px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from{opacity:0;transform:translateY(-8px);}to{opacity:1;transform:translateY(0);} }
        .alert-success { background: var(--accent-light); color: var(--accent); border: 1px solid #b7ddc9; }
        .alert-danger  { background: var(--danger-light); color: var(--danger); border: 1px solid #f5b7b1; }
        .alert i { font-size: 16px; flex-shrink: 0; }
        .alert-close { margin-left: auto; cursor: pointer; opacity: 0.6; }

        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
        .card-head { padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); background: var(--surface-2); flex-wrap: wrap; }
        .card-head-title { font-size: 13.5px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .card-head-title i { color: var(--accent); }
        .search-wrap { position: relative; }
        .search-wrap i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 14px; color: var(--text-muted); }
        .search-input { padding: 7px 12px 7px 32px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; font-family: inherit; background: var(--surface); width: 220px; transition: var(--transition); }
        .search-input:focus { outline: none; border-color: var(--accent); width: 260px; box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }

        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead th { padding: 11px 16px; text-align: left; font-weight: 600; font-size: 12px; letter-spacing: 0.4px; text-transform: uppercase; color: var(--text-muted); background: var(--surface-2); border-bottom: 1px solid var(--border); white-space: nowrap; }
        tbody td { padding: 13px 16px; border-bottom: 1px solid var(--border); color: var(--text-primary); vertical-align: middle; }
        tbody tr:hover { background: var(--surface-2); }

        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 550; }
        .badge-pending  { background: var(--warning-light); color: var(--warning); }
        .badge-diproses { background: var(--info-light); color: var(--info); }
        .badge-dikirim  { background: #e8e8ff; color: #3730a3; }
        .badge-ditolak  { background: var(--danger-light); color: var(--danger); }
        .badge-gray     { background: var(--surface-2); color: var(--text-secondary); border: 1px solid var(--border); }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 36px; opacity: 0.4; display: block; margin-bottom: 10px; }

        .btn-action { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary); cursor: pointer; transition: var(--transition); text-decoration: none; font-size: 14px; }
        .btn-action:hover { background: var(--surface-2); }
        .btn-action.danger:hover { background: var(--danger-light); border-color: #f5b7b1; color: var(--danger); }
        .btn-action.success:hover { background: var(--accent-light); border-color: #b7ddc9; color: var(--accent); }

        .btn-primary { display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; background: var(--accent); color: white; border: none; border-radius: var(--radius-sm); font-size: 13.5px; font-weight: 600; font-family: inherit; cursor: pointer; transition: var(--transition); text-decoration: none; }
        .btn-primary:hover { background: var(--accent-hover); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(45,106,79,0.25); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-primary i { font-size: 15px; }
        .btn-cancel { padding: 8px 16px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text-secondary); font-size: 13.5px; font-weight: 500; font-family: inherit; cursor: pointer; transition: var(--transition); }
        .btn-cancel:hover { background: var(--surface-2); }
        .row-count { font-size: 12px; color: var(--text-muted); background: var(--surface-2); border: 1px solid var(--border); padding: 3px 10px; border-radius: 99px; }

        .summary-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; box-shadow: var(--shadow-sm); }
        .summary-card small { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .summary-card h5 { font-size: 24px; font-weight: 700; margin: 4px 0 0; color: var(--text-primary); }

        .panel-permintaan { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); margin-bottom: 24px; overflow: hidden; }
        .panel-permintaan .panel-head { padding: 14px 20px; border-bottom: 1px solid var(--border); background: var(--surface-2); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
        .panel-head-left { display: flex; align-items: center; gap: 10px; font-size: 13.5px; font-weight: 600; }
        .panel-head-left i { color: var(--accent); }
        .permintaan-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px,1fr)); gap: 16px; padding: 16px; }
        .perm-card { border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); box-shadow: var(--shadow-sm); transition: var(--transition); overflow: hidden; }
        .perm-card:hover { border-color: var(--accent); box-shadow: 0 4px 20px rgba(45,106,79,0.12); transform: translateY(-1px); }
        .perm-card.status-pending  { border-left: 3px solid var(--warning); }
        .perm-card.status-diproses { border-left: 3px solid var(--info); }
        .perm-card-head { padding: 10px 14px; border-bottom: 1px solid var(--border); background: var(--surface-2); display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .perm-id { font-size: 12px; font-weight: 700; color: var(--accent); font-family: 'JetBrains Mono', monospace; }
        .perm-card-body { padding: 12px 14px; }
        .perm-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; font-size: 12px; color: var(--text-secondary); }
        .perm-meta span { display: flex; align-items: center; gap: 4px; }
        .perm-items-title { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        .perm-chips { display: flex; flex-wrap: wrap; gap: 5px; }
        .perm-chip { background: var(--accent-light); color: var(--accent); border: 1px solid #b7ddc9; border-radius: 6px; padding: 3px 10px; font-size: 12px; font-weight: 500; display: flex; align-items: center; gap: 5px; }
        .chip-qty { background: var(--accent); color: white; border-radius: 4px; padding: 0 5px; font-size: 11px; font-weight: 700; }
        .perm-card-actions { display: flex; gap: 6px; padding: 10px 14px; border-top: 1px solid var(--border); background: var(--surface-2); }
        .btn-proses { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 12px; background: var(--accent); color: white; border: none; border-radius: var(--radius-sm); font-size: 13px; font-weight: 600; font-family: inherit; cursor: pointer; transition: var(--transition); }
        .btn-proses:hover { background: var(--accent-hover); transform: translateY(-1px); }
        .btn-tolak { display: inline-flex; align-items: center; justify-content: center; gap: 5px; padding: 8px 12px; background: var(--surface); color: var(--danger); border: 1px solid #f5b7b1; border-radius: var(--radius-sm); font-size: 13px; font-weight: 500; font-family: inherit; cursor: pointer; transition: var(--transition); }
        .btn-tolak:hover { background: var(--danger-light); }
        .perm-empty { padding: 36px; text-align: center; color: var(--text-muted); }
        .perm-empty i { font-size: 28px; opacity: 0.35; display: block; margin-bottom: 8px; }

        .modal-overlay-proses { position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 300; display: none; align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay-proses.show { display: flex; }
        .modal-box-proses { background: var(--surface); border-radius: 14px; width: 100%; max-width: 700px; box-shadow: 0 20px 60px rgba(0,0,0,0.25); max-height: 92vh; display: flex; flex-direction: column; }
        .modal-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: var(--surface-2); }
        .modal-title { font-size: 16px; font-weight: 700; }
        .modal-close { width: 34px; height: 34px; border: none; border-radius: 8px; background: transparent; cursor: pointer; color: var(--text-secondary); transition: var(--transition); }
        .modal-close:hover { background: var(--border); color: var(--text-primary); }
        .modal-body { padding: 20px; overflow-y: auto; max-height: 70vh; }
        .modal-footer { padding: 16px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; background: var(--surface-2); }
        .info-box { background: var(--info-light); border: 1px solid #b6d0e8; border-radius: var(--radius-sm); padding: 10px 14px; font-size: 13px; color: var(--info); margin-bottom: 14px; display: flex; align-items: flex-start; gap: 8px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap: 16px; }
        .form-group { display: flex; flex-direction: column; }
        .form-label { font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; }
        .form-control, .form-select, textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); font-family: inherit; font-size: 13px; transition: var(--transition); }
        .form-control:focus, .form-select:focus, textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }
        .section-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); margin-bottom: 12px; }
        .proses-item-row { display: grid; grid-template-columns: 1fr auto auto auto; gap: 10px; align-items: center; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); margin-bottom: 8px; background: var(--surface-2); }
        .proses-item-row.stok-habis { border-color: #f5b7b1; background: var(--danger-light); }
        .proses-item-nama { font-size: 13.5px; font-weight: 600; }
        .proses-item-req { font-size: 12px; color: var(--text-muted); }
        .proses-stok-badge { font-size: 12px; padding: 3px 10px; border-radius: 99px; white-space: nowrap; font-weight: 550; }
        .stok-ok   { background: var(--accent-light); color: var(--accent); }
        .stok-warn { background: var(--warning-light); color: var(--warning); }
        .stok-fail { background: var(--danger-light); color: var(--danger); }
        .proses-input { width: 72px; padding: 6px 8px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; font-family: inherit; text-align: center; background: var(--surface); }
        .proses-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(45,106,79,0.1); }
        .proses-label { font-size: 11px; color: var(--text-muted); display: block; margin-bottom: 3px; }
        .item-list { display: flex; flex-direction: column; gap: 10px; }
        .item-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 12px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-2); }
        .kode-text { font-family: 'JetBrains Mono', monospace; font-weight: 600; color: var(--accent); }
        .topbar-notif { position: relative; }
        .notif-count { position: absolute; top:-5px; right:-5px; min-width:18px; height:18px; padding:0 5px; border-radius:99px; background:var(--danger); color:white; font-size:10px; font-weight:700; display:flex; align-items:center; justify-content:center; }

        @media (max-width: 768px) {
            .sidebar { left: -252px; }
            .sidebar.mobile-open { left: 0; }
            .main { margin-left: 0; }
            .permintaan-grid { grid-template-columns: 1fr; }
            .proses-item-row { grid-template-columns: 1fr 1fr; }
            .search-input, .search-input:focus { width: 100%; }
        }
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
            <div><div class="user-name"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></div><div class="user-role">Administrator</div></div>
            <a href="../logout.php" class="btn-sm" style="margin-left:auto;border:none;"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </div>
</aside>

<div class="main" id="main">
    <header class="topbar">
        <button class="btn-toggle" id="sidebarToggle"><i class="bi bi-list"></i></button>
        <div class="breadcrumb-bar">
            <a href="dashboard.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Barang Keluar</span>
        </div>
        <div class="topbar-right">
            <?php if ($jumlahPermintaanAktif > 0): ?>
            <div class="topbar-notif">
                <button class="btn-toggle" onclick="document.getElementById('panelPermintaan').scrollIntoView({behavior:'smooth'})">
                    <i class="bi bi-inbox"></i>
                </button>
                <span class="notif-count"><?= $jumlahPermintaanAktif ?></span>
            </div>
            <?php endif; ?>
            <span style="font-size:12px;color:var(--text-muted);"><?= date('d M Y') ?></span>
        </div>
    </header>

    <div class="page-body">
        <?php if(isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?= $_SESSION['success']; unset($_SESSION['success']); ?><i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i></div>
        <?php endif; ?>
        <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?><i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i></div>
        <?php endif; ?>
        <?php if(isset($_SESSION['warning'])): ?>
        <div class="alert alert-danger" style="background: var(--warning-light); color: var(--warning); border-color: #f0c25e;"><i class="bi bi-exclamation-triangle-fill"></i> <?= $_SESSION['warning']; unset($_SESSION['warning']); ?><i class="bi bi-x alert-close" onclick="this.closest('.alert').remove()"></i></div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <div class="page-title">Barang Keluar (Supply)</div>
                <div class="page-subtitle">Supply dibuat dari permintaan POS</div>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px,1fr)); gap:16px; margin-bottom:24px;">
            <div class="summary-card"><small>Total Transaksi</small><h5><?= $totalTrans ?></h5></div>
            <div class="summary-card"><small>Total Barang Keluar</small><h5><?= $totalItem ?> item</h5></div>
            <?php if ($jumlahPermintaanAktif > 0): ?>
            <div class="summary-card" style="border-left:3px solid var(--warning);">
                <small>Permintaan Menunggu</small>
                <h5 style="color:var(--warning);"><?= $jumlahPermintaanAktif ?></h5>
            </div>
            <?php endif; ?>
        </div>

        <div class="panel-permintaan" id="panelPermintaan">
            <div class="panel-head">
                <div class="panel-head-left">
                    <i class="bi bi-inbox-fill"></i>
                    Permintaan Masuk (Diproses)
                    <?php if ($jumlahPermintaanAktif > 0): ?>
                    <span class="badge badge-pending"><?= $jumlahPermintaanAktif ?> aktif</span>
                    <?php endif; ?>
                </div>
                <div class="panel-head-hint">Klik <strong>Proses Supply</strong></div>
            </div>

            <?php if (!$permintaan_panel || $permintaan_panel->num_rows === 0): ?>
            <div class="perm-empty">
                <i class="bi bi-check2-all"></i>
                <p>Tidak ada permintaan yang menunggu</p>
            </div>
            <?php else: ?>
            <div class="permintaan-grid">
                <?php while ($p = $permintaan_panel->fetch_assoc()):
                    $produkRaw = $p['produk'] ?? '';
                    $jumlahRaw = $p['jumlah'] ?? '';
                    $produkArr = [];
                    $jumlahArr = [];
                    if (trim($produkRaw) !== '' && trim($jumlahRaw) !== '') {
                        $produkArr = array_map('trim', explode(',', $produkRaw));
                        $jumlahArr = array_map('intval', explode(',', $jumlahRaw));
                        $produkArr = array_filter($produkArr, fn($v) => $v !== '');
                        while (count($jumlahArr) < count($produkArr)) $jumlahArr[] = 0;
                        while (count($produkArr) < count($jumlahArr)) $produkArr[] = '(tidak diketahui)';
                    }
                    $tglFmt = date('d/m/Y H:i', strtotime($p['tanggal']));
                    $statusClass = $p['status'] == 'Pending' ? 'status-pending' : 'status-diproses';
                    $badgeStatus = $p['status'] == 'Pending' ? 'badge-pending' : 'badge-diproses';
                ?>
                <div class="perm-card <?= $statusClass ?>">
                    <div class="perm-card-head">
                        <span class="perm-id"><?= htmlspecialchars($p['id_permintaan'] ?? 'PRM-'.$p['id']) ?></span>
                        <span class="badge <?= $badgeStatus ?>"><?= htmlspecialchars($p['status']) ?></span>
                    </div>
                    <div class="perm-card-body">
                        <div class="perm-meta">
                            <span><i class="bi bi-person"></i> <?= htmlspecialchars($p['user']) ?></span>
                            <span><i class="bi bi-calendar3"></i> <?= $tglFmt ?></span>
                        </div>
                        <div class="perm-items-title">Barang Diminta</div>
                        <div class="perm-chips">
                            <?php if (empty($produkArr)): ?>
                                <span class="perm-chip" style="background:#fef9e7; color:#b9770e;">⚠️ Data produk tidak lengkap</span>
                            <?php else: ?>
                                <?php foreach ($produkArr as $idx => $namaProduk): ?>
                                    <span class="perm-chip">
                                        <?= htmlspecialchars($namaProduk) ?>
                                        <span class="chip-qty"><?= (int)($jumlahArr[$idx] ?? 0) ?></span>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="perm-card-actions">
                        <button class="btn-proses"
                            onclick="bukaModalProses(<?= (int)$p['id'] ?>, '<?= addslashes($p['id_permintaan'] ?? 'PRM-'.$p['id']) ?>', '<?= addslashes($p['user']) ?>', '<?= addslashes($produkRaw) ?>', '<?= addslashes($jumlahRaw) ?>', '<?= $p['tanggal'] ?>')">
                            <i class="bi bi-lightning-charge-fill"></i> Proses Supply
                        </button>
                        <button class="btn-tolak"
                            onclick="tolakPermintaan(<?= (int)$p['id'] ?>, '<?= addslashes($p['id_permintaan'] ?? 'PRM-'.$p['id']) ?>')">
                            <i class="bi bi-x-lg"></i> Tolak
                        </button>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- CARD RIWAYAT SUPPLY -->
        <div class="card">
            <div class="card-head">
                <div class="card-head-title"><i class="bi bi-truck"></i> Riwayat Supply <span class="row-count" id="rowCount"></span></div>
                <div class="search-wrap"><i class="bi bi-search"></i><input type="text" class="search-input" id="searchInput" placeholder="Cari permintaan, user_pos..."></div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>No.</th><th>Permintaan ID</th><th>Tanggal</th><th>User POS</th><th>Barang</th><th>Qty</th><th>Diproses Oleh</th><th style="width:90px;">Aksi</th></tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php if($list && $list->num_rows): $no = 1; while($r=$list->fetch_assoc()): ?>
                        <tr>
                            <td class="kode-text"><?= $no++ ?></td>
                            <td><?= htmlspecialchars($r['id_permintaan']) ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($r['tanggal'])) ?></td>
                            <td><?= htmlspecialchars($r['user_pos']) ?></td>
                            <td><?= htmlspecialchars($r['nama_barang'] ?? 'Tidak diketahui') ?></td>
                            <td><span class="badge badge-gray"><?= (int)$r['qty'] ?></span></td>
                            <td>admin</td>
                            <td>
                                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                    <button class="btn-action btn-detail" data-id="<?= $r['id'] ?>" title="Detail"><i class="bi bi-eye"></i></button>
                                    <a href="?hapus=<?= $r['id'] ?>" class="btn-action danger" title="Batalkan" onclick="return confirm('Yakin batalkan supply ini? Stok akan dikembalikan.')"><i class="bi bi-trash"></i></a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i><p>Belum ada riwayat supply</p></div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- MODAL PROSES PERMINTAAN -->
<div class="modal-overlay-proses" id="modalProsesPermintaan">
    <div class="modal-box-proses">
        <form method="POST" id="formProsesPermintaan">
            <div class="modal-header">
                <div>
                    <div class="modal-title"><i class="bi bi-lightning-charge-fill" style="color:var(--accent);margin-right:6px;"></i>Proses Permintaan → Buat Supply</div>
                    <div id="modalProsesSub" style="font-size:12px;color:var(--text-muted);margin-top:2px;">—</div>
                </div>
                <button type="button" class="modal-close" onclick="tutupModalProses()"><i class="bi bi-x"></i></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="permintaan_id" id="proses_permintaan_id">
                <input type="hidden" name="items_json" id="proses_items_json">
                <input type="hidden" name="simpan_dari_permintaan" value="1">

                <div class="info-box">
                    <i class="bi bi-info-circle-fill"></i>
                    <span>Periksa stok, sesuaikan qty jika perlu. Qty > 0 akan diproses.</span>
                </div>

                <div class="form-grid" style="margin-bottom:16px;">
                    <div class="form-group">
                        <label class="form-label">User POS</label>
                        <input type="text" name="user_pos" id="proses_user_pos" class="form-control" placeholder="Nama user POS" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tanggal</label>
                        <input type="datetime-local" name="tanggal" id="proses_tanggal" class="form-control" value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                </div>

                <div class="section-label">Detail Barang</div>
                <div id="prosesItemRows"></div>

                <div class="form-group" style="margin-top:14px;">
                    <label class="form-label">Catatan (opsional)</label>
                    <textarea name="catatan" id="proses_catatan" class="form-control" rows="2" placeholder="Catatan tambahan..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="tutupModalProses()">Batal</button>
                <button type="submit" class="btn-primary" id="btnSimpanProses"><i class="bi bi-check2-circle"></i> Buat Supply</button>
            </div>
        </form>
    </div>
</div>

<script>
// ========== UTILITY ==========
function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

// ========== SIDEBAR ==========
const sidebar = document.getElementById('sidebar');
document.getElementById('sidebarToggle').addEventListener('click', () => {
    if (window.innerWidth <= 768) sidebar.classList.toggle('mobile-open');
    else sidebar.classList.toggle('collapsed');
});
function toggleNav(id, btn) {
    const el = document.getElementById(id);
    const isOpen = el.style.display !== 'none';
    el.style.display = isOpen ? 'none' : 'block';
    btn.setAttribute('aria-expanded', !isOpen);
}

// ========== DETAIL MODAL (FIXED) ==========
document.querySelectorAll('.btn-detail').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;

        fetch(`?get_data=${id}`)
            .then(async (r) => {
                // Ambil sebagai teks dulu, supaya kalau server balas HTML
                // (misal karena error PHP) kita bisa tahu isinya, bukan
                // langsung gagal parse tanpa penjelasan.
                const text = await r.text();
                let json;
                try {
                    json = JSON.parse(text);
                } catch (parseErr) {
                    console.error('Response bukan JSON valid dari server:', text);
                    throw new Error('Server mengembalikan response tidak valid (cek console browser untuk detail).');
                }
                if (!r.ok && !json.error) {
                    throw new Error(`Request gagal dengan status ${r.status}`);
                }
                return json;
            })
            .then(d => {
                if (d.error) { alert(d.error); return; }

                const detailModal = document.createElement('div');
                detailModal.className = 'modal-overlay-proses show';
                detailModal.style.display = 'flex';
                detailModal.innerHTML = `
                    <div class="modal-box-proses" style="max-width:600px;margin:20px;">
                        <div class="modal-header"><div class="modal-title">Detail Supply</div><button type="button" class="modal-close" onclick="this.closest('.modal-overlay-proses').remove()"><i class="bi bi-x"></i></button></div>
                        <div class="modal-body">
                            <div class="form-grid" style="margin-bottom:16px;">
                                <div><div class="form-label">Permintaan ID</div><div>${escapeHtml(d.id_permintaan)}</div></div>
                                <div><div class="form-label">Tanggal</div><div>${escapeHtml(d.tanggal)}</div></div>
                                <div><div class="form-label">User POS</div><div>${escapeHtml(d.user_pos)}</div></div>
                                <div><div class="form-label">Barang</div><div>${escapeHtml(d.nama_barang)}</div></div>
                                <div><div class="form-label">Qty</div><div>${escapeHtml(d.qty)}</div></div>
                                <div><div class="form-label">Diproses Oleh</div><div>admin</div></div>
                            </div>
                        </div>
                        <div class="modal-footer"><button type="button" class="btn-cancel" onclick="this.closest('.modal-overlay-proses').remove()">Tutup</button></div>
                    </div>`;
                document.body.appendChild(detailModal);
                detailModal.addEventListener('click', e => { if(e.target === detailModal) detailModal.remove(); });
            })
            .catch(e => {
                console.error(e);
                alert(e.message || 'Terjadi kesalahan saat mengambil detail data.');
            });
    });
});

// ========== SEARCH & ROW COUNT ==========
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

// ========== MODAL PROSES PERMINTAAN ==========
const stokGudang = <?= json_encode($stokSemuaBarang) ?>;

function cariBarang(nama) {
    if (!nama) return null;
    const normalized = nama.trim().toLowerCase();
    return stokGudang.find(b => b.nama.toLowerCase().trim() === normalized) || null;
}

let prosesItemsData = [];

function bukaModalProses(permId, idPermintaan, user, produkStr, jumlahStr, tanggal) {
    document.getElementById('proses_permintaan_id').value = permId;
    document.getElementById('modalProsesSub').textContent = `${idPermintaan} · dari ${user} · ${tanggal.slice(0,10).split('-').reverse().join('/')}`;
    document.getElementById('proses_user_pos').value = user;

    const produkArr = produkStr.split(',').map(s => s.trim()).filter(Boolean);
    const jumlahArr = jumlahStr.split(',').map(s => parseInt(s.trim()) || 0);
    while (jumlahArr.length < produkArr.length) jumlahArr.push(0);
    while (produkArr.length < jumlahArr.length) produkArr.push('');

    const container = document.getElementById('prosesItemRows');
    container.innerHTML = '';
    prosesItemsData = [];

    let adaBarangTidakDitemukan = false;

    produkArr.forEach((nama, idx) => {
        if (nama === '') return;
        const qtyReq = jumlahArr[idx] || 0;
        const barang = cariBarang(nama);
        const stok = barang ? barang.stok : 0;
        const bid  = barang ? barang.id : '';

        let stokClass, stokLabel;
        if (!barang)            { stokClass='stok-fail'; stokLabel='✗ Tidak ditemukan'; adaBarangTidakDitemukan=true; }
        else if (stok === 0)    { stokClass='stok-fail'; stokLabel='✗ Stok habis'; }
        else if (stok < qtyReq) { stokClass='stok-warn'; stokLabel=`⚠ Stok: ${stok} (diminta: ${qtyReq})`; }
        else                    { stokClass='stok-ok';   stokLabel=`✓ Stok: ${stok}`; }

        const qtyKirim = barang ? Math.min(qtyReq, stok) : 0;
        const disabled = (!barang || stok === 0) ? 'disabled' : '';

        const div = document.createElement('div');
        div.className = 'proses-item-row' + ((!barang || stok===0) ? ' stok-habis' : '');
        div.innerHTML = `
            <div>
                <div class="proses-item-nama">${escapeHtml(nama)}</div>
                <div class="proses-item-req">Diminta: <strong>${qtyReq}</strong> unit</div>
            </div>
            <span class="proses-stok-badge ${stokClass}">${stokLabel}</span>
            <div>
                <label class="proses-label">Qty Kirim</label>
                <input type="number" id="pqty_${idx}" class="proses-input" value="${qtyKirim}" min="0" max="${stok}" onchange="updateProsesJson()" ${disabled}>
            </div>
            <div style="grid-column:1/-1; font-size:12px; color:var(--text-muted);">Barang ID: ${bid || '-'}</div>`;
        container.appendChild(div);

        prosesItemsData.push({ idx, barang_id: bid, nama, stok, qtyReq });
    });

    if (adaBarangTidakDitemukan) {
        const warn = document.createElement('div');
        warn.className = 'info-box';
        warn.style.cssText = 'background:var(--warning-light);border-color:#f0c25e;color:var(--warning);margin-top:8px;';
        warn.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i><span><strong>Beberapa barang tidak cocok dengan master stok.</strong> Pastikan nama barang di permintaan <strong>sama persis</strong> dengan nama di master.</span>';
        container.appendChild(warn);
    }

    updateProsesJson();
    document.getElementById('modalProsesPermintaan').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function updateProsesJson() {
    const items = [];
    prosesItemsData.forEach(it => {
        if (!it.barang_id) return;
        const qtyEl   = document.getElementById(`pqty_${it.idx}`);
        const qty     = parseInt(qtyEl?.value || 0);
        if (qty > 0) items.push({ barang_id: it.barang_id, qty, nama: it.nama });
    });
    document.getElementById('proses_items_json').value = JSON.stringify(items);
}

function tutupModalProses() {
    document.getElementById('modalProsesPermintaan').classList.remove('show');
    document.body.style.overflow = '';
}

document.getElementById('modalProsesPermintaan')?.addEventListener('click', function(e) {
    if (e.target === this) tutupModalProses();
});

document.getElementById('formProsesPermintaan')?.addEventListener('submit', function(e) {
    updateProsesJson();
    const items = JSON.parse(document.getElementById('proses_items_json').value || '[]');
    if (items.length === 0) {
        e.preventDefault();
        alert('Tidak ada barang yang bisa diproses. Pastikan qty > 0 dan stok tersedia.');
        return;
    }
});

function tolakPermintaan(permId, idPermintaan) {
    if (!confirm(`Tolak permintaan ${idPermintaan}?`)) return;
    window.location.href = `?tolak_permintaan=${permId}`;
}
</script>
</body>
</html>