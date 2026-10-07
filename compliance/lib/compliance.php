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

        setSetting(COMPLIANCE_RETROCONF_FLAG, date('Y-m-d H:i:s'));
        $db->commit();

        return [
            'already_done'      => false,
            'services_numbered' => $svcAssigned,
            'services_vat_filled' => $svcVatFilled,
            'cash_numbered'     => $cashAssigned,
            'days_closed'       => $daysClosed,
            'at'                => date('Y-m-d H:i:s'),
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
