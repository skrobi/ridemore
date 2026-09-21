<?php
/* ============================================================================
   UPLOAD IMAGE - Prosty upload dla wyzwań i medali
   POST /api/manage/upload-image.php
   ============================================================================ */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// Auth - użyj standardowej funkcji requireAuth
$user = requireAuth();

// Sprawdź czy user ma uprawnienia (premium lub admin)
if ($user['status'] !== 'premium' && $user['role'] !== 'admin') {
    sendError('Brak uprawnień. Tylko użytkownicy premium i admin mogą uploadować obrazki.', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

if (!isset($_FILES['image'])) {
    sendError('Brak pliku');
}

$file = $_FILES['image'];
$type = $_POST['type'] ?? 'challenge'; // 'challenge' lub 'medal'

// Walidacja
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$maxSize = 5 * 1024 * 1024; // 5MB

if (!in_array($file['type'], $allowedTypes)) {
    sendError('Nieprawidłowy typ pliku. Dozwolone: JPG, PNG, GIF, WEBP');
}

if ($file['size'] > $maxSize) {
    sendError('Plik za duży. Maksymalnie 5MB');
}

if ($file['error'] !== UPLOAD_ERR_OK) {
    sendError('Błąd uploadu: ' . $file['error']);
}

// Bezpieczna nazwa pliku
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$filename = uniqid() . '_' . time() . '.' . $ext;

// Folder docelowy (bez 'public/')
$uploadDir = $type === 'medal' ? 'uploads/medals/' : 'uploads/challenges/';
$fullUploadDir = __DIR__ . '/../../' . $uploadDir;

// Utwórz folder jeśli nie istnieje
if (!is_dir($fullUploadDir)) {
    mkdir($fullUploadDir, 0755, true);
}

$uploadPath = $fullUploadDir . $filename;

// Przenieś plik
if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
    sendError('Nie udało się zapisać pliku');
}

// Zwróć RELATYWNY URL (bez base_url)
$relativeUrl = $uploadDir . $filename;

sendJSON(true, [
    'url' => $relativeUrl,  // uploads/challenges/xxx.jpg
    'filename' => $filename,
    'type' => $type
]);