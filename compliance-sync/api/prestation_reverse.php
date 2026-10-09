<?php
/**
 * Contrepassation d'une prestation (mécanisme légal belge).
 *
 * POST params:
 *   id             (int)      — id de la prestation originale à contrepasser
 *   mode           (string)   — 'cancel' (annulation sèche) ou 'supersede' (correction)
 *   reason         (string)   — obligatoire, 500 chars max
 *   Si mode='supersede', fournir aussi les champs d'une update (date, montant, …)
 *   NOTE: la nouvelle date est TOUJOURS today — la contrepassation s'inscrit
 *   dans la journée courante, pas rétroactivement.
 *
 * Résultat : {success, cancelled_id, annulation_id, correction_id|null,
 *             annulation_receipt, correction_receipt|null}
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

// Ownership check
$db = getDB();
$stmt = $db->prepare("SELECT technician_id FROM services WHERE id = ?");
$stmt->execute([$id]);
$ownerTech = (int)$stmt->fetchColumn();
if (!$ownerTech) jsonResponse(['error' => 'Prestation introuvable'], 404);
if (!isAdmin() && $ownerTech !== currentUserId()) jsonResponse(['error' => 'Accès refusé'], 403);

$newValues = null;
if ($mode === 'supersede') {
    // Validate the correction payload (same shape as prestation_save.php update)
    $cDate = trim($_POST['date'] ?? '');
    if (!$cDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $cDate)) jsonResponse(['error' => 'Date invalide dans la correction'], 400);
    $cTypeId = (int)($_POST['type_nettoyage_id'] ?? 0);
    if (!$cTypeId) jsonResponse(['error' => 'Type de nettoyage requis'], 400);
    $cLieu = trim($_POST['lieu'] ?? '');
    if (!in_array($cLieu, ['domicile', 'atelier'], true)) jsonResponse(['error' => 'Lieu invalide'], 400);
    $cPaiement = trim($_POST['paiement'] ?? '');
    if (!in_array($cPaiement, ['virement', 'cash', 'qrcode', 'facture'], true)) jsonResponse(['error' => 'Mode de paiement invalide'], 400);
    $cMontant = (float)($_POST['montant'] ?? 0);
    if ($cMontant < 0) jsonResponse(['error' => 'Montant invalide'], 400);

    $newValues = [
        'technician_id'     => (int)($_POST['technician_id'] ?? $ownerTech),
        'date'              => $cDate,          // informational — ReverseEntry forces today anyway
        'type_nettoyage_id' => $cTypeId,
        'lieu'              => $cLieu,
        'ticket_tva'        => (int)($_POST['ticket_tva'] ?? 0),
        'paiement'          => $cPaiement,
        'facture_a_faire'   => (int)($_POST['facture_a_faire'] ?? 0),
        'facture_envoyee'   => (int)($_POST['facture_envoyee'] ?? 0),
        'montant'           => $cMontant,
        'vat_rate'          => isset($_POST['vat_rate']) && $_POST['vat_rate'] !== '' ? (float)$_POST['vat_rate'] : null,
        'notes'             => trim($_POST['notes'] ?? '') ?: null,
    ];
}

try {
    $res = complianceReverseServiceEntry($id, $reason, $mode, $newValues);
} catch (Throwable $e) {
    jsonResponse(['error' => $e->getMessage()], 409);
}

addNotification(currentUserId(), 'update', $id,
    currentUserName() . " a " . ($mode === 'cancel' ? 'annulé' : 'corrigé') . " la prestation (motif : $reason)"
);

jsonResponse(['success' => true] + $res);
