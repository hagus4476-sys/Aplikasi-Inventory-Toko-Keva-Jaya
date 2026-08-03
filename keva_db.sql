-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 03, 2026 at 02:05 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `keva_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `barang`
--

CREATE TABLE `barang` (
  `id_barang` int(11) NOT NULL,
  `kode_barang` varchar(50) DEFAULT NULL,
  `nama_barang` varchar(200) NOT NULL,
  `kategori` varchar(100) DEFAULT NULL,
  `kategori_id` int(11) DEFAULT NULL,
  `satuan` varchar(20) DEFAULT NULL,
  `satuan_besar` varchar(20) DEFAULT NULL,
  `stok` int(11) DEFAULT 0,
  `stok_besar` int(11) NOT NULL DEFAULT 0,
  `sisa_karung_kg` int(11) NOT NULL DEFAULT 0,
  `stok_rusak` int(11) DEFAULT 0,
  `min_stok` int(11) DEFAULT 0,
  `tanggal_kadaluarsa` date DEFAULT NULL,
  `gambar` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('aktif','nonaktif') DEFAULT 'aktif',
  `deleted_at` datetime DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `total_rusak` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barang`
--

INSERT INTO `barang` (`id_barang`, `kode_barang`, `nama_barang`, `kategori`, `kategori_id`, `satuan`, `satuan_besar`, `stok`, `stok_besar`, `sisa_karung_kg`, `stok_rusak`, `min_stok`, `tanggal_kadaluarsa`, `gambar`, `created_at`, `status`, `deleted_at`, `supplier_id`, `total_rusak`) VALUES
(1, 'BRS-0001', 'Lele Orange (5kg)', 'Beras', NULL, 'Kg', 'Karung', 5, 33, 0, 0, 12, '2028-07-21', NULL, '2026-07-21 05:12:22', 'aktif', NULL, 1, 2),
(2, 'BRS-0002', '2 Jempol (5kg)', 'Beras', NULL, 'Kg', 'Karung', 3, 10, 2, 0, 12, '2027-09-21', NULL, '2026-07-21 05:34:21', 'aktif', NULL, 1, 0),
(3, 'MKU-0001', '324K', 'Makanan Unggas', NULL, 'Kg', 'Karung', 0, 0, 0, 0, 15, '2026-12-23', NULL, '2026-07-23 14:42:33', 'aktif', NULL, 1, 0),
(4, 'MKU-0002', 'BR-1', 'Makanan Unggas', NULL, 'Kg', 'Karung', 0, 50, 0, 0, 15, '2027-12-23', NULL, '2026-07-23 14:43:26', 'aktif', NULL, 1, 10),
(5, 'MKU-0003', 'BR-2', 'Makanan Unggas', NULL, 'Kg', 'Karung', 0, 80, 0, 0, 12, '2027-09-23', NULL, '2026-07-23 14:44:12', 'aktif', NULL, 1, 0),
(6, 'MKK-0001', 'Cat Choize Adult Salmon 20kg', 'Makanan Kucing', NULL, 'Kg', 'Karung', 0, 0, 0, 0, 10, '2027-07-23', NULL, '2026-07-23 14:47:04', 'aktif', NULL, 3, 0),
(7, 'MKK-0002', 'Cat Choize Adult Salmon (Free)', 'Makanan Kucing', NULL, 'Kg', 'karung', 0, 160, 0, 0, 10, '2027-08-23', NULL, '2026-07-23 14:47:51', 'aktif', NULL, 3, 0),
(8, 'MKK-0003', 'Lezato Adult Tuna 20kg', 'Makanan Kucing', NULL, 'Kg', 'Karung', 0, 0, 0, 0, 10, '2027-07-23', NULL, '2026-07-23 14:48:39', 'aktif', NULL, 3, 0);

-- --------------------------------------------------------

--
-- Table structure for table `barang_keluar`
--

CREATE TABLE `barang_keluar` (
  `id` int(11) NOT NULL,
  `id_permintaan` int(11) NOT NULL,
  `tanggal` date DEFAULT NULL,
  `user_pos` varchar(50) DEFAULT NULL,
  `barang_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `status` enum('pending','diproses','selesai') DEFAULT 'pending',
  `diproses_oleh` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kategori`
--

CREATE TABLE `kategori` (
  `id` int(11) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `kode_prefix` varchar(10) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `kategori`
--

INSERT INTO `kategori` (`id`, `nama`, `kode_prefix`, `created_at`) VALUES
(1, 'Beras', 'BRS', '2026-07-21 05:06:10'),
(2, 'Makanan Kucing', 'MKK', '2026-07-21 05:06:23'),
(3, 'Makanan Unggas', 'MKU', '2026-07-21 05:06:44');

-- --------------------------------------------------------

--
-- Table structure for table `log_aktivitas`
--

CREATE TABLE `log_aktivitas` (
  `id` int(11) NOT NULL,
  `user` varchar(100) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `aksi` varchar(50) DEFAULT NULL,
  `tabel` varchar(50) DEFAULT NULL,
  `data_id` int(11) DEFAULT NULL,
  `detail` text DEFAULT NULL,
  `ip` varchar(50) DEFAULT NULL,
  `waktu` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `log_aktivitas`
--

INSERT INTO `log_aktivitas` (`id`, `user`, `user_id`, `aksi`, `tabel`, `data_id`, `detail`, `ip`, `waktu`) VALUES
(1, NULL, NULL, 'Tambah', 'barang', 1, 'Kode: BRS-0001, Nama: Lele Orange (5kg), Supplier: AHTIMA AKYA PRATAMA', '127.0.0.1', '2026-07-21 05:12:22'),
(2, 'admin', NULL, 'Tambah', 'pre_order', 1, 'Supplier ID: 1', '127.0.0.1', '2026-07-21 05:12:55'),
(3, 'owner', NULL, 'Approve', 'pre_order', 1, 'Pre order disetujui', '127.0.0.1', '2026-07-21 05:13:05'),
(4, 'admin', NULL, 'Tambah', 'pre_order', 2, 'Supplier ID: 1', '127.0.0.1', '2026-07-21 05:14:01'),
(5, 'owner', NULL, 'Approve', 'pre_order', 2, 'Pre order disetujui', '127.0.0.1', '2026-07-21 05:14:16'),
(6, NULL, NULL, 'Edit', 'barang', 1, 'Kode: BRS-0001, Nama: Lele Orange (5kg), Supplier: AHTIMA AKYA PRATAMA', '127.0.0.1', '2026-07-21 05:15:18'),
(7, NULL, NULL, 'Tambah', 'barang', 2, 'Kode: BRS-0002, Nama: 2 Jempol (5kg), Supplier: AHTIMA AKYA PRATAMA', '127.0.0.1', '2026-07-21 05:34:21'),
(8, 'admin', NULL, 'Tambah', 'pre_order', 3, 'Supplier ID: 1', '127.0.0.1', '2026-07-21 05:34:39'),
(9, 'owner', NULL, 'Approve', 'pre_order', 3, 'Pre order disetujui', '127.0.0.1', '2026-07-21 05:34:51'),
(10, 'admin', NULL, 'Tambah', 'pre_order', 4, 'Supplier ID: 1', '127.0.0.1', '2026-07-21 05:57:10'),
(11, 'owner', NULL, 'Approve', 'pre_order', 4, 'Pre order disetujui', '127.0.0.1', '2026-07-21 05:57:22'),
(12, NULL, NULL, 'Olah Stok', 'barang', 1, 'Buka 1 Karung Lele Orange (5kg), hasil timbang 5Kg, langsung masuk stok 5Kg, sisa di karung 0Kg', '127.0.0.1', '2026-07-21 05:58:26'),
(13, 'admin', NULL, 'Tambah', 'pre_order', 5, 'Supplier ID: 1', '127.0.0.1', '2026-07-21 06:02:15'),
(14, 'owner', NULL, 'Approve', 'pre_order', 5, 'Pre order disetujui', '127.0.0.1', '2026-07-21 06:02:24'),
(15, NULL, NULL, 'Olah Stok', 'barang', 2, 'Buka 1 Karung 2 Jempol (5kg), hasil timbang 5Kg, langsung masuk stok 3Kg, sisa di karung 2Kg', '127.0.0.1', '2026-07-21 06:10:42'),
(16, 'admin', NULL, 'Tambah', 'stock_opname', 1, 'Pengajuan koreksi SO-20260721-916 (Pending) — 1 item, menunggu persetujuan Owner.', '127.0.0.1', '2026-07-21 06:25:46'),
(17, NULL, NULL, 'Approve', 'stock_opname', 1, 'Owner menyetujui koreksi stok SO-20260721-916. Stok barang telah diperbarui.', '127.0.0.1', '2026-07-21 06:28:05'),
(18, NULL, NULL, 'Tambah', 'barang', 3, 'Kode: MKU-0001, Nama: 324K, Supplier: AHTIMA AKYA PRATAMA', '127.0.0.1', '2026-07-23 14:42:33'),
(19, NULL, NULL, 'Tambah', 'barang', 4, 'Kode: MKU-0002, Nama: BR-1, Supplier: AHTIMA AKYA PRATAMA', '127.0.0.1', '2026-07-23 14:43:26'),
(20, NULL, NULL, 'Tambah', 'barang', 5, 'Kode: MKU-0003, Nama: BR-2, Supplier: AHTIMA AKYA PRATAMA', '127.0.0.1', '2026-07-23 14:44:12'),
(21, NULL, NULL, 'Tambah', 'barang', 6, 'Kode: MKK-0001, Nama: Cat Choize Adult Salmon 20kg, Supplier: PT. PCI', '127.0.0.1', '2026-07-23 14:47:04'),
(22, NULL, NULL, 'Tambah', 'barang', 7, 'Kode: MKK-0002, Nama: Cat Choize Adult Salmon (Free), Supplier: PT. PCI', '127.0.0.1', '2026-07-23 14:47:51'),
(23, NULL, NULL, 'Tambah', 'barang', 8, 'Kode: MKK-0003, Nama: Lezato Adult Tuna 20kg, Supplier: PT. PCI', '127.0.0.1', '2026-07-23 14:48:39'),
(24, 'admin', NULL, 'Tambah', 'pre_order', 8, 'Supplier ID: 3', '127.0.0.1', '2026-07-23 16:12:14'),
(25, 'admin', NULL, 'Tambah', 'pre_order', 9, 'Supplier ID: 3', '127.0.0.1', '2026-07-23 16:17:57'),
(26, 'admin', NULL, 'Tambah', 'pre_order', 10, 'Supplier ID: 1', '127.0.0.1', '2026-07-23 16:18:54'),
(27, 'admin', NULL, 'Tambah', 'pre_order', 11, 'Supplier ID: 1', '127.0.0.1', '2026-07-23 16:19:33'),
(28, 'owner', NULL, 'Approve', 'pre_order', 11, 'Pre order disetujui oleh user ID 2', '127.0.0.1', '2026-07-23 16:23:04'),
(29, 'owner', NULL, 'Approve', 'pre_order', 10, 'Pre order disetujui oleh user ID 2', '127.0.0.1', '2026-07-23 16:23:07'),
(30, 'owner', NULL, 'Approve', 'pre_order', 9, 'Pre order disetujui oleh user ID 2', '127.0.0.1', '2026-07-23 16:23:11'),
(31, 'owner', NULL, 'Approve', 'pre_order', 8, 'Pre order disetujui oleh user ID 2', '127.0.0.1', '2026-07-23 16:23:15'),
(32, 'admin', NULL, 'Tambah', 'stock_opname', 5, 'Pengajuan koreksi SO-20260723-851 (Pending) — 1 item, menunggu persetujuan Owner.', '127.0.0.1', '2026-07-23 21:37:38'),
(33, NULL, NULL, 'Approve', 'stock_opname', 5, 'Owner menyetujui koreksi stok SO-20260723-851. Stok barang telah diperbarui.', '127.0.0.1', '2026-07-23 21:37:57');

-- --------------------------------------------------------

--
-- Table structure for table `pengajuan_rusak`
--

CREATE TABLE `pengajuan_rusak` (
  `id` int(11) NOT NULL,
  `barang_id` int(11) NOT NULL,
  `jumlah` int(11) DEFAULT NULL,
  `aksi` enum('Dibuang','Retur Supplier') DEFAULT NULL,
  `keterangan` text DEFAULT NULL,
  `status` enum('Pending','Disetujui','Ditolak') DEFAULT 'Pending',
  `owner_note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pengajuan_rusak`
--

INSERT INTO `pengajuan_rusak` (`id`, `barang_id`, `jumlah`, `aksi`, `keterangan`, `status`, `owner_note`, `created_at`, `updated_at`) VALUES
(1, 1, 12, 'Dibuang', '', 'Disetujui', '', '2026-07-21 06:37:48', '2026-07-21 06:38:01'),
(2, 1, 2, 'Dibuang', 'sudah tidak laku', 'Disetujui', '', '2026-07-22 00:36:00', '2026-07-22 00:36:11'),
(3, 1, 2, 'Dibuang', '', 'Disetujui', '', '2026-07-22 00:52:45', '2026-07-22 00:52:56'),
(4, 4, 10, 'Dibuang', '', 'Disetujui', '', '2026-07-23 21:38:35', '2026-07-23 21:38:52');

-- --------------------------------------------------------

--
-- Table structure for table `pre_order`
--

CREATE TABLE `pre_order` (
  `id` int(11) NOT NULL,
  `tanggal` date DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `status` enum('pending','disetujui','ditolak') DEFAULT 'pending',
  `total_item` int(11) DEFAULT NULL,
  `total_estimasi` decimal(15,2) DEFAULT NULL,
  `catatan` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_used` tinyint(4) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pre_order`
--

INSERT INTO `pre_order` (`id`, `tanggal`, `supplier_id`, `status`, `total_item`, `total_estimasi`, `catatan`, `created_by`, `approved_by`, `created_at`, `is_used`) VALUES
(1, '2026-07-21', 1, 'disetujui', 1, NULL, '', NULL, NULL, '2026-07-21 05:12:55', 1),
(2, '2026-07-21', 1, 'disetujui', 120, NULL, '', NULL, NULL, '2026-07-21 05:14:01', 1),
(3, '2026-07-21', 1, 'disetujui', 129, NULL, '', NULL, NULL, '2026-07-21 05:34:39', 1),
(4, '2026-07-21', 1, 'disetujui', 50, NULL, '', NULL, NULL, '2026-07-21 05:57:10', 1),
(5, '2026-07-21', 1, 'disetujui', 50, NULL, '', NULL, NULL, '2026-07-21 06:02:15', 1),
(8, '2026-07-23', 3, 'disetujui', 120, NULL, '', 1, 2, '2026-07-23 16:12:14', 1),
(9, '2026-07-23', 3, 'disetujui', 40, NULL, '', 1, 2, '2026-07-23 16:17:57', 1),
(10, '2026-07-23', 1, 'disetujui', 60, NULL, '', 1, 2, '2026-07-23 16:18:54', 1),
(11, '2026-07-23', 1, 'disetujui', 50, NULL, '', 1, 2, '2026-07-23 16:19:33', 1);

-- --------------------------------------------------------

--
-- Table structure for table `pre_order_item`
--

CREATE TABLE `pre_order_item` (
  `id` int(11) NOT NULL,
  `pre_order_id` int(11) NOT NULL,
  `barang_id` int(11) DEFAULT NULL,
  `temp_nama_barang` varchar(200) DEFAULT NULL,
  `temp_satuan` varchar(20) DEFAULT NULL,
  `temp_kategori` varchar(100) DEFAULT NULL,
  `qty` int(11) DEFAULT NULL,
  `harga_estimasi` decimal(15,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pre_order_item`
--

INSERT INTO `pre_order_item` (`id`, `pre_order_id`, `barang_id`, `temp_nama_barang`, `temp_satuan`, `temp_kategori`, `qty`, `harga_estimasi`) VALUES
(1, 1, 1, NULL, NULL, NULL, 1, NULL),
(2, 2, 1, NULL, NULL, NULL, 120, NULL),
(3, 3, 1, NULL, NULL, NULL, 129, NULL),
(4, 4, 1, NULL, NULL, NULL, 50, NULL),
(5, 5, 2, NULL, NULL, NULL, 50, NULL),
(6, 8, 7, NULL, NULL, NULL, 120, NULL),
(7, 9, 7, NULL, NULL, NULL, 40, NULL),
(8, 10, 4, NULL, NULL, NULL, 60, NULL),
(9, 11, 5, NULL, NULL, NULL, 50, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `stock_mutasi`
--

CREATE TABLE `stock_mutasi` (
  `id` int(11) NOT NULL,
  `barang_id` int(11) NOT NULL,
  `jenis` enum('MASUK','KELUAR','KOREKSI','RUSAK','OPNAME') NOT NULL,
  `qty` int(11) DEFAULT NULL,
  `stok_sebelum` int(11) DEFAULT NULL,
  `stok_sesudah` int(11) DEFAULT NULL,
  `keterangan` text DEFAULT NULL,
  `ref_id` int(11) DEFAULT NULL,
  `ref_type` varchar(30) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_mutasi`
--

INSERT INTO `stock_mutasi` (`id`, `barang_id`, `jenis`, `qty`, `stok_sebelum`, `stok_sesudah`, `keterangan`, `ref_id`, `ref_type`, `created_at`) VALUES
(1, 1, 'MASUK', 1, 0, 1, 'Barang masuk dari PO #1', 1, NULL, '2026-07-21 05:13:32'),
(2, 1, 'MASUK', 120, 1, 121, 'Barang masuk dari PO #2', 2, NULL, '2026-07-21 05:14:36'),
(3, 1, 'MASUK', 129, 121, 250, 'Barang masuk dari PO #3', 3, NULL, '2026-07-21 05:35:17'),
(4, 1, 'MASUK', 50, 0, 50, 'Barang masuk (karung) dari PO #4', 4, NULL, '2026-07-21 05:57:52'),
(5, 2, 'MASUK', 50, 0, 50, 'Barang masuk (karung) dari PO #5', 5, NULL, '2026-07-21 06:02:58'),
(11, 2, 'KOREKSI', 39, 49, 10, 'Koreksi stok disetujui Owner — Opname: SO-20260721-916', 1, NULL, '2026-07-21 06:28:05'),
(12, 1, 'RUSAK', 12, 49, 37, '', NULL, NULL, '2026-07-21 06:37:48'),
(13, 1, 'RUSAK', 2, 37, 35, 'sudah tidak laku', NULL, NULL, '2026-07-22 00:36:00'),
(14, 1, 'RUSAK', 2, 35, 33, '', NULL, NULL, '2026-07-22 00:52:45'),
(15, 7, 'MASUK', 120, 0, 120, 'Barang masuk (karung) dari PO #8', 6, NULL, '2026-07-23 16:24:20'),
(16, 5, 'MASUK', 50, 0, 50, 'Barang masuk (karung) dari PO #11', 7, NULL, '2026-07-23 16:25:01'),
(17, 4, 'MASUK', 60, 0, 60, 'Barang masuk (karung) dari PO #10', 8, NULL, '2026-07-23 16:25:30'),
(18, 7, 'MASUK', 40, 120, 160, 'Barang masuk (karung) dari PO #9', 9, NULL, '2026-07-23 16:26:06'),
(19, 5, 'KOREKSI', 30, 50, 80, 'Koreksi stok disetujui Owner — Opname: SO-20260723-851', 5, NULL, '2026-07-23 21:37:57'),
(20, 4, 'RUSAK', 10, 60, 50, '', NULL, NULL, '2026-07-23 21:38:35');

-- --------------------------------------------------------

--
-- Table structure for table `stock_opname`
--

CREATE TABLE `stock_opname` (
  `id` int(11) NOT NULL,
  `no_opname` varchar(50) DEFAULT NULL,
  `periode` varchar(100) DEFAULT NULL,
  `tanggal` date DEFAULT NULL,
  `total_item` int(11) DEFAULT NULL,
  `status` enum('Pending','Disetujui','Ditolak','Selesai') DEFAULT 'Pending',
  `keterangan` text DEFAULT NULL,
  `catatan_owner` varchar(500) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_opname`
--

INSERT INTO `stock_opname` (`id`, `no_opname`, `periode`, `tanggal`, `total_item`, `status`, `keterangan`, `catatan_owner`, `approved_by`, `approved_at`, `created_by`, `created_at`) VALUES
(1, 'SO-20260721-916', 'July 2026 - Minggu 1', '2026-07-21', 1, 'Disetujui', '', '', NULL, '2026-07-21 13:28:05', NULL, '2026-07-21 06:25:46'),
(5, 'SO-20260723-851', 'July 2026 - Minggu 1', '2026-07-23', 1, 'Disetujui', '', '', NULL, '2026-07-24 04:37:57', 1, '2026-07-23 21:37:38');

-- --------------------------------------------------------

--
-- Table structure for table `stock_opname_item`
--

CREATE TABLE `stock_opname_item` (
  `id` int(11) NOT NULL,
  `opname_id` int(11) NOT NULL,
  `barang_id` int(11) NOT NULL,
  `stok_sistem` int(11) DEFAULT NULL,
  `stok_fisik` int(11) DEFAULT NULL,
  `selisih` int(11) DEFAULT NULL,
  `keterangan_item` text DEFAULT NULL,
  `bukti_foto` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stock_opname_item`
--

INSERT INTO `stock_opname_item` (`id`, `opname_id`, `barang_id`, `stok_sistem`, `stok_fisik`, `selisih`, `keterangan_item`, `bukti_foto`) VALUES
(1, 1, 2, 49, 10, -39, '', 'uploads/koreksi/SO-20260721-916_2_1784615146.png'),
(2, 5, 5, 50, 80, 30, '', 'uploads/koreksi/SO-20260723-851_5_1784842658.jpg');

-- --------------------------------------------------------

--
-- Table structure for table `supplier`
--

CREATE TABLE `supplier` (
  `id` int(11) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `alamat` text DEFAULT NULL,
  `telepon` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supplier`
--

INSERT INTO `supplier` (`id`, `nama`, `alamat`, `telepon`, `created_at`) VALUES
(1, 'AHTIMA AKYA PRATAMA', 'Jl.Cendana', '08654332111123', '2026-07-21 05:07:14'),
(2, 'APB Distributor', 'Jl.antasari', '087756354432', '2026-07-21 05:07:51'),
(3, 'PT. PCI', 'Jl.Banggris', '085544332211', '2026-07-21 05:08:21'),
(4, 'PT Perfect Companion', 'Jl.Sultan Hasanuddin', '08654377883', '2026-07-21 05:08:49'),
(5, '(Surat Jalan Kuning)', 'Jl.Cipto Mangunkusumo', '089694439487', '2026-07-21 05:09:41'),
(6, 'PT Ayam Barokah Jaya', 'Jl.karang asam', '087766554433', '2026-07-21 05:10:18');

-- --------------------------------------------------------

--
-- Table structure for table `transaksi_masuk`
--

CREATE TABLE `transaksi_masuk` (
  `id` int(11) NOT NULL,
  `nomor_faktur` varchar(100) DEFAULT NULL,
  `tanggal` date DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `total_item` int(11) DEFAULT NULL,
  `total_biaya` decimal(15,2) DEFAULT NULL,
  `catatan` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transaksi_masuk`
--

INSERT INTO `transaksi_masuk` (`id`, `nomor_faktur`, `tanggal`, `supplier_id`, `total_item`, `total_biaya`, `catatan`, `created_at`) VALUES
(1, 'BM-20260721-001', '2026-07-21', 1, 1, 12000.00, '', '2026-07-21 05:13:32'),
(2, 'BM-20260721-002', '2026-07-21', 1, 120, 9600000.00, '', '2026-07-21 05:14:36'),
(3, 'BM-20260721-003', '2026-07-21', 1, 129, 10320000.00, '', '2026-07-21 05:35:17'),
(4, 'BM-20260721-004', '2026-07-21', 1, 50, 3000000.00, '', '2026-07-21 05:57:52'),
(5, 'BM-20260721-005', '2026-07-21', 1, 50, 600000.00, '', '2026-07-21 06:02:58'),
(6, 'BM-20260723-001', '2026-07-23', 3, 120, 51600000.00, '', '2026-07-23 16:24:20'),
(7, 'BM-20260723-002', '2026-07-23', 1, 50, 22250000.00, '', '2026-07-23 16:25:01'),
(8, 'BM-20260723-003', '2026-07-23', 1, 60, 26700000.00, '', '2026-07-23 16:25:30'),
(9, 'BM-20260723-004', '2026-07-23', 3, 40, 1600000.00, '', '2026-07-23 16:26:06');

-- --------------------------------------------------------

--
-- Table structure for table `transaksi_masuk_item`
--

CREATE TABLE `transaksi_masuk_item` (
  `id` int(11) NOT NULL,
  `transaksi_masuk_id` int(11) NOT NULL,
  `barang_id` int(11) NOT NULL,
  `qty` int(11) DEFAULT NULL,
  `harga_beli` decimal(15,2) DEFAULT NULL,
  `satuan` varchar(10) NOT NULL DEFAULT 'kg',
  `satuan_type` varchar(10) NOT NULL DEFAULT 'kecil'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transaksi_masuk_item`
--

INSERT INTO `transaksi_masuk_item` (`id`, `transaksi_masuk_id`, `barang_id`, `qty`, `harga_beli`, `satuan`, `satuan_type`) VALUES
(1, 1, 1, 1, 12000.00, 'kg', 'kecil'),
(2, 2, 1, 120, 80000.00, 'kg', 'kecil'),
(3, 3, 1, 129, 80000.00, 'kg', 'kecil'),
(4, 4, 1, 50, 60000.00, 'besar', 'kecil'),
(5, 5, 2, 50, 12000.00, 'besar', 'kecil'),
(6, 6, 7, 120, 430000.00, 'besar', 'kecil'),
(7, 7, 5, 50, 445000.00, 'besar', 'kecil'),
(8, 8, 4, 60, 445000.00, 'besar', 'kecil'),
(9, 9, 7, 40, 40000.00, 'besar', 'kecil');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','owner') DEFAULT 'admin'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`) VALUES
(1, 'admin', 'admin123', 'admin'),
(2, 'owner', 'owner123', 'owner');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `barang`
--
ALTER TABLE `barang`
  ADD PRIMARY KEY (`id_barang`),
  ADD KEY `kategori_id` (`kategori_id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `barang_keluar`
--
ALTER TABLE `barang_keluar`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_permintaan` (`id_permintaan`),
  ADD KEY `barang_id` (`barang_id`),
  ADD KEY `diproses_oleh` (`diproses_oleh`);

--
-- Indexes for table `kategori`
--
ALTER TABLE `kategori`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `log_aktivitas`
--
ALTER TABLE `log_aktivitas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `pengajuan_rusak`
--
ALTER TABLE `pengajuan_rusak`
  ADD PRIMARY KEY (`id`),
  ADD KEY `barang_id` (`barang_id`);

--
-- Indexes for table `pre_order`
--
ALTER TABLE `pre_order`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `fk_po_created` (`created_by`),
  ADD KEY `fk_po_approved` (`approved_by`);

--
-- Indexes for table `pre_order_item`
--
ALTER TABLE `pre_order_item`
  ADD PRIMARY KEY (`id`),
  ADD KEY `pre_order_id` (`pre_order_id`),
  ADD KEY `barang_id` (`barang_id`);

--
-- Indexes for table `stock_mutasi`
--
ALTER TABLE `stock_mutasi`
  ADD PRIMARY KEY (`id`),
  ADD KEY `barang_id` (`barang_id`);

--
-- Indexes for table `stock_opname`
--
ALTER TABLE `stock_opname`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_so_created` (`created_by`),
  ADD KEY `fk_so_approved` (`approved_by`);

--
-- Indexes for table `stock_opname_item`
--
ALTER TABLE `stock_opname_item`
  ADD PRIMARY KEY (`id`),
  ADD KEY `opname_id` (`opname_id`),
  ADD KEY `barang_id` (`barang_id`);

--
-- Indexes for table `supplier`
--
ALTER TABLE `supplier`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transaksi_masuk`
--
ALTER TABLE `transaksi_masuk`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `transaksi_masuk_item`
--
ALTER TABLE `transaksi_masuk_item`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transaksi_masuk_id` (`transaksi_masuk_id`),
  ADD KEY `barang_id` (`barang_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `barang`
--
ALTER TABLE `barang`
  MODIFY `id_barang` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `barang_keluar`
--
ALTER TABLE `barang_keluar`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kategori`
--
ALTER TABLE `kategori`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `log_aktivitas`
--
ALTER TABLE `log_aktivitas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `pengajuan_rusak`
--
ALTER TABLE `pengajuan_rusak`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `pre_order`
--
ALTER TABLE `pre_order`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `pre_order_item`
--
ALTER TABLE `pre_order_item`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `stock_mutasi`
--
ALTER TABLE `stock_mutasi`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `stock_opname`
--
ALTER TABLE `stock_opname`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `stock_opname_item`
--
ALTER TABLE `stock_opname_item`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `supplier`
--
ALTER TABLE `supplier`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `transaksi_masuk`
--
ALTER TABLE `transaksi_masuk`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `transaksi_masuk_item`
--
ALTER TABLE `transaksi_masuk_item`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `barang`
--
ALTER TABLE `barang`
  ADD CONSTRAINT `fk_barang_kategori` FOREIGN KEY (`kategori_id`) REFERENCES `kategori` (`id`),
  ADD CONSTRAINT `fk_barang_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`);

--
-- Constraints for table `barang_keluar`
--
ALTER TABLE `barang_keluar`
  ADD CONSTRAINT `fk_bk_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id_barang`),
  ADD CONSTRAINT `fk_bk_user` FOREIGN KEY (`diproses_oleh`) REFERENCES `users` (`id`);

--
-- Constraints for table `log_aktivitas`
--
ALTER TABLE `log_aktivitas`
  ADD CONSTRAINT `log_aktivitas_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `pengajuan_rusak`
--
ALTER TABLE `pengajuan_rusak`
  ADD CONSTRAINT `fk_pr_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id_barang`);

--
-- Constraints for table `pre_order`
--
ALTER TABLE `pre_order`
  ADD CONSTRAINT `fk_po_approved` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_po_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`);

--
-- Constraints for table `pre_order_item`
--
ALTER TABLE `pre_order_item`
  ADD CONSTRAINT `fk_poi_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id_barang`),
  ADD CONSTRAINT `fk_poi_po` FOREIGN KEY (`pre_order_id`) REFERENCES `pre_order` (`id`);

--
-- Constraints for table `stock_mutasi`
--
ALTER TABLE `stock_mutasi`
  ADD CONSTRAINT `fk_sm_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id_barang`);

--
-- Constraints for table `stock_opname`
--
ALTER TABLE `stock_opname`
  ADD CONSTRAINT `fk_so_approved` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_so_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `stock_opname_item`
--
ALTER TABLE `stock_opname_item`
  ADD CONSTRAINT `fk_soi_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id_barang`),
  ADD CONSTRAINT `fk_soi_opname` FOREIGN KEY (`opname_id`) REFERENCES `stock_opname` (`id`);

--
-- Constraints for table `transaksi_masuk`
--
ALTER TABLE `transaksi_masuk`
  ADD CONSTRAINT `fk_tm_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`);

--
-- Constraints for table `transaksi_masuk_item`
--
ALTER TABLE `transaksi_masuk_item`
  ADD CONSTRAINT `fk_tmi_barang` FOREIGN KEY (`barang_id`) REFERENCES `barang` (`id_barang`),
  ADD CONSTRAINT `fk_tmi_tm` FOREIGN KEY (`transaksi_masuk_id`) REFERENCES `transaksi_masuk` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
