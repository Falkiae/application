<?php
require_once __DIR__ . '/../auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$db = getDB();
$id = (int)($_POST['id'] ?? 0);

// Toggle active
if (isset($_POST['active']) && $id) {
    $active = (int)$_POST['active'];
    $db->prepare("UPDATE cleaning_types SET active=? WHERE id=?")->execute([$active, $id]);
    jsonResponse(['success' => true]);
}

// Save type
$label = trim($_POST['label'] ?? '');
if (!$label || strlen($label) > 100) jsonResponse(['error' => 'Libellé invalide'], 400);

if ($id) {
    try {
        $db->prepare("UPDATE cleaning_types SET label=? WHERE id=?")->execute([$label, $id]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) jsonResponse(['error' => 'Ce libellé existe déjà'], 409);
        jsonResponse(['error' => 'Erreur'], 500);
    }
} else {
    $maxOrder = $db->query("SELECT COALESCE(MAX(sort_order),0) FROM cleaning_types")->fetchColumn();
    try {
        $db->prepare("INSERT INTO cleaning_types (label, sort_order) VALUES (?, ?)")
           ->execute([$label, $maxOrder + 1]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) jsonResponse(['error' => 'Ce libellé existe déjà'], 409);
        jsonResponse(['error' => 'Erreur'], 500);
    }
}

jsonResponse(['success' => true]);
