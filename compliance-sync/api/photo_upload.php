<?php
require_once __DIR__ . '/../auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['error' => 'Aucun fichier reçu'], 400);
}

$file = $_FILES['photo'];

// Size check (10MB max)
if ($file['size'] > 10 * 1024 * 1024) {
    jsonResponse(['error' => 'Fichier trop volumineux (max 10 Mo)'], 400);
}

// Validate image using GD (reads magic bytes)
if (!function_exists('getimagesize')) {
    jsonResponse(['error' => 'Extension GD non disponible'], 500);
}

$imageInfo = @getimagesize($file['tmp_name']);
if (!$imageInfo || !in_array($imageInfo['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'])) {
    jsonResponse(['error' => 'Format d\'image invalide'], 400);
}

// Create upload directory
$year  = date('Y');
$month = date('m');
$dir   = UPLOAD_PATH . "/$year/$month/";
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    jsonResponse(['error' => 'Impossible de créer le dossier d\'upload'], 500);
}

// Generate unique filename
$uuid = bin2hex(random_bytes(16));
$filename = $uuid . '.jpg';
$destPath = $dir . $filename;

// Re-encode via GD to strip EXIF data and normalize format
$image = false;
switch ($imageInfo['mime']) {
    case 'image/jpeg': $image = @imagecreatefromjpeg($file['tmp_name']); break;
    case 'image/png':  $image = @imagecreatefrompng($file['tmp_name']); break;
    case 'image/webp': $image = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false; break;
    default:
        // Try JPEG fallback
        $image = @imagecreatefromjpeg($file['tmp_name']);
}

if (!$image) {
    // Fallback: just move the file without re-encoding
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        jsonResponse(['error' => 'Erreur lors de la sauvegarde'], 500);
    }
} else {
    if (!imagejpeg($image, $destPath, 85)) {
        imagedestroy($image);
        jsonResponse(['error' => 'Erreur lors de la conversion'], 500);
    }
    imagedestroy($image);
}

// Return relative path (without leading slash)
$relativePath = "$year/$month/$filename";
jsonResponse(['success' => true, 'path' => $relativePath]);
