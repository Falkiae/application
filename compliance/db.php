<?php
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO('sqlite:' . DB_PATH);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA foreign_keys=ON');
            initSchema($pdo);
            // Compliance one-shot retroconformance: assigns receipt_no + HTVA/TVA
            // to legacy rows and seals past days. Idempotent via settings flag.
            require_once __DIR__ . '/lib/compliance.php';
            complianceRetroconformHistoricalData();
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Database connection failed']));
        }
    }
    return $pdo;
}

/**
 * Read a value from the key/value `settings` table.
 * `key` is a reserved SQLite keyword, hence the quotes.
 */
function getSetting(string $key, string $default = ''): string {
    $stmt = getDB()->prepare('SELECT value FROM settings WHERE "key" = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val === false ? $default : (string)$val;
}

/**
 * Insert or overwrite a value in the `settings` table.
 */
function setSetting(string $key, string $value): void {
    getDB()->prepare('INSERT OR REPLACE INTO settings ("key", value) VALUES (?, ?)')
           ->execute([$key, $value]);
}

function initSchema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS technicians (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            pin_hash TEXT NOT NULL,
            role TEXT DEFAULT 'technician' CHECK(role IN ('admin','technician')),
            color TEXT DEFAULT '#596FF3',
            active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS cleaning_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL UNIQUE,
            active INTEGER DEFAULT 1,
            sort_order INTEGER DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS services (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            technician_id INTEGER NOT NULL,
            date TEXT NOT NULL,
            type_nettoyage_id INTEGER NOT NULL,
            lieu TEXT NOT NULL CHECK(lieu IN ('domicile','atelier')),
            ticket_tva INTEGER DEFAULT 0,
            paiement TEXT NOT NULL CHECK(paiement IN ('virement','cash','qrcode','facture')),
            facture_a_faire INTEGER DEFAULT 0,
            montant REAL DEFAULT 0,
            photo_avant TEXT,
            photo_apres TEXT,
            notes TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (technician_id) REFERENCES technicians(id),
            FOREIGN KEY (type_nettoyage_id) REFERENCES cleaning_types(id)
        );

        CREATE TABLE IF NOT EXISTS cash_movements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            technician_id INTEGER NOT NULL,
            type TEXT NOT NULL CHECK(type IN ('initial','depot_banque','note')),
            montant REAL NOT NULL,
            notes TEXT,
            date TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (technician_id) REFERENCES technicians(id)
        );

        CREATE TABLE IF NOT EXISTS service_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service_id INTEGER NOT NULL,
            technician_id INTEGER NOT NULL,
            action TEXT NOT NULL CHECK(action IN ('create','update','delete')),
            changed_fields TEXT,
            old_values TEXT,
            new_values TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (technician_id) REFERENCES technicians(id)
        );

        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            from_technician_id INTEGER NOT NULL,
            action TEXT NOT NULL CHECK(action IN ('create','update','delete')),
            service_id INTEGER,
            message TEXT NOT NULL,
            read_by TEXT DEFAULT '[]',
            created_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (from_technician_id) REFERENCES technicians(id)
        );

        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );

        CREATE TABLE IF NOT EXISTS clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nom TEXT NOT NULL UNIQUE,
            notes TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS abonnements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            nettoyages_total INTEGER NOT NULL,
            prix_total REAL DEFAULT 0,
            notes TEXT,
            active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (client_id) REFERENCES clients(id)
        );

        CREATE TABLE IF NOT EXISTS abonnement_passages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            abonnement_id INTEGER NOT NULL,
            technician_id INTEGER NOT NULL,
            date TEXT NOT NULL,
            nettoyages_debites INTEGER DEFAULT 1,
            photo_avant TEXT,
            photo_apres TEXT,
            notes TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (abonnement_id) REFERENCES abonnements(id),
            FOREIGN KEY (technician_id) REFERENCES technicians(id)
        );

        CREATE TABLE IF NOT EXISTS pointages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            technician_id INTEGER NOT NULL,
            date TEXT NOT NULL,
            debut TEXT NOT NULL,
            fin TEXT,
            notes TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (technician_id) REFERENCES technicians(id)
        );

        CREATE TABLE IF NOT EXISTS pointage_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pointage_id INTEGER,
            technician_id INTEGER NOT NULL,
            admin_id INTEGER,
            action TEXT NOT NULL,
            old_values TEXT,
            new_values TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );
    ");

    // Seed default cleaning types
    $count = $pdo->query("SELECT COUNT(*) FROM cleaning_types")->fetchColumn();
    if ($count == 0) {
        $types = [
            ['Voiture', 1], ['Moto', 2], ['Camion', 3],
            ['Bateau', 4], ['Intérieur', 5], ['Autre', 6]
        ];
        $stmt = $pdo->prepare("INSERT INTO cleaning_types (label, sort_order) VALUES (?, ?)");
        foreach ($types as $t) {
            $stmt->execute($t);
        }
    }

    // Seed default admin
    $count = $pdo->query("SELECT COUNT(*) FROM technicians")->fetchColumn();
    if ($count == 0) {
        $hash = password_hash(DEFAULT_ADMIN_PIN, PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO technicians (name, pin_hash, role, color) VALUES (?, ?, 'admin', '#596FF3')")
            ->execute([DEFAULT_ADMIN_NAME, $hash]);
    }

    // Migrations
    $cols = array_column($pdo->query("PRAGMA table_info(services)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('facture_envoyee', $cols)) {
        $pdo->exec("ALTER TABLE services ADD COLUMN facture_envoyee INTEGER DEFAULT 0");
    }

    // Migration: update cash_movements CHECK constraint to include achat_liquide
    $cmSchema = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='cash_movements'")->fetchColumn();
    if ($cmSchema && strpos($cmSchema, 'achat_liquide') === false) {
        $pdo->exec("PRAGMA foreign_keys=OFF");
        $pdo->exec("CREATE TABLE cash_movements_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            technician_id INTEGER NOT NULL,
            type TEXT NOT NULL CHECK(type IN ('initial','depot_banque','achat_liquide','note')),
            montant REAL NOT NULL,
            notes TEXT,
            date TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (technician_id) REFERENCES technicians(id)
        )");
        $pdo->exec("INSERT INTO cash_movements_new SELECT * FROM cash_movements");
        $pdo->exec("DROP TABLE cash_movements");
        $pdo->exec("ALTER TABLE cash_movements_new RENAME TO cash_movements");
        $pdo->exec("PRAGMA foreign_keys=ON");
    }

    // ─── Compliance migrations (livre de recettes belge) ────────────────────
    // Idempotent: all ALTER TABLE / CREATE TABLE IF NOT EXISTS checks before applying.

    $svcCols = array_column($pdo->query("PRAGMA table_info(services)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $svcAdd = [
        'receipt_no'       => "ALTER TABLE services ADD COLUMN receipt_no TEXT",
        'vat_rate'         => "ALTER TABLE services ADD COLUMN vat_rate REAL DEFAULT 21.0",
        'montant_htva'     => "ALTER TABLE services ADD COLUMN montant_htva REAL",
        'montant_tva'      => "ALTER TABLE services ADD COLUMN montant_tva REAL",
        'facture_ref'      => "ALTER TABLE services ADD COLUMN facture_ref TEXT",
        'facture_date'     => "ALTER TABLE services ADD COLUMN facture_date TEXT",
        'cancelled_at'     => "ALTER TABLE services ADD COLUMN cancelled_at TEXT",
        'cancelled_by'     => "ALTER TABLE services ADD COLUMN cancelled_by INTEGER",
        'cancelled_reason' => "ALTER TABLE services ADD COLUMN cancelled_reason TEXT",
        'supersedes_id'    => "ALTER TABLE services ADD COLUMN supersedes_id INTEGER",
        'cancels_id'       => "ALTER TABLE services ADD COLUMN cancels_id INTEGER",
    ];
    foreach ($svcAdd as $col => $sql) {
        if (!in_array($col, $svcCols, true)) $pdo->exec($sql);
    }
    // Unique index on receipt_no (SQLite allows multiple NULLs → safe before retroconformance)
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_services_receipt_no ON services(receipt_no) WHERE receipt_no IS NOT NULL");

    $cmCols = array_column($pdo->query("PRAGMA table_info(cash_movements)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $cmAdd = [
        'receipt_no'       => "ALTER TABLE cash_movements ADD COLUMN receipt_no TEXT",
        'cancelled_at'     => "ALTER TABLE cash_movements ADD COLUMN cancelled_at TEXT",
        'cancelled_by'     => "ALTER TABLE cash_movements ADD COLUMN cancelled_by INTEGER",
        'cancelled_reason' => "ALTER TABLE cash_movements ADD COLUMN cancelled_reason TEXT",
        'supersedes_id'    => "ALTER TABLE cash_movements ADD COLUMN supersedes_id INTEGER",
        'cancels_id'       => "ALTER TABLE cash_movements ADD COLUMN cancels_id INTEGER",
    ];
    foreach ($cmAdd as $col => $sql) {
        if (!in_array($col, $cmCols, true)) $pdo->exec($sql);
    }
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_cash_receipt_no ON cash_movements(receipt_no) WHERE receipt_no IS NOT NULL");

    // service_history enrichments
    $shCols = array_column($pdo->query("PRAGMA table_info(service_history)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('reason',   $shCols, true)) $pdo->exec("ALTER TABLE service_history ADD COLUMN reason TEXT");
    if (!in_array('admin_id', $shCols, true)) $pdo->exec("ALTER TABLE service_history ADD COLUMN admin_id INTEGER");
    if (!in_array('ip',       $shCols, true)) $pdo->exec("ALTER TABLE service_history ADD COLUMN ip TEXT");
    // Note: the CHECK constraint on `action` still restricts to create/update/delete.
    // Rebuild the table to extend it with cancel/supersede.
    $shSchema = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='service_history'")->fetchColumn();
    if ($shSchema && strpos($shSchema, "'cancel'") === false) {
        $pdo->exec("PRAGMA foreign_keys=OFF");
        $pdo->exec("CREATE TABLE service_history_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service_id INTEGER NOT NULL,
            technician_id INTEGER NOT NULL,
            admin_id INTEGER,
            action TEXT NOT NULL CHECK(action IN ('create','update','delete','cancel','supersede')),
            changed_fields TEXT,
            old_values TEXT,
            new_values TEXT,
            reason TEXT,
            ip TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (technician_id) REFERENCES technicians(id)
        )");
        $pdo->exec("INSERT INTO service_history_new (id, service_id, technician_id, admin_id, action, changed_fields, old_values, new_values, reason, ip, created_at)
                    SELECT id, service_id, technician_id, admin_id, action, changed_fields, old_values, new_values, reason, ip, created_at FROM service_history");
        $pdo->exec("DROP TABLE service_history");
        $pdo->exec("ALTER TABLE service_history_new RENAME TO service_history");
        $pdo->exec("PRAGMA foreign_keys=ON");
    }

    // cash_history (symmetric to service_history)
    $pdo->exec("CREATE TABLE IF NOT EXISTS cash_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cash_movement_id INTEGER,
        technician_id INTEGER NOT NULL,
        admin_id INTEGER,
        action TEXT NOT NULL CHECK(action IN ('create','update','delete','cancel','supersede')),
        old_values TEXT,
        new_values TEXT,
        reason TEXT,
        ip TEXT,
        created_at TEXT DEFAULT (datetime('now','localtime'))
    )");

    // daily_close (seals a given date — Phase 2 use)
    $pdo->exec("CREATE TABLE IF NOT EXISTS daily_close (
        date         TEXT PRIMARY KEY,
        closed_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        closed_by    INTEGER,
        totals_json  TEXT
    )");
}
