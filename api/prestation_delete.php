<?php
require_once __DIR__ . '/../auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$id = (int)($_POST['id'] ?? 0);
if (!$id) jsonResponse(['error' => 'ID manquant'], 400);

$db = getDB();
$stmt = $db->prepare("SELECT s.*, ct.label as type_label FROM services s JOIN cleaning_types ct ON ct.id=s.type_nettoyage_id WHERE s.id = ?");
$stmt->execute([$id]);
$service = $stmt->fetch();

if (!$service) jsonResponse(['error' => 'Prestation introuvable'], 404);

// Only admin or owner can delete
if (!isAdmin() && $service['technician_id'] != currentUserId()) {
    jsonResponse(['error' => 'Accès refusé'], 403);
}

// Save history before deletion
addServiceHistory($id, currentUserId(), 'delete', [
    'date' => $service['date'],
    'type_nettoyage_id' => $service['type_nettoyage_id'],
    'lieu' => $service['lieu'],
    'montant' => $service['montant'],
    'paiement' => $service['paiement'],
], null);

addNotification(currentUserId(), 'delete', $id,
    currentUserName() . " a supprimé une prestation (" . $service['type_label'] . ", " . number_format($service['montant'], 2, ',', '.') . " €)"
);

$db->prepare("DELETE FROM services WHERE id = ?")->execute([$id]);

jsonResponse(['success' => true]);
