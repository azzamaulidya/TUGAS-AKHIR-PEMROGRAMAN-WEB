<?php
ini_set('session.cookie_path', '/');
ini_set('session.cookie_domain', '');
session_save_path('C:/xampp/tmp');
session_start();
require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/koneksi.php';

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') responseError('Method tidak diizinkan.', 405);

        $body     = getJsonBody();
        $nama     = trim($body['username'] ?? '');
        $password = trim($body['password'] ?? '');

        if (!$nama || !$password) responseError('Username dan password wajib diisi.');

        $stmt = $conn->prepare("SELECT id, nama, password FROM users WHERE nama = ? LIMIT 1");
        $stmt->bind_param('s', $nama);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) responseError('Username tidak ditemukan.', 401);

        $valid = password_verify($password, $user['password']) || $password === $user['password'];
        if (!$valid) responseError('Password salah.', 401);

        $_SESSION['id_user']   = $user['id'];
        $_SESSION['nama_user'] = $user['nama'];
        $_SESSION['nama_level'] = 'Kasir';

        responseOk([
            'id_user'    => $user['id'],
            'username'   => $user['nama'],
            'nama_user'  => $user['nama'],
            'nama_level' => 'Kasir'
        ], 'Login berhasil.');

    case 'registrasi':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') responseError('Method tidak diizinkan.', 405);

        $body       = getJsonBody();
        $nama       = trim($body['username']            ?? '');
        $password   = trim($body['password']            ?? '');
        $konfirmasi = trim($body['konfirmasi_password'] ?? '');

        if (!$nama || !$password || !$konfirmasi) responseError('Semua field wajib diisi.');
        if (strlen($password) < 6) responseError('Password minimal 6 karakter.');
        if ($password !== $konfirmasi) responseError('Password dan konfirmasi tidak cocok.');

        $stmt = $conn->prepare("SELECT id FROM users WHERE nama = ? LIMIT 1");
        $stmt->bind_param('s', $nama);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) { $stmt->close(); responseError('Username sudah digunakan.'); }
        $stmt->close();

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO users (nama, password) VALUES (?, ?)");
        $stmt->bind_param('ss', $nama, $hash);
        if (!$stmt->execute()) responseError('Gagal menyimpan akun.', 500);
        $stmt->close();

        responseOk(null, 'Registrasi berhasil. Silakan login.', 201);

    case 'logout':
        $_SESSION = [];
        session_destroy();
        responseOk(null, 'Logout berhasil.');

    case 'cek_session':
        if (empty($_SESSION['id_user'])) responseError('Belum login.', 401);
        responseOk([
            'id_user'    => $_SESSION['id_user'],
            'username'   => $_SESSION['nama_user'],
            'nama_user'  => $_SESSION['nama_user'],
            'nama_level' => $_SESSION['nama_level']
        ], 'Session aktif.');

    default:
        responseError('Action tidak dikenali.', 404);
}