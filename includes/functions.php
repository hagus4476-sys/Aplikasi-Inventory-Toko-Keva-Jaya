<?php
/**
 * functions.php
 * File ini berisi semua fungsi yang digunakan di aplikasi inventory Toko Keva Jaya
 * 
 * Struktur Database:
 * - keva_db: database utama (barang, supplier, kategori, transaksi_masuk, barang_keluar, stock_mutasi, dll)
 * - db_pos_keva: database POS teman (sumber permintaan_barang)
 * 
 * Tabel barang_keluar:
 * - id (int, auto_increment)
 * - id_permintaan (int)
 * - tanggal (datetime)
 * - user_pos (varchar)
 * - barang_id (int, FK ke barang.id_barang)
 * - qty (int)
 * - status (enum('Diproses','Diterima'))
 * - diproses_oleh (int, FK ke users.id)
 * - created_at (timestamp)
 */

require_once __DIR__ . '/../config/database.php';

// ============================================================
// 1. CEK KONEKSI POS (DATABASE TEMAN)
// ============================================================

if (!function_exists('posTersedia')) {
    /**
     * Mengecek apakah koneksi ke database POS (db_pos_keva) tersedia
     */
    function posTersedia() {
        global $conn_pos;

        if (!$conn_pos instanceof mysqli) {
            return false;
        }

        try {
            return $conn_pos->ping();
        } catch (\mysqli_sql_exception $e) {
            error_log("posTersedia(): koneksi POS error - " . $e->getMessage());
            return false;
        }
    }
}


// ============================================================
// 2. DASHBOARD - FUNGSI STATISTIK
// ============================================================

if (!function_exists('getTotalBarang')) {
    function getTotalBarang() {
        global $conn;
        $res = $conn->query("SELECT COUNT(*) as total FROM barang WHERE status = 'aktif'");
        return (int)($res->fetch_assoc()['total'] ?? 0);
    }
}

if (!function_exists('getBarangMenipis')) {
    function getBarangMenipis() {
        global $conn;
        $res = $conn->query("
            SELECT COUNT(*) as total
            FROM barang
            WHERE status = 'aktif'
              AND stok < min_stok
        ");
        return (int)($res->fetch_assoc()['total'] ?? 0);
    }
}

if (!function_exists('getBarangHabis')) {
    function getBarangHabis() {
        global $conn;
        $res = $conn->query("
            SELECT COUNT(*) as total
            FROM barang
            WHERE status = 'aktif'
              AND stok <= 0
        ");
        return (int)($res->fetch_assoc()['total'] ?? 0);
    }
}

if (!function_exists('getBarangMasukHariIni')) {
    function getBarangMasukHariIni() {
        global $conn;
        $today = date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(i.qty), 0) as total
            FROM transaksi_masuk_item i
            JOIN transaksi_masuk m ON i.transaksi_masuk_id = m.id
            WHERE DATE(m.tanggal) = ?
        ");
        $stmt->bind_param("s", $today);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int)($row['total'] ?? 0);
    }
}

if (!function_exists('getBarangKeluarHariIni')) {
    function getBarangKeluarHariIni() {
        global $conn;
        $today = date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(qty), 0) as total
            FROM barang_keluar
            WHERE DATE(tanggal) = ?
        ");
        $stmt->bind_param("s", $today);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int)($row['total'] ?? 0);
    }
}

if (!function_exists('getPermintaanPending')) {
    function getPermintaanPending() {
        global $conn_pos;
        if (!posTersedia()) return 0;
        $res = $conn_pos->query("SELECT COUNT(*) as total FROM permintaan_barang WHERE status IN ('Pending','Diproses')");
        return (int)($res->fetch_assoc()['total'] ?? 0);
    }
}

if (!function_exists('getDistribusiKategori')) {
    function getDistribusiKategori() {
        global $conn;
        $res = $conn->query("
            SELECT kategori, SUM(stok) as total_stok
            FROM barang
            WHERE status = 'aktif'
              AND kategori IS NOT NULL
              AND kategori != ''
            GROUP BY kategori
            ORDER BY total_stok DESC
        ");
        $data = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $data[$row['kategori']] = (int)$row['total_stok'];
            }
        }
        return $data;
    }
}

if (!function_exists('getTotalTransaksiMasuk')) {
    function getTotalTransaksiMasuk($tanggal) {
        global $conn;
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(i.qty), 0) as total
            FROM transaksi_masuk_item i
            JOIN transaksi_masuk m ON i.transaksi_masuk_id = m.id
            WHERE DATE(m.tanggal) = ?
        ");
        $stmt->bind_param("s", $tanggal);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int)($row['total'] ?? 0);
    }
}

if (!function_exists('getTotalTransaksiKeluar')) {
    function getTotalTransaksiKeluar($tanggal) {
        global $conn;
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(qty), 0) as total
            FROM barang_keluar
            WHERE DATE(tanggal) = ?
        ");
        $stmt->bind_param("s", $tanggal);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int)($row['total'] ?? 0);
    }
}

if (!function_exists('getAktivitasStok7Hari')) {
    function getAktivitasStok7Hari() {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $tgl = date('Y-m-d', strtotime("-$i days"));
            $hari = date('D', strtotime($tgl));
            $data[$hari] = getTotalTransaksiMasuk($tgl) + getTotalTransaksiKeluar($tgl);
        }
        return $data;
    }
}


// ============================================================
// 3. PRE ORDER
// ============================================================

if (!function_exists('getAllPreOrder')) {
    function getAllPreOrder($status = null) {
        global $conn;
        $sql = "SELECT po.*, s.nama as supplier_nama
                FROM pre_order po
                LEFT JOIN supplier s ON po.supplier_id = s.id";
        if ($status) {
            $stmt = $conn->prepare($sql . " WHERE po.status = ? ORDER BY po.tanggal DESC");
            $stmt->bind_param("s", $status);
            $stmt->execute();
            return $stmt->get_result();
        } else {
            return $conn->query($sql . " ORDER BY po.tanggal DESC");
        }
    }
}

if (!function_exists('getPreOrderWithSupplier')) {
    function getPreOrderWithSupplier($conn, $id) {
        $stmt = $conn->prepare("
            SELECT po.*, s.nama as supplier_nama 
            FROM pre_order po 
            LEFT JOIN supplier s ON po.supplier_id = s.id 
            WHERE po.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('getPreOrderItems')) {
    function getPreOrderItems($conn, $pre_order_id) {
        $stmt = $conn->prepare("
            SELECT i.*, IFNULL(b.nama_barang, i.temp_nama_barang) as nama_barang
            FROM pre_order_item i
            LEFT JOIN barang b ON i.barang_id = b.id_barang
            WHERE i.pre_order_id = ?
        ");
        $stmt->bind_param("i", $pre_order_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

if (!function_exists('updatePreOrderStatus')) {
    function updatePreOrderStatus($id, $status, $approved_by) {
        global $conn;
        $stmt = $conn->prepare("UPDATE pre_order SET status = ?, approved_by = ? WHERE id = ?");
        $stmt->bind_param("ssi", $status, $approved_by, $id);
        return $stmt->execute();
    }
}

if (!function_exists('getPreOrderById')) {
    function getPreOrderById($id) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM pre_order WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('markPreOrderUsed')) {
    function markPreOrderUsed($id) {
        global $conn;
        $stmt = $conn->prepare("UPDATE pre_order SET is_used = 1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }
}


// ============================================================
// 4. PERMINTAAN BARANG (DARI DATABASE POS TEMAN - db_pos_keva)
// ============================================================

if (!function_exists('getPermintaanAktif')) {
    /**
     * Ambil daftar permintaan barang yang masih berstatus 'Diproses'
     * dari database POS milik teman (db_pos_keva).
     *
     * CATATAN: Sesuai activity diagram "Barang Keluar", panel permintaan
     * masuk hanya menampilkan permintaan berstatus 'Diproses' saja
     * (bukan 'Pending' juga seperti versi sebelumnya).
     */
    function getPermintaanAktif() {
        global $conn_pos;
        if (!posTersedia()) return null;
        return $conn_pos->query("
            SELECT id, id_permintaan, user, tanggal, produk, jumlah, status
            FROM permintaan_barang
            WHERE status = 'Diproses'
            ORDER BY tanggal DESC
        ");
    }
}

if (!function_exists('getPermintaanById')) {
    /**
     * Ambil satu baris permintaan_barang dari db_pos_keva
     * @param int $permintaan_id
     * @param bool $forUpdate - tambahkan FOR UPDATE untuk lock row
     */
    function getPermintaanById($permintaan_id, $forUpdate = false) {
        global $conn_pos;
        if (!posTersedia()) return null;
        $sql = "SELECT * FROM permintaan_barang WHERE id = ?";
        if ($forUpdate) $sql .= " FOR UPDATE";
        $stmt = $conn_pos->prepare($sql);
        $stmt->bind_param("i", $permintaan_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('updateStatusPermintaan')) {
    /**
     * Update status permintaan_barang di db_pos_keva
     */
    function updateStatusPermintaan($permintaan_id, $status_baru) {
        global $conn_pos;
        if (!posTersedia()) return false;
        // 'Diterima' ditambahkan agar sesuai activity diagram (status akhir
        // setelah supply berhasil dibuat). 'Dikirim' tetap dipertahankan
        // untuk kompatibilitas ke belakang bila masih dipakai di tempat lain.
        $allowed = ['Pending', 'Diproses', 'Diterima', 'Dikirim', 'Ditolak'];
        if (!in_array($status_baru, $allowed)) return false;
        $stmt = $conn_pos->prepare("UPDATE permintaan_barang SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status_baru, $permintaan_id);
        return $stmt->execute();
    }
}

if (!function_exists('updatePermintaanDikirim')) {
    /**
     * Tandai permintaan sebagai 'Dikirim' dan isi tanggal_diterima & jumlah_diterima
     * (Dipertahankan untuk kompatibilitas; gunakan updatePermintaanDiterima()
     * untuk alur baru sesuai activity diagram.)
     */
    function updatePermintaanDikirim($permintaan_id, $jumlah_diterima_str) {
        global $conn_pos;
        if (!posTersedia()) return false;
        $stmt = $conn_pos->prepare("
            UPDATE permintaan_barang
            SET status = 'Dikirim', tanggal_diterima = NOW(), jumlah_diterima = ?
            WHERE id = ?
        ");
        $stmt->bind_param("si", $jumlah_diterima_str, $permintaan_id);
        return $stmt->execute();
    }
}

if (!function_exists('updatePermintaanDiterima')) {
    /**
     * Tandai permintaan sebagai 'Diterima' dan isi tanggal_diterima & jumlah_diterima.
     * Ini yang dipakai barang_keluar.php sekarang, sesuai activity diagram:
     * setelah supply berhasil dibuat, status permintaan di POS langsung
     * menjadi 'Diterima'.
     */
    function updatePermintaanDiterima($permintaan_id, $jumlah_diterima_str) {
        global $conn_pos;
        if (!posTersedia()) return false;
        $stmt = $conn_pos->prepare("
            UPDATE permintaan_barang
            SET status = 'Diterima', tanggal_diterima = NOW(), jumlah_diterima = ?
            WHERE id = ?
        ");
        $stmt->bind_param("si", $jumlah_diterima_str, $permintaan_id);
        return $stmt->execute();
    }
}


// ============================================================
// 5. BARANG KELUAR (TABEL barang_keluar)
// ============================================================

if (!function_exists('getAllBarangKeluar')) {
    /**
     * Ambil semua data barang keluar dengan join ke barang
     * @param array $filter ['dari' => 'date', 'sampai' => 'date', 'status' => 'Diproses|Diterima', 'id_permintaan' => int]
     */
    function getAllBarangKeluar($filter = []) {
        global $conn;
        $sql = "SELECT bk.*, b.nama_barang, b.kode_barang 
                FROM barang_keluar bk
                LEFT JOIN barang b ON bk.barang_id = b.id_barang";
        $where = [];
        $params = [];
        $types = '';
        
        if (!empty($filter['dari']) && !empty($filter['sampai'])) {
            $where[] = "DATE(bk.tanggal) BETWEEN ? AND ?";
            $params[] = $filter['dari'];
            $params[] = $filter['sampai'];
            $types .= 'ss';
        }
        if (!empty($filter['status']) && $filter['status'] != 'semua') {
            $where[] = "bk.status = ?";
            $params[] = $filter['status'];
            $types .= 's';
        }
        if (!empty($filter['id_permintaan'])) {
            $where[] = "bk.id_permintaan = ?";
            $params[] = (int)$filter['id_permintaan'];
            $types .= 'i';
        }
        if (!empty($filter['user_pos'])) {
            $where[] = "bk.user_pos LIKE ?";
            $params[] = '%' . $filter['user_pos'] . '%';
            $types .= 's';
        }
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY bk.tanggal DESC";
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->get_result();
    }
}

if (!function_exists('getBarangKeluarById')) {
    function getBarangKeluarById($id) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM barang_keluar WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('getBarangKeluarByPermintaanId')) {
    function getBarangKeluarByPermintaanId($id_permintaan) {
        global $conn;
        $stmt = $conn->prepare("
            SELECT bk.*, b.nama_barang 
            FROM barang_keluar bk
            LEFT JOIN barang b ON bk.barang_id = b.id_barang
            WHERE bk.id_permintaan = ?
            ORDER BY bk.tanggal DESC
        ");
        $stmt->bind_param("i", $id_permintaan);
        $stmt->execute();
        return $stmt->get_result();
    }
}

if (!function_exists('insertBarangKeluar')) {
    /**
     * Insert satu baris barang keluar
     * @return int|bool ID yang diinsert atau false jika gagal
     */
    function insertBarangKeluar($id_permintaan, $tanggal, $user_pos, $barang_id, $qty, $status, $diproses_oleh) {
        global $conn;
        $stmt = $conn->prepare("
            INSERT INTO barang_keluar (id_permintaan, tanggal, user_pos, barang_id, qty, status, diproses_oleh) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issiiii", $id_permintaan, $tanggal, $user_pos, $barang_id, $qty, $status, $diproses_oleh);
        if ($stmt->execute()) {
            return $conn->insert_id;
        }
        return false;
    }
}

if (!function_exists('updateBarangKeluarStatus')) {
    function updateBarangKeluarStatus($id, $status_baru) {
        global $conn;
        $allowed = ['Diproses', 'Diterima'];
        if (!in_array($status_baru, $allowed)) return false;
        $stmt = $conn->prepare("UPDATE barang_keluar SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status_baru, $id);
        return $stmt->execute();
    }
}

if (!function_exists('deleteBarangKeluar')) {
    function deleteBarangKeluar($id) {
        global $conn;
        $stmt = $conn->prepare("DELETE FROM barang_keluar WHERE id = ?");
        $stmt->bind_param("i", $id);
        if (!$stmt->execute()) {
            return false;
        }
        // Pastikan baris benar-benar terhapus, bukan cuma query sukses
        // tapi affected_rows = 0 (misal karena id tidak ditemukan).
        return $stmt->affected_rows > 0;
    }
}

if (!function_exists('getTotalBarangKeluar')) {
    function getTotalBarangKeluar() {
        global $conn;
        $res = $conn->query("SELECT COALESCE(SUM(qty), 0) as total FROM barang_keluar");
        return (int)($res->fetch_assoc()['total'] ?? 0);
    }
}

if (!function_exists('getCountBarangKeluar')) {
    function getCountBarangKeluar() {
        global $conn;
        $res = $conn->query("SELECT COUNT(*) as total FROM barang_keluar");
        return (int)($res->fetch_assoc()['total'] ?? 0);
    }
}


// ============================================================
// 6. STOK & MUTASI STOK
// ============================================================

if (!function_exists('hitungStok')) {
    function hitungStok($id_barang) {
        global $conn;
        $stmt = $conn->prepare("SELECT stok FROM barang WHERE id_barang = ?");
        $stmt->bind_param("i", $id_barang);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int)($row['stok'] ?? 0);
    }
}

if (!function_exists('getStokBarang')) {
    function getStokBarang($id_barang) {
        return hitungStok($id_barang);
    }
}

if (!function_exists('updateStokBarang')) {
    function updateStokBarang($id_barang, $stok_baru) {
        global $conn;
        $stmt = $conn->prepare("UPDATE barang SET stok = ? WHERE id_barang = ?");
        $stmt->bind_param("ii", $stok_baru, $id_barang);
        return $stmt->execute();
    }
}

if (!function_exists('catatMutasiStok')) {
    /**
     * Catat mutasi stok ke tabel stock_mutasi
     */
    function catatMutasiStok(
        $barang_id,
        $jenis,
        $qty,
        $stok_sebelum,
        $stok_sesudah,
        $keterangan = '',
        $ref_id = null
    ) {
        global $conn;
        $stmt = $conn->prepare("
            INSERT INTO stock_mutasi
            (barang_id, jenis, qty, stok_sebelum, stok_sesudah, keterangan, ref_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            error_log("catatMutasiStok prepare failed: " . $conn->error);
            return false;
        }
        $stmt->bind_param("isiiisi", $barang_id, $jenis, $qty, $stok_sebelum, $stok_sesudah, $keterangan, $ref_id);
        $result = $stmt->execute();
        if (!$result) {
            error_log("catatMutasiStok execute failed: " . $stmt->error);
        }
        $stmt->close();
        return $result;
    }
}

if (!function_exists('getMutasiBarang')) {
    function getMutasiBarang($barang_id, $tgl_awal, $tgl_akhir, $jenis = null) {
        global $conn;
        $sql = "
            SELECT
                created_at as tanggal,
                jenis,
                qty,
                stok_sebelum,
                stok_sesudah,
                keterangan
            FROM stock_mutasi
            WHERE barang_id = ?
              AND DATE(created_at) BETWEEN ? AND ?
        ";
        if ($jenis && $jenis !== '') {
            $sql .= " AND jenis = ?";
        }
        $sql .= " ORDER BY created_at ASC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        if ($jenis && $jenis !== '') {
            $stmt->bind_param("isss", $barang_id, $tgl_awal, $tgl_akhir, $jenis);
        } else {
            $stmt->bind_param("iss", $barang_id, $tgl_awal, $tgl_akhir);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
        return $data;
    }
}

if (!function_exists('getMutasiBarangLengkap')) {
    function getMutasiBarangLengkap($barang_id, $tgl_awal, $tgl_akhir, $jenis = null) {
        return getMutasiBarang($barang_id, $tgl_awal, $tgl_akhir, $jenis);
    }
}


// ============================================================
// 7. CRUD MASTER DATA
// ============================================================

// ---------- SUPPLIER ----------
if (!function_exists('getAllSupplier')) {
    function getAllSupplier() {
        global $conn;
        return $conn->query("SELECT id, nama, alamat, telepon FROM supplier ORDER BY nama");
    }
}

if (!function_exists('getSupplierById')) {
    function getSupplierById($id) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM supplier WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('insertSupplier')) {
    function insertSupplier($nama, $alamat, $telepon) {
        global $conn;
        $stmt = $conn->prepare("INSERT INTO supplier (nama, alamat, telepon) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $nama, $alamat, $telepon);
        return $stmt->execute();
    }
}

if (!function_exists('updateSupplier')) {
    function updateSupplier($id, $nama, $alamat, $telepon) {
        global $conn;
        $stmt = $conn->prepare("UPDATE supplier SET nama = ?, alamat = ?, telepon = ? WHERE id = ?");
        $stmt->bind_param("sssi", $nama, $alamat, $telepon, $id);
        return $stmt->execute();
    }
}

if (!function_exists('deleteSupplier')) {
    function deleteSupplier($id) {
        global $conn;
        $stmt = $conn->prepare("DELETE FROM supplier WHERE id = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }
}

// ---------- KATEGORI ----------
if (!function_exists('getAllKategori')) {
    function getAllKategori() {
        global $conn;
        return $conn->query("SELECT id, nama FROM kategori ORDER BY nama");
    }
}

if (!function_exists('getKategoriById')) {
    function getKategoriById($id) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM kategori WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('getKategoriOptions')) {
    function getKategoriOptions($selected_id = null) {
        $kategori = getAllKategori();
        $options = '';
        while ($row = $kategori->fetch_assoc()) {
            $selectedAttr = ($selected_id == $row['id']) ? 'selected' : '';
            $options .= "<option value=\"" . $row['id'] . "\" $selectedAttr>" . htmlspecialchars($row['nama']) . "</option>";
        }
        return $options;
    }
}

if (!function_exists('insertKategori')) {
    function insertKategori($nama) {
        global $conn;
        $stmt = $conn->prepare("INSERT INTO kategori (nama) VALUES (?)");
        $stmt->bind_param("s", $nama);
        return $stmt->execute();
    }
}

if (!function_exists('updateKategori')) {
    function updateKategori($id, $nama) {
        global $conn;
        $stmt = $conn->prepare("UPDATE kategori SET nama = ? WHERE id = ?");
        $stmt->bind_param("si", $nama, $id);
        return $stmt->execute();
    }
}

if (!function_exists('deleteKategori')) {
    function deleteKategori($id) {
        global $conn;
        $stmt = $conn->prepare("DELETE FROM kategori WHERE id = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }
}

// ---------- BARANG ----------
if (!function_exists('getAllBarang')) {
    function getAllBarang() {
        global $conn;
        $sql = "SELECT b.id_barang, b.kode_barang, b.nama_barang, b.kategori, b.satuan, b.stok, b.min_stok, b.status,
                       s.id as supplier_id, s.nama as supplier_nama
                FROM barang b
                LEFT JOIN supplier s ON b.supplier_id = s.id
                WHERE b.status = 'aktif'
                ORDER BY b.nama_barang";
        return $conn->query($sql);
    }
}

if (!function_exists('getBarangById')) {
    function getBarangById($id) {
        global $conn;
        $stmt = $conn->prepare("
            SELECT b.*, s.nama as supplier_nama
            FROM barang b
            LEFT JOIN supplier s ON b.supplier_id = s.id
            WHERE b.id_barang = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

if (!function_exists('insertBarang')) {
    function insertBarang($kode, $nama, $kategori, $satuan, $stok, $min_stok, $supplier_id, $status = 'aktif') {
        global $conn;
        $stmt = $conn->prepare("
            INSERT INTO barang (kode_barang, nama_barang, kategori, satuan, stok, min_stok, supplier_id, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("ssssiiis", $kode, $nama, $kategori, $satuan, $stok, $min_stok, $supplier_id, $status);
        return $stmt->execute();
    }
}

if (!function_exists('updateBarang')) {
    function updateBarang($id, $kode, $nama, $kategori, $satuan, $stok, $min_stok, $supplier_id, $status) {
        global $conn;
        $stmt = $conn->prepare("
            UPDATE barang 
            SET kode_barang = ?, nama_barang = ?, kategori = ?, satuan = ?, stok = ?, min_stok = ?, supplier_id = ?, status = ?
            WHERE id_barang = ?
        ");
        $stmt->bind_param("ssssiiisi", $kode, $nama, $kategori, $satuan, $stok, $min_stok, $supplier_id, $status, $id);
        return $stmt->execute();
    }
}

if (!function_exists('deleteBarang')) {
    function deleteBarang($id) {
        global $conn;
        $stmt = $conn->prepare("UPDATE barang SET status = 'nonaktif' WHERE id_barang = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }
}


// ============================================================
// 8. GENERATOR KODE (DIPERBAIKI)
// ============================================================

if (!function_exists('generateKode')) {
    function generateKode($prefix, $table, $field = 'kode') {
        global $conn;
        $fieldMap = [
            'pre_order'         => 'id',
            'transaksi_masuk'   => 'nomor_faktur',
            'transaksi_keluar'  => 'nomor_supply',
            'barang'            => 'kode_barang'
        ];
        $actualField = $fieldMap[$table] ?? $field;
        if ($table == 'pre_order') {
            $res = $conn->query("SELECT MAX(id) as max_id FROM pre_order");
            $max = $res->fetch_assoc()['max_id'];
            $angka = $max ? $max + 1 : 1;
            return $prefix . str_pad($angka, 3, '0', STR_PAD_LEFT);
        }
        $stmt = $conn->prepare("SELECT MAX($actualField) as max_kode FROM $table");
        $stmt->execute();
        $res = $stmt->get_result();
        $max = $res->fetch_assoc()['max_kode'];
        $angka = $max ? (int)substr($max, strlen($prefix)) + 1 : 1;
        return $prefix . str_pad($angka, 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('generateNomorFaktur')) {
    function generateNomorFaktur() {
        global $conn;
        $tanggal = date('Ymd');
        $prefix = "BM-$tanggal-";
        $stmt = $conn->prepare("SELECT nomor_faktur FROM transaksi_masuk WHERE nomor_faktur LIKE ? ORDER BY id DESC LIMIT 1");
        $like = $prefix . '%';
        $stmt->bind_param("s", $like);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $last = $res->fetch_assoc()['nomor_faktur'];
            $lastNumber = (int)substr($last, -3);
            $newNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '001';
        }
        return $prefix . $newNumber;
    }
}

if (!function_exists('generateNomorSupply')) {
    /**
     * Generator nomor supply untuk tampilan (tidak disimpan di database)
     * Format: SUP-YYYYMMDD-XXX
     */
    function generateNomorSupply() {
        $tanggal = date('Ymd');
        $prefix = "SUP-$tanggal-";
        $lastNumber = rand(1, 999);
        return $prefix . str_pad($lastNumber, 3, '0', STR_PAD_LEFT);
    }
}

// ----- FUNGSI GENERATE KODE BARANG YANG DIPERBAIKI -----
if (!function_exists('generateKodeBarang')) {
    /**
     * Generate kode barang berdasarkan prefix kategori.
     * Jika kategori belum punya prefix, fallback ke 'BRG'.
     * Mengambil nomor terbesar dengan CAST ke angka agar urut benar.
     * 
     * @param string|null $kategori_nama Nama kategori (opsional)
     * @return string Kode barang format PREFIX-XXXX (4 digit)
     */
    function generateKodeBarang($kategori_nama = null) {
        global $conn;
        $prefix = 'BRG'; // default

        // Cek apakah kolom kode_prefix ada di tabel kategori
        $colCheck = $conn->query("SHOW COLUMNS FROM kategori LIKE 'kode_prefix'");
        $hasPrefix = ($colCheck && $colCheck->num_rows > 0);

        if (!empty($kategori_nama) && $hasPrefix) {
            $stmt = $conn->prepare("SELECT kode_prefix FROM kategori WHERE nama = ? LIMIT 1");
            $stmt->bind_param("s", $kategori_nama);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && !empty($row['kode_prefix'])) {
                $prefix = strtoupper($row['kode_prefix']);
            }
        }

        $like = $prefix . '-%';
        // Ambil nomor urut terbesar dengan CAST ke UNSIGNED (angka)
        $stmt = $conn->prepare("
            SELECT MAX(CAST(SUBSTRING(kode_barang, LENGTH(?) + 2) AS UNSIGNED)) as max_num
            FROM barang
            WHERE kode_barang LIKE ?
        ");
        $stmt->bind_param("ss", $prefix, $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $next = ((int)($row['max_num'] ?? 0)) + 1;

        return $prefix . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
    }
}


// ============================================================
// 9. LOG AKTIVITAS
// ============================================================

if (!function_exists('catatLog')) {
    function catatLog($user, $aksi, $tabel, $data_id, $detail = '') {
        global $conn;
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $stmt = $conn->prepare("
            INSERT INTO log_aktivitas (user, aksi, tabel, data_id, detail, ip) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("sssiss", $user, $aksi, $tabel, $data_id, $detail, $ip);
        return $stmt->execute();
    }
}


// ============================================================
// 10. FUNGSI LAINNYA
// ============================================================

if (!function_exists('formatRupiah')) {
    function formatRupiah($angka) {
        return 'Rp ' . number_format($angka, 0, ',', '.');
    }
}

if (!function_exists('formatTanggal')) {
    function formatTanggal($tanggal, $format = 'd/m/Y H:i') {
        $timestamp = strtotime($tanggal);
        return date($format, $timestamp);
    }
}

if (!function_exists('getStatusBadge')) {
    function getStatusBadge($status) {
        $map = [
            'Pending'   => 'badge-pending',
            'Diproses'  => 'badge-diproses',
            'Dikirim'   => 'badge-dikirim',
            'Diterima'  => 'badge-dikirim',
            'Selesai'   => 'badge-selesai',
            'Ditolak'   => 'badge-ditolak',
            'batal'     => 'badge-ditolak',
            'proses'    => 'badge-diproses',
            'dikirim'   => 'badge-dikirim',
        ];
        return $map[$status] ?? 'badge-gray';
    }
}