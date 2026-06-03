<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function responseOk($data = null, string $message = 'Berhasil', int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['status' => 'success', 'message' => $message, 'data' => $data]);
    exit();
}

function responseError(string $message = 'Terjadi kesalahan', int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message, 'data' => null]);
    exit();
}

function getJsonBody(): array
{
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function requireLogin(): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['id_user'])) {
        responseError('Unauthorized. Silakan login terlebih dahulu.', 401);
    }
}