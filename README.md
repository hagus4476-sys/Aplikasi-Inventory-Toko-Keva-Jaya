# Aplikasi-Inventory-Toko-Keva-Jaya

# Aplikasi-Inventory-Toko-Keva-Jaya

## Deskripsi

Aplikasi Inventory Toko Keva Jaya merupakan aplikasi berbasis web yang dikembangkan untuk membantu proses pengelolaan persediaan barang pada Toko Keva Jaya. Aplikasi ini menyediakan fitur pengelolaan data barang, supplier, barang masuk, barang keluar, koreksi stok, stock opname, serta laporan persediaan sehingga proses pencatatan stok menjadi lebih efektif, akurat, dan terstruktur.

Aplikasi ini dikembangkan menggunakan bahasa pemrograman PHP, database MySQL, serta dijalankan pada web server Apache melalui XAMPP.

---

## Fitur Utama

- Login Admin dan Owner
- Manajemen Data Barang
- Manajemen Data Supplier
- Transaksi Barang Masuk
- Transaksi Barang Keluar
- Koreksi Stok
- Stock Opname
- Laporan Persediaan Barang
- Dashboard Monitoring Stok

---

## Persyaratan

- XAMPP (Apache & MySQL)
- PHP 8.x atau versi yang kompatibel
- MySQL
- Web Browser (Google Chrome, Microsoft Edge, Mozilla Firefox)

---

## Cara Menjalankan

1. Salin folder **toko-keva** ke dalam:

```
C:\xampp\htdocs\
```

2. Jalankan **XAMPP**, kemudian aktifkan:

- Apache
- MySQL

3. Buka **phpMyAdmin** melalui browser:

```
http://localhost/phpmyadmin
```

4. Buat database sesuai dengan konfigurasi aplikasi.

5. Import file database yang telah disediakan.

6. Karena folder **dompdf** tidak disertakan pada repository GitHub, silakan mengunduh library Dompdf terlebih dahulu.

Repository Dompdf:
https://github.com/dompdf/dompdf

Setelah diunduh, letakkan folder **dompdf** pada direktori utama project sehingga struktur menjadi:

```
toko-keva/
│
├── admin/
├── config/
├── dompdf/
├── includes/
├── owner/
├── uploads/
├── index.php
├── login.php
└── logout.php
```

7. Setelah seluruh proses selesai, buka browser kemudian akses:

```
http://localhost/toko-keva
```

---

## Login

### Admin

**Username:** admin

**Password:** admin123

### Owner

**Username:** owner

**Password:** owner123

> Sesuaikan username dan password di atas dengan data yang terdapat pada tabel `users` di database apabila berbeda.

---

## Teknologi yang Digunakan

- PHP Native
- MySQL
- HTML5
- CSS3
- Bootstrap 5
- JavaScript
- Dompdf
- XAMPP

---

## Lisensi

Aplikasi ini dibuat untuk keperluan penelitian dan penyusunan Tugas Akhir pada Program Studi D3 Teknik Informatika, Politeknik Negeri Samarinda.
