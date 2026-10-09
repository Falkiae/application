<?php
require_once __DIR__ . '/../auth.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$db     = getDB();
$action = trim($_POST['action'] ?? '');

if ($action === 'create') {
    $clientNom       = trim($_POST['client_nom'] ?? '');
    $nettoyagesTotal = (int)($_POST['nettoyages_total'] ?? 0);
    $prixTotal       = (float)($_POST['prix_total'] ?? 0);
    $notes           = trim($_POST['notes'] ?? '');

    if (!$clientNom) jsonResponse(['error' => 'Nom du client requis'], 400);
    if ($nettoyagesTotal < 1) jsonResponse(['error' => 'Nombre de nettoyages invalide'], 400);

    // Get or create client
    $stmt = $db->prepare("SELECT id FROM clients WHERE nom = ?");
    $stmt->execute([$clientNom]);
    $client = $stmt->fetch();
    if ($client) {
        $clientId = $client['id'];
    } else {
        $db->prepare("INSERT INTO clients (nom) VALUES (?)")->execute([$clientNom]);
        $clientId = $db->lastInsertId();
    }

    $db->prepare("INSERT INTO abonnements (client_id, nettoyages_total, prix_total, notes) VALUES (?,?,?,?)")
       ->execute([$clientId, $nettoyagesTotal, $prixTotal, $notes ?: null]);

    jsonResponse(['success' => true, 'id' => (int)$db->lastInsertId()]);

} elseif ($action === 'update') {
    $id              = (int)($_POST['id'] ?? 0);
    $nettoyagesTotal = (int)($_POST['nettoyages_total'] ?? 0);
    $prixTotal       = (float)($_POST['prix_total'] ?? 0);
    $notes           = trim($_POST['notes'] ?? '');

    if (!$id) jsonResponse(['error' => 'ID manquant'], 400);
    if ($nettoyagesTotal < 1) jsonResponse(['error' => 'Nombre invalide'], 400);

    $db->prepare("UPDATE abonnements SET nettoyages_total=?, prix_total=?, notes=? WHERE id=?")
       ->execute([$nettoyagesTotal, $prixTotal, $notes ?: null, $id]);

    jsonResponse(['success' => true]);

} else {
    jsonResponse(['error' => 'Action invalide'], 400);
}
