<?php
require_once __DIR__ . '/../../lib/compliance.php';

$db = getDB();

// Period selector
$period = $_GET['period'] ?? 'month';  // 'month' | 'quarter' | 'year'
$year   = max(2020, min(2030, (int)($_GET['year']  ?? date('Y'))));
$month  = max(1,    min(12,   (int)($_GET['month'] ?? date('n'))));
$qtr    = max(1,    min(4,    (int)($_GET['qtr']   ?? ceil(date('n') / 3))));

$monthNames = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

// Date range for the SELECT
if ($period === 'month') {
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end   = date('Y-m-d', strtotime("$start +1 month"));
    $periodLabel = "{$monthNames[$month]} $year";
} elseif ($period === 'quarter') {
    $firstMonth = ($qtr - 1) * 3 + 1;
    $start = sprintf('%04d-%02d-01', $year, $firstMonth);
    $end   = date('Y-m-d', strtotime("$start +3 months"));
    $periodLabel = "T$qtr $year";
} else {  // year
    $start = sprintf('%04d-01-01', $year);
    $end   = sprintf('%04d-01-01', $year + 1);
    $periodLabel = "Année $year";
}

// Company identity
$company = [
    'name'    => getSetting('company_name'),
    'form'    => getSetting('company_form'),
    'bce'     => getSetting('company_bce'),
    'vat'     => getSetting('company_vat'),
    'address' => getSetting('company_address'),
    'email'   => getSetting('company_email'),
    'phone'   => getSetting('company_phone'),
];
$identityIncomplete = empty($company['name']) || empty($company['bce']) || empty($company['vat']);

// Fetch rows in the period, ordered chronologically
$stmt = $db->prepare("
    SELECT s.id, s.receipt_no, s.date, s.technician_id, s.lieu, s.ticket_tva, s.paiement,
           s.facture_a_faire, s.facture_envoyee, s.facture_ref, s.facture_date,
           s.vat_rate, s.montant, s.montant_htva, s.montant_tva,
           s.notes, s.cancelled_at, s.cancelled_reason,
           s.cancels_id, s.supersedes_id,
           t.name AS tech_name, ct.label AS type_label,
           o1.receipt_no AS cancels_receipt_no,
           o2.receipt_no AS supersedes_receipt_no
    FROM services s
    JOIN technicians t ON t.id = s.technician_id
    JOIN cleaning_types ct ON ct.id = s.type_nettoyage_id
    LEFT JOIN services o1 ON o1.id = s.cancels_id
    LEFT JOIN services o2 ON o2.id = s.supersedes_id
    WHERE s.date >= ? AND s.date < ?
    ORDER BY s.date ASC, s.receipt_no ASC
");
$stmt->execute([$start, $end]);
$rows = $stmt->fetchAll();

// Build "reversed_by" map so originals can show their reversal badge
$reversedBy = [];
$supersededBy = [];
foreach ($rows as $r) {
    if ($r['cancels_id']) $reversedBy[(int)$r['cancels_id']] = $r['receipt_no'];
    if ($r['supersedes_id']) $supersededBy[(int)$r['supersedes_id']] = $r['receipt_no'];
}

// Totals: by day, by month (if period != month), overall
$totalsByDay = [];
$totalsOverall = ['nb' => 0, 'htva' => 0, 'tva' => 0, 'tvac' => 0];
foreach ($rows as $r) {
    // Exclude soft-cancelled rows (never had a counter-entry) from totals.
    // Counter-entries (cancels_id/supersedes_id rows) and originals that have been
    // reversed via counter-entry ALL remain in totals — their algebraic sum is correct.
    if ($r['cancelled_at'] !== null) continue;
    $d = $r['date'];
    if (!isset($totalsByDay[$d])) $totalsByDay[$d] = ['nb' => 0, 'htva' => 0, 'tva' => 0, 'tvac' => 0];
    $totalsByDay[$d]['nb']   += 1;
    $totalsByDay[$d]['htva'] += (float)$r['montant_htva'];
    $totalsByDay[$d]['tva']  += (float)$r['montant_tva'];
    $totalsByDay[$d]['tvac'] += (float)$r['montant'];
    $totalsOverall['nb']   += 1;
    $totalsOverall['htva'] += (float)$r['montant_htva'];
    $totalsOverall['tva']  += (float)$r['montant_tva'];
    $totalsOverall['tvac'] += (float)$r['montant'];
}

$fmtEur = fn($v) => number_format((float)$v, 2, ',', ' ');
$paiementLabel = ['cash'=>'Cash', 'virement'=>'Virement', 'qrcode'=>'QR Code', 'facture'=>'Sur facture'];
?>

<div class="admin-page livre-page">
    <?php if ($identityIncomplete): ?>
    <div class="livre-warn no-print">
        ⚠ Identité de l'entreprise incomplète (nom, BCE ou numéro TVA manquant). <a href="index.php?page=admin/entreprise">Compléter la fiche entreprise</a> avant impression.
    </div>
    <?php endif; ?>

    <div class="livre-toolbar no-print">
        <form method="GET" class="livre-filters">
            <input type="hidden" name="page" value="admin/livre_recettes">
            <select name="period">
                <option value="month"   <?= $period === 'month'   ? 'selected' : '' ?>>Mensuel</option>
                <option value="quarter" <?= $period === 'quarter' ? 'selected' : '' ?>>Trimestriel</option>
                <option value="year"    <?= $period === 'year'    ? 'selected' : '' ?>>Annuel</option>
            </select>
            <?php if ($period === 'month'): ?>
            <select name="month">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>><?= $monthNames[$m] ?></option>
                <?php endfor; ?>
            </select>
            <?php elseif ($period === 'quarter'): ?>
            <select name="qtr">
                <?php foreach ([1,2,3,4] as $q): ?>
                <option value="<?= $q ?>" <?= $q == $qtr ? 'selected' : '' ?>>T<?= $q ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="year">
                <?php for ($y = (int)date('Y') - 2; $y <= (int)date('Y') + 1; $y++): ?>
                <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn btn-sm">Afficher</button>
        </form>
        <div class="livre-actions">
            <?php if ($period === 'month'): ?>
            <a href="api/export_xlsx.php?month=<?= sprintf('%04d-%02d', $year, $month) ?>&csrf_token=<?= urlencode($csrfToken) ?>" class="btn btn-sm">Export Excel</a>
            <?php endif; ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">Imprimer / PDF</button>
        </div>
    </div>

    <!-- Printable area -->
    <div class="livre-paper">
        <header class="livre-header">
            <div class="livre-identity">
                <h1><?= htmlspecialchars($company['name'] ?: 'Entreprise non renseignée') ?></h1>
                <?php if ($company['form']):     ?><div><?= htmlspecialchars($company['form']) ?></div><?php endif; ?>
                <?php if ($company['address']):  ?><div class="livre-address"><?= nl2br(htmlspecialchars($company['address'])) ?></div><?php endif; ?>
                <?php if ($company['bce']):      ?><div>BCE : <?= htmlspecialchars($company['bce']) ?></div><?php endif; ?>
                <?php if ($company['vat']):      ?><div>TVA : <?= htmlspecialchars($company['vat']) ?></div><?php endif; ?>
                <?php if ($company['email']):    ?><div><?= htmlspecialchars($company['email']) ?></div><?php endif; ?>
                <?php if ($company['phone']):    ?><div><?= htmlspecialchars($company['phone']) ?></div><?php endif; ?>
            </div>
            <div class="livre-title">
                <h2>Livre de recettes</h2>
                <div class="livre-period"><?= htmlspecialchars($periodLabel) ?></div>
                <div class="livre-gendate">Édité le <?= date('d/m/Y H:i') ?></div>
            </div>
        </header>

        <?php if (empty($rows)): ?>
        <p class="livre-empty">Aucune écriture pour cette période.</p>
        <?php else: ?>

        <table class="livre-table">
            <thead>
                <tr>
                    <th>N° recette</th>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Paiement</th>
                    <th class="num">Taux TVA</th>
                    <th class="num">HTVA</th>
                    <th class="num">TVA</th>
                    <th class="num">TVAC</th>
                    <th>Réf facture</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $currentDay = null;
                foreach ($rows as $r):
                    $day = $r['date'];
                    // Day sub-total row BEFORE moving on
                    if ($currentDay !== null && $currentDay !== $day && isset($totalsByDay[$currentDay])):
                        $t = $totalsByDay[$currentDay];
                ?>
                <tr class="livre-daytotal">
                    <td colspan="5">Sous-total du <?= date('d/m/Y', strtotime($currentDay)) ?> (<?= (int)$t['nb'] ?> opération<?= $t['nb'] > 1 ? 's' : '' ?>)</td>
                    <td class="num"><?= $fmtEur($t['htva']) ?> €</td>
                    <td class="num"><?= $fmtEur($t['tva']) ?> €</td>
                    <td class="num"><?= $fmtEur($t['tvac']) ?> €</td>
                    <td colspan="2"></td>
                </tr>
                <?php endif; $currentDay = $day;

                    $cls = '';
                    $statusBadge = '';
                    if ($r['cancelled_at']) {
                        $cls = 'livre-cancelled';
                        $statusBadge = '<span class="badge-cancel">Annulée (soft)</span>';
                    } elseif (isset($reversedBy[(int)$r['id']])) {
                        $cls = 'livre-reversed';
                        $statusBadge = '<span class="badge-reversed">Contrepassée par #' . htmlspecialchars($reversedBy[(int)$r['id']]) . '</span>';
                    } elseif ($r['cancels_id']) {
                        $cls = 'livre-cancellation';
                        $statusBadge = '<span class="badge-cancellation">Annulation de #' . htmlspecialchars($r['cancels_receipt_no']) . '</span>';
                    } elseif ($r['supersedes_id']) {
                        $cls = 'livre-correction';
                        $statusBadge = '<span class="badge-correction">Correction de #' . htmlspecialchars($r['supersedes_receipt_no']) . '</span>';
                    } else {
                        $statusBadge = '<span class="badge-active">Actif</span>';
                    }
                    $description = htmlspecialchars($r['type_label']) . ' — ' . ($r['lieu'] === 'domicile' ? 'Domicile' : 'Atelier')
                        . ' (' . htmlspecialchars($r['tech_name']) . ')';
                    if ($r['notes']) $description .= ' — <small>' . htmlspecialchars(mb_substr($r['notes'], 0, 80)) . '</small>';
                ?>
                <tr class="<?= $cls ?>">
                    <td class="receipt-no"><?= htmlspecialchars($r['receipt_no']) ?></td>
                    <td><?= date('d/m/Y', strtotime($r['date'])) ?></td>
                    <td><?= $description ?></td>
                    <td><?= htmlspecialchars($paiementLabel[$r['paiement']] ?? $r['paiement']) ?></td>
                    <td class="num"><?= number_format((float)$r['vat_rate'], 0) ?>%</td>
                    <td class="num"><?= $fmtEur($r['montant_htva']) ?> €</td>
                    <td class="num"><?= $fmtEur($r['montant_tva']) ?> €</td>
                    <td class="num"><?= $fmtEur($r['montant']) ?> €</td>
                    <td><?= htmlspecialchars($r['facture_ref'] ?? '') ?></td>
                    <td><?= $statusBadge ?></td>
                </tr>
                <?php endforeach;
                // Last day's sub-total
                if ($currentDay !== null && isset($totalsByDay[$currentDay])):
                    $t = $totalsByDay[$currentDay];
                ?>
                <tr class="livre-daytotal">
                    <td colspan="5">Sous-total du <?= date('d/m/Y', strtotime($currentDay)) ?> (<?= (int)$t['nb'] ?> opération<?= $t['nb'] > 1 ? 's' : '' ?>)</td>
                    <td class="num"><?= $fmtEur($t['htva']) ?> €</td>
                    <td class="num"><?= $fmtEur($t['tva']) ?> €</td>
                    <td class="num"><?= $fmtEur($t['tvac']) ?> €</td>
                    <td colspan="2"></td>
                </tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="livre-total">
                    <td colspan="5">TOTAL <?= htmlspecialchars($periodLabel) ?> (<?= (int)$totalsOverall['nb'] ?> opération<?= $totalsOverall['nb'] > 1 ? 's' : '' ?>)</td>
                    <td class="num"><?= $fmtEur($totalsOverall['htva']) ?> €</td>
                    <td class="num"><?= $fmtEur($totalsOverall['tva']) ?> €</td>
                    <td class="num"><?= $fmtEur($totalsOverall['tvac']) ?> €</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>

        <p class="livre-note no-print">
            <em>Les lignes « Annulation » et « Correction » sont des contre-écritures légales (contrepassation).
            Les totaux agrègent toutes les lignes actives : original + annulation + correction = net correct.</em>
        </p>

        <?php endif; ?>

        <footer class="livre-footer">
            <div>Livre de recettes conforme AR n°1 TVA — <?= htmlspecialchars($company['name'] ?: '') ?></div>
        </footer>
    </div>
</div>
