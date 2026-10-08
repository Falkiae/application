<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/compliance.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);

$csrf = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrf)) jsonResponse(['error' => 'Token invalide'], 403);

$db = getDB();
$action = $_POST['action'] ?? 'create';
$actorId = currentUserId();

$assignedTechId = (int)($_POST['technician_id'] ?? 0);
if (!$assignedTechId) $assignedTechId = $actorId;

if (!isAdmin() && $assignedTechId !== $actorId) {
    jsonResponse(['error' => 'Vous ne pouvez pas assigner une prestation à un autre technicien'], 403);
}

$stmt = $db->prepare("SELECT 1 FROM technicians WHERE id = ? AND active = 1");
$stmt->execute([$assignedTechId]);
if (!$stmt->fetchColumn()) jsonResponse(['error' => 'Technicien invalide'], 400);

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
    // Compliance: cannot create a prestation on an already-sealed day.
    if (complianceIsDateSealed($date)) {
        jsonResponse(['error' => 'La journée du ' . $date . ' est clôturée. Pour corriger une prestation existante, utilisez « Contrepasser ».'], 409);
    }

    // Compute compliance fields (VAT breakdown + sequential receipt number).
    $vatRate = COMPLIANCE_DEFAULT_VAT_RATE;
    $split   = complianceSplitVAT($montant, $vatRate);
    $year    = (int)substr($date, 0, 4);

    $db->beginTransaction();
    try {
        $receiptNo = complianceAllocReceiptNo('services', $year);
        $db->prepare("
            INSERT INTO services (technician_id, date, type_nettoyage_id, lieu, ticket_tva, paiement, facture_a_faire, facture_envoyee, montant, photo_avant, photo_apres, notes, receipt_no, vat_rate, montant_htva, montant_tva)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $assignedTechId, $date, $typeId, $lieu, $ticketTva, $paiement, $factureAFaire, $factureEnvoyee, $montant,
            sanitizePhotoPath($photoAvant), sanitizePhotoPath($photoApres), $notes ?: null,
            $receiptNo, $vatRate, $split['htva'], $split['tva']
        ]);
        $newId = (int)$db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        jsonResponse(['error' => 'Enregistrement impossible : ' . $e->getMessage()], 500);
    }

    // History & notification
    $stmt = $db->prepare("SELECT ct.label FROM cleaning_types ct JOIN services s ON s.type_nettoyage_id=ct.id WHERE s.id=?");
    $stmt->execute([$newId]);
    $typeLabel = $stmt->fetchColumn() ?: 'Prestation';

    addServiceHistory($newId, $actorId, 'create', null, [
        'technician_id'=>$assignedTechId,'date'=>$date,'type_nettoyage_id'=>$typeId,'lieu'=>$lieu,
        'ticket_tva'=>$ticketTva,'paiement'=>$paiement,'facture_a_faire'=>$factureAFaire,
        'facture_envoyee'=>$factureEnvoyee,
        'montant'=>$montant,'notes'=>$notes,
        'photo_avant'=>sanitizePhotoPath($photoAvant),'photo_apres'=>sanitizePhotoPath($photoApres),
        'receipt_no'=>$receiptNo,'vat_rate'=>$vatRate,
        'montant_htva'=>$split['htva'],'montant_tva'=>$split['tva']
    ]);
    addNotification($actorId, 'create', $newId,
        currentUserName() . " a ajouté une prestation (" . $typeLabel . ", " . number_format($montant, 2, ',', '.') . " €) — $receiptNo"
    );

    jsonResponse(['success' => true, 'id' => $newId, 'receipt_no' => $receiptNo]);

} elseif ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'ID manquant'], 400);
    $reason = trim($_POST['reason'] ?? '');
    if ($reason === '')         jsonResponse(['error' => 'Un motif de modification est obligatoire.'], 400);
    if (strlen($reason) > 500)  jsonResponse(['error' => 'Motif trop long (max 500 caractères).'], 400);

    // Fetch old values & check ownership
    $stmt = $db->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([$id]);
    $old = $stmt->fetch();
    if (!$old) jsonResponse(['error' => 'Prestation introuvable'], 404);
    if (!isAdmin() && $old['technician_id'] != $actorId) jsonResponse(['error' => 'Accès refusé'], 403);
    if ($old['cancelled_at'] !== null) jsonResponse(['error' => 'Cette prestation est annulée, elle ne peut plus être modifiée.'], 409);

    // Compliance: if the original date is sealed, only non-accounting fields may be edited.
    // Accounting fields require a contrepassation (api/prestation_reverse.php).
    if (complianceIsDateSealed($old['date'])) {
        $accountingDiffs = [];
        if ((int)$assignedTechId !== (int)$old['technician_id'])                $accountingDiffs[] = 'technicien';
        if ($date !== $old['date'])                                             $accountingDiffs[] = 'date';
        if ((int)$typeId !== (int)$old['type_nettoyage_id'])                    $accountingDiffs[] = 'type';
        if ($lieu !== $old['lieu'])                                             $accountingDiffs[] = 'lieu';
        if ((int)$ticketTva !== (int)$old['ticket_tva'])                        $accountingDiffs[] = 'ticket TVA';
        if ($paiement !== $old['paiement'])                                     $accountingDiffs[] = 'mode de paiement';
        if ((int)$factureAFaire !== (int)$old['facture_a_faire'])               $accountingDiffs[] = 'facture à faire';
        if (abs((float)$montant - (float)$old['montant']) > 0.001)              $accountingDiffs[] = 'montant';
        if (isset($_POST['vat_rate']) && (float)$_POST['vat_rate'] !== (float)$old['vat_rate']) $accountingDiffs[] = 'taux TVA';
        if (!empty($accountingDiffs)) {
            jsonResponse([
                'error' => 'La journée du ' . $old['date'] . ' est clôturée. Les champs comptables (' . implode(', ', $accountingDiffs) . ') ne peuvent plus être modifiés directement. Utilisez « Contrepasser » pour créer une correction.',
                'sealed' => true,
                'date'   => $old['date'],
            ], 409);
        }
    }

    // Handle photo deletion
    $newPhotoAvant = $photoAvant === '__deleted__' ? null : (sanitizePhotoPath($photoAvant) ?? $old['photo_avant']);
    $newPhotoApres = $photoApres === '__deleted__' ? null : (sanitizePhotoPath($photoApres) ?? $old['photo_apres']);

    // Recompute VAT breakdown — keep stored vat_rate unless admin sent a different one.
    $vatRate = isset($_POST['vat_rate']) && $_POST['vat_rate'] !== ''
        ? (float)$_POST['vat_rate']
        : (float)($old['vat_rate'] ?? COMPLIANCE_DEFAULT_VAT_RATE);
    $split = complianceSplitVAT($montant, $vatRate);

    $newValues = [
        'technician_id'=>$assignedTechId,'date'=>$date,'type_nettoyage_id'=>$typeId,'lieu'=>$lieu,
        'ticket_tva'=>$ticketTva,'paiement'=>$paiement,'facture_a_faire'=>$factureAFaire,
        'facture_envoyee'=>$factureEnvoyee,'montant'=>$montant,'notes'=>$notes,
        'photo_avant'=>$newPhotoAvant,'photo_apres'=>$newPhotoApres,
        'vat_rate'=>$vatRate,'montant_htva'=>$split['htva'],'montant_tva'=>$split['tva']
    ];
    $oldValues = [
        'technician_id'=>$old['technician_id'],'date'=>$old['date'],'type_nettoyage_id'=>$old['type_nettoyage_id'],'lieu'=>$old['lieu'],
        'ticket_tva'=>$old['ticket_tva'],'paiement'=>$old['paiement'],'facture_a_faire'=>$old['facture_a_faire'],
        'facture_envoyee'=>$old['facture_envoyee'] ?? 0,'montant'=>$old['montant'],'notes'=>$old['notes'],
        'photo_avant'=>$old['photo_avant'],'photo_apres'=>$old['photo_apres'],
        'vat_rate'=>$old['vat_rate'],'montant_htva'=>$old['montant_htva'],'montant_tva'=>$old['montant_tva']
    ];

    // receipt_no is IMMUTABLE and never part of the SET list.
    $db->prepare("
        UPDATE services SET technician_id=?, date=?, type_nettoyage_id=?, lieu=?, ticket_tva=?, paiement=?,
        facture_a_faire=?, facture_envoyee=?, montant=?, photo_avant=?, photo_apres=?, notes=?,
        vat_rate=?, montant_htva=?, montant_tva=?, updated_at=datetime('now','localtime')
        WHERE id=?
    ")->execute([$assignedTechId, $date, $typeId, $lieu, $ticketTva, $paiement, $factureAFaire, $factureEnvoyee, $montant,
                 $newPhotoAvant, $newPhotoApres, $notes ?: null,
                 $vatRate, $split['htva'], $split['tva'],
                 $id]);

    $stmt = $db->prepare("SELECT label FROM cleaning_types WHERE id=?");
    $stmt->execute([$typeId]);
    $typeLabel = $stmt->fetchColumn() ?: 'Prestation';

    addServiceHistory($id, $actorId, 'update', $oldValues, $newValues, $reason);
    addNotification($actorId, 'update', $id,
        currentUserName() . " a modifié la prestation " . $old['receipt_no'] . " (" . $typeLabel . ")"
    );

    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Action invalide'], 400);
