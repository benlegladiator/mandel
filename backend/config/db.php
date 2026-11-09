<?php
// Interrupteur de Licence PWA & Mode Hors-Ligne (Feature Flag)
define('PWA_OFFLINE_ENABLED', false);
define('PWA_DISABLED_MESSAGE', 'Service PWA & Mode Hors-Ligne non disponible pour l\'instant. Veuillez contacter votre prestataire technique / hébergeur pour l\'activation de ce service.');

// Définir le fuseau horaire du Cameroun (UTC+1)
date_default_timezone_set('Africa/Douala');

// Détection automatique de l'environnement (Hébergement en ligne InfinityFree vs Local XAMPP)
$is_online = (
    (isset($_SERVER['HTTP_HOST']) && (strpos($_SERVER['HTTP_HOST'], 'free.nf') !== false || strpos($_SERVER['HTTP_HOST'], 'infinityfree') !== false))
    || (isset($_SERVER['SERVER_NAME']) && strpos($_SERVER['SERVER_NAME'], 'free.nf') !== false)
    || (!file_exists('C:/xampp') && !file_exists('C:\\xampp') && PHP_OS_FAMILY !== 'Windows')
);

if ($is_online) {
    // Configuration pour l'hébergement en ligne (InfinityFree)
    define('DB_HOST', 'sql113.infinityfree.com');
    define('DB_USER', 'if0_39882531');
    define('DB_PASS', 'cmTJtR2Z2yq8MO');
    define('DB_NAME', 'if0_39882531_mandel');
} else {
    // Configuration locale (XAMPP) - 127.0.0.1 force TCP et évite l'erreur de socket [2002]
    define('DB_HOST', '127.0.0.1');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'mandel_db');
}

$pdo = null;

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 4,
    ]);
} catch (PDOException $e) {
    // Fallback automatique si la détection d'hôte a été inversée
    try {
        if ($is_online) {
            $pdo = new PDO("mysql:host=127.0.0.1;dbname=mandel_db;charset=utf8mb4", "root", "", [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 4,
            ]);
        } else {
            $pdo = new PDO("mysql:host=sql113.infinityfree.com;dbname=if0_39882531_mandel;charset=utf8mb4", "if0_39882531", "cmTJtR2Z2yq8MO", [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 4,
            ]);
        }
    } catch (PDOException $e2) {
        die("Erreur de connexion à la base de données : " . $e->getMessage());
    }
}

    // Synchroniser l'heure MySQL avec le fuseau horaire du Cameroun (UTC+1)
    $pdo->exec("SET time_zone = '+01:00'");

    // Système d'auto-migration ultra-rapide avec verrou de version pour performances maximales (Pilier 1)
    define('APP_SCHEMA_VERSION', 21);

    $current_installed_version = 0;
    try {
        $stmt_ver = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'schema_version' LIMIT 1");
        if ($stmt_ver) {
            $current_installed_version = intval($stmt_ver->fetchColumn() ?: 0);
        }
    } catch (Exception $e) {
        $current_installed_version = 0;
    }

    // Exécuter les migrations UNIQUEMENT si une nouvelle version de schéma est requise
    if ($current_installed_version < APP_SCHEMA_VERSION) {
        // 0. Table de configuration système app_settings
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(50) PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        if (!function_exists('safeAddIndex')) {
            function safeAddIndex($pdo, $table, $index_name, $columns_sql) {
                try {
                    $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
                    $stmt->execute([$index_name]);
                    if (!$stmt->fetch()) {
                        $pdo->exec("ALTER TABLE `$table` ADD INDEX `$index_name` ($columns_sql)");
                    }
                } catch (Exception $ex) {}
            }
        }

        // 1. Table: expenses
    $checkExpenses = $pdo->query("SHOW TABLES LIKE 'expenses'");
    if (!$checkExpenses->fetch()) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS expenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                shop_id INT NOT NULL,
                user_id INT NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                category VARCHAR(100) NOT NULL,
                expense_date DATE NOT NULL,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // 2. Table: stock_conversions
    $checkConversions = $pdo->query("SHOW TABLES LIKE 'stock_conversions'");
    if (!$checkConversions->fetch()) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS stock_conversions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                shop_id INT NOT NULL,
                user_id INT NOT NULL,
                source_product_id INT NOT NULL,
                target_product_id INT NOT NULL,
                source_quantity INT NOT NULL,
                multiplier INT NOT NULL,
                target_quantity INT NOT NULL,
                reason VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
                FOREIGN KEY (source_product_id) REFERENCES products(id) ON DELETE CASCADE,
                FOREIGN KEY (target_product_id) REFERENCES products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // 3. Table orders column status
    $checkStatus = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'");
    if (!$checkStatus->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN status ENUM('COMPLETED', 'PENDING_APPROVAL', 'REJECTED') NOT NULL DEFAULT 'COMPLETED' AFTER notes");
    }

    // 4. Table products column margin_percent
    $checkMargin = $pdo->query("SHOW COLUMNS FROM products LIKE 'margin_percent'");
    if (!$checkMargin->fetch()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN margin_percent DECIMAL(5,2) NOT NULL DEFAULT 5.00 AFTER price_sale");
    }

    // 5. Columns for CREDIT management in orders table
    $checkDueDate = $pdo->query("SHOW COLUMNS FROM orders LIKE 'due_date'");
    if (!$checkDueDate->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN due_date DATE DEFAULT NULL AFTER status");
    }

    $checkAmountPaid = $pdo->query("SHOW COLUMNS FROM orders LIKE 'amount_paid'");
    if (!$checkAmountPaid->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER due_date");
    }

    $checkPaymentStatus = $pdo->query("SHOW COLUMNS FROM orders LIKE 'payment_status'");
    if (!$checkPaymentStatus->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN payment_status ENUM('PAID', 'PARTIAL', 'UNPAID') NOT NULL DEFAULT 'PAID' AFTER amount_paid");
    }

    // 6. Column discount_reason in orders table
    $checkDiscountReason = $pdo->query("SHOW COLUMNS FROM orders LIKE 'discount_reason'");
    if (!$checkDiscountReason->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN discount_reason TEXT DEFAULT NULL AFTER payment_status");
    }

    // 7. Column status in expenses table
    $checkExpenseStatus = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'status'");
    if (!$checkExpenseStatus->fetch()) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN status ENUM('PENDING_ACK', 'ACKNOWLEDGED') NOT NULL DEFAULT 'ACKNOWLEDGED' AFTER notes");
    }

    // 8. Table: cash_transactions
    $checkCashTransactions = $pdo->query("SHOW TABLES LIKE 'cash_transactions'");
    if (!$checkCashTransactions->fetch()) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS cash_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                shop_id INT NULL,
                user_id INT NOT NULL,
                type ENUM('IN', 'OUT', 'TRANSFER') NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                description VARCHAR(255) NOT NULL,
                reference_id INT NULL,
                reference_type VARCHAR(50) NULL,
                status ENUM('PENDING', 'VALIDATED') NOT NULL DEFAULT 'VALIDATED',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // 9. Add status column to cash_transactions if it doesn't exist (migration for existing database)
    $checkCtStatus = $pdo->query("SHOW COLUMNS FROM cash_transactions LIKE 'status'");
    if (!$checkCtStatus->fetch()) {
        $pdo->exec("ALTER TABLE cash_transactions ADD COLUMN status ENUM('PENDING', 'VALIDATED') NOT NULL DEFAULT 'VALIDATED'");
    }

        // Backfill history
        $checkEmpty = $pdo->query("SELECT COUNT(*) FROM cash_transactions")->fetchColumn();
        if ($checkEmpty == 0) {
            // Ventes Cash et acomptes passés
            $pdo->exec("
                INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type, created_at)
                SELECT o.shop_id, COALESCE(o.user_id, 1), 'IN', o.amount_paid, CONCAT('Vente FAC-', LPAD(o.id, 5, '0')), o.id, 'ORDER', o.created_at
                FROM orders o
                WHERE o.type = 'SALE' AND o.status = 'COMPLETED' AND o.amount_paid > 0
            ");
            // Achats / Ravitaillements passés (payés par le Boss)
            $pdo->exec("
                INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type, created_at)
                SELECT NULL, COALESCE(o.user_id, 1), 'OUT', o.total_amount, CONCAT('Achat/Ravitaillement FAC-', LPAD(o.id, 5, '0')), o.id, 'ORDER', o.created_at
                FROM orders o
                WHERE o.type = 'PURCHASE' AND o.status = 'COMPLETED'
            ");
            // Dépenses passées
            $pdo->exec("
                INSERT INTO cash_transactions (shop_id, user_id, type, amount, description, reference_id, reference_type, created_at)
                SELECT e.shop_id, e.user_id, 'OUT', e.amount, CONCAT('Dépense : ', e.category), e.id, 'EXPENSE', e.created_at
                FROM expenses e
            ");
        }

    // 10. Table: shops (Legal fields & Depot flag)
    $checkShopsNui = $pdo->query("SHOW COLUMNS FROM shops LIKE 'nui'");
    if (!$checkShopsNui->fetch()) {
        $pdo->exec("ALTER TABLE shops ADD COLUMN nui VARCHAR(100) NULL, ADD COLUMN rc VARCHAR(100) NULL");
    }
    
    $checkShopsDepot = $pdo->query("SHOW COLUMNS FROM shops LIKE 'is_depot'");
    if (!$checkShopsDepot->fetch()) {
        $pdo->exec("ALTER TABLE shops ADD COLUMN is_depot TINYINT(1) NOT NULL DEFAULT 0");
        // Optionnel : Créer automatiquement un dépôt si aucun n'existe
        // On le fera plutôt depuis l'interface s'ils veulent configurer une boutique en dépôt ou en créer une nouvelle.
    }

    // 11. Table: clients (Legal fields)
    $checkClientsNui = $pdo->query("SHOW COLUMNS FROM clients LIKE 'nui'");
    if (!$checkClientsNui->fetch()) {
        $pdo->exec("ALTER TABLE clients ADD COLUMN nui VARCHAR(100) NULL, ADD COLUMN rc VARCHAR(100) NULL, ADD COLUMN category VARCHAR(50) NULL DEFAULT 'PARTICULIER', ADD COLUMN bp VARCHAR(100) NULL");
    }

    // 12. Table: suppliers (Legal fields)
    $checkSuppliersNui = $pdo->query("SHOW COLUMNS FROM suppliers LIKE 'nui'");
    if (!$checkSuppliersNui->fetch()) {
        $pdo->exec("ALTER TABLE suppliers ADD COLUMN nui VARCHAR(100) NULL, ADD COLUMN rc VARCHAR(100) NULL, ADD COLUMN category VARCHAR(50) NULL DEFAULT 'PME', ADD COLUMN bp VARCHAR(100) NULL");
    }

    // 13. Table: orders (Tax and formal fields, and PRODUCTION type)
    $checkOrdersFormal = $pdo->query("SHOW COLUMNS FROM orders LIKE 'is_formal'");
    if (!$checkOrdersFormal->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN is_formal TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00, ADD COLUMN tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00");
        // Backfill existing orders
        $pdo->exec("UPDATE orders SET subtotal = total_amount WHERE subtotal = 0 AND total_amount > 0");
    }
    
    $checkOrdersIr = $pdo->query("SHOW COLUMNS FROM orders LIKE 'ir_amount'");
    if (!$checkOrdersIr->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN ir_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER tax_amount, ADD COLUMN nap_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER ir_amount");
        $pdo->exec("UPDATE orders SET nap_amount = total_amount WHERE nap_amount = 0");
    }
    
    // Modification de l'ENUM de la colonne type pour accepter PRODUCTION
    $pdo->exec("ALTER TABLE orders MODIFY COLUMN type ENUM('SALE', 'PURCHASE', 'PRODUCTION') NOT NULL");

    // 14. Table: stock_transfers
    $checkTransfers = $pdo->query("SHOW TABLES LIKE 'stock_transfers'");
    if (!$checkTransfers->fetch()) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS stock_transfers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                from_shop_id INT NOT NULL,
                to_shop_id INT NOT NULL,
                product_id INT NOT NULL,
                quantity INT NOT NULL,
                notes VARCHAR(255) NULL,
                user_id INT NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (from_shop_id) REFERENCES shops(id) ON DELETE RESTRICT,
                FOREIGN KEY (to_shop_id) REFERENCES shops(id) ON DELETE RESTRICT,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } else {
        $checkIsRead = $pdo->query("SHOW COLUMNS FROM stock_transfers LIKE 'is_read'");
        if (!$checkIsRead->fetch()) {
            $pdo->exec("ALTER TABLE stock_transfers ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0");
        }

        $checkTransferCode = $pdo->query("SHOW COLUMNS FROM stock_transfers LIKE 'transfer_code'");
        if (!$checkTransferCode->fetch()) {
            $pdo->exec("ALTER TABLE stock_transfers ADD COLUMN transfer_code VARCHAR(50) NULL AFTER id");
            $pdo->exec("UPDATE stock_transfers SET transfer_code = CONCAT('TR-', LPAD(id, 5, '0')) WHERE transfer_code IS NULL OR transfer_code = ''");
        }
    }

    // 15. Table: expense_categories
    $checkExpenseCategories = $pdo->query("SHOW TABLES LIKE 'expense_categories'");
    if (!$checkExpenseCategories->fetch()) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS expense_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                description TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        
        // Seed initial default categories
        $defaultCategories = [
            ['EAU POTABLE', 'Consommation d\'eau potable et boissons boutique'],
            ['TAXI / TRANSPORT', 'Frais de transport, coursier et déplacements'],
            ['EMBALLAGE', 'Emballages, plastiques et conditionnement'],
            ['PETITES FOURNITURES', 'Fournitures de bureau (stylos, papier, consommables)'],
            ['ELECTRICITE / ABONNEMENT', 'Factures d\'électricité, eau réseau et abonnements internet'],
            ['LOYER', 'Loyer commercial des boutiques et dépôts'],
            ['SALAIRES & PRIMES', 'Avances sur salaires, primes et gratifications du personnel'],
            ['MAINTENANCE / REPARATION', 'Entretien et réparations des matériels et locaux'],
            ['AUTRE', 'Autres charges courantes d\'exploitation']
        ];
        
        $stmt_seed = $pdo->prepare("INSERT IGNORE INTO expense_categories (name, description) VALUES (?, ?)");
        foreach ($defaultCategories as $cat) {
            $stmt_seed->execute([$cat[0], $cat[1]]);
        }
    }

    // 16. Column is_historical in orders table (for pre-digitalization debts/credits)
    $checkIsHistorical = $pdo->query("SHOW COLUMNS FROM orders LIKE 'is_historical'");
    if (!$checkIsHistorical->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN is_historical TINYINT(1) NOT NULL DEFAULT 0 AFTER discount_reason");
    }

    // 17. Table: documents (Partage de fichiers Word, Excel, PDF, Images entre le Boss et les Employés)
    $checkDocuments = $pdo->query("SHOW TABLES LIKE 'documents'");
    if (!$checkDocuments->fetch()) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                category VARCHAR(100) NOT NULL DEFAULT 'Général',
                description TEXT NULL,
                file_name VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                file_size BIGINT NOT NULL DEFAULT 0,
                file_ext VARCHAR(20) NOT NULL,
                user_id INT NOT NULL,
                target_shop_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // 18. Auto-synchronisation de product_stock : chaque produit du catalogue doit exister dans chaque boutique
    try {
        $pdo->exec("
            INSERT IGNORE INTO product_stock (product_id, shop_id, quantity, initial_stock)
            SELECT p.id, s.id, 0, 0
            FROM products p
            CROSS JOIN shops s
            LEFT JOIN product_stock ps ON ps.product_id = p.id AND ps.shop_id = s.id
            WHERE ps.product_id IS NULL
        ");
    } catch (Exception $e) {}

    // 19. PILIER 2 : Indexation stratégique des tables pour performances maximales
    safeAddIndex($pdo, 'orders', 'idx_orders_type_status_created', 'type, status, created_at');
    safeAddIndex($pdo, 'orders', 'idx_orders_created_at', 'created_at');
    safeAddIndex($pdo, 'orders', 'idx_orders_payment_status', 'payment_status');
    safeAddIndex($pdo, 'orders', 'idx_orders_due_date', 'due_date');

    safeAddIndex($pdo, 'cash_transactions', 'idx_cash_shop_status_created', 'shop_id, status, created_at');
    safeAddIndex($pdo, 'cash_transactions', 'idx_cash_created_at', 'created_at');
    safeAddIndex($pdo, 'cash_transactions', 'idx_cash_type', 'type');
    safeAddIndex($pdo, 'cash_transactions', 'idx_cash_ref', 'reference_type, reference_id');

    safeAddIndex($pdo, 'products', 'idx_products_name', 'name');
    safeAddIndex($pdo, 'products', 'idx_products_min_stock', 'min_stock_level');

    safeAddIndex($pdo, 'stock_transfers', 'idx_transfers_status_created', 'status, created_at');
    safeAddIndex($pdo, 'stock_transfers', 'idx_transfers_to_shop_status', 'to_shop_id, status');
    safeAddIndex($pdo, 'stock_transfers', 'idx_transfers_code', 'transfer_code');

    safeAddIndex($pdo, 'stock_exits', 'idx_exits_created_at', 'created_at');
    safeAddIndex($pdo, 'stock_exits', 'idx_exits_reason', 'reason');

    safeAddIndex($pdo, 'order_items', 'idx_oi_order_product', 'order_id, product_id');
    safeAddIndex($pdo, 'order_items', 'idx_oi_product_qty', 'product_id, quantity');

        // 21. Table d'audit des ajustements de stock (séparée du compte de résultat)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS stock_adjustments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                shop_id INT NULL,
                user_id INT NULL,
                category VARCHAR(100) DEFAULT 'Ajustement Inventaire',
                amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                notes TEXT,
                adjustment_date DATE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Migrer les anciennes pertes de stock théoriques hors des dépenses d'exploitation
        $chk_cnt = $pdo->query("SELECT COUNT(*) FROM expenses WHERE category LIKE '%stock%' OR category LIKE '%inventaire%' OR category LIKE '%perte%'")->fetchColumn();
        if ($chk_cnt > 0) {
            $pdo->exec("
                INSERT IGNORE INTO stock_adjustments (id, shop_id, user_id, category, amount, notes, adjustment_date, created_at)
                SELECT id, shop_id, user_id, category, amount, notes, expense_date, created_at
                FROM expenses
                WHERE category LIKE '%stock%' OR category LIKE '%inventaire%' OR category LIKE '%perte%'
            ");
            $pdo->exec("DELETE FROM expenses WHERE category LIKE '%stock%' OR category LIKE '%inventaire%' OR category LIKE '%perte%'");
        }
    } catch (Exception $e) {}

    // 20. Séparation des gros achats fournisseurs de la petite caisse liquide du Boss
    try {
        $pdo->exec("
            UPDATE cash_transactions 
            SET reference_type = 'PURCHASE' 
            WHERE reference_type = 'ORDER' 
              AND type = 'OUT' 
              AND shop_id IS NULL
        ");
    } catch (Exception $e) {}

    // Enregistrer la version de schéma pour verrouiller les vérifications futures
    $pdo->prepare("REPLACE INTO app_settings (setting_key, setting_value) VALUES ('schema_version', ?)")->execute([strval(APP_SCHEMA_VERSION)]);
}

