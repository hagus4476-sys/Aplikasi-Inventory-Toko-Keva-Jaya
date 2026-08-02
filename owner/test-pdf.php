<?php
// ========== TEST DOMPDF DENGAN PATH PASTI ==========
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Path sesuai struktur folder Anda (berdasarkan gambar)
$autoload_file = __DIR__ . '/vendor/dompdf/autoload.inc';

echo "<h3>Debug Informasi</h3>";
echo "Mencari file: " . htmlspecialchars($autoload_file) . "<br>";

if (!file_exists($autoload_file)) {
    echo "<span style='color:red'>File tidak ditemukan!</span><br>";
    echo "Coba cek folder:<br>";
    $vendor = __DIR__ . '/vendor';
    if (is_dir($vendor)) {
        echo "Folder vendor ada. Isinya:<br><ul>";
        $files = scandir($vendor);
        foreach ($files as $f) {
            if ($f != '.' && $f != '..') echo "<li>$f</li>";
        }
        echo "</ul>";
        $dompdfDir = $vendor . '/dompdf';
        if (is_dir($dompdfDir)) {
            echo "Folder vendor/dompdf ada. Isinya:<br><ul>";
            $files2 = scandir($dompdfDir);
            foreach ($files2 as $f) {
                if ($f != '.' && $f != '..') echo "<li>$f</li>";
            }
            echo "</ul>";
        } else {
            echo "Folder vendor/dompdf tidak ditemukan.<br>";
        }
    } else {
        echo "Folder vendor tidak ditemukan di " . htmlspecialchars($vendor) . "<br>";
    }
    exit;
}

echo "<span style='color:green'>File ditemukan!</span><br>";
require_once $autoload_file;

// Cek apakah class Dompdf tersedia
if (!class_exists('Dompdf\Dompdf')) {
    die("Error: Class Dompdf\Dompdf tidak ditemukan setelah require. Mungkin file autoload.inc tidak benar.");
}

// Gunakan namespace
use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('defaultFont', 'Arial');
$dompdf = new Dompdf($options);

$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Test PDF</title>
    <style>
        body { font-family: Arial, sans-serif; }
        h1 { color: #0d6efd; }
    </style>
</head>
<body>
    <h1>Dompdf Berhasil Terpasang!</h1>
    <p>Waktu: ' . date('d/m/Y H:i:s') . '</p>
</body>
</html>
';

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream("test-dompdf.pdf", array("Attachment" => 0));
?>