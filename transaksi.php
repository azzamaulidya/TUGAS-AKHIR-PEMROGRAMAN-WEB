<?php
session_start();
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/koneksi.php';

requireLogin();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    case 'keranjang':
        // Simpan keranjang di session karena tabel transaksi tidak ada kolom status
        $keranjang = $_SESSION['keranjang'] ?? [];
        $total     = array_sum(array_column($keranjang, 'subtotal'));
        responseOk(['items' => array_values($keranjang), 'total' => $total]);

    case 'tambah_item':
        if ($method !== 'POST') responseError('Method tidak diizinkan.', 405);

        $body     = getJsonBody();
        $idProduk = (int)($body['id_barang'] ?? 0);
        $qty      = (int)($body['qty']       ?? 1);

        if (!$idProduk || $qty < 1) responseError('ID produk dan qty wajib diisi.');

        $stmt = $conn->prepare("SELECT id, kode_produk, nama_produk, harga, stok FROM produk WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $idProduk);
        $stmt->execute();
        $produk = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$produk) responseError('Produk tidak ditemukan.', 404);
        if ($produk['stok'] < $qty) responseError("Stok tidak mencukupi. Tersedia: {$produk['stok']} pcs.");

        // Simpan ke session keranjang
        if (!isset($_SESSION['keranjang'])) $_SESSION['keranjang'] = [];

        if (isset($_SESSION['keranjang'][$idProduk])) {
            $qtyBaru = $_SESSION['keranjang'][$idProduk]['qty'] + $qty;
            if ($produk['stok'] < $qtyBaru) responseError("Stok tidak mencukupi. Tersedia: {$produk['stok']} pcs.");
            $_SESSION['keranjang'][$idProduk]['qty']      = $qtyBaru;
            $_SESSION['keranjang'][$idProduk]['subtotal'] = $produk['harga'] * $qtyBaru;
        } else {
            $_SESSION['keranjang'][$idProduk] = [
                'id_detail'   => $idProduk,
                'id_barang'   => $produk['id'],
                'kode_barang' => $produk['kode_produk'],
                'nama_barang' => $produk['nama_produk'],
                'harga'       => $produk['harga'],
                'qty'         => $qty,
                'subtotal'    => $produk['harga'] * $qty
            ];
        }

        responseOk(null, 'Produk berhasil ditambahkan ke keranjang.');

    case 'hapus_item':
        if ($method !== 'DELETE') responseError('Method tidak diizinkan.', 405);

        $idProduk = (int)($_GET['id'] ?? 0);
        if (!$idProduk) responseError('ID produk wajib disertakan.');

        if (!isset($_SESSION['keranjang'][$idProduk])) responseError('Item tidak ditemukan di keranjang.', 404);

        unset($_SESSION['keranjang'][$idProduk]);
        responseOk(null, 'Item berhasil dihapus dari keranjang.');

    case 'checkout':
        if ($method !== 'POST') responseError('Method tidak diizinkan.', 405);

        $keranjang = $_SESSION['keranjang'] ?? [];
        if (empty($keranjang)) responseError('Keranjang kosong, tidak bisa checkout.');

        $namaKasir = $_SESSION['nama_user'];
        $total     = array_sum(array_column($keranjang, 'subtotal'));

        $conn->begin_transaction();
        try {
            // Simpan header transaksi
            $stmt = $conn->prepare("INSERT INTO transaksi (total, kasir) VALUES (?, ?)");
            $stmt->bind_param('is', $total, $namaKasir);
            $stmt->execute();
            $idTrx = $conn->insert_id;
            $stmt->close();

            // Simpan detail & kurangi stok
            foreach ($keranjang as $item) {
                // Cek stok terbaru
                $stmt = $conn->prepare("SELECT stok FROM produk WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $item['id_barang']);
                $stmt->execute();
                $produk = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($produk['stok'] < $item['qty']) {
                    $conn->rollback();
                    responseError("Stok {$item['nama_barang']} tidak mencukupi saat checkout.");
                }

                // Insert detail
                $stmt = $conn->prepare("INSERT INTO detail_transaksi (transaksi_id, produk_id, qty, subtotal) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('iiii', $idTrx, $item['id_barang'], $item['qty'], $item['subtotal']);
                $stmt->execute();
                $stmt->close();

                // Kurangi stok
                $stokBaru = $produk['stok'] - $item['qty'];
                $stmt = $conn->prepare("UPDATE produk SET stok = ? WHERE id = ?");
                $stmt->bind_param('ii', $stokBaru, $item['id_barang']);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();

            // Kosongkan keranjang session
            $_SESSION['keranjang'] = [];

            responseOk(['id_transaksi' => $idTrx, 'total_bayar' => $total], 'Transaksi berhasil.');

        } catch (Exception $e) {
            $conn->rollback();
            responseError('Transaksi gagal: ' . $e->getMessage(), 500);
        }

    case 'laporan_harian':
        $tanggal = $_GET['tanggal'] ?? date('Y-m-d');

        $stmt = $conn->prepare("
            SELECT t.id AS id_transaksi,
                   t.tanggal, t.kasir,
                   t.total AS total_bayar,
                   COUNT(d.id) AS jumlah_item
            FROM transaksi t
            JOIN detail_transaksi d ON d.transaksi_id = t.id
            WHERE DATE(t.tanggal) = ?
            GROUP BY t.id
            ORDER BY t.id DESC
        ");
        $stmt->bind_param('s', $tanggal);
        $stmt->execute();
        $laporan = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        responseOk([
            'tanggal'      => $tanggal,
            'laporan'      => $laporan,
            'total_harian' => array_sum(array_column($laporan, 'total_bayar'))
        ]);

    default:
        responseError('Action tidak dikenali.', 404);
}