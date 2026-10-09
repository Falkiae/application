<?php
/**
 * Contrepassation d'un mouvement de caisse. Symétrique à prestation_reverse.php.
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/compliance.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) jsonResponse(['error' => 'Token invalide'], 403);

$id     = (int)($_POST['id'] ?? 0);
$mode   = trim($_POST['mode'] ?? '');
$reason = trim($_POST['reason'] ?? '');

if (!$id) jsonResponse(['error' => 'ID manquant'], 400);
if (!in_array($mode, ['cancel', 'supersede'], true)) jsonResponse(['error' => 'Mode invalide'], 400);
if ($reason === '') jsonResponse(['error' => 'Un motif est obligatoire.'], 400);
if (strlen($reason) > 500) jsonResponse(['error' => 'Motif trop long (max 500 caractères).'], 400);

$db = getDB();
$stmt = $db->prepare("SELECT technician_id FROM cash_movements WHERE id = ?");
$stmt->execute([$id]);
$ownerTech = (int)$stmt->fetchColumn();
if (!$ownerTech) jsonResponse(['error' => 'Mouvement introuvable'], 404);
if (!isAdmin() && $ownerTech !== currentUserId()) jsonResponse(['error' => 'Accès refusé'], 403);

$newValues = null;
if ($mode === 'supersede') {
    $cType = trim($_POST['type'] ?? '');
    if (!in_array($cType, ['initial', 'depot_banque', 'achat_liquide', 'note'], true)) jsonResponse(['error' => 'Type invalide'], 400);
    $cMontant = (float)($_POST['montant'] ?? 0);
    if ($cMontant <= 0) jsonResponse(['error' => 'Montant invalide'], 400);
    $newValues = [
        'technician_id' => (int)($_POST['technician_id'] ?? $ownerTech),
        'type'          => $cType,
        'montant'       => $cMontant,
        'notes'         => trim($_POST['notes'] ?? '') ?: null,
    ];
}

try {
    $res = complianceReverseCashEntry($id, $reason, $mode, $newValues);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 409);
}

jsonResponse(['success' => true] + $res);
