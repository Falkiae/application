<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page = $_GET['page'] ?? 'dashboard';

// Whitelist of allowed pages
$publicPages = ['login'];
$adminPages = ['admin/index', 'admin/techniciens', 'admin/types', 'admin/historique'];
$allPages = [
    'dashboard', 'login',
    'prestation_new', 'prestation_edit', 'prestations',
    'cash', 'export',
    'abonnements', 'abonnement_detail',
    'admin/index', 'admin/techniciens', 'admin/types', 'admin/historique'
];

if (!in_array($page, $allPages)) {
    $page = 'dashboard';
}

// Auth gates
if (!in_array($page, $publicPages)) {
    requireLogin();
}
if (in_array($page, $adminPages)) {
    requireAdmin();
}

// Login page: minimal layout
if ($page === 'login') {
    include __DIR__ . '/pages/login.php';
    exit;
}

// Generate CSRF token
$csrfToken = generateCsrfToken();

// Get unread notification count
$unreadCount = 0;
if (isLoggedIn()) {
    try {
        $db = getDB();
        $notifs = $db->query("SELECT read_by FROM notifications ORDER BY created_at DESC LIMIT 50")->fetchAll();
        foreach ($notifs as $n) {
            $readBy = json_decode($n['read_by'] ?? '[]', true);
            if (!in_array(currentUserId(), $readBy)) {
                $unreadCount++;
            }
        }
    } catch (Exception $e) {}
}

$currentPage = $page;
$pageTitle = match($page) {
    'dashboard'          => 'Tableau de bord',
    'prestation_new'     => 'Nouvelle prestation',
    'prestation_edit'    => 'Modifier prestation',
    'prestations'        => 'Prestations',
    'cash'               => 'Liquidités',
    'export'             => 'Export Excel',
    'abonnements'        => 'Abonnements',
    'abonnement_detail'  => 'Détail abonnement',
    'admin/index'        => 'Administration',
    'admin/techniciens'  => 'Techniciens',
    'admin/types'        => 'Types de nettoyage',
    'admin/historique'   => 'Historique',
    default              => 'Keepnew'
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="theme-color" content="#13162f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?= htmlspecialchars($pageTitle) ?> — Keepnew</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- Sidebar (desktop) -->
    <aside class="sidebar">
        <div class="sidebar-logo">
            <img src="assets/img/logo.png" alt="Keepnew" height="40">
        </div>
        <nav class="sidebar-nav">
            <a href="index.php?page=dashboard" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                <span>Tableau de bord</span>
            </a>
            <a href="index.php?page=prestation_new" class="nav-item <?= $currentPage === 'prestation_new' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                <span>Nouvelle prestation</span>
            </a>
            <a href="index.php?page=prestations" class="nav-item <?= $currentPage === 'prestations' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                <span>Prestations</span>
            </a>
            <a href="index.php?page=cash" class="nav-item <?= $currentPage === 'cash' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01M18 12h.01"/></svg>
                <span>Liquidités</span>
            </a>
            <a href="index.php?page=export" class="nav-item <?= $currentPage === 'export' ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 18 15 15"/></svg>
                <span>Export Excel</span>
            </a>
            <a href="index.php?page=abonnements" class="nav-item <?= str_starts_with($currentPage, 'abonnement') ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 4v5"/><path d="M15 4v5"/><path d="M8 14h4"/><path d="M8 17h8"/></svg>
                <span>Abonnements</span>
            </a>
            <?php if (isAdmin()): ?>
            <div class="nav-separator"></div>
            <a href="index.php?page=admin/index" class="nav-item <?= str_starts_with($currentPage, 'admin') ? 'active' : '' ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/></svg>
                <span>Administration</span>
            </a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar" style="background:<?= htmlspecialchars($_SESSION['user_color'] ?? '#596FF3') ?>">
                    <?= strtoupper(substr(currentUserName(), 0, 1)) ?>
                </div>
                <div class="user-details">
                    <span class="user-name"><?= htmlspecialchars(currentUserName()) ?></span>
                    <span class="user-role"><?= isAdmin() ? 'Administrateur' : 'Technicien' ?></span>
                </div>
            </div>
            <a href="api/logout.php" class="btn-logout" title="Déconnexion">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </a>
        </div>
    </aside>

    <!-- Main content -->
    <main class="main-content">
        <!-- Top header (mobile) -->
        <header class="top-header">
            <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
            <div class="header-actions">
                <button class="btn-notif" id="notifBtn" aria-label="Notifications">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <?php if ($unreadCount > 0): ?>
                    <span class="notif-badge"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
                    <?php endif; ?>
                </button>
            </div>
        </header>

        <!-- Page content -->
        <div class="page-content">
            <?php include __DIR__ . '/pages/' . $page . '.php'; ?>
        </div>
    </main>

    <!-- Bottom navigation (mobile) -->
    <nav class="bottom-nav">
        <a href="index.php?page=dashboard" class="bnav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            <span>Accueil</span>
        </a>
        <a href="index.php?page=prestations" class="bnav-item <?= $currentPage === 'prestations' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            <span>Prestations</span>
        </a>
        <a href="index.php?page=prestation_new" class="bnav-item bnav-center <?= $currentPage === 'prestation_new' ? 'active' : '' ?>">
            <div class="bnav-fab">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </div>
            <span>Ajouter</span>
        </a>
        <a href="index.php?page=cash" class="bnav-item <?= $currentPage === 'cash' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/></svg>
            <span>Cash</span>
        </a>
        <a href="index.php?page=abonnements" class="bnav-item <?= str_starts_with($currentPage, 'abonnement') ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 4v5"/><path d="M15 4v5"/><path d="M8 14h4"/><path d="M8 17h8"/></svg>
            <span>Abonnements</span>
        </a>
    </nav>

    <!-- Notification panel -->
    <div class="notif-overlay" id="notifOverlay"></div>
    <div class="notif-panel" id="notifPanel">
        <div class="notif-panel-header">
            <h3>Notifications</h3>
            <button id="notifClose">&times;</button>
        </div>
        <div class="notif-list" id="notifList">
            <div class="notif-loading">Chargement...</div>
        </div>
        <div class="notif-footer">
            <button class="btn-text" id="markAllRead">Tout marquer comme lu</button>
        </div>
    </div>

    <input type="hidden" id="csrfToken" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" id="currentUserId" value="<?= currentUserId() ?>">

    <script src="assets/js/app.js"></script>
    <?php if (in_array($page, ['prestation_new', 'prestation_edit'])): ?>
    <script src="assets/js/camera.js"></script>
    <script src="assets/js/prestation.js"></script>
    <?php endif; ?>
    <?php if ($page === 'abonnement_detail'): ?>
    <script src="assets/js/camera.js"></script>
    <?php endif; ?>
</body>
</html>
