<?php
session_start();
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/koneksi.php';

requireLogin();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        // Ambil data 1 barang spesifik berdasarkan ID
        if (!empty($_GET['id'])) {
            $id   = (int) $_GET['id'];
            $stmt = $conn->prepare("
                SELECT id, kode_produk, nama_produk, harga, stok, gambar
                FROM produk WHERE id = ? LIMIT 1
            ");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $barang = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$barang) responseError('Barang tidak ditemukan.', 404);
            responseOk($barang);
        }

        // Pencarian barang (Untuk Kasir)
        if (!empty($_GET['cari'])) {
            $keyword = '%' . $_GET['cari'] . '%';
            $stmt = $conn->prepare("
                SELECT id, kode_produk, nama_produk, harga, stok, gambar
                FROM produk
                WHERE (nama_produk LIKE ? OR kode_produk LIKE ?) AND stok > 0
                ORDER BY nama_produk ASC
            ");
            $stmt->bind_param('ss', $keyword, $keyword);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            responseOk($rows);
        }

        // Default: Ambil semua daftar barang untuk tabel Update Barang
        $result = $conn->query("SELECT id, kode_produk, nama_produk, harga, stok, gambar FROM produk ORDER BY id DESC");
        $rows   = $result->fetch_all(MYSQLI_ASSOC);
        responseOk($rows);
        break;


    case 'POST':
        // LOGIKA UPDATE / EDIT BARANG 
        if (isset($_GET['action']) && $_GET['action'] === 'update') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) responseError('ID produk wajib disertakan pada URL.');

            $nama  = trim($_POST['nama']  ?? '');
            $harga = (int)($_POST['harga'] ?? 0);
            $stok  = (int)($_POST['stok']  ?? 0);

            if (!$nama || $harga <= 0 || $stok < 0) {
                responseError('Nama, harga, dan stok wajib diisi dengan benar.');
            }

            // Proses upload gambar baru (jika kasir memilih gambar baru)
            $pathGambar = null;
            if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['gambar']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $namaFileBaru = time() . '_' . uniqid() . '.' . $ext;
                    if (!is_dir('uploads')) { mkdir('uploads', 0777, true); }
                    if (move_uploaded_file($_FILES['gambar']['tmp_name'], 'uploads/' . $namaFileBaru)) {
                        $pathGambar = 'uploads/' . $namaFileBaru;
                    }
                } else {
                    responseError('Format gambar tidak valid. Harap gunakan JPG, JPEG, atau PNG.');
                }
            }

            if ($pathGambar) {
                // Hapus gambar lama di server agar penyimpanan tidak penuh
                $stmtCek = $conn->prepare("SELECT gambar FROM produk WHERE id = ?");
                $stmtCek->bind_param('i', $id);
                $stmtCek->execute();
                $resCek = $stmtCek->get_result()->fetch_assoc();
                if (!empty($resCek['gambar']) && file_exists($resCek['gambar'])) {
                    @unlink($resCek['gambar']);
                }
                $stmtCek->close();

                // Update data + update path gambar
                $stmt = $conn->prepare("UPDATE produk SET nama_produk = ?, harga = ?, stok = ?, gambar = ? WHERE id = ?");
                $stmt->bind_param('siisi', $nama, $harga, $stok, $pathGambar, $id);
            } else {
                // Update data saja (gambar lama tetap dipakai)
                $stmt = $conn->prepare("UPDATE produk SET nama_produk = ?, harga = ?, stok = ? WHERE id = ?");
                $stmt->bind_param('siii', $nama, $harga, $stok, $id);
            }

            if (!$stmt->execute()) responseError('Gagal memperbarui produk.');
            $stmt->close();
            responseOk(null, 'Produk berhasil diperbarui.');
        } 
        
        // LOGIKA TAMBAH BARANG BARU 
        else {
            $kode  = trim($_POST['id']    ?? '');
            $nama  = trim($_POST['nama']  ?? '');
            $harga = (int)($_POST['harga'] ?? 0);
            $stok  = (int)($_POST['stok']  ?? 0);

            if (!$kode || !$nama || $harga <= 0 || $stok < 0) {
                responseError('Semua field (Kode, Nama, Harga, Stok) wajib diisi dengan benar.');
            }

            // Cek apakah Kode Produk sudah ada di database
            $stmtCek = $conn->prepare("SELECT id FROM produk WHERE kode_produk = ? LIMIT 1");
            $stmtCek->bind_param('s', $kode);
            $stmtCek->execute();
            $stmtCek->store_result();
            if ($stmtCek->num_rows > 0) {
                $stmtCek->close();
                responseError('Kode produk/ID Barang tersebut sudah terdaftar.');
            }
            $stmtCek->close();

            // Proses upload gambar baru
            $pathGambar = null;
            if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['gambar']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $namaFileBaru = time() . '_' . uniqid() . '.' . $ext;
                    if (!is_dir('uploads')) { mkdir('uploads', 0777, true); }
                    if (move_uploaded_file($_FILES['gambar']['tmp_name'], 'uploads/' . $namaFileBaru)) {
                        $pathGambar = 'uploads/' . $namaFileBaru;
                    }
                } else {
                    responseError('Format gambar tidak valid. Harap gunakan JPG, JPEG, atau PNG.');
                }
            }

            // Simpan ke database
            $stmt = $conn->prepare("INSERT INTO produk (kode_produk, nama_produk, harga, stok, gambar) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('ssiis', $kode, $nama, $harga, $stok, $pathGambar);
            
            if (!$stmt->execute()) responseError('Gagal menyimpan produk baru ke database.');
            $stmt->close();

            responseOk(null, 'Produk berhasil ditambahkan.');
        }
        break;


    case 'DELETE':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) responseError('ID produk wajib disertakan.');

        // Pengecekan Relasi Database: Jika barang sudah ada di struk belanjaan, tolak hapus.
        $stmtCek = $conn->prepare("SELECT id FROM detail_transaksi WHERE produk_id = ? LIMIT 1");
        $stmtCek->bind_param('i', $id);
        $stmtCek->execute();
        $stmtCek->store_result();
        if ($stmtCek->num_rows > 0) {
            $stmtCek->close();
            responseError('Produk tidak bisa dihapus karena sudah ada di riwayat transaksi.');
        }
        $stmtCek->close();

        // Cari dan Hapus file gambar fisiknya dari folder uploads
        $stmtImg = $conn->prepare("SELECT gambar FROM produk WHERE id = ?");
        $stmtImg->bind_param('i', $id);
        $stmtImg->execute();
        $resImg = $stmtImg->get_result()->fetch_assoc();
        if (!empty($resImg['gambar']) && file_exists($resImg['gambar'])) {
            @unlink($resImg['gambar']);
        }
        $stmtImg->close();

        // Hapus record dari database
        $stmt = $conn->prepare("DELETE FROM produk WHERE id = ?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute() || $stmt->affected_rows === 0) {
            responseError('Produk tidak ditemukan atau gagal dihapus.', 404);
        }
        $stmt->close();

        responseOk(null, 'Produk beserta gambarnya berhasil dihapus.');
        break;


    default:
        responseError('Metode HTTP tidak diizinkan.', 405);
}