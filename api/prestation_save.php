<?php
require_once __DIR__ . '/../auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$db = getDB();
$action = $_POST['action'] ?? 'create';
$techId = currentUserId();

// Validate common fields
$date = trim($_POST['date'] ?? '');
$typeId = (int)($_POST['type_nettoyage_id'] ?? 0);
$lieu = trim($_POST['lieu'] ?? '');
$ticketTva = isset($_POST['ticket_tva']) ? (int)$_POST['ticket_tva'] : 0;
$paiement = trim($_POST['paiement'] ?? '');
$factureAFaire = isset($_POST['facture_a_faire']) ? (int)$_POST['facture_a_faire'] : 0;
$montant = (float)($_POST['montant'] ?? 0);
$notes = trim($_POST['notes'] ?? '');
$photoAvant = trim($_POST['photo_avant_path'] ?? '');
$photoApres = trim($_POST['photo_apres_path'] ?? '');
$factureEnvoyee = isset($_POST['facture_envoyee']) ? (int)$_POST['facture_envoyee'] : 0;

// Validate
if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jsonResponse(['error' => 'Date invalide'], 400);
if (!$typeId) jsonResponse(['error' => 'Type de nettoyage requis'], 400);
if (!in_array($lieu, ['domicile', 'atelier'])) jsonResponse(['error' => 'Lieu invalide'], 400);
if (!in_array($paiement, ['virement', 'cash', 'qrcode', 'facture'])) jsonResponse(['error' => 'Mode de paiement invalide'], 400);
if ($montant < 0) jsonResponse(['error' => 'Montant invalide'], 400);
if (strlen($notes) > 2000) jsonResponse(['error' => 'Notes trop longues'], 400);

// Sanitize photo paths (must be relative paths within uploads/)
function sanitizePhotoPath(?string $path): ?string {
    if (!$path || $path === '__deleted__') return null;
    $path = ltrim($path, '/');
    if (str_contains($path, '..')) return null;
    // Accept uploads/YYYY/MM/file.jpg, photos/..., or bare YYYY/MM/hex.jpg from photo_upload.php
    if (str_starts_with($path, 'uploads/') || str_starts_with($path, 'photos/')) return $path;
    if (preg_match('/^\d{4}\/\d{2}\/[a-f0-9]+\.jpg$/', $path)) return $path;
    return null;
}

if ($action === 'create') {
    $db->prepare("
        INSERT INTO services (technician_id, date, type_nettoyage_id, lieu, ticket_tva, paiement, facture_a_faire, facture_envoyee, montant, photo_avant, photo_apres, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $techId, $date, $typeId, $lieu, $ticketTva, $paiement, $factureAFaire, $factureEnvoyee, $montant,
        sanitizePhotoPath($photoAvant), sanitizePhotoPath($photoApres), $notes ?: null
    ]);
    $newId = (int)$db->lastInsertId();

    // History & notification
    $stmt = $db->prepare("SELECT ct.label FROM cleaning_types ct JOIN services s ON s.type_nettoyage_id=ct.id WHERE s.id=?");
    $stmt->execute([$newId]);
    $typeLabel = $stmt->fetchColumn() ?: 'Prestation';

    addServiceHistory($newId, $techId, 'create', null, [
        'date'=>$date,'type_nettoyage_id'=>$typeId,'lieu'=>$lieu,
        'ticket_tva'=>$ticketTva,'paiement'=>$paiement,'facture_a_faire'=>$factureAFaire,
        'montant'=>$montant,'notes'=>$notes
    ]);
    addNotification($techId, 'create', $newId,
        currentUserName() . " a ajouté une prestation (" . $typeLabel . ", " . number_format($montant, 2, ',', '.') . " €)"
    );

    jsonResponse(['success' => true, 'id' => $newId]);

} elseif ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID manquant'], 400);

    // Fetch old values & check ownership
    $stmt = $db->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([$id]);
    $old = $stmt->fetch();
    if (!$old) jsonResponse(['error' => 'Prestation introuvable'], 404);
    if (!isAdmin() && $old['technician_id'] != $techId) jsonResponse(['error' => 'Accès refusé'], 403);

    // Handle photo deletion
    $newPhotoAvant = $photoAvant === '__deleted__' ? null : (sanitizePhotoPath($photoAvant) ?? $old['photo_avant']);
    $newPhotoApres = $photoApres === '__deleted__' ? null : (sanitizePhotoPath($photoApres) ?? $old['photo_apres']);

    $newValues = [
        'date'=>$date,'type_nettoyage_id'=>$typeId,'lieu'=>$lieu,
        'ticket_tva'=>$ticketTva,'paiement'=>$paiement,'facture_a_faire'=>$factureAFaire,
        'facture_envoyee'=>$factureEnvoyee,'montant'=>$montant,'notes'=>$notes,
        'photo_avant'=>$newPhotoAvant,'photo_apres'=>$newPhotoApres
    ];
    $oldValues = [
        'date'=>$old['date'],'type_nettoyage_id'=>$old['type_nettoyage_id'],'lieu'=>$old['lieu'],
        'ticket_tva'=>$old['ticket_tva'],'paiement'=>$old['paiement'],'facture_a_faire'=>$old['facture_a_faire'],
        'facture_envoyee'=>$old['facture_envoyee'] ?? 0,'montant'=>$old['montant'],'notes'=>$old['notes'],
        'photo_avant'=>$old['photo_avant'],'photo_apres'=>$old['photo_apres']
    ];

    $db->prepare("
        UPDATE services SET date=?, type_nettoyage_id=?, lieu=?, ticket_tva=?, paiement=?,
        facture_a_faire=?, facture_envoyee=?, montant=?, photo_avant=?, photo_apres=?, notes=?, updated_at=datetime('now','localtime')
        WHERE id=?
    ")->execute([$date, $typeId, $lieu, $ticketTva, $paiement, $factureAFaire, $factureEnvoyee, $montant, $newPhotoAvant, $newPhotoApres, $notes ?: null, $id]);

    // Fetch type label for notification
    $stmt = $db->prepare("SELECT label FROM cleaning_types WHERE id=?");
    $stmt->execute([$typeId]);
    $typeLabel = $stmt->fetchColumn() ?: 'Prestation';

    addServiceHistory($id, $techId, 'update', $oldValues, $newValues);
    addNotification($techId, 'update', $id,
        currentUserName() . " a modifié une prestation (" . $typeLabel . ", " . number_format($montant, 2, ',', '.') . " €)"
    );

    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Action invalide'], 400);
