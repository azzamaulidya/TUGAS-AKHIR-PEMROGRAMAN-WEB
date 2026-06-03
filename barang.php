<?php
session_start();
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/koneksi.php';

requireLogin();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        if (!empty($_GET['id'])) {
            $id   = (int) $_GET['id'];
            $stmt = $conn->prepare("
                SELECT id, kode_produk, nama_produk, harga, stok
                FROM produk WHERE id = ? LIMIT 1
            ");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $barang = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$barang) responseError('Barang tidak ditemukan.', 404);
            responseOk($barang);
        }

        if (!empty($_GET['cari'])) {
            $keyword = '%' . $_GET['cari'] . '%';
            $stmt = $conn->prepare("
                SELECT id, kode_produk, nama_produk, harga, stok
                FROM produk
                WHERE nama_produk LIKE ? AND stok > 0
                ORDER BY nama_produk ASC
            ");
            $stmt->bind_param('s', $keyword);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            responseOk($rows);
        }

        $result = $conn->query("
            SELECT id, kode_produk, nama_produk, harga, stok
            FROM produk ORDER BY nama_produk ASC
        ");
        responseOk($result->fetch_all(MYSQLI_ASSOC));

    case 'POST':
        $body  = getJsonBody();
        $kode  = trim($body['id']    ?? '');
        $nama  = trim($body['nama']  ?? '');
        $harga = (int)($body['harga'] ?? 0);
        $stok  = (int)($body['stok']  ?? 0);

        if (!$kode || !$nama || $harga <= 0 || $stok < 0) responseError('Semua field wajib diisi dengan benar.');

        $stmt = $conn->prepare("SELECT id FROM produk WHERE kode_produk = ? LIMIT 1");
        $stmt->bind_param('s', $kode);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) { $stmt->close(); responseError('Kode produk sudah digunakan.'); }
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO produk (kode_produk, nama_produk, harga, stok) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssii', $kode, $nama, $harga, $stok);
        if (!$stmt->execute()) responseError('Gagal menyimpan produk.', 500);
        $newId = $conn->insert_id;
        $stmt->close();

        responseOk(['id' => $newId], 'Produk berhasil ditambahkan.', 201);

    case 'PUT':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) responseError('ID produk wajib disertakan.');

        $body  = getJsonBody();
        $nama  = trim($body['nama']  ?? '');
        $harga = (int)($body['harga'] ?? 0);
        $stok  = (int)($body['stok']  ?? 0);

        if (!$nama || $harga <= 0 || $stok < 0) responseError('Nama, harga, dan stok wajib diisi.');

        $stmt = $conn->prepare("UPDATE produk SET nama_produk = ?, harga = ?, stok = ? WHERE id = ?");
        $stmt->bind_param('siii', $nama, $harga, $stok, $id);
        if (!$stmt->execute() || $stmt->affected_rows === 0) responseError('Produk tidak ditemukan.', 404);
        $stmt->close();

        responseOk(null, 'Produk berhasil diperbarui.');

    case 'DELETE':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) responseError('ID produk wajib disertakan.');

        $stmt = $conn->prepare("SELECT id FROM detail_transaksi WHERE produk_id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) { $stmt->close(); responseError('Produk tidak bisa dihapus, sudah ada di transaksi.'); }
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM produk WHERE id = ?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute() || $stmt->affected_rows === 0) responseError('Produk tidak ditemukan.', 404);
        $stmt->close();

        responseOk(null, 'Produk berhasil dihapus.');

    default:
        responseError('Method tidak diizinkan.', 405);
}