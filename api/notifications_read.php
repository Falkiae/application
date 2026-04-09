<?php
require_once __DIR__ . '/../auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

$db = getDB();
$userId = currentUserId();
$ids = $_POST['ids'] ?? 'all';

if ($ids === 'all') {
    $stmt = $db->query("SELECT id, read_by FROM notifications");
} else {
    $idList = array_filter(array_map('intval', explode(',', $ids)));
    if (empty($idList)) jsonResponse(['success' => true]);
    $placeholders = implode(',', array_fill(0, count($idList), '?'));
    $stmt = $db->prepare("SELECT id, read_by FROM notifications WHERE id IN ($placeholders)");
    $stmt->execute($idList);
}

$updateStmt = $db->prepare("UPDATE notifications SET read_by=? WHERE id=?");
foreach ($stmt->fetchAll() as $n) {
    $readBy = json_decode($n['read_by'] ?? '[]', true);
    if (!in_array($userId, $readBy)) {
        $readBy[] = $userId;
        $updateStmt->execute([json_encode($readBy), $n['id']]);
    }
}

jsonResponse(['success' => true]);
