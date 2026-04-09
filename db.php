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
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Database connection failed']));
        }
    }
    return $pdo;
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
}
