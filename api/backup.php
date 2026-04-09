<?php
require_once __DIR__ . '/../auth.php';

// Allow via POST (admin UI) or GET with secret token (OVH cron)
$isAdminPost = isLoggedIn() && isAdmin() && $_SERVER['REQUEST_METHOD'] === 'POST';
$isCronGet = $_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['token'] ?? '') === BACKUP_TOKEN;

if (!$isAdminPost && !$isCronGet) {
    if ($isAdminPost !== false) {
        $csrf = $_POST['csrf_token'] ?? '';
        if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);
    }
    http_response_code(403);
    die('Accès refusé');
}

if ($isAdminPost) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);
}

// Create backup directory
if (!is_dir(BACKUP_PATH)) {
    mkdir(BACKUP_PATH, 0755, true);
}

$timestamp = date('Ymd_His');
$backupFile = BACKUP_PATH . '/keepnew_' . $timestamp . '.sqlite';

// Use PDO to do a clean backup
try {
    $db = getDB();
    // VACUUM INTO creates a clean defragmented copy
    $db->exec('VACUUM INTO "' . $backupFile . '"');
} catch (Exception $e) {
    // Fallback: simple copy
    if (!copy(DB_PATH, $backupFile)) {
        if ($isCronGet) { die('Backup failed: ' . $e->getMessage()); }
        jsonResponse(['error' => 'Sauvegarde échouée: ' . $e->getMessage()], 500);
    }
}

if (!file_exists($backupFile)) {
    if ($isCronGet) { die('Backup file not created'); }
    jsonResponse(['error' => 'Fichier de sauvegarde non créé'], 500);
}

// Clean up old backups (keep last 30)
$files = glob(BACKUP_PATH . '/keepnew_*.sqlite') ?: [];
usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
foreach (array_slice($files, BACKUP_KEEP_DAYS) as $old) {
    @unlink($old);
}

if ($isCronGet) {
    echo 'Backup OK: ' . basename($backupFile) . ' (' . round(filesize($backupFile) / 1024) . ' Ko)';
    exit;
}

jsonResponse([
    'success' => true,
    'filename' => basename($backupFile),
    'size_kb' => round(filesize($backupFile) / 1024),
]);
