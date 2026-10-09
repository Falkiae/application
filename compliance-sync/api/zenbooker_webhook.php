<?php
/**
 * Zenbooker webhook endpoint (compliance-sync sandbox).
 *
 * Reçoit TOUS les événements Zenbooker (job.created, job.completed, job.canceled, …).
 * Comportement par défaut : log + dry_run (aucun appel sortant vers Odoo).
 *
 * Filtre de sécurité : seuls les bookings dont le client/notes contient un marqueur
 * « test » sont considérés comme traités. Les vraies réservations réelles sont
 * reçues, loguées pour inspection, mais JAMAIS actionnées.
 *
 * Mode : setting 'sync_mode' = 'dry_run' (défaut) | 'review_only' | 'active'
 *   - dry_run    : log tout, zéro appel sortant
 *   - review_only: filtre test + enqueue pour approbation manuelle (pas d'Odoo auto)
 *   - active     : filtre test + push vers Odoo en DRAFT (jamais validé ni envoyé)
 *
 * Pas d'authentification requise (webhook public), mais le secret est vérifié
 * via le header X-Zenbooker-Signature si fourni.
 */

require_once __DIR__ . '/../auth.php';

$db = getDB();

// Lire le payload brut
$rawBody = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];
$eventType = $_SERVER['HTTP_X_ZENBOOKER_EVENT'] ?? ($headers['X-Zenbooker-Event'] ?? 'unknown');

// Early-exit robustesse : si le body n'est pas du JSON valide, on log et on OK
$payload = json_decode($rawBody, true);
if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
    logSyncEvent($db, 'zenbooker', $eventType, null, 'error',
        'Invalid JSON: ' . json_last_error_msg(), ['raw' => substr($rawBody, 0, 500)]);
    http_response_code(200);  // on OK quand même sinon Zenbooker va retry en boucle
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

$externalId = $payload['id'] ?? $payload['job']['id'] ?? $payload['data']['id'] ?? null;

// Lire le mode courant (dry_run par défaut)
$syncMode = getSetting('sync_mode', 'dry_run');

// --------- 1) MODE DRY RUN : on logue tout et on ne fait RIEN d'autre ---------
if ($syncMode === 'dry_run') {
    logSyncEvent($db, 'zenbooker', $eventType, $externalId, 'dry_run',
        'Mode dry_run actif — aucun traitement, log uniquement', $payload);
    http_response_code(200);
    echo json_encode(['ok' => true, 'mode' => 'dry_run']);
    exit;
}

// --------- 2) Filtre test booking (whitelist) ---------
$isTestBooking = detectTestBooking($payload);
if (!$isTestBooking) {
    logSyncEvent($db, 'zenbooker', $eventType, $externalId, 'ignored',
        'Pas un booking de test (filtre whitelist)', $payload);
    http_response_code(200);
    echo json_encode(['ok' => true, 'ignored' => true, 'reason' => 'not a test booking']);
    exit;
}

// --------- 3) MODE REVIEW ONLY : enqueue pour approbation manuelle ---------
if ($syncMode === 'review_only') {
    logSyncEvent($db, 'zenbooker', $eventType, $externalId, 'pending_review',
        'Booking de test détecté — en attente d\'approbation admin', $payload);
    http_response_code(200);
    echo json_encode(['ok' => true, 'pending_review' => true]);
    exit;
}

// --------- 4) MODE ACTIVE : traitement complet (vers Odoo en DRAFT) ---------
// À IMPLÉMENTER dans la prochaine phase (après validation dry_run).
// Pour l'instant on log en « pending_review » même en mode active, pour sécurité.
logSyncEvent($db, 'zenbooker', $eventType, $externalId, 'pending_review',
    'Mode active mais traitement complet non encore implémenté — mise en attente', $payload);
http_response_code(200);
echo json_encode(['ok' => true, 'mode' => 'active', 'note' => 'implementation pending']);
exit;


// ─── Helpers ───────────────────────────────────────────────────────────

function detectTestBooking(array $payload): bool {
    // 3 chemins pour reconnaître un booking de test
    $customer = $payload['customer'] ?? $payload['job']['customer'] ?? [];
    $notes = $payload['notes'] ?? $payload['job']['notes'] ?? '';

    $name  = strtolower($customer['first_name'] ?? '') . ' ' . strtolower($customer['last_name'] ?? '');
    $email = strtolower($customer['email'] ?? '');

    if (strpos($name, 'test') !== false) return true;
    if (strpos($email, 'sync-test@') !== false) return true;
    if (stripos($notes, '[SYNC-TEST]') !== false) return true;
    return false;
}

function logSyncEvent(PDO $db, string $source, string $eventType, ?string $externalId,
                     string $action, ?string $reason, ?array $payload): void {
    try {
        $stmt = $db->prepare("
            INSERT INTO sync_events (source, event_type, external_id, action, reason, payload_json)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $source,
            $eventType,
            $externalId,
            $action,
            $reason,
            $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        error_log('Sync event log failed: ' . $e->getMessage());
    }
}
