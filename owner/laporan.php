<?php
date_default_timezone_set('Asia/Makassar');

require_once '../includes/auth.php';
cekLogin();
cekRole('owner');
require_once '../includes/functions.php';

global $conn;

// ========== HELPER ==========
function rupiah($val) { return 'Rp ' . number_format((float)$val, 0, ',', '.'); }
function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function bulanIndo($date) {
    $bulan = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',
              7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
    $split = explode('-', $date);
    return $bulan[(int)$split[1]] . ' ' . $split[0];
}

// Cek apakah kolom total_rusak ada di tabel barang (akumulasi historis stok rusak)
$hasTotalRusak = false;
$colCheck = $conn->query("SHOW COLUMNS FROM barang LIKE 'total_rusak'");
if ($colCheck instanceof mysqli_result && $colCheck->num_rows > 0) {
    $hasTotalRusak = true;
}
$colCheck = null; // bebaskan result set agar tidak bentrok dengan query berikutnya

// ========== TAB ==========
$allowed_tabs = ['pembelian', 'supply', 'stok', 'opname', 'preorder', 'rusak', 'kadaluarsa'];
$tab = $_GET['tab'] ?? 'pembelian';
if (!in_array($tab, $allowed_tabs)) $tab = 'pembelian';

// ========== PERIODE ==========
$filter_periode  = $_GET['periode'] ?? 'bulan_ini';
$allowed_periode = ['bulan_ini', 'bulan_lalu', 'custom'];
if (!in_array($filter_periode, $allowed_periode)) $filter_periode = 'bulan_ini';
$tgl_mulai = $_GET['tgl_mulai'] ?? '';
$tgl_akhir = $_GET['tgl_akhir'] ?? '';
if ($filter_periode == 'bulan_ini') {
    $tgl_mulai = date('Y-m-01'); $tgl_akhir = date('Y-m-t');
} elseif ($filter_periode == 'bulan_lalu') {
    $tgl_mulai = date('Y-m-01', strtotime('-1 month'));
    $tgl_akhir = date('Y-m-t',  strtotime('-1 month'));
} elseif ($filter_periode == 'custom' && !empty($tgl_mulai) && !empty($tgl_akhir)) {
    $tgl_mulai = date('Y-m-d', strtotime($tgl_mulai));
    $tgl_akhir = date('Y-m-d', strtotime($tgl_akhir));
} else {
    $filter_periode = 'bulan_ini';
    $tgl_mulai = date('Y-m-01'); $tgl_akhir = date('Y-m-t');
}
if ($filter_periode == 'bulan_ini')   $judul_periode = 'Bulan Ini (' . bulanIndo(date('Y-m-d')) . ')';
elseif ($filter_periode == 'bulan_lalu') $judul_periode = 'Bulan Lalu (' . bulanIndo(date('Y-m-01', strtotime('-1 month'))) . ')';
else $judul_periode = date('d/m/Y', strtotime($tgl_mulai)) . ' s/d ' . date('d/m/Y', strtotime($tgl_akhir));

// ========== AMBIL DATA ==========
$data = []; $columns = [];
switch ($tab) {
    case 'pembelian':
        $sql = "SELECT tm.id, tm.nomor_faktur, tm.tanggal, s.nama AS supplier,
                       b.nama_barang, mi.qty, mi.harga_beli, (mi.qty * mi.harga_beli) AS total
                FROM transaksi_masuk tm
                JOIN supplier s ON tm.supplier_id = s.id
                JOIN transaksi_masuk_item mi ON tm.id = mi.transaksi_masuk_id
                JOIN barang b ON mi.barang_id = b.id_barang
                WHERE tm.tanggal BETWEEN ? AND ? ORDER BY tm.tanggal DESC, tm.id DESC";
        $stmt = $conn->prepare($sql); $stmt->bind_param("ss", $tgl_mulai, $tgl_akhir);
        $stmt->execute(); $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $data[] = $row;
        $columns = ['No','No. Faktur','Tanggal','Supplier','Barang','Qty','Harga Beli','Total'];
        break;

    case 'supply':
        $sql = "SELECT bk.id, bk.id_permintaan, bk.tanggal, bk.user_pos,
                       b.nama_barang, bk.qty
                FROM barang_keluar bk
                JOIN barang b ON bk.barang_id = b.id_barang
                WHERE DATE(bk.tanggal) BETWEEN ? AND ? ORDER BY bk.tanggal DESC, bk.id DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $tgl_mulai, $tgl_akhir);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
        $columns = ['No','ID Permintaan','Tanggal','User POS','Barang','Qty'];
        break;

    case 'stok':
        // Stok rusak diambil dari total_rusak (akumulasi historis, tidak di-reset saat disetujui Owner).
        // Fallback ke 0 kalau kolom belum ada di database.
        $rusak_select = $hasTotalRusak ? "b.total_rusak" : "0 AS total_rusak";
        $sql = "SELECT 
                    b.kode_barang, 
                    b.nama_barang, 
                    b.min_stok, 
                    b.satuan,
                    b.satuan_besar,
                    b.stok, 
                    b.stok_besar,
                    $rusak_select AS stok_rusak,
                    IF(IFNULL(b.satuan_besar, '') != '', b.stok_besar, b.stok) AS stok_utama,
                    IF(IFNULL(b.satuan_besar, '') != '', b.satuan_besar, b.satuan) AS satuan_utama
                FROM barang b 
                ORDER BY b.nama_barang";
        $res = $conn->query($sql);
        while ($row = $res->fetch_assoc()) $data[] = $row;
        $columns = ['Kode','Nama Barang','Stok Utama','Satuan','Stok Rusak','Min Stok','Status Stok','Kondisi'];
        break;

    case 'opname':
        $sql = "SELECT no_opname AS id, periode, tanggal, total_item, status, keterangan
                FROM stock_opname WHERE tanggal BETWEEN ? AND ? ORDER BY tanggal DESC";
        $stmt = $conn->prepare($sql); $stmt->bind_param("ss", $tgl_mulai, $tgl_akhir);
        $stmt->execute(); $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $data[] = $row;
        $columns = ['ID Opname','Periode','Tanggal','Total Item','Status','Keterangan'];
        break;

    case 'preorder':
        $sql = "SELECT po.id, po.tanggal, s.nama AS supplier, po.status, po.approved_by
                FROM pre_order po LEFT JOIN supplier s ON po.supplier_id = s.id
                WHERE po.tanggal BETWEEN ? AND ? ORDER BY po.tanggal DESC";
        $stmt = $conn->prepare($sql); $stmt->bind_param("ss", $tgl_mulai, $tgl_akhir);
        $stmt->execute(); $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $row['status'] = ucfirst($row['status']); $data[] = $row; }
        $columns = ['ID PO','Tanggal','Supplier','Status','Disetujui Oleh'];
        break;

    case 'rusak':
        $sql = "SELECT p.id, p.barang_id, b.nama_barang, p.jumlah, p.keterangan, p.aksi, p.created_at
                FROM pengajuan_rusak p
                JOIN barang b ON p.barang_id = b.id_barang
                WHERE p.status = 'Disetujui'
                  AND DATE(p.created_at) BETWEEN ? AND ?
                ORDER BY p.created_at DESC, p.id DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $tgl_mulai, $tgl_akhir);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $data[] = $row;
        $columns = ['No', 'Tanggal', 'Barang', 'Jumlah Rusak', 'Keterangan', 'Penanganan'];
        break;

    case 'kadaluarsa':
        // Perbaikan: gunakan stok_utama dan satuan_utama
        $sql = "SELECT 
                    b.id_barang,
                    b.kode_barang,
                    b.nama_barang,
                    b.kategori,
                    b.satuan,
                    b.satuan_besar,
                    b.stok,
                    b.stok_besar,
                    b.tanggal_kadaluarsa,
                    DATEDIFF(b.tanggal_kadaluarsa, CURDATE()) AS sisa_hari,
                    IF(IFNULL(b.satuan_besar, '') != '', b.stok_besar, b.stok) AS stok_utama,
                    IF(IFNULL(b.satuan_besar, '') != '', b.satuan_besar, b.satuan) AS satuan_utama
                FROM barang b
                WHERE (b.stok > 0 OR b.stok_besar > 0)
                ORDER BY (sisa_hari IS NULL) ASC, sisa_hari ASC";
        $res = $conn->query($sql);
        while ($row = $res->fetch_assoc()) {
            $sisa = $row['sisa_hari'];
            if ($sisa === null) {
                $row['status'] = 'Belum Diatur';
            } elseif ($sisa < 0) {
                $row['status'] = 'Kadaluarsa';
            } elseif ($sisa <= 30) {
                $row['status'] = 'Segera Kadaluarsa';
            } else {
                $row['status'] = 'Aman';
            }
            $data[] = $row;
        }
        $columns = ['No', 'Kode', 'Nama Barang', 'Kategori', 'Stok Utama', 'Satuan', 'Tgl Kadaluarsa', 'Sisa Hari', 'Status'];
        break;
}

// ========== EXPORT / CETAK PDF ==========
if (isset($_GET['export_pdf']) && $_GET['export_pdf'] == 1) {
    $dompdf_loaded = false;
    $paths = [__DIR__.'/../vendor/autoload.php',__DIR__.'/../vendor/dompdf/autoload.inc.php',
              __DIR__.'/../dompdf/autoload.inc.php'];
    foreach ($paths as $p) { if (file_exists($p)) { require_once $p; $dompdf_loaded=true; break; } }
    if (!$dompdf_loaded) die("Dompdf tidak ditemukan.");
    $options = new \Dompdf\Options();
    $options->set('defaultFont','DejaVu Sans'); $options->set('isRemoteEnabled',true);
    $dompdf = new \Dompdf\Dompdf($options);

    $nama_usaha   = "Keva Jaya";
    // Sisipkan zero-width space setelah "Jl." agar pola alamat tidak auto-terdeteksi
    // sebagai link oleh sebagian PDF viewer (Chrome/Edge). Tidak mengubah tampilan visual.
    $alamat_usaha = "Jl.\u{200B} Ampera, Kec. Palaran, Kota Samarinda, Kalimantan Timur 75251";
    $dicetak_oleh = $_SESSION['username'] ?? 'Owner';

    ob_start(); ?>
<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Laporan <?= ucfirst($tab) ?> - Keva Jaya</title>
<style>
    @page { margin: 20px 25px; }
    body{font-family:'DejaVu Sans',sans-serif;font-size:9.5pt;color:#222;margin:0;padding:0;}
    .kop{width:100%;border-bottom:2px solid #2d6a4f;padding-bottom:8px;margin-bottom:10px;}
    .kop table{width:100%;border-collapse:collapse;table-layout:fixed;}
    .kop td{border:none;padding:0;vertical-align:top;}
    .kop .logo-box{width:44px;height:44px;background:#2d6a4f;border-radius:6px;color:#fff;
                    text-align:center;line-height:44px;font-size:18px;font-weight:bold;}
    .kop .nama-usaha{font-size:15px;font-weight:bold;color:#1a1916;padding-top:3px;}
    .kop .alamat-usaha{font-size:9px;color:#666;padding-top:3px;}
    .judul-laporan{width:100%;text-align:center;margin:18px 0 16px;}
    .judul-laporan h2{margin:0;font-size:15px;text-transform:uppercase;letter-spacing:1px;}
    .judul-laporan .periode{font-size:9.5pt;color:#555;margin-top:4px;}
    table.data{width:100%;border-collapse:collapse;margin-bottom:22px;}
    table.data th{background:#2d6a4f;color:#fff;padding:7px 5px;text-align:left;font-size:9pt;}
    table.data td{border:1px solid #ddd;padding:6px 5px;font-size:9pt;vertical-align:top;}
    table.data tr:nth-child(even) td{background:#f7f7f5;}
    .text-center{text-align:center;}.text-right{text-align:right;}
    .badge-habis{color:#c0392b;font-weight:bold;}.badge-menipis{color:#d68910;font-weight:bold;}
    .badge-aman{color:#2d6a4f;font-weight:bold;}.badge-rusak{color:#c0392b;font-weight:bold;}
    .badge-rusak-menipis{color:#c0392b;font-weight:bold;}
    .badge-disetujui{color:#2d6a4f;font-weight:bold;}.badge-pending{color:#d68910;font-weight:bold;}
    .badge-ditolak{color:#c0392b;font-weight:bold;}.badge-dibuang{color:#c0392b;font-weight:bold;}
    .badge-retur{color:#1a5276;font-weight:bold;}
    .ttd{width:100%;margin-top:35px;}
    .ttd table{width:100%;border-collapse:collapse;}
    .ttd td{border:none;text-align:center;width:33.33%;font-size:9.5pt;padding:0 10px;}
    .ttd .nama-jabatan{margin-top:48px;font-weight:bold;text-decoration:underline;}
    .footer-note{margin-top:16px;font-size:7.5pt;color:#999;text-align:right;}
</style></head><body>

<div class="kop">
    <table><tr>
        <td style="width:50px;"><div class="logo-box">KJ</div></td>
        <td style="width:60%;padding-left:10px;">
            <div class="nama-usaha"><?= e($nama_usaha) ?></div>
            <div class="alamat-usaha"><?= e($alamat_usaha) ?></div>
        </td>
        <td style="width:auto;text-align:right;font-size:8.5pt;color:#666;padding-top:3px;">
            Dicetak: <?= date('d/m/Y H:i') ?><br>
            Oleh: <?= e($dicetak_oleh) ?>
        </td>
    </tr></table>
</div>

<div class="judul-laporan">
    <h2>Laporan <?= ucfirst($tab) ?></h2>
    <div class="periode">Periode: <?= $judul_periode ?></div>
</div>

<table class="data"><thead><tr><?php foreach ($columns as $col): ?><th><?= $col ?></th><?php endforeach; ?></tr></thead><tbody>
<?php if (empty($data)): ?>
<tr><td colspan="<?= count($columns) ?>" class="text-center">Tidak ada data</td></tr>
<?php else: $no_pdf = 1; foreach ($data as $row):
    if ($tab=='pembelian'): ?>
<tr><td class="text-center"><?= $no_pdf++ ?></td><td><?= e($row['nomor_faktur']) ?></td><td><?= date('d/m/Y',strtotime($row['tanggal'])) ?></td><td><?= e($row['supplier']) ?></td><td><?= e($row['nama_barang']) ?></td><td class="text-center"><?= $row['qty'] ?></td><td class="text-right"><?= rupiah($row['harga_beli']) ?></td><td class="text-right"><?= rupiah($row['total']) ?></td></tr>
<?php elseif ($tab=='supply'): ?>
<tr><td class="text-center"><?= $no_pdf++ ?></td><td><?= e($row['id_permintaan']) ?></td><td><?= date('d/m/Y',strtotime($row['tanggal'])) ?></td><td><?= e($row['user_pos']) ?></td><td><?= e($row['nama_barang']) ?></td><td class="text-center"><?= $row['qty'] ?></td></tr>
<?php elseif ($tab=='stok'):
    $stok_utama = (int)$row['stok_utama'];
    $satuan_utama = e($row['satuan_utama'] ?? ($row['satuan'] ?? ''));
    $sr = (int)($row['stok_rusak'] ?? 0);
    $min = (int)$row['min_stok'];
    if ($sr > 0 && $stok_utama < $min)  { $status_stok = 'Rusak & Menipis'; $badge_stok = 'badge-rusak-menipis'; }
    elseif ($sr > 0)                    { $status_stok = 'Ada Stok Rusak';  $badge_stok = 'badge-rusak'; }
    elseif ($stok_utama <= 0)           { $status_stok = 'Stok Habis';      $badge_stok = 'badge-habis'; }
    elseif ($stok_utama < $min)         { $status_stok = 'Stok Menipis';    $badge_stok = 'badge-menipis'; }
    else                                { $status_stok = 'Stok Aman';        $badge_stok = 'badge-aman'; }
    $kondisi = $sr > 0 ? 'Ada Rusak' : 'Baik';
?>
<tr><td><?= e($row['kode_barang']) ?></td><td><?= e($row['nama_barang']) ?></td><td class="text-center"><?= $stok_utama ?></td><td><?= $satuan_utama ?></td><td class="text-center <?= $sr>0?'badge-rusak':'' ?>"><?= $sr ?></td><td class="text-center"><?= $min ?></td><td class="<?= $badge_stok ?>"><?= $status_stok ?></td><td class="<?= $sr>0?'badge-rusak':'badge-aman' ?>"><?= $kondisi ?></td></tr>
<?php elseif ($tab=='opname'): ?>
<tr><td><?= e($row['id']) ?></td><td><?= e($row['periode']) ?></td><td><?= date('d/m/Y',strtotime($row['tanggal'])) ?></td><td class="text-center"><?= $row['total_item'] ?></td><td><?= e($row['status']??'-') ?></td><td><?= e($row['keterangan']??'-') ?></td></tr>
<?php elseif ($tab=='preorder'): ?>
<tr><td>PO-<?= $row['id'] ?></td><td><?= date('d/m/Y',strtotime($row['tanggal'])) ?></td><td><?= e($row['supplier']??'-') ?></td><td><?= e($row['status']) ?></td><td><?= e($row['approved_by']??'-') ?></td></tr>
<?php elseif ($tab=='rusak'): ?>
<tr><td class="text-center"><?= $no_pdf++ ?></td><td><?= date('d/m/Y',strtotime($row['created_at'])) ?></td><td><?= e($row['nama_barang']) ?></td><td class="text-center"><?= $row['jumlah'] ?></td><td><?= e($row['keterangan']??'-') ?></td><td><?= e($row['aksi']) ?></td></tr>
<?php elseif ($tab=='kadaluarsa'): 
    $sisa = $row['sisa_hari'];
    $stok_utama = (int)$row['stok_utama'];
    $satuan_utama = e($row['satuan_utama'] ?? ($row['satuan'] ?? ''));
    if ($sisa === null) {
        $statusLabel = 'Belum Diatur';
        $badgeClass = '';
    } elseif ($sisa < 0) {
        $statusLabel = 'Kadaluarsa';
        $badgeClass = 'badge-habis';
    } elseif ($sisa <= 30) {
        $statusLabel = 'Segera Kadaluarsa';
        $badgeClass = 'badge-menipis';
    } else {
        $statusLabel = 'Aman';
        $badgeClass = 'badge-aman';
    }
?>
<tr><td class="text-center"><?= $no_pdf++ ?></td><td><?= e($row['kode_barang']) ?></td><td><?= e($row['nama_barang']) ?></td><td><?= e($row['kategori'] ?? '-') ?></td><td class="text-center"><?= $stok_utama ?></td><td><?= $satuan_utama ?></td><td><?= !empty($row['tanggal_kadaluarsa']) ? date('d/m/Y',strtotime($row['tanggal_kadaluarsa'])) : '—' ?></td><td class="text-center"><?= $sisa !== null ? $sisa . ' hari' : '—' ?></td><td class="<?= $badgeClass ?>"><?= $statusLabel ?></td></tr>
<?php endif; endforeach; endif; ?>
</tbody></table>

</body></html>
<?php
    $html = ob_get_clean();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4','landscape');
    $dompdf->render();
    $dompdf->stream("laporan_{$tab}_".date('Ymd').".pdf", ["Attachment"=>0]);
    exit;
}

// ========== HITUNG BADGE UNTUK SIDEBAR ==========
$jmlPendingKoreksi = $conn->query("SELECT COUNT(*) as c FROM stock_opname WHERE status='Pending'")->fetch_assoc()['c'] ?? 0;
$jmlPendingRusak   = $conn->query("SELECT COUNT(*) as c FROM pengajuan_rusak WHERE status='Pending'")->fetch_assoc()['c'] ?? 0;
$pendingPreorder   = $conn->query("SELECT COUNT(*) as c FROM pre_order WHERE status='pending'")->fetch_assoc()['c'] ?? 0;

// ========== SIDEBAR ACTIVE STATE ==========
$current_file = basename($_SERVER['PHP_SELF']);
$open_transaksi = in_array($current_file, ['preorder.php','barang_masuk.php','barang_keluar.php','barang_rusak.php','koreksi_stok.php','olah_stok.php']);
$open_monitor   = in_array($current_file, ['stok.php','stock_opname.php','kadaluarsa.php']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Laporan — Keva Jaya</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --bg:#f5f4f0;--surface:#fff;--surface-2:#f9f8f5;
            --border:#e8e6e0;--border-strong:#d4d0c8;
            --text-primary:#1a1916;--text-secondary:#6b6860;--text-muted:#9c9890;
            --accent:#2d6a4f;--accent-light:#e8f4ee;--accent-hover:#245a42;
            --danger:#c0392b;--danger-light:#fdecea;
            --warning:#d68910;--warning-light:#fef9e7;
            --info:#1a5276;--info-light:#e8f0f8;
            --sidebar-w:252px;--radius:10px;--radius-sm:6px;
            --shadow-sm:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);
            --shadow:0 4px 16px rgba(0,0,0,.06),0 1px 3px rgba(0,0,0,.04);
            --transition:all .2s cubic-bezier(.4,0,.2,1);
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text-primary);min-height:100vh;font-size:14px;line-height:1.6;}
        ::-webkit-scrollbar{width:6px;height:6px;}::-webkit-scrollbar-track{background:transparent;}::-webkit-scrollbar-thumb{background:var(--border-strong);border-radius:99px;}

        .sidebar{position:fixed;top:0;left:0;width:var(--sidebar-w);height:100vh;background:var(--text-primary);display:flex;flex-direction:column;z-index:100;transition:var(--transition);overflow-y:auto;overflow-x:hidden;}
        .sidebar.collapsed{left:calc(-1 * var(--sidebar-w));}
        .sidebar-brand{padding:20px 20px 16px;border-bottom:1px solid rgba(255,255,255,.08);flex-shrink:0;}
        .sidebar-brand .brand-logo{display:flex;align-items:center;gap:10px;}
        .brand-icon{width:34px;height:34px;background:var(--accent);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:16px;color:white;flex-shrink:0;}
        .brand-name{font-size:15px;font-weight:700;color:white;letter-spacing:-.3px;}
        .brand-sub{font-size:11px;color:rgba(255,255,255,.38);font-weight:400;letter-spacing:.4px;}
        .sidebar-nav{padding:12px 10px;flex:1;}
        .nav-section-label{font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:rgba(255,255,255,.28);padding:12px 10px 6px;}
        .nav-item{list-style:none;}
        .nav-link{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:var(--radius-sm);color:rgba(255,255,255,.55);text-decoration:none;font-size:13.5px;font-weight:450;transition:var(--transition);cursor:pointer;border:none;background:none;width:100%;}
        .nav-link:hover{background:rgba(255,255,255,.07);color:rgba(255,255,255,.9);}
        .nav-link.active{background:rgba(255,255,255,.1);color:white;font-weight:550;}
        .nav-link i{font-size:16px;flex-shrink:0;width:18px;text-align:center;}
        .nav-badge{margin-left:auto;background:var(--warning);color:white;font-size:10px;font-weight:700;padding:2px 7px;border-radius:99px;line-height:1.4;}
        .sidebar-footer{padding:12px 14px;border-top:1px solid rgba(255,255,255,.08);flex-shrink:0;}
        .user-card{display:flex;align-items:center;gap:10px;}
        .user-avatar{width:30px;height:30px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:white;flex-shrink:0;}
        .user-name{font-size:13px;font-weight:550;color:rgba(255,255,255,.8);}
        .user-role{font-size:11px;color:rgba(255,255,255,.35);}
        .btn-sm-nav{display:inline-flex;align-items:center;gap:5px;padding:5px 11px;border-radius:var(--radius-sm);font-size:12px;font-weight:500;font-family:inherit;cursor:pointer;transition:var(--transition);border:1px solid var(--border);background:var(--surface);color:var(--text-secondary);text-decoration:none;}

        .main{margin-left:var(--sidebar-w);transition:margin-left .2s;min-height:100vh;display:flex;flex-direction:column;}
        .main.expanded{margin-left:0;}
        .topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:0 24px;height:56px;display:flex;align-items:center;gap:16px;position:sticky;top:0;z-index:50;}
        .btn-toggle{width:34px;height:34px;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--surface-2);color:var(--text-secondary);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:var(--transition);font-size:16px;flex-shrink:0;}
        .btn-toggle:hover{background:var(--border);color:var(--text-primary);}
        .breadcrumb-bar{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--text-muted);}
        .breadcrumb-bar span{color:var(--text-secondary);font-weight:500;}
        .topbar-right{margin-left:auto;display:flex;align-items:center;gap:10px;}
        .page-body{padding:24px;flex:1;}
        .page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:24px;}
        .page-title{font-size:22px;font-weight:700;letter-spacing:-.5px;}
        .page-subtitle{font-size:13px;color:var(--text-muted);margin-top:2px;}

        .nav-tabs-custom{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:24px;}
        .nav-tabs-custom .nav-link{background:var(--surface-2);border:1px solid var(--border);border-radius:40px;padding:6px 18px;font-size:13px;font-weight:500;color:var(--text-secondary);transition:var(--transition);}
        .nav-tabs-custom .nav-link:hover{background:var(--border);color:var(--text-primary);}
        .nav-tabs-custom .nav-link.active{background:var(--accent);border-color:var(--accent);color:white;}

        .filter-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;margin-bottom:24px;box-shadow:var(--shadow-sm);}
        .form-label{font-size:12px;font-weight:500;color:var(--text-secondary);margin-bottom:4px;display:block;}
        .form-control,.form-select{border-radius:var(--radius-sm);border:1px solid var(--border);padding:8px 12px;font-size:13px;background:var(--surface);transition:var(--transition);}
        .form-control:focus,.form-select:focus{border-color:var(--accent);outline:none;box-shadow:0 0 0 3px rgba(45,106,79,.1);}
        .btn{border-radius:var(--radius-sm);padding:8px 16px;font-weight:500;font-size:13px;transition:var(--transition);border:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
        .btn-primary{background:var(--accent);color:white;}
        .btn-primary:hover{background:var(--accent-hover);transform:translateY(-1px);}
        .btn-pdf{background:var(--danger);color:white;}
        .btn-pdf:hover{background:#a93226;transform:translateY(-1px);}

        .card-custom{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden;margin-bottom:24px;}
        .table-responsive{overflow-x:auto;}
        .table{width:100%;border-collapse:collapse;font-size:13px;}
        .table th{background:var(--surface-2);padding:12px 16px;text-align:left;font-weight:600;color:var(--text-secondary);border-bottom:1px solid var(--border);white-space:nowrap;}
        .table td{padding:12px 16px;border-bottom:1px solid var(--border);vertical-align:middle;}
        .table tr:last-child td{border-bottom:none;}
        .table tbody tr:hover{background:var(--surface-2);}

        .badge-status{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;}
        .badge-habis{background:var(--danger-light);color:var(--danger);}
        .badge-menipis{background:var(--warning-light);color:var(--warning);}
        .badge-aman{background:var(--accent-light);color:var(--accent);}
        .badge-rusak{background:#fff0f0;color:#9b1c1c;border:1px solid #f5b7b1;}
        .badge-rusak-menipis{background:var(--danger-light);color:var(--danger);}
        .badge-disetujui{background:var(--accent-light);color:var(--accent);}
        .badge-pending{background:var(--warning-light);color:var(--warning);}
        .badge-ditolak{background:var(--danger-light);color:var(--danger);}
        .badge-dibuang{background:var(--danger-light);color:var(--danger);}
        .badge-retur{background:var(--info-light);color:var(--info);}
        .badge-secondary{background:var(--surface-2);color:var(--text-secondary);border:1px solid var(--border);}
        .badge-success{background:var(--accent-light);color:var(--accent);}
        .badge-warning{background:var(--warning-light);color:var(--warning);}
        .badge-danger{background:var(--danger-light);color:var(--danger);}
        .stok-rusak-val{font-weight:700;color:var(--danger);background:var(--danger-light);padding:2px 8px;border-radius:99px;font-size:12.5px;}
        .stok-rusak-zero{color:var(--text-muted);}

        .empty-state{text-align:center;padding:40px 20px;color:var(--text-muted);}
        .empty-state i{font-size:48px;margin-bottom:12px;opacity:.5;}

        .d-flex{display:flex;} .gap-2{gap:8px;} .mb-3{margin-bottom:12px;} .justify-content-end{justify-content:flex-end;}

        @media(max-width:768px){
            .sidebar{left:calc(-1 * var(--sidebar-w));}.sidebar.mobile-open{left:0;}.main{margin-left:0;}
            .page-body{padding:16px;}.nav-tabs-custom .nav-link{padding:4px 12px;font-size:12px;}
        }
    </style>
</head>
<body>

<!-- ===== SIDEBAR OWNER ===== -->
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
                <a href="konfirmasi_rusak.php" class="nav-link">
                    <i class="bi bi-slash-circle"></i> Konfirmasi Barang Rusak
                    <?php if ($jmlPendingRusak > 0): ?>
                        <span class="nav-badge"><?= $jmlPendingRusak ?></span>
                    <?php endif; ?>
                </a>
            </li>

            <div class="nav-section-label">Laporan</div>
            <li class="nav-item"><a href="laporan.php" class="nav-link active"><i class="bi bi-bar-chart"></i> Laporan</a></li>

            <div class="nav-section-label">Monitoring</div>
            <li class="nav-item"><a href="stok.php" class="nav-link <?= $current_file == 'stok.php' ? 'active' : '' ?>"><i class="bi bi-boxes"></i> Stok Barang</a></li>
            <li class="nav-item"><a href="stock_opname.php" class="nav-link <?= $current_file == 'stock_opname.php' ? 'active' : '' ?>"><i class="bi bi-clock-history"></i> Stock Opname</a></li>
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
        <div class="breadcrumb-bar"><span>Laporan</span></div>
        <div class="topbar-right">
            <span style="font-size:12px;color:var(--text-muted);"><?= date('d M Y') ?></span>
        </div>
    </header>

    <div class="page-body">
        <div class="page-header">
            <div>
                <div class="page-title">Laporan</div>
                <div class="page-subtitle">Lihat dan cetak data transaksi, stok, koreksi stok, pre order, barang rusak, dan kadaluarsa</div>
            </div>
        </div>

        <!-- TAB NAVIGASI -->
        <ul class="nav nav-tabs-custom">
            <?php
            $tab_labels = [
                'pembelian'=>'Pembelian',
                'supply'=>'Supply',
                'stok'=>'Stok',
                'opname'=>'Koreksi Stok',
                'preorder'=>'Pre Order',
                'rusak'=>'Barang Rusak',
                'kadaluarsa'=>'Kadaluarsa'
            ];
            foreach ($allowed_tabs as $t):
            ?>
            <li class="nav-item">
                <a href="?tab=<?= $t ?>&periode=<?= $filter_periode ?>&tgl_mulai=<?= $tgl_mulai ?>&tgl_akhir=<?= $tgl_akhir ?>"
                   class="nav-link <?= $tab==$t?'active':'' ?>">
                    <?= $tab_labels[$t] ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>

        <!-- FILTER PERIODE (tidak ditampilkan untuk tab stok dan kadaluarsa) -->
        <?php if ($tab !== 'stok' && $tab !== 'kadaluarsa'): ?>
        <div class="filter-card">
            <form method="GET" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                <input type="hidden" name="tab" value="<?= $tab ?>">
                <div>
                    <label class="form-label">Periode</label>
                    <select name="periode" class="form-select" id="periodeSelect">
                        <option value="bulan_ini"  <?= $filter_periode=='bulan_ini' ?'selected':'' ?>>Bulan Ini</option>
                        <option value="bulan_lalu" <?= $filter_periode=='bulan_lalu'?'selected':'' ?>>Bulan Lalu</option>
                        <option value="custom"     <?= $filter_periode=='custom'    ?'selected':'' ?>>Kustom</option>
                    </select>
                </div>
                <div id="div_tgl_mulai" style="<?= $filter_periode=='custom'?'':'display:none' ?>">
                    <label class="form-label">Dari Tanggal</label>
                    <input type="date" name="tgl_mulai" class="form-control" value="<?= $tgl_mulai ?>">
                </div>
                <div id="div_tgl_akhir" style="<?= $filter_periode=='custom'?'':'display:none' ?>">
                    <label class="form-label">Sampai Tanggal</label>
                    <input type="date" name="tgl_akhir" class="form-control" value="<?= $tgl_akhir ?>">
                </div>
                <div><button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Tampilkan</button></div>
            </form>
        </div>
        <?php endif; ?>

        <!-- CETAK -->
        <div class="d-flex justify-content-end gap-2 mb-3">
            <a href="?<?= http_build_query(array_merge($_GET, ['tab'=>$tab,'export_pdf'=>1])) ?>"
               class="btn btn-pdf" target="_blank"><i class="bi bi-printer"></i> Cetak Laporan</a>
        </div>

        <!-- TABEL DATA -->
        <div class="card-custom">
            <div class="table-responsive">
                <table class="table" id="reportTable">
                    <thead>
                        <tr><?php foreach ($columns as $col): ?><th><?= $col ?></th><?php endforeach; ?></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($data)): ?>
                        <tr><td colspan="<?= count($columns) ?>"><div class="empty-state"><i class="bi bi-inbox"></i><p>Tidak ada data untuk periode ini</p></div></td></tr>
                    <?php else: $no = 1; foreach ($data as $row):

                        if ($tab == 'pembelian'): ?>
                        <tr>
                            <td class="text-center"><?= $no++ ?></td>
                            <td><?= e($row['nomor_faktur']) ?></td>
                            <td nowrap><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                            <td><?= e($row['supplier']) ?></td>
                            <td><?= e($row['nama_barang']) ?></td>
                            <td class="text-center"><?= $row['qty'] ?></td>
                            <td><?= rupiah($row['harga_beli']) ?></td>
                            <td><?= rupiah($row['total']) ?></td>
                        </tr>

                        <?php elseif ($tab == 'supply'): ?>
                        <tr>
                            <td class="text-center"><?= $no++ ?></td>
                            <td><?= e($row['id_permintaan']) ?></td>
                            <td nowrap><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                            <td><?= e($row['user_pos']) ?></td>
                            <td><?= e($row['nama_barang']) ?></td>
                            <td class="text-center"><?= $row['qty'] ?></td>
                        </tr>

                        <?php elseif ($tab == 'stok'):
                            $stok_utama = (int)$row['stok_utama'];
                            $satuan_utama = e($row['satuan_utama'] ?? ($row['satuan'] ?? ''));
                            $sr = (int)($row['stok_rusak'] ?? 0);
                            $min = (int)$row['min_stok'];

                            if ($sr > 0 && $stok_utama < $min)  { $status_stok = 'Rusak & Menipis'; $badge_stok = 'badge-rusak-menipis'; }
                            elseif ($sr > 0)                   { $status_stok = 'Ada Stok Rusak';  $badge_stok = 'badge-rusak'; }
                            elseif ($stok_utama <= 0)          { $status_stok = 'Stok Habis';      $badge_stok = 'badge-habis'; }
                            elseif ($stok_utama < $min)        { $status_stok = 'Stok Menipis';    $badge_stok = 'badge-menipis'; }
                            else                               { $status_stok = 'Stok Aman';        $badge_stok = 'badge-aman'; }

                            $kondisi = $sr > 0 ? 'Ada Rusak' : 'Baik';
                            $badge_kondisi = $sr > 0 ? 'badge-rusak' : 'badge-aman';
                        ?>
                        <tr>
                            <td><span style="font-family:'JetBrains Mono',monospace;font-size:12px;"><?= e($row['kode_barang']) ?></span></td>
                            <td style="font-weight:500;"><?= e($row['nama_barang']) ?></td>
                            <td>
                                <span style="font-weight:700;color:<?= $stok_utama<$min&&$stok_utama>0?'var(--warning)':($stok_utama<=0?'var(--danger)':'var(--accent)') ?>;">
                                    <?= $stok_utama ?>
                                </span>
                                <small style="color:var(--text-muted);"> <?= $satuan_utama ?></small>
                            </td>
                            <td><?= $satuan_utama ?></td>
                            <td>
                                <?php if ($sr > 0): ?>
                                <span class="stok-rusak-val"><?= $sr ?></span>
                                <?php else: ?>
                                <span class="stok-rusak-zero">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--text-secondary);"><?= $min ?> <small style="color:var(--text-muted);"><?= $satuan_utama ?></small></td>
                            <td><span class="badge-status <?= $badge_stok ?>"><?= $status_stok ?></span></td>
                            <td><span class="badge-status <?= $badge_kondisi ?>"><?= $kondisi ?></span></td>
                        </tr>

                        <?php elseif ($tab == 'opname'):
                            $statusOpname = $row['status'] ?? '-';
                            $badgeOpname  = match($statusOpname) {
                                'Disetujui' => 'badge-disetujui',
                                'Ditolak'   => 'badge-ditolak',
                                'Pending'   => 'badge-pending',
                                default     => ''
                            };
                        ?>
                        <tr>
                            <td><span style="font-family:'JetBrains Mono',monospace;font-size:12px;"><?= e($row['id']) ?></span></td>
                            <td><?= e($row['periode']) ?></td>
                            <td nowrap><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                            <td class="text-center"><?= $row['total_item'] ?></td>
                            <td><span class="badge-status <?= $badgeOpname ?>"><?= e($statusOpname) ?></span></td>
                            <td><?= e($row['keterangan'] ?? '—') ?></td>
                        </tr>

                        <?php elseif ($tab == 'preorder'):
                            $badgeClass = ($row['status']=='Disetujui') ? 'badge-disetujui' : (($row['status']=='Pending') ? 'badge-pending' : 'badge-ditolak');
                        ?>
                        <tr>
                            <td>PO-<?= $row['id'] ?></td>
                            <td nowrap><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                            <td><?= e($row['supplier'] ?? '—') ?></td>
                            <td><span class="badge-status <?= $badgeClass ?>"><?= $row['status'] ?></span></td>
                            <td><?= e($row['approved_by'] ?? '—') ?></td>
                        </tr>

                        <?php elseif ($tab == 'rusak'):
                            $badgePenanganan = ($row['aksi'] == 'Dibuang') ? 'badge-dibuang' : 'badge-retur';
                        ?>
                        <tr>
                            <td class="text-center"><?= $no++ ?></td>
                            <td nowrap><?= date('d/m/Y', strtotime($row['created_at'])) ?></td>
                            <td><?= e($row['nama_barang']) ?></td>
                            <td class="text-center"><?= $row['jumlah'] ?></td>
                            <td><?= e($row['keterangan'] ?? '-') ?></td>
                            <td><span class="badge-status <?= $badgePenanganan ?>"><?= e($row['aksi']) ?></span></td>
                        </tr>

                        <?php elseif ($tab == 'kadaluarsa'):
                            $sisa = $row['sisa_hari'];
                            $stok_utama = (int)$row['stok_utama'];
                            $satuan_utama = e($row['satuan_utama'] ?? ($row['satuan'] ?? ''));
                            if ($sisa === null) {
                                $statusLabel = 'Belum Diatur';
                                $badgeClass = 'badge-secondary';
                            } elseif ($sisa < 0) {
                                $statusLabel = 'Kadaluarsa';
                                $badgeClass = 'badge-danger';
                            } elseif ($sisa <= 30) {
                                $statusLabel = 'Segera Kadaluarsa';
                                $badgeClass = 'badge-warning';
                            } else {
                                $statusLabel = 'Aman';
                                $badgeClass = 'badge-success';
                            }
                        ?>
                        <tr>
                            <td class="text-center"><?= $no++ ?></td>
                            <td><?= e($row['kode_barang']) ?></td>
                            <td><?= e($row['nama_barang']) ?></td>
                            <td><?= e($row['kategori'] ?? '-') ?></td>
                            <td class="text-center"><?= $stok_utama ?></td>
                            <td><?= $satuan_utama ?></td>
                            <td><?= !empty($row['tanggal_kadaluarsa']) ? date('d/m/Y', strtotime($row['tanggal_kadaluarsa'])) : '—' ?></td>
                            <td class="text-center"><?= $sisa !== null ? $sisa . ' hari' : '—' ?></td>
                            <td><span class="badge-status <?= $badgeClass ?>"><?= $statusLabel ?></span></td>
                        </tr>

                        <?php endif; endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    const sidebar   = document.getElementById('sidebar');
    const main      = document.getElementById('main');
    document.getElementById('sidebarToggle').addEventListener('click', () => {
        const isMobile = window.innerWidth <= 768;
        if (isMobile) sidebar.classList.toggle('mobile-open');
        else { sidebar.classList.toggle('collapsed'); main.classList.toggle('expanded'); }
    });

    function toggleNav(id, btn) {
        const el   = document.getElementById(id);
        const open = el.style.display !== 'none';
        el.style.display = open ? 'none' : 'block';
        btn.setAttribute('aria-expanded', !open);
    }

    const periodeSelect = document.getElementById('periodeSelect');
    if (periodeSelect) {
        periodeSelect.addEventListener('change', function() {
            document.getElementById('div_tgl_mulai').style.display = this.value==='custom'?'block':'none';
            document.getElementById('div_tgl_akhir').style.display = this.value==='custom'?'block':'none';
        });
    }
</script>
</body>
</html>