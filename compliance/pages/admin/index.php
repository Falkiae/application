<?php
$db = getDB();

$techCount = $db->query("SELECT COUNT(*) FROM technicians WHERE active=1")->fetchColumn();
// Compliance: count logical prestations (exclude counter-entries + soft-cancelled)
$serviceCount = $db->query("SELECT COUNT(*) FROM services WHERE cancels_id IS NULL AND supersedes_id IS NULL AND cancelled_at IS NULL")->fetchColumn();
$thisMonthCount = $db->query("SELECT COUNT(*) FROM services WHERE strftime('%Y-%m', date) = strftime('%Y-%m', 'now') AND cancels_id IS NULL AND supersedes_id IS NULL AND cancelled_at IS NULL")->fetchColumn();
$typeCount = $db->query("SELECT COUNT(*) FROM cleaning_types WHERE active=1")->fetchColumn();
$backupFiles = glob(BACKUP_PATH . '/*.sqlite') ?: [];
rsort($backupFiles);
?>

<div class="admin-page">
    <div class="admin-grid">
        <div class="stat-card">
            <div class="stat-icon stat-icon-blue">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $techCount ?></span>
                <span class="stat-label">Techniciens actifs</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-purple">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/></svg>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $serviceCount ?></span>
                <span class="stat-label">Prestations totales</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-green">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div class="stat-info">
                <span class="stat-value"><?= $thisMonthCount ?></span>
                <span class="stat-label">Ce mois</span>
            </div>
        </div>
    </div>

    <div class="admin-links">
        <a href="index.php?page=admin/techniciens" class="admin-link-card">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
            <div>
                <strong>Gérer les techniciens</strong>
                <span><?= $techCount ?> technicien<?= $techCount > 1 ? 's' : '' ?> actif<?= $techCount > 1 ? 's' : '' ?></span>
            </div>
            <svg class="arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a href="index.php?page=admin/types" class="admin-link-card">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/></svg>
            <div>
                <strong>Types de nettoyage</strong>
                <span><?= $typeCount ?> type<?= $typeCount > 1 ? 's' : '' ?> actif<?= $typeCount > 1 ? 's' : '' ?></span>
            </div>
            <svg class="arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a href="index.php?page=admin/livre_recettes" class="admin-link-card">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            <div>
                <strong>Journal mensuel</strong>
                <span>Livre de recettes + facturier de sortie (AR n°1 TVA)</span>
            </div>
            <svg class="arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a href="index.php?page=admin/import_prod" class="admin-link-card">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
            <div>
                <strong>Importer la base de production</strong>
                <span>Reprendre tes données existantes dans l'app compliance</span>
            </div>
            <svg class="arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a href="index.php?page=admin/entreprise" class="admin-link-card">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V7l8-4 8 4v14M9 9h1M9 13h1M9 17h1M14 9h1M14 13h1M14 17h1"/></svg>
            <div>
                <strong>Fiche entreprise</strong>
                <span>Raison sociale, BCE, numéro TVA, adresse</span>
            </div>
            <svg class="arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a href="index.php?page=admin/historique" class="admin-link-card">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <div>
                <strong>Historique des modifications</strong>
                <span>Toutes les activités sur les prestations</span>
            </div>
            <svg class="arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
        <a href="index.php?page=admin/pointage_historique" class="admin-link-card">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="9" y1="16" x2="15" y2="16"/></svg>
            <div>
                <strong>Historique des pointages</strong>
                <span>Modifications, suppressions, fermetures auto</span>
            </div>
            <svg class="arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
    </div>

    <?php if (!empty($backupFiles)): ?>
    <div class="section-card">
        <h3 class="card-section-title">Dernières sauvegardes</h3>
        <div class="backup-list">
            <?php foreach (array_slice($backupFiles, 0, 5) as $f): ?>
            <div class="backup-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/></svg>
                <span><?= basename($f) ?></span>
                <span class="backup-size"><?= round(filesize($f) / 1024) ?> Ko</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
