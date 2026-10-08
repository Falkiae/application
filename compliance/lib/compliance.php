<?php
/**
 * Belgian "livre de recettes" compliance utilities.
 *
 * Three concerns are owned here:
 *  1. Sequential, immutable receipt numbers per fiscal year (receipt_no).
 *  2. Stored HTVA/TVA breakdown (default 21 %, extensible later).
 *  3. One-shot retroconformance of historical data on first access.
 *
 * Later phases will add:
 *  4. Daily close (daily_close table) and reverse-entry (contrepassation).
 */

const COMPLIANCE_DEFAULT_VAT_RATE = 21.0;
const COMPLIANCE_RETROCONF_FLAG  = 'compliance_retroconformed_at';

/* ─── Receipt numbering ──────────────────────────────────────────────────── */

/**
 * Allocate the next sequential receipt number for the given fiscal year.
 * Format: 'YYYY-NNNNN' for services, 'YYYY-CNNNN' for cash movements.
 *
 * Caller MUST hold an active transaction OR tolerate rare collisions on the
 * unique index — we rely on the unique index as the final arbiter.
 *
 * @param string $kind 'services' or 'cash_movements'
 * @param int    $year e.g. 2026
 */
function complianceAllocReceiptNo(string $kind, int $year): string {
    $db = getDB();
    $pattern = $year . ($kind === 'cash_movements' ? '-C%' : '-%');
    $table   = $kind === 'cash_movements' ? 'cash_movements' : 'services';

    // Extract numeric suffix from existing values matching this year's prefix.
    $sql = "SELECT MAX(CAST(SUBSTR(receipt_no, "
         . ($kind === 'cash_movements' ? "7" : "6")   // skip 'YYYY-C' or 'YYYY-'
         . ") AS INTEGER)) FROM $table WHERE receipt_no LIKE ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$pattern]);
    $max = (int)($stmt->fetchColumn() ?: 0);
    $next = $max + 1;

    return $kind === 'cash_movements'
        ? sprintf('%04d-C%04d', $year, $next)
        : sprintf('%04d-%05d', $year, $next);
}

/* ─── VAT breakdown ──────────────────────────────────────────────────────── */

/**
 * Compute HTVA + TVA from a TVAC amount at a given rate.
 * Returns ['htva' => float, 'tva' => float] rounded to the cent.
 */
function complianceSplitVAT(float $tvac, float $rate = COMPLIANCE_DEFAULT_VAT_RATE): array {
    if ($rate <= 0) {
        return ['htva' => round($tvac, 2), 'tva' => 0.0];
    }
    $htva = round($tvac / (1 + $rate / 100), 2);
    $tva  = round($tvac - $htva, 2);
    return ['htva' => $htva, 'tva' => $tva];
}

/* ─── Retroconformance (one-shot) ───────────────────────────────────────── */

/**
 * Retrofit historical rows:
 *  - Attribute receipt_no in chronological order (date ASC, created_at ASC, id ASC),
 *    grouped by year, for services AND cash_movements.
 *  - Compute and persist montant_htva + montant_tva at the default rate (21 %) for services.
 *  - Default vat_rate to 21 for services where NULL.
 *  - Seal daily_close for every past date (d < today) that has at least one service row.
 *
 * Idempotent: runs once, flagged in settings. Admin can trigger it manually too
 * (via the admin page link) but running twice is a no-op (does not touch rows
 * that already carry a receipt_no).
 *
 * Returns a summary dict for display.
 */
function complianceRetroconformHistoricalData(bool $force = false): array {
    $db = getDB();

    if (!$force && getSetting(COMPLIANCE_RETROCONF_FLAG) !== '') {
        return ['already_done' => true, 'at' => getSetting(COMPLIANCE_RETROCONF_FLAG)];
    }

    $db->beginTransaction();
    try {
        $svcAssigned = 0;
        $svcVatFilled = 0;
        $cashAssigned = 0;
        $daysClosed = 0;

        // Services: process rows missing receipt_no, grouped by fiscal year.
        $years = $db->query("
            SELECT DISTINCT strftime('%Y', date) AS y
            FROM services
            WHERE receipt_no IS NULL
            ORDER BY y ASC
        ")->fetchAll(PDO::FETCH_COLUMN);
        $upd = $db->prepare("UPDATE services SET receipt_no=? WHERE id=?");
        foreach ($years as $y) {
            $yearInt = (int)$y;
            // Current max within this year (should be 0 the first time, non-zero if partial retro)
            $max = (int)$db->query("SELECT COALESCE(MAX(CAST(SUBSTR(receipt_no,6) AS INTEGER)),0)
                                    FROM services WHERE receipt_no LIKE '$yearInt-%'")->fetchColumn();
            $rows = $db->prepare("
                SELECT id FROM services
                WHERE receipt_no IS NULL AND strftime('%Y', date) = ?
                ORDER BY date ASC, created_at ASC, id ASC
            ");
            $rows->execute([$y]);
            foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $sid) {
                $max++;
                $upd->execute([sprintf('%04d-%05d', $yearInt, $max), (int)$sid]);
                $svcAssigned++;
            }
        }

        // Services: fill vat_rate / montant_htva / montant_tva where missing.
        $missing = $db->query("
            SELECT id, montant FROM services
            WHERE montant_htva IS NULL OR montant_tva IS NULL OR vat_rate IS NULL
        ")->fetchAll();
        $vatUpd = $db->prepare("UPDATE services SET vat_rate=?, montant_htva=?, montant_tva=? WHERE id=?");
        foreach ($missing as $row) {
            $tvac = (float)$row['montant'];
            $split = complianceSplitVAT($tvac, COMPLIANCE_DEFAULT_VAT_RATE);
            $vatUpd->execute([COMPLIANCE_DEFAULT_VAT_RATE, $split['htva'], $split['tva'], (int)$row['id']]);
            $svcVatFilled++;
        }

        // Cash movements: receipt_no attribution per fiscal year.
        $cYears = $db->query("
            SELECT DISTINCT strftime('%Y', date) AS y
            FROM cash_movements
            WHERE receipt_no IS NULL
            ORDER BY y ASC
        ")->fetchAll(PDO::FETCH_COLUMN);
        $cUpd = $db->prepare("UPDATE cash_movements SET receipt_no=? WHERE id=?");
        foreach ($cYears as $y) {
            $yearInt = (int)$y;
            $max = (int)$db->query("SELECT COALESCE(MAX(CAST(SUBSTR(receipt_no,7) AS INTEGER)),0)
                                    FROM cash_movements WHERE receipt_no LIKE '$yearInt-C%'")->fetchColumn();
            $rows = $db->prepare("
                SELECT id FROM cash_movements
                WHERE receipt_no IS NULL AND strftime('%Y', date) = ?
                ORDER BY date ASC, created_at ASC, id ASC
            ");
            $rows->execute([$y]);
            foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                $max++;
                $cUpd->execute([sprintf('%04d-C%04d', $yearInt, $max), (int)$cid]);
                $cashAssigned++;
            }
        }

        // Daily close for every past date with at least one service row and no existing close.
        $today = date('Y-m-d');
        $dates = $db->prepare("
            SELECT DISTINCT date FROM services
            WHERE date < ? AND date NOT IN (SELECT date FROM daily_close)
            ORDER BY date ASC
        ");
        $dates->execute([$today]);
        $closeIns = $db->prepare("INSERT INTO daily_close (date, closed_by, totals_json) VALUES (?, NULL, ?)");
        foreach ($dates->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $totals = _complianceDayTotals($db, $d);
            $closeIns->execute([$d, json_encode($totals, JSON_UNESCAPED_UNICODE)]);
            $daysClosed++;
        }

        // ─── History backfill ────────────────────────────────────────────────
        // So the audit page shows a "Création — rétroconformité" entry for every
        // historical row. Entry's created_at is the row's own created_at so the
        // audit page keeps its chronological order honest; admin_id is NULL
        // (= system) and reason is explicit so a tax auditor can distinguish
        // real-time entries from retro-imported ones.
        $retroReason = 'Rétroconformité initiale (import depuis l\'ancienne base le ' . date('Y-m-d') . ')';

        $svcHistBackfilled = 0;
        $svcToBackfill = $db->query("
            SELECT s.id, s.technician_id, s.date, s.type_nettoyage_id, s.lieu, s.ticket_tva,
                   s.paiement, s.facture_a_faire, s.facture_envoyee, s.montant, s.notes,
                   s.photo_avant, s.photo_apres, s.receipt_no, s.vat_rate,
                   s.montant_htva, s.montant_tva, s.created_at
            FROM services s
            LEFT JOIN service_history sh ON sh.service_id = s.id AND sh.action = 'create'
            WHERE sh.id IS NULL
        ")->fetchAll();
        $svcHistIns = $db->prepare(
            "INSERT INTO service_history
             (service_id, technician_id, admin_id, action, new_values, reason, created_at)
             VALUES (?, ?, NULL, 'create', ?, ?, ?)"
        );
        foreach ($svcToBackfill as $s) {
            $newValues = [
                'technician_id'     => (int)$s['technician_id'],
                'date'              => $s['date'],
                'type_nettoyage_id' => (int)$s['type_nettoyage_id'],
                'lieu'              => $s['lieu'],
                'ticket_tva'        => (int)$s['ticket_tva'],
                'paiement'          => $s['paiement'],
                'facture_a_faire'   => (int)$s['facture_a_faire'],
                'facture_envoyee'   => (int)($s['facture_envoyee'] ?? 0),
                'montant'           => (float)$s['montant'],
                'montant_htva'      => (float)$s['montant_htva'],
                'montant_tva'       => (float)$s['montant_tva'],
                'vat_rate'          => (float)$s['vat_rate'],
                'receipt_no'        => $s['receipt_no'],
                'notes'             => $s['notes'],
                'photo_avant'       => $s['photo_avant'],
                'photo_apres'       => $s['photo_apres'],
            ];
            $svcHistIns->execute([
                (int)$s['id'],
                (int)$s['technician_id'],
                json_encode($newValues, JSON_UNESCAPED_UNICODE),
                $retroReason,
                $s['created_at'] ?: date('Y-m-d H:i:s'),
            ]);
            $svcHistBackfilled++;
        }

        $cashHistBackfilled = 0;
        $cashToBackfill = $db->query("
            SELECT cm.id, cm.technician_id, cm.type, cm.montant, cm.notes, cm.date,
                   cm.receipt_no, cm.created_at
            FROM cash_movements cm
            LEFT JOIN cash_history ch ON ch.cash_movement_id = cm.id AND ch.action = 'create'
            WHERE ch.id IS NULL
        ")->fetchAll();
        $cashHistIns = $db->prepare(
            "INSERT INTO cash_history
             (cash_movement_id, technician_id, admin_id, action, new_values, reason, created_at)
             VALUES (?, ?, NULL, 'create', ?, ?, ?)"
        );
        foreach ($cashToBackfill as $c) {
            $newValues = [
                'technician_id' => (int)$c['technician_id'],
                'type'          => $c['type'],
                'montant'       => (float)$c['montant'],
                'date'          => $c['date'],
                'notes'         => $c['notes'],
                'receipt_no'    => $c['receipt_no'],
            ];
            $cashHistIns->execute([
                (int)$c['id'],
                (int)$c['technician_id'],
                json_encode($newValues, JSON_UNESCAPED_UNICODE),
                $retroReason,
                $c['created_at'] ?: date('Y-m-d H:i:s'),
            ]);
            $cashHistBackfilled++;
        }

        setSetting(COMPLIANCE_RETROCONF_FLAG, date('Y-m-d H:i:s'));
        $db->commit();

        return [
            'already_done'          => false,
            'services_numbered'     => $svcAssigned,
            'services_vat_filled'   => $svcVatFilled,
            'cash_numbered'         => $cashAssigned,
            'days_closed'           => $daysClosed,
            'service_history_added' => $svcHistBackfilled,
            'cash_history_added'    => $cashHistBackfilled,
            'at'                    => date('Y-m-d H:i:s'),
        ];
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Internal: snapshot the totals of a day (active rows only).
 */
function _complianceDayTotals(PDO $db, string $date): array {
    $stmt = $db->prepare("
        SELECT COUNT(*) AS nb,
               COALESCE(SUM(montant),      0) AS tvac,
               COALESCE(SUM(montant_htva), 0) AS htva,
               COALESCE(SUM(montant_tva),  0) AS tva
        FROM services
        WHERE date = ? AND (cancelled_at IS NULL)
    ");
    $stmt->execute([$date]);
    $r = $stmt->fetch();

    // Breakdown per payment mode
    $modes = [];
    $m = $db->prepare("
        SELECT paiement, COALESCE(SUM(montant),0) AS total, COUNT(*) AS nb
        FROM services WHERE date = ? AND (cancelled_at IS NULL)
        GROUP BY paiement
    ");
    $m->execute([$date]);
    foreach ($m->fetchAll() as $row) {
        $modes[$row['paiement']] = ['total' => (float)$row['total'], 'nb' => (int)$row['nb']];
    }

    return [
        'nb'    => (int)$r['nb'],
        'tvac'  => round((float)$r['tvac'], 2),
        'htva'  => round((float)$r['htva'], 2),
        'tva'   => round((float)$r['tva'], 2),
        'modes' => $modes,
    ];
}

/* ─── Phase 2: daily close + reverse-entry (contrepassation) ─────────────── */

/**
 * Close every past date (d < today) that is not yet in daily_close AND that has
 * at least one row in services. System-driven when $byAdminId === null.
 *
 * Idempotent. Call this once per request (hooked in getDB()).
 * Returns number of days newly closed.
 */
function complianceCloseStaleDays(?int $byAdminId = null): int {
    $db = getDB();
    $today = date('Y-m-d');
    $stmt = $db->prepare("
        SELECT DISTINCT date FROM services
        WHERE date < ? AND date NOT IN (SELECT date FROM daily_close)
        ORDER BY date ASC
    ");
    $stmt->execute([$today]);
    $toClose = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$toClose) return 0;

    $ins = $db->prepare("INSERT INTO daily_close (date, closed_by, totals_json) VALUES (?, ?, ?)");
    $n = 0;
    foreach ($toClose as $d) {
        $totals = _complianceDayTotals($db, $d);
        $ins->execute([$d, $byAdminId, json_encode($totals, JSON_UNESCAPED_UNICODE)]);
        $n++;
    }
    return $n;
}

/**
 * Fast check: is a given 'YYYY-MM-DD' already sealed?
 */
function complianceIsDateSealed(string $date): bool {
    $stmt = getDB()->prepare("SELECT 1 FROM daily_close WHERE date = ?");
    $stmt->execute([$date]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Produce a reverse-entry (contrepassation) for a sealed row.
 *
 * $mode = 'cancel'     → marks original as cancelled, creates ONE new "annulation" row
 *                        with mirrored (negated) amounts, cancels_id → original.id,
 *                        in today's journal with a fresh receipt_no.
 * $mode = 'supersede'  → same as 'cancel' + a second new "correction" row with the
 *                        $newValues the admin provided (same shape as a create payload),
 *                        supersedes_id → original.id, also in today's journal.
 *
 * Returns ['cancelled_id' => originalId, 'annulation_id' => int,
 *          'correction_id' => int|null, 'annulation_receipt' => string,
 *          'correction_receipt' => string|null].
 *
 * All in one transaction. Caller must have an active user session; this function
 * reads actorId/adminId from the current session.
 *
 * Services only (cash symmetry handled via complianceReverseCashEntry below).
 */
function complianceReverseServiceEntry(int $originalId, string $reason, string $mode, ?array $newValues = null): array {
    if (!in_array($mode, ['cancel', 'supersede'], true)) {
        throw new InvalidArgumentException("Invalid mode: $mode");
    }
    if ($mode === 'supersede' && empty($newValues)) {
        throw new InvalidArgumentException("supersede requires newValues");
    }
    $reason = trim($reason);
    if ($reason === '') throw new InvalidArgumentException("Reason required");

    $db = getDB();
    $actorId = (int)($_SESSION['user_id'] ?? 0);
    if (!$actorId) throw new RuntimeException("No active session");

    $stmt = $db->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([$originalId]);
    $orig = $stmt->fetch();
    if (!$orig) throw new RuntimeException("Prestation introuvable: #$originalId");
    if ($orig['cancelled_at'] !== null) throw new RuntimeException("Déjà annulée (soft cancel): #$originalId");

    // Prevent double-reversal: if a counter-entry already points to this id, bail out.
    $check = $db->prepare("SELECT id FROM services WHERE cancels_id = ? LIMIT 1");
    $check->execute([$originalId]);
    if ($check->fetchColumn()) throw new RuntimeException("Déjà contrepassée: #$originalId");

    $today = date('Y-m-d');
    $year  = (int)substr($today, 0, 4);

    $db->beginTransaction();
    try {
        // IMPORTANT: do NOT mark the original as cancelled_at.
        // The original's date is sealed → its day totals must stay unchanged.
        // The counter-entry in today's journal is what makes the correction visible.
        // (The 'cancelled_at' flag is reserved for pre-closure soft-deletes.)

        // 1. Insert annulation row (today's journal, mirrored amounts, cancels_id → original)
        $annulReceipt = complianceAllocReceiptNo('services', $year);
        $annulMontant     = -1 * (float)$orig['montant'];
        $annulHtva        = -1 * (float)$orig['montant_htva'];
        $annulTva         = -1 * (float)$orig['montant_tva'];
        $annulNote        = 'Annulation de #' . $orig['receipt_no'] . ' — ' . $reason;
        $db->prepare("INSERT INTO services
            (technician_id, date, type_nettoyage_id, lieu, ticket_tva, paiement, facture_a_faire, facture_envoyee,
             montant, photo_avant, photo_apres, notes,
             receipt_no, vat_rate, montant_htva, montant_tva, cancels_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?)")
           ->execute([
               (int)$orig['technician_id'], $today, (int)$orig['type_nettoyage_id'], $orig['lieu'],
               (int)$orig['ticket_tva'], $orig['paiement'], 0, 0,
               $annulMontant, $annulNote,
               $annulReceipt, (float)$orig['vat_rate'], $annulHtva, $annulTva, $originalId,
           ]);
        $annulId = (int)$db->lastInsertId();

        $correctionId = null;
        $correctionReceipt = null;

        // 2. If supersede, insert the correction row with new values
        if ($mode === 'supersede') {
            $nv = $newValues;
            $cMontant = (float)($nv['montant'] ?? 0);
            $cRate    = (float)($nv['vat_rate'] ?? $orig['vat_rate'] ?? COMPLIANCE_DEFAULT_VAT_RATE);
            $split    = complianceSplitVAT($cMontant, $cRate);
            $correctionReceipt = complianceAllocReceiptNo('services', $year);
            $cNote = 'Correction de #' . $orig['receipt_no'] . ' — ' . $reason
                   . (!empty($nv['notes']) ? ' | ' . $nv['notes'] : '');
            $db->prepare("INSERT INTO services
                (technician_id, date, type_nettoyage_id, lieu, ticket_tva, paiement, facture_a_faire, facture_envoyee,
                 montant, photo_avant, photo_apres, notes,
                 receipt_no, vat_rate, montant_htva, montant_tva, supersedes_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   (int)($nv['technician_id'] ?? $orig['technician_id']),
                   $today,
                   (int)($nv['type_nettoyage_id'] ?? $orig['type_nettoyage_id']),
                   $nv['lieu'] ?? $orig['lieu'],
                   (int)($nv['ticket_tva'] ?? $orig['ticket_tva']),
                   $nv['paiement'] ?? $orig['paiement'],
                   (int)($nv['facture_a_faire'] ?? $orig['facture_a_faire']),
                   (int)($nv['facture_envoyee'] ?? ($orig['facture_envoyee'] ?? 0)),
                   $cMontant,
                   $nv['photo_avant'] ?? null,
                   $nv['photo_apres'] ?? null,
                   $cNote,
                   $correctionReceipt, $cRate, $split['htva'], $split['tva'],
                   $originalId,
               ]);
            $correctionId = (int)$db->lastInsertId();
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // History: three (or two) entries, outside the transaction
    $origSnapshot = [
        'receipt_no'  => $orig['receipt_no'],
        'date'        => $orig['date'],
        'montant'     => (float)$orig['montant'],
        'montant_htva'=> (float)$orig['montant_htva'],
        'montant_tva' => (float)$orig['montant_tva'],
        'paiement'    => $orig['paiement'],
    ];
    // Log 'cancel' on the original even though we don't flip cancelled_at —
    // this entry tells the auditor "this row was reversed by #annulId".
    addServiceHistory($originalId, (int)$orig['technician_id'], 'cancel', $origSnapshot,
        ['reversed_by' => $annulId, 'annulation_receipt' => $annulReceipt], $reason);
    addServiceHistory($annulId, (int)$orig['technician_id'], 'create', null,
        ['receipt_no' => $annulReceipt, 'cancels_id' => $originalId, 'montant' => $annulMontant], $reason);
    if ($correctionId) {
        addServiceHistory($correctionId, (int)$orig['technician_id'], 'supersede', null,
            ['receipt_no' => $correctionReceipt, 'supersedes_id' => $originalId, 'montant' => $cMontant ?? 0], $reason);
    }

    return [
        'cancelled_id'       => $originalId,
        'annulation_id'      => $annulId,
        'correction_id'      => $correctionId,
        'annulation_receipt' => $annulReceipt,
        'correction_receipt' => $correctionReceipt,
    ];
}

/**
 * Symmetric reverse-entry for cash_movements. Shares the same contract as the
 * service variant but applies to the cash journal.
 */
function complianceReverseCashEntry(int $originalId, string $reason, string $mode, ?array $newValues = null): array {
    if (!in_array($mode, ['cancel', 'supersede'], true)) {
        throw new InvalidArgumentException("Invalid mode: $mode");
    }
    if ($mode === 'supersede' && empty($newValues)) {
        throw new InvalidArgumentException("supersede requires newValues");
    }
    $reason = trim($reason);
    if ($reason === '') throw new InvalidArgumentException("Reason required");

    $db = getDB();
    $actorId = (int)($_SESSION['user_id'] ?? 0);
    if (!$actorId) throw new RuntimeException("No active session");

    $stmt = $db->prepare("SELECT * FROM cash_movements WHERE id = ?");
    $stmt->execute([$originalId]);
    $orig = $stmt->fetch();
    if (!$orig) throw new RuntimeException("Mouvement introuvable: #$originalId");
    if ($orig['cancelled_at'] !== null) throw new RuntimeException("Déjà annulé (soft cancel): #$originalId");
    $check = $db->prepare("SELECT id FROM cash_movements WHERE cancels_id = ? LIMIT 1");
    $check->execute([$originalId]);
    if ($check->fetchColumn()) throw new RuntimeException("Déjà contrepassé: #$originalId");

    $today = date('Y-m-d');
    $year  = (int)substr($today, 0, 4);

    $db->beginTransaction();
    try {
        // Same rule as services: do NOT touch cancelled_at on the original —
        // the sealed day's totals stay as-is; the counter-entry is what reverses the effect.

        $annulReceipt = complianceAllocReceiptNo('cash_movements', $year);
        $annulMontant = -1 * (float)$orig['montant'];
        $annulNote    = 'Annulation de #' . $orig['receipt_no'] . ' — ' . $reason;
        $db->prepare("INSERT INTO cash_movements
            (technician_id, type, montant, notes, date, receipt_no, cancels_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([
               (int)$orig['technician_id'], $orig['type'], $annulMontant,
               $annulNote, $today, $annulReceipt, $originalId,
           ]);
        $annulId = (int)$db->lastInsertId();

        $correctionId = null;
        $correctionReceipt = null;
        if ($mode === 'supersede') {
            $nv = $newValues;
            $cType    = $nv['type']    ?? $orig['type'];
            $cMontant = (float)($nv['montant'] ?? 0);
            // Convention: outflows stored negative
            $cStored  = in_array($cType, ['depot_banque', 'achat_liquide'], true)
                        ? -abs($cMontant) : abs($cMontant);
            $correctionReceipt = complianceAllocReceiptNo('cash_movements', $year);
            $cNote = 'Correction de #' . $orig['receipt_no'] . ' — ' . $reason
                   . (!empty($nv['notes']) ? ' | ' . $nv['notes'] : '');
            $db->prepare("INSERT INTO cash_movements
                (technician_id, type, montant, notes, date, receipt_no, supersedes_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   (int)($nv['technician_id'] ?? $orig['technician_id']),
                   $cType, $cStored, $cNote, $today, $correctionReceipt, $originalId,
               ]);
            $correctionId = (int)$db->lastInsertId();
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $snapshot = [
        'receipt_no' => $orig['receipt_no'],
        'type'       => $orig['type'],
        'montant'    => (float)$orig['montant'],
        'date'       => $orig['date'],
    ];
    addCashHistory($originalId, (int)$orig['technician_id'], 'cancel', $snapshot,
        ['reversed_by' => $annulId, 'annulation_receipt' => $annulReceipt], $reason);
    addCashHistory($annulId, (int)$orig['technician_id'], 'create', null,
        ['receipt_no' => $annulReceipt, 'cancels_id' => $originalId, 'montant' => $annulMontant], $reason);
    if ($correctionId) {
        addCashHistory($correctionId, (int)$orig['technician_id'], 'supersede', null,
            ['receipt_no' => $correctionReceipt, 'supersedes_id' => $originalId, 'montant' => $cStored ?? 0], $reason);
    }

    return [
        'cancelled_id'       => $originalId,
        'annulation_id'      => $annulId,
        'correction_id'      => $correctionId,
        'annulation_receipt' => $annulReceipt,
        'correction_receipt' => $correctionReceipt,
    ];
}

