<?php
require_once __DIR__ . '/../auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) jsonResponse(['error' => 'Token invalide'], 403);

$mode = $_POST['mode'] ?? '';
if (!in_array($mode, ['dry_run', 'review_only', 'active'], true)) {
    jsonResponse(['error' => 'Mode invalide'], 400);
}

setSetting('sync_mode', $mode);

// Log the mode change in sync_events pour audit
$db = getDB();
$db->prepare("
    INSERT INTO sync_events (source, event_type, action, reason)
    VALUES ('local', 'sync_mode.changed', 'processed', ?)
")->execute(["Mode changé en « $mode » par admin #" . currentUserId()]);

jsonResponse(['success' => true, 'mode' => $mode]);
