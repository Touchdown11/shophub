<?php
/**
 * ShopHub — installer + migrator
 * ---------------------------------------------------------------------------
 * Creates every table and loads demo content. Safe to re-run: it upgrades an
 * existing installation in place (new tables/columns are added, existing data
 * is never dropped unless you tick "fresh install").
 *
 *   Browser : http://localhost/shophub/install.php
 *   CLI     : php install.php            (install/upgrade)
 *             php install.php --fresh    (drop everything and rebuild)
 *             php install.php --migrate  (apply pending migrations only)
 *
 * Works with MySQL (XAMPP default) and SQLite (zero-setup demo mode).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/variants.php';

$isCli   = PHP_SAPI === 'cli';
$argvList = $isCli ? ($argv ?? []) : [];
$fresh   = $isCli ? in_array('--fresh', $argvList, true) : (bool)($_POST['fresh'] ?? false);
$migrateOnly = $isCli ? in_array('--migrate', $argvList, true) : (bool)($_POST['migrate_only'] ?? false);
$confirm = $isCli ? true : (bool)($_POST['confirm'] ?? false);
$run     = $isCli || $confirm;

/* ------------------------------------------------------------------ helpers */

function table_exists(string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        if (Database::driver() === 'sqlite') {
            return $cache[$table] = (bool)Database::value(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
        }
        return $cache[$table] = (bool)Database::value(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
    } catch (Throwable) {
        return false;
    }
}

function column_exists(string $table, string $column): bool
{
    if (!table_exists($table)) {
        return false;
    }
    try {
        if (Database::driver() === 'sqlite') {
            foreach (Database::all("PRAGMA table_info($table)") as $row) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        return (bool)Database::value(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );
    } catch (Throwable) {
        return false;
    }
}

/** Add a column only when missing — keeps upgrades idempotent. */
function ensure_column(string $table, string $column, string $definition): ?string
{
    if (!table_exists($table) || column_exists($table, $column)) {
        return null;
    }
    Database::pdo()->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    return "Added $table.$column";
}

/* ------------------------------------------------------------------- schema */

/**
 * Failed sign-ins are counted per account, not per browser session: a session
 * counter can be reset by simply dropping the cookie, which makes it useless
 * against a scripted attack on the staff portal.
 */
function login_attempts_table_sql(): string
{
    $mysql = Database::driver() !== 'sqlite';
    $pk    = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $table = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    return "CREATE TABLE IF NOT EXISTS login_attempts (
            id            $pk,
            scope         VARCHAR(20)  NOT NULL DEFAULT 'store',
            identifier    VARCHAR(190) NOT NULL,
            attempts      INT NOT NULL DEFAULT 0,
            locked_until  DATETIME NULL,
            last_attempt  DATETIME
        )$table";
}

function schema(): array
{
    $mysql = Database::driver() !== 'sqlite';
    $pk    = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $fk    = $mysql ? 'INT UNSIGNED' : 'INTEGER';
    $table = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $ifNot = 'IF NOT EXISTS';

    return [
        'settings' => "CREATE TABLE $ifNot settings (
            setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value TEXT
        )$table",

        'users' => "CREATE TABLE $ifNot users (
            id                $pk,
            name              VARCHAR(160) NOT NULL,
            email             VARCHAR(190) NOT NULL UNIQUE,
            phone             VARCHAR(40)  DEFAULT '',
            password_hash     VARCHAR(255) NOT NULL,
            role              VARCHAR(20)  NOT NULL DEFAULT 'customer',
            status            VARCHAR(20)  NOT NULL DEFAULT 'active',
            email_verified_at DATETIME NULL,
            created_at        DATETIME,
            last_login        DATETIME
        )$table",

        'login_attempts' => login_attempts_table_sql(),

        'auth_tokens' => "CREATE TABLE $ifNot auth_tokens (
            id         $pk,
            user_id    $fk NOT NULL,
            type       VARCHAR(30) NOT NULL,
            token_hash VARCHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at    DATETIME NULL,
            ip         VARCHAR(45) DEFAULT '',
            created_at DATETIME
        )$table",

        'email_log' => "CREATE TABLE $ifNot email_log (
            id         $pk,
            recipient  VARCHAR(190) NOT NULL,
            subject    VARCHAR(220) DEFAULT '',
            template   VARCHAR(60) DEFAULT '',
            status     VARCHAR(20) NOT NULL DEFAULT 'sent',
            note       VARCHAR(500) DEFAULT '',
            created_at DATETIME
        )$table",

        'addresses' => "CREATE TABLE $ifNot addresses (
            id          $pk,
            user_id     $fk NOT NULL,
            label       VARCHAR(60) DEFAULT 'Shipping',
            full_name   VARCHAR(160),
            phone       VARCHAR(40),
            address     VARCHAR(255),
            city        VARCHAR(120),
            postal_code VARCHAR(30),
            country     VARCHAR(120) DEFAULT 'Sri Lanka',
            is_default  TINYINT NOT NULL DEFAULT 0,
            created_at  DATETIME
        )$table",

        'categories' => "CREATE TABLE $ifNot categories (
            id          $pk,
            name        VARCHAR(140) NOT NULL,
            slug        VARCHAR(160) NOT NULL UNIQUE,
            description TEXT,
            image       VARCHAR(400) DEFAULT '',
            parent_id   $fk NULL,
            sort_order  INT NOT NULL DEFAULT 0,
            is_active   TINYINT NOT NULL DEFAULT 1
        )$table",

        'products' => "CREATE TABLE $ifNot products (
            id          $pk,
            category_id $fk NULL,
            name        VARCHAR(220) NOT NULL,
            slug        VARCHAR(240) NOT NULL UNIQUE,
            sku         VARCHAR(80) DEFAULT '',
            brand       VARCHAR(120) DEFAULT '',
            short_desc  VARCHAR(400) DEFAULT '',
            description TEXT,
            specs       TEXT,
            price       DECIMAL(12,2) NOT NULL DEFAULT 0,
            sale_price  DECIMAL(12,2) NULL,
            stock       INT NOT NULL DEFAULT 0,
            image       VARCHAR(400) DEFAULT '',
            is_active   TINYINT NOT NULL DEFAULT 1,
            is_featured TINYINT NOT NULL DEFAULT 0,
            created_at  DATETIME,
            updated_at  DATETIME
        )$table",

        'product_images' => "CREATE TABLE $ifNot product_images (
            id         $pk,
            product_id $fk NOT NULL,
            image_path VARCHAR(400) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0
        )$table",

        'product_options' => "CREATE TABLE $ifNot product_options (
            id          $pk,
            product_id  $fk NOT NULL,
            name        VARCHAR(80) NOT NULL,
            values_json TEXT,
            sort_order  INT NOT NULL DEFAULT 0
        )$table",

        'product_variants' => "CREATE TABLE $ifNot product_variants (
            id           $pk,
            product_id   $fk NOT NULL,
            options_json TEXT,
            sku          VARCHAR(80) DEFAULT '',
            price        DECIMAL(12,2) NULL,
            stock        INT NOT NULL DEFAULT 0,
            image        VARCHAR(400) DEFAULT '',
            is_active    TINYINT NOT NULL DEFAULT 1,
            created_at   DATETIME
        )$table",

        'orders' => "CREATE TABLE $ifNot orders (
            id               $pk,
            user_id          $fk NULL,
            reference        VARCHAR(40) NOT NULL UNIQUE,
            email            VARCHAR(190) NOT NULL,
            customer_name    VARCHAR(160) NOT NULL,
            phone            VARCHAR(40)  DEFAULT '',
            shipping_address VARCHAR(400) DEFAULT '',
            city             VARCHAR(120) DEFAULT '',
            postal_code      VARCHAR(30)  DEFAULT '',
            country          VARCHAR(120) DEFAULT '',
            notes            TEXT,
            subtotal         DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount         DECIMAL(12,2) NOT NULL DEFAULT 0,
            shipping_fee     DECIMAL(12,2) NOT NULL DEFAULT 0,
            tax              DECIMAL(12,2) NOT NULL DEFAULT 0,
            total            DECIMAL(12,2) NOT NULL DEFAULT 0,
            coupon_code      VARCHAR(40) NULL,
            currency         VARCHAR(8) NOT NULL DEFAULT 'USD',
            payment_method   VARCHAR(30) NOT NULL DEFAULT 'cod',
            payment_status   VARCHAR(20) NOT NULL DEFAULT 'unpaid',
            payment_ref      VARCHAR(190) DEFAULT '',
            paid_at          DATETIME NULL,
            status           VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at       DATETIME,
            updated_at       DATETIME
        )$table",

        'order_items' => "CREATE TABLE $ifNot order_items (
            id            $pk,
            order_id      $fk NOT NULL,
            product_id    $fk NULL,
            variant_id    $fk NULL,
            product_name  VARCHAR(220) NOT NULL,
            variant_label VARCHAR(160) DEFAULT '',
            sku           VARCHAR(80) DEFAULT '',
            unit_price    DECIMAL(12,2) NOT NULL DEFAULT 0,
            quantity      INT NOT NULL DEFAULT 1,
            line_total    DECIMAL(12,2) NOT NULL DEFAULT 0
        )$table",

        'order_notes' => "CREATE TABLE $ifNot order_notes (
            id         $pk,
            order_id   $fk NOT NULL,
            user_id    $fk NULL,
            note       TEXT,
            created_at DATETIME
        )$table",

        'reviews' => "CREATE TABLE $ifNot reviews (
            id         $pk,
            product_id $fk NOT NULL,
            user_id    $fk NULL,
            name       VARCHAR(160) NOT NULL,
            rating     INT NOT NULL DEFAULT 5,
            title      VARCHAR(200) DEFAULT '',
            body       TEXT,
            status     VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME
        )$table",

        'wishlists' => "CREATE TABLE $ifNot wishlists (
            id         $pk,
            user_id    $fk NOT NULL,
            product_id $fk NOT NULL,
            created_at DATETIME,
            UNIQUE (user_id, product_id)
        )$table",

        'coupons' => "CREATE TABLE $ifNot coupons (
            id           $pk,
            code         VARCHAR(40) NOT NULL UNIQUE,
            type         VARCHAR(12) NOT NULL DEFAULT 'percent',
            value        DECIMAL(12,2) NOT NULL DEFAULT 0,
            min_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
            max_uses     INT NOT NULL DEFAULT 0,
            used_count   INT NOT NULL DEFAULT 0,
            starts_at    DATETIME NULL,
            expires_at   DATETIME NULL,
            is_active    TINYINT NOT NULL DEFAULT 1
        )$table",

        'messages' => "CREATE TABLE $ifNot messages (
            id         $pk,
            name       VARCHAR(160) NOT NULL,
            email      VARCHAR(190) NOT NULL,
            subject    VARCHAR(200) DEFAULT '',
            message    TEXT,
            user_id    $fk NULL,
            status     VARCHAR(20) NOT NULL DEFAULT 'new',
            created_at DATETIME
        )$table",

        'subscribers' => "CREATE TABLE $ifNot subscribers (
            id         $pk,
            email      VARCHAR(190) NOT NULL UNIQUE,
            created_at DATETIME
        )$table",

        'schema_migrations' => "CREATE TABLE $ifNot schema_migrations (
            id         VARCHAR(80) NOT NULL PRIMARY KEY,
            applied_at DATETIME
        )$table",
    ];
}

/**
 * Incremental upgrades for installations created by an earlier version.
 * Every step is idempotent, so running them repeatedly is harmless.
 */
function migrations(): array
{
    return [
        '2026_01_verify_email' => static function (): array {
            $out = [];
            if ($line = ensure_column('users', 'email_verified_at', 'DATETIME NULL')) {
                $out[] = $line;
            }
            if ($line = ensure_column('orders', 'paid_at', 'DATETIME NULL')) {
                $out[] = $line;
            }
            if ($line = ensure_column('orders', 'payment_ref', 'VARCHAR(190) DEFAULT \'\'')) {
                $out[] = $line;
            }
            // Existing accounts were created before verification existed.
            Database::run('UPDATE users SET email_verified_at = COALESCE(created_at, ?) WHERE email_verified_at IS NULL', [now()]);
            return $out;
        },

        '2026_04_login_attempts' => static function (): array {
            Database::run(login_attempts_table_sql());
            try {
                Database::run('CREATE UNIQUE INDEX login_attempts_unique ON login_attempts (scope, identifier)');
            } catch (Throwable $e) {
                // Already indexed, or the engine named it differently — not fatal.
            }
            return ['failed sign-ins are now counted per account'];
        },

        '2026_03_bank_setting_key' => static function (): array {
            // The bank-transfer switch used to be stored as payment_bank_enabled,
            // while the settings screen writes payment_bank_transfer_enabled.
            $legacy = (string)Database::value('SELECT setting_value FROM settings WHERE setting_key = ?', ['payment_bank_enabled']);
            if ($legacy === '') {
                return [];
            }
            if ((int)Database::value('SELECT COUNT(*) FROM settings WHERE setting_key = ?', ['payment_bank_transfer_enabled']) === 0) {
                Database::insert('settings', ['setting_key' => 'payment_bank_transfer_enabled', 'setting_value' => $legacy]);
            }
            Database::run('DELETE FROM settings WHERE setting_key = ?', ['payment_bank_enabled']);
            return ['bank-transfer setting moved to payment_bank_transfer_enabled'];
        },

        '2026_02_variants' => static function (): array {
            $out = [];
            if ($line = ensure_column('order_items', 'variant_id', Database::driver() === 'sqlite' ? 'INTEGER NULL' : 'INT UNSIGNED NULL')) {
                $out[] = $line;
            }
            if ($line = ensure_column('order_items', 'variant_label', 'VARCHAR(160) DEFAULT \'\'')) {
                $out[] = $line;
            }
            if ($line = ensure_column('order_items', 'sku', 'VARCHAR(80) DEFAULT \'\'')) {
                $out[] = $line;
            }
            return $out;
        },
    ];
}

function migrations_applied(): array
{
    if (!table_exists('schema_migrations')) {
        return [];
    }
    return array_column(Database::all('SELECT id FROM schema_migrations'), 'id');
}

function run_migrations(array &$log): void
{
    $applied = migrations_applied();
    foreach (migrations() as $id => $migration) {
        if (in_array($id, $applied, true)) {
            continue;
        }
        $changes = $migration();
        Database::run('DELETE FROM schema_migrations WHERE id = ?', [$id]);
        Database::insert('schema_migrations', ['id' => $id, 'applied_at' => now()]);
        $log[] = 'Migration ' . $id . ': ' . ($changes ? implode(', ', $changes) : 'no changes needed');
    }
}

/* -------------------------------------------------------------- seed content */

function seed_data(): array
{
    $img = static fn(string $slug): string => 'assets/img/products/' . $slug . '.svg';

    $categories = [
        ['Electronics',       'electronics',       'Audio, wearables and everyday tech that earns its place.',    'shophub-electronics'],
        ['Fashion',           'fashion',           'Outerwear, knitwear and footwear built to last.',             'shophub-fashion'],
        ['Home & Living',     'home-living',       'Objects that make a room feel considered.',                   'shophub-home'],
        ['Beauty & Care',     'beauty-care',       'Skincare and fragrance with honest formulations.',            'shophub-beauty'],
        ['Sports & Outdoors', 'sports-outdoors',   'Gear for trails, roads and everything between.',              'shophub-sports'],
        ['Accessories',       'accessories',       'Watches, eyewear and leather that ages well.',                'shophub-accessories'],
    ];

    $products = [
        ['Auron Pro Wireless Headphones', 'auron-pro-wireless-headphones', 'Electronics', 'Auron', 'AUR-1001',
         249.00, 199.00, 42, true,
         'Studio-grade active noise cancelling with 40-hour battery life.',
         "The Auron Pro pairs a 40mm beryllium-coated driver with hybrid ANC that tunes itself to your environment.\n\nMemory-foam earcups keep long sessions comfortable, and the aluminium yoke survives being thrown in a bag. Multipoint Bluetooth 5.3 keeps a laptop and phone connected at the same time.",
         ['Driver' => '40 mm beryllium-coated', 'Battery' => '40 hours (ANC on)', 'Codec' => 'LDAC / aptX Adaptive', 'Weight' => '268 g', 'Warranty' => '2 years']],
        ['Nimbus 14 Ultrabook', 'nimbus-14-ultrabook', 'Electronics', 'Nimbus', 'NIM-1402',
         1299.00, 1149.00, 12, true,
         'A 1.1 kg magnesium laptop with a 14-inch 3K display and 18-hour battery.',
         "Built for people who work from anywhere: a 14-inch 3K 120Hz panel, 32GB of unified memory and a fanless chassis that stays silent under load.\n\nCharges over USB-C at 65W, so one brick covers laptop, phone and headphones.",
         ['Display' => '14" 3K 120Hz IPS', 'Memory' => '32 GB unified', 'Storage' => '1 TB NVMe', 'Battery' => '18 hours typical', 'Weight' => '1.1 kg']],
        ['PulseFit Smartwatch Series 6', 'pulsefit-smartwatch-series-6', 'Electronics', 'PulseFit', 'PUL-6031',
         329.00, 279.00, 30, true,
         'ECG, SpO2 and 12-day battery in a titanium case.',
         "Track training load, sleep stages and recovery with clinical-grade sensors. The sapphire crystal shrugs off daily wear and the titanium case keeps it light at 43 g.",
         ['Case' => '43 mm titanium', 'Glass' => 'Sapphire crystal', 'Battery' => 'Up to 12 days', 'Water rating' => '10 ATM', 'Sensors' => 'ECG, SpO2, GPS']],
        ['Lumen 4K Action Camera', 'lumen-4k-action-camera', 'Electronics', 'Lumen', 'LUM-4410',
         389.00, null, 18, false,
         '4K/120fps stabilised capture with a magnetic quick-release mount.',
         "Shoot handheld or mount it anywhere with the magnetic system. HyperSteady stabilisation keeps footage usable on rough ground, and the 1/1.7-inch sensor holds detail in low light.",
         ['Sensor' => '1/1.7" CMOS', 'Video' => '4K 120fps / 1080p 240fps', 'Stabilisation' => 'HyperSteady 3.0', 'Waterproof' => '12 m without housing', 'Storage' => 'microSD to 1 TB']],
        ['Volt 20,000mAh Power Bank', 'volt-20000mah-power-bank', 'Electronics', 'Volt', 'VOL-2005',
         79.00, 59.00, 85, false,
         '140W USB-C PD output that charges a laptop and two phones at once.',
         "Three ports, 140W total, with a small display that reports the exact wattage flowing to each device. Airline-safe capacity.",
         ['Capacity' => '20,000 mAh / 74 Wh', 'Output' => '140 W USB-C PD 3.1', 'Ports' => '2× USB-C, 1× USB-A', 'Recharge' => '1.2 hours at 100 W', 'Weight' => '435 g']],
        ['Echo Mini Bluetooth Speaker', 'echo-mini-bluetooth-speaker', 'Electronics', 'Echo', 'ECH-3300',
         129.00, null, 60, false,
         'Room-filling sound from a speaker the size of a coffee cup.',
         "A passive bass radiator gives this little box surprising low end. IP67 rating means it lives by the pool without complaint.",
         ['Output' => '20 W stereo', 'Battery' => '16 hours', 'Rating' => 'IP67 waterproof', 'Pairing' => 'Stereo pairing of two units', 'Weight' => '540 g']],
        ['Cashmere Blend Overcoat', 'cashmere-blend-overcoat', 'Fashion', 'Marlow', 'MAR-7101',
         349.00, 289.00, 22, true,
         'A tailored overcoat in a wool-cashmere blend, cut for layering.',
         "Woven in a 70/30 wool-cashmere blend with a half-canvas front, so it drapes rather than bubbles. Raglan sleeves and a below-knee length keep it comfortable over a suit or knitwear.",
         ['Fabric' => '70% wool, 30% cashmere', 'Lining' => 'Cupro satin', 'Length' => '108 cm (size M)', 'Care' => 'Dry clean', 'Made in' => 'Portugal']],
        ['Merino Crewneck Jumper', 'merino-crewneck-jumper', 'Fashion', 'Northloom', 'NOR-3120',
         129.00, null, 55, false,
         'Extra-fine 19.5 micron merino that layers without bulk.',
         "Knitted in a fine gauge that reads smart but feels like a t-shirt. Naturally temperature-regulating and machine washable on a wool cycle.",
         ['Yarn' => '100% extra-fine merino', 'Micron' => '19.5', 'Weight' => '280 g', 'Care' => 'Machine wash 30°C wool cycle', 'Fit' => 'Regular']],
        ['Selvedge Denim Jacket', 'selvedge-denim-jacket', 'Fashion', 'Ironhold', 'IRO-5202',
         219.00, 179.00, 26, false,
         '14 oz Japanese selvedge that fades to your shape.',
         "Cut on vintage Type III lines with a slightly longer body. Raw 14 oz selvedge denim from a Japanese mill, chain-stitched hems and copper hardware that patinas beautifully.",
         ['Denim' => '14 oz raw selvedge', 'Mill' => 'Okayama, Japan', 'Hardware' => 'Copper rivets, YKK zip', 'Fit' => 'Regular, mid-length body', 'Care' => 'Wash cold, inside out']],
        ['Leather Weekender Duffle', 'leather-weekender-duffle', 'Fashion', 'Ashcroft', 'ASH-8810',
         429.00, null, 14, false,
         'Full-grain vegetable-tanned leather, cabin-sized and built to age.',
         "Made from a single hide of vegetable-tanned leather with solid brass hardware. Fits a 45-litre cabin allowance with a separate shoe compartment.",
         ['Material' => 'Full-grain vegetable-tanned leather', 'Capacity' => '45 litres', 'Hardware' => 'Solid brass', 'Dimensions' => '55 × 30 × 28 cm', 'Warranty' => 'Lifetime repair']],
        ['Suede Chelsea Boots', 'suede-chelsea-boots', 'Fashion', 'Ashcroft', 'ASH-6620',
         279.00, 219.00, 20, false,
         'Water-resistant suede on a Goodyear-welted sole you can resole.',
         "A clean Chelsea silhouette in water-resistant Italian suede, Goodyear welted so they can be resoled for years. Elastic gussets in a matching tone.",
         ['Upper' => 'Water-resistant Italian suede', 'Construction' => 'Goodyear welted', 'Sole' => 'Dainite rubber', 'Last' => 'Rounded, true to size', 'Care' => 'Suede brush + protector']],
        ['Nero Ceramic Coffee Set', 'nero-ceramic-coffee-set', 'Home & Living', 'Atelier Nero', 'ATN-1200',
         89.00, null, 48, false,
         'Matte-black stoneware for four, dishwasher friendly.',
         "Hand-glazed stoneware in a matte black that shows the maker's marks. Six pieces: four cups, a server and a sugar bowl.",
         ['Pieces' => '4 cups, 1 server, 1 sugar bowl', 'Material' => 'Stoneware', 'Volume' => '220 ml per cup', 'Dishwasher' => 'Yes', 'Finish' => 'Matte black glaze']],
        ['Aurelia Table Lamp', 'aurelia-table-lamp', 'Home & Living', 'Aurelia', 'AUR-2200',
         149.00, 119.00, 24, false,
         'Brushed brass with a dimmable warm-white LED and touch control.',
         "A weighted brass base and an opal glass diffuser that throws soft light without glare. Three brightness levels, remembered when you switch it back on.",
         ['Base' => 'Brushed brass', 'Diffuser' => 'Opal glass', 'Colour temp' => '2700 K warm white', 'Control' => 'Touch, 3 levels', 'Height' => '42 cm']],
        ['Linen Bedding Set — Queen', 'linen-bedding-set-queen', 'Home & Living', 'Northloom', 'NOR-4400',
         189.00, null, 33, false,
         'Stonewashed European flax that gets softer with every wash.',
         "A complete queen set: duvet cover, fitted sheet and two pillowcases in 100% European flax linen. Breathable in summer, warm in winter.",
         ['Material' => '100% European flax linen', 'Includes' => 'Duvet cover, fitted sheet, 2 cases', 'Fit' => 'Queen (150 × 200 cm)', 'Care' => 'Machine wash 40°C', 'Certification' => 'OEKO-TEX Standard 100']],
        ['Ebony Oak Serving Board', 'ebony-oak-serving-board', 'Home & Living', 'Ironhold', 'IRO-1510',
         65.00, null, 40, false,
         'End-grain oak with an ebony handle and a juice groove.',
         "End-grain construction is kinder to knife edges and hides cuts. Finished with food-safe oil and beeswax.",
         ['Material' => 'End-grain oak, ebony handle', 'Size' => '45 × 30 × 3 cm', 'Finish' => 'Food-safe oil and beeswax', 'Care' => 'Hand wash, re-oil monthly', 'Weight' => '2.1 kg']],
        ['Diffuser & Candle Gift Set', 'diffuser-candle-gift-set', 'Home & Living', 'Aurelia', 'AUR-7730',
         74.00, 59.00, 52, false,
         'Bergamot, cedar and vanilla — reed diffuser plus a 220 g candle.',
         "A gift-ready pairing: 100 ml reed diffuser and a hand-poured soy candle in a reusable vessel, both in the same bergamot and cedar accord.",
         ['Scent' => 'Bergamot, cedar, vanilla', 'Diffuser' => '100 ml, ~10 weeks', 'Candle' => '220 g soy wax, ~45 hours', 'Vessel' => 'Reusable amber glass', 'Packaging' => 'Gift box included']],
        ['Vitamin C Radiance Serum', 'vitamin-c-radiance-serum', 'Beauty & Care', 'Lumea', 'LUM-1008',
         46.00, null, 90, false,
         '15% stabilised vitamin C with ferulic acid, fragrance free.',
         "A brightening serum that uses 15% ethylated ascorbic acid — stable, gentle and effective — buffered with ferulic acid and vitamin E.",
         ['Active' => '15% ethylated vitamin C', 'Support' => 'Ferulic acid, vitamin E', 'Skin type' => 'All, including sensitive', 'Volume' => '30 ml', 'Fragrance' => 'None']],
        ['Oud & Amber Eau de Parfum', 'oud-amber-eau-de-parfum', 'Beauty & Care', 'Maison Noir', 'MAS-4404',
         158.00, 129.00, 28, true,
         'Smoked oud, amber resin and a dry cedar finish. 50 ml.',
         "A dense, evening-leaning fragrance: saffron and pink pepper open onto smoked oud and amber resin, drying down to cedar and a trace of leather. 18–24% concentration.",
         ['Concentration' => 'Eau de parfum, 22%', 'Notes' => 'Saffron, oud, amber, cedar', 'Longevity' => '8–10 hours', 'Volume' => '50 ml', 'Made in' => 'Grasse, France']],
        ['Silk Recovery Hair Mask', 'silk-recovery-hair-mask', 'Beauty & Care', 'Lumea', 'LUM-2201',
         38.00, null, 70, false,
         'Hydrolysed silk protein for bleached and heat-stressed hair.',
         "A five-minute mask that rebuilds bonds in over-processed hair. Silicone-free and safe for colour-treated hair.",
         ['Key ingredient' => 'Hydrolysed silk protein', 'Use' => '5 minutes, once weekly', 'Hair type' => 'Damaged, colour-treated', 'Volume' => '250 ml', 'Silicones' => 'Free']],
        ['Clay Detox Face Mask', 'clay-detox-face-mask', 'Beauty & Care', 'Lumea', 'LUM-3312',
         29.00, null, 80, false,
         'Kaolin and charcoal that clear pores without stripping.',
         "Kaolin draws out oil while glycerin and panthenol keep the barrier intact, so skin feels clean rather than tight.",
         ['Clays' => 'Kaolin, bentonite', 'Extras' => 'Charcoal, panthenol', 'Skin type' => 'Oily and combination', 'Volume' => '100 ml', 'Use' => 'Twice weekly']],
        ['Trail Master Hiking Backpack 35L', 'trail-master-hiking-backpack-35l', 'Sports & Outdoors', 'Ridgeway', 'RID-3500',
         139.00, 109.00, 36, false,
         'A 35-litre ripstop pack with a ventilated back panel.',
         "Weighs 1.1 kg, carries 12 kg comfortably. Suspended mesh back panel, load-lifter straps and a rain cover in the base.",
         ['Capacity' => '35 litres', 'Weight' => '1.1 kg', 'Fabric' => '210D ripstop nylon', 'Back system' => 'Suspended mesh', 'Extras' => 'Rain cover included']],
        ['Carbon Road Helmet', 'carbon-road-helmet', 'Sports & Outdoors', 'Velo', 'VEL-8801',
         179.00, null, 25, false,
         '240 g carbon-shell helmet with rotational-impact protection.',
         "Wind-tunnel shaped vents keep air moving at low speed, and the rotational liner reduces angular forces in a crash. Boa dial fits in seconds.",
         ['Weight' => '240 g (size M)', 'Shell' => 'Carbon composite', 'Safety' => 'MIPS rotational liner', 'Fit' => 'Boa dial', 'Vents' => '16 wind-tunnel vents']],
        ['Yoga Alignment Mat', 'yoga-alignment-mat', 'Sports & Outdoors', 'Stillform', 'STI-4410',
         89.00, 69.00, 44, false,
         'Natural rubber with alignment lines, 5 mm of quiet cushioning.',
         "Grip that holds through a sweaty vinyasa, plus subtle alignment markings to square your hands and feet without a mirror.",
         ['Material' => 'Natural rubber, PU top', 'Thickness' => '5 mm', 'Size' => '183 × 68 cm', 'Weight' => '2.4 kg', 'Grip' => 'Wet and dry']],
        ['Insulated Steel Bottle 750ml', 'insulated-steel-bottle-750ml', 'Sports & Outdoors', 'Stillform', 'STI-7700',
         39.00, null, 120, false,
         'Keeps drinks cold 24 hours, hot 12. Powder-coated, dent-resistant.',
         "Double-walled 18/8 stainless steel with a leak-proof lid and a wide mouth that takes ice cubes and fits most filters.",
         ['Capacity' => '750 ml', 'Material' => '18/8 stainless steel', 'Cold' => '24 hours', 'Hot' => '12 hours', 'Weight' => '395 g']],
        ['Heritage Automatic Watch', 'heritage-automatic-watch', 'Accessories', 'Ashcroft', 'ASH-9901',
         649.00, 549.00, 9, true,
         'Swiss automatic movement, sapphire crystal, 100 m water resistance.',
         "A 38 mm dress-diver with a Swiss Sellita automatic movement, 72-hour power reserve and a domed sapphire crystal. Comes on a tapered leather strap with a quick-release system.",
         ['Movement' => 'Swiss automatic (Sellita SW200)', 'Power reserve' => '72 hours', 'Case' => '38 mm stainless steel', 'Crystal' => 'Domed sapphire, AR coated', 'Water resistance' => '100 m']],
        ['Titanium Aviator Sunglasses', 'titanium-aviator-sunglasses', 'Accessories', 'Lumen', 'LUM-8812',
         219.00, null, 31, false,
         'Featherweight titanium frames with polarised Zeiss lenses.',
         "18 g frames with adjustable nose pads and polarised Zeiss lenses in a classic aviator shape. Comes with a hard case and microfibre pouch.",
         ['Frame' => 'Beta-titanium, 18 g', 'Lenses' => 'Polarised Zeiss', 'UV' => '100% UVA/UVB', 'Lens category' => '3', 'Extras' => 'Hard case, cloth']],
        ['Full-Grain Leather Wallet', 'full-grain-leather-wallet', 'Accessories', 'Ashcroft', 'ASH-1102',
         95.00, null, 58, false,
         'Eight card slots, RFID-blocking lining, hand-stitched edges.',
         "Cut from the same full-grain hides as our bags and saddle-stitched by hand. RFID-blocking lining keeps contactless cards yours.",
         ['Material' => 'Full-grain leather', 'Capacity' => '8 cards + notes', 'Stitching' => 'Hand saddle-stitched', 'RFID' => 'Blocking lining', 'Dimensions' => '11 × 9 cm']],
    ];

    /**
     * Demo variants: slug => [option definitions]. Stocks/prices are generated
     * for the full cartesian product so the storefront has real combinations.
     */
    $variantSeed = [
        'merino-crewneck-jumper' => [
            ['name' => 'Size',   'values' => ['S', 'M', 'L', 'XL'], 'priceDelta' => 0],
            ['name' => 'Colour', 'values' => ['Black', 'Camel'],     'priceDelta' => 0],
        ],
        'suede-chelsea-boots' => [
            ['name' => 'Size', 'values' => ['40', '41', '42', '43', '44'], 'priceDelta' => 0],
        ],
        'yoga-alignment-mat' => [
            ['name' => 'Colour', 'values' => ['Charcoal', 'Deep Gold', 'Olive'], 'priceDelta' => 0],
        ],
        'insulated-steel-bottle-750ml' => [
            ['name' => 'Colour', 'values' => ['Matte Black', 'Brushed Steel', 'Gold'], 'priceDelta' => 2],
        ],
        'full-grain-leather-wallet' => [
            ['name' => 'Colour', 'values' => ['Black', 'Tan'], 'priceDelta' => 0],
        ],
    ];

    $users = [
        ['Ayesha Fernando', 'admin@shophub.test',    '071 234 5678', 'admin123',    'admin'],
        ['Amara Perera',    'amara@example.com',     '077 555 1122', 'password123', 'customer'],
        ['Ruwan Silva',     'ruwan@example.com',     '076 444 8899', 'password123', 'customer'],
        ['Nadia Fonseka',   'nadia@example.com',     '075 222 3311', 'password123', 'customer'],
    ];

    return compact('categories', 'products', 'variantSeed', 'users', 'img');
}

function seed_coupons(): array
{
    $now = date('Y-m-d H:i:s');
    return [
        ['WELCOME15', 'percent', 15.00, 0,   0,   null, date('Y-m-d H:i:s', strtotime('+1 year')), 1],
        ['GOLD10',    'percent', 10.00, 100, 0,   null, date('Y-m-d H:i:s', strtotime('+6 months')), 1],
        ['FREESHIP',  'fixed',   12.00, 60,  500, $now, date('Y-m-d H:i:s', strtotime('+3 months')), 1],
        ['FLASH20',   'percent', 20.00, 250, 100, null, date('Y-m-d H:i:s', strtotime('+30 days')), 1],
    ];
}

/** Default settings, including the new payments + mail groups. */
function seed_settings(): array
{
    return [
        'store_name'         => 'ShopHub',
        'currency'           => 'USD',
        'currency_symbol'    => '$',
        'secondary_rate'     => '305',
        'secondary_symbol'   => 'Rs.',
        'shipping_flat'      => '12',
        'free_shipping_over' => '150',
        'tax_percent'        => '8',
        'items_per_page'     => '12',
        'announcement'       => 'Members save 15% — use code WELCOME15 at checkout',
        'bank_account_name'  => 'ShopHub Trading',
        'bank_account_number'=> '0012 3456 7890',
        'bank_name'          => 'Bank of Ceylon, Colombo 03',

        // Payments
        'payment_cod_enabled'      => '1',
        'payment_bank_transfer_enabled' => '1',
        'payment_stripe_enabled'   => '0',
        'stripe_publishable_key'   => '',
        'stripe_secret_key'        => '',
        'payment_payhere_enabled'  => '0',
        'payhere_merchant_id'      => '',
        'payhere_merchant_secret'  => '',
        'payhere_mode'             => 'sandbox',
        'payhere_currency'         => '',   // blank = charge in the order currency

        // Mail
        'mail_driver'         => 'log',
        'mail_from'           => 'no-reply@shophub.test',
        'mail_from_name'      => 'ShopHub',
        'mail_reply_to'       => 'hello@shophub.test',
        'smtp_host'           => 'smtp.gmail.com',
        'smtp_port'           => '587',
        'smtp_secure'         => 'tls',
        'smtp_user'           => '',
        'smtp_pass'           => '',
        'notify_admin_on_order' => '1',
    ];
}

/* --------------------------------------------------------------------- setup */

$log   = [];
$error = null;

if ($run) {
    try {
        $pdo    = Database::pdo();
        $driver = Database::driver();

        if ($driver === 'mysql') {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        }

        if ($fresh) {
            foreach (array_reverse(array_keys(schema())) as $table) {
                $pdo->exec("DROP TABLE IF EXISTS $table");
            }
            $log[] = 'Dropped existing tables (fresh install).';
        }

        $existing = table_exists('users') && (int)Database::value('SELECT COUNT(*) FROM users') > 0;

        foreach (schema() as $name => $sql) {
            $pdo->exec($sql);
        }
        if ($driver === 'mysql') {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        $log[] = count(schema()) . ' tables verified/created.';

        // Bring older databases up to date before anything else touches them.
        run_migrations($log);

        $productCount = (int)Database::value('SELECT COUNT(*) FROM products');
        $orderCount   = (int)Database::value('SELECT COUNT(*) FROM orders');

        if ($migrateOnly) {
            $log[] = 'Migrations only — catalogue left untouched.';
        } elseif ($productCount > 0 || $orderCount > 0) {
            $log[] = 'Existing catalogue found — demo content left untouched. '
                . 'Tick "Drop existing tables first" (or run with --fresh) to rebuild it.';
        } else {
            if ($existing) {
                $log[] = 'Empty catalogue with existing accounts detected — finishing the earlier interrupted install.';
            }
            $data = seed_data();

            $catIds = [];
            foreach ($data['categories'] as $i => [$name, $slug, $desc, $seed]) {
                $catIds[$name] = (int)Database::value('SELECT id FROM categories WHERE slug = ?', [$slug]) ?: Database::insert('categories', [
                    'name'        => $name,
                    'slug'        => $slug,
                    'description' => $desc,
                    'image'       => 'assets/img/categories/' . $slug . '.svg',
                    'sort_order'  => $i,
                    'is_active'   => 1,
                ]);
            }
            $log[] = count($catIds) . ' categories created.';

            $productIds = [];
            $bySlug = [];
            foreach ($data['products'] as $p) {
                [$name, $slug, $cat, $brand, $sku, $price, $sale, $stock, $featured, $short, $long, $specs] = $p;
                $id = Database::insert('products', [
                    'category_id' => $catIds[$cat] ?? null,
                    'name'        => $name,
                    'slug'        => $slug,
                    'sku'         => $sku,
                    'brand'       => $brand,
                    'short_desc'  => $short,
                    'description' => $long,
                    'specs'       => json_encode($specs, JSON_UNESCAPED_UNICODE),
                    'price'       => $price,
                    'sale_price'  => $sale,
                    'stock'       => $stock,
                    'image'       => $data['img']($slug),
                    'is_active'   => 1,
                    'is_featured' => $featured ? 1 : 0,
                    'created_at'  => date('Y-m-d H:i:s', strtotime('-' . random_int(1, 60) . ' days')),
                    'updated_at'  => now(),
                ]);
                $productIds[] = $id;
                $bySlug[$slug] = $id;

                foreach (['a', 'b'] as $k => $suffix) {
                    Database::insert('product_images', [
                        'product_id' => $id,
                        'image_path' => $data['img']($slug . '-' . $suffix),
                        'sort_order' => $k,
                    ]);
                }
            }
            $log[] = count($productIds) . ' products created (with gallery images).';

            /* ---- demo variants ------------------------------------------ */
            $variantCount = 0;
            foreach ($data['variantSeed'] as $slug => $definitions) {
                if (empty($bySlug[$slug])) {
                    continue;
                }
                $productId = $bySlug[$slug];
                $product   = Database::row('SELECT * FROM products WHERE id = ?', [$productId]);

                // Build every combination of the option values.
                $combos = [[]];
                $options = [];
                foreach ($definitions as $i => $definition) {
                    $options[] = ['name' => $definition['name'], 'values' => $definition['values']];
                    $next = [];
                    foreach ($combos as $combo) {
                        foreach ($definition['values'] as $value) {
                            $next[] = $combo + [$definition['name'] => $value];
                        }
                    }
                    $combos = $next;
                }

                $rows = [];
                foreach ($combos as $combo) {
                    $delta = 0;
                    foreach ($definitions as $definition) {
                        if (($definition['priceDelta'] ?? 0) && in_array($combo[$definition['name']], ['Gold', 'Brushed Steel'], true)) {
                            $delta = (float)$definition['priceDelta'];
                        }
                    }
                    $rows[] = [
                        'options'   => $combo,
                        'sku'       => $product['sku'] . '-' . implode('-', array_map(static fn($v) => strtoupper(substr((string)$v, 0, 3)), array_values($combo))),
                        'price'     => $delta > 0 ? round(product_price($product) + $delta, 2) : null,
                        'stock'     => random_int(0, 14),
                        'is_active' => 1,
                    ];
                }
                // Demo data deliberately includes a sold-out combination (and one
                // product where every combination is gone) so the shop shows what
                // "Sold out" looks like before a real catalogue exists.
                if ($rows) {
                    $rows[count($rows) - 1]['stock'] = 0;
                }
                if ($slug === array_key_last($data['variantSeed'])) {
                    foreach ($rows as $i => $row) {
                        $rows[$i]['stock'] = 0;
                    }
                }

                save_variants($productId, $options, $rows);
                $variantCount += count($rows);

                // Parent stock mirrors the variant ledger.
                Database::run(
                    'UPDATE products SET stock = (SELECT COALESCE(SUM(stock),0) FROM product_variants WHERE product_id = ? AND is_active = 1) WHERE id = ?',
                    [$productId, $productId]
                );
            }
            $log[] = $variantCount . ' product variants created across ' . count($data['variantSeed']) . ' products.';

            $userIds = [];
            foreach ($data['users'] as $u) {
                [$name, $email, $phone, $pass, $role] = $u;
                $existingId = (int)Database::value('SELECT id FROM users WHERE LOWER(email) = ?', [strtolower($email)]);
                if ($existingId > 0) {
                    $userIds[$role === 'admin' ? 'admin' : $email] = $existingId;
                    continue;
                }
                $userIds[$role === 'admin' ? 'admin' : $email] = Database::insert('users', [
                    'name'              => $name,
                    'email'             => $email,
                    'phone'             => $phone,
                    'password_hash'     => password_hash($pass, PASSWORD_DEFAULT),
                    'role'              => $role,
                    'status'            => 'active',
                    'email_verified_at' => now(),
                    'created_at'        => date('Y-m-d H:i:s', strtotime('-' . random_int(20, 240) . ' days')),
                ]);
            }
            $log[] = count($userIds) . ' user accounts created (all emails pre-verified).';

            foreach (seed_coupons() as [$code, $type, $value, $min, $maxUses, $starts, $expires, $active]) {
                if ((int)Database::value('SELECT COUNT(*) FROM coupons WHERE code = ?', [$code]) > 0) {
                    continue;
                }
                Database::insert('coupons', [
                    'code' => $code, 'type' => $type, 'value' => $value, 'min_subtotal' => $min,
                    'max_uses' => $maxUses, 'used_count' => random_int(0, 40),
                    'starts_at' => $starts, 'expires_at' => $expires, 'is_active' => $active,
                ]);
            }
            $log[] = 'Coupons created (WELCOME15, GOLD10, FREESHIP, FLASH20).';

            foreach ([$userIds['amara@example.com'] ?? null, $userIds['ruwan@example.com'] ?? null] as $i => $uid) {
                if (!$uid) {
                    continue;
                }
                Database::insert('addresses', [
                    'user_id'     => $uid,
                    'label'       => $i === 0 ? 'Home' : 'Office',
                    'full_name'   => $i === 0 ? 'Amara Perera' : 'Ruwan Silva',
                    'phone'       => $i === 0 ? '077 555 1122' : '076 444 8899',
                    'address'     => $i === 0 ? '18/2 Temple Lane, Bambalapitiya' : 'Level 7, Orion Tower, Union Place',
                    'city'        => $i === 0 ? 'Colombo 04' : 'Colombo 02',
                    'postal_code' => $i === 0 ? '00400' : '00200',
                    'country'     => 'Sri Lanka',
                    'is_default'  => 1,
                    'created_at'  => now(),
                ]);
            }

            /* ---- sample orders ------------------------------------------ */
            $customers = [
                ['amara@example.com', 'Amara Perera', '077 555 1122', '18/2 Temple Lane, Bambalapitiya', 'Colombo 04'],
                ['ruwan@example.com', 'Ruwan Silva', '076 444 8899', 'Level 7, Orion Tower, Union Place', 'Colombo 02'],
                ['nadia@example.com', 'Nadia Fonseka', '075 222 3311', '22 Lighthouse Street', 'Galle Fort'],
            ];
            $statuses = ['delivered', 'delivered', 'shipped', 'processing', 'pending', 'cancelled', 'delivered', 'processing', 'delivered', 'shipped'];
            $methods  = ['cod', 'bank_transfer', 'cod', 'stripe'];

            foreach ($statuses as $i => $status) {
                [$email, $name, $phone, $addr, $city] = $customers[$i % 3];
                $daysAgo = 20 - ($i * 1.6);
                $created = date('Y-m-d H:i:s', (int)strtotime('-' . max(0, (int)$daysAgo) . ' days'));

                $picks = array_rand($productIds, random_int(1, 3));
                $picks = is_array($picks) ? $picks : [$picks];

                $subtotal = 0.0;
                $lines    = [];
                foreach ((array)$picks as $index) {
                    $pid  = $productIds[$index];
                    $prod = Database::row('SELECT * FROM products WHERE id = ?', [$pid]);

                    // Multi-variant lines pick one combination at random. The pick happens in
                    // PHP on purpose: RANDOM() is SQLite-only and RAND() is MySQL-only, and a
                    // dialect slip here would break the whole install.
                    $variant = null;
                    if ((int)$prod['stock'] > 0) {
                        $active = Database::all(
                            'SELECT * FROM product_variants WHERE product_id = ? AND is_active = 1',
                            [$pid]
                        );
                        if ($active) {
                            $variant = $active[random_int(0, count($active) - 1)];
                        }
                    }

                    $qty  = random_int(1, 2);
                    $unit = $variant ? variant_price($variant, $prod) : product_price($prod);
                    $line = $unit * $qty;
                    $subtotal += $line;

                    $options = $variant ? (json_decode((string)$variant['options_json'], true) ?: []) : [];
                    $lines[] = [
                        'id'      => $pid,
                        'vid'     => $variant['id'] ?? null,
                        'name'    => $prod['name'],
                        'label'   => $options ? variant_label($options) : '',
                        'sku'     => $variant['sku'] ?? $prod['sku'],
                        'unit'    => $unit,
                        'qty'     => $qty,
                        'line'    => $line,
                    ];
                }

                $shipping = $subtotal >= 150 ? 0 : 12;
                $tax      = round($subtotal * 0.08, 2);
                $total    = round($subtotal + $shipping + $tax, 2);
                $method   = $methods[$i % 4];

                $orderId = Database::insert('orders', [
                    'user_id'          => $userIds[$email] ?? null,
                    'reference'        => 'SH-' . date('ymd', strtotime($created)) . '-' . strtoupper(bin2hex(random_bytes(3))),
                    'email'            => $email,
                    'customer_name'    => $name,
                    'phone'            => $phone,
                    'shipping_address' => $addr,
                    'city'             => $city,
                    'postal_code'      => '00100',
                    'country'          => 'Sri Lanka',
                    'notes'            => $i % 4 === 0 ? 'Please call before delivering.' : null,
                    'subtotal'         => $subtotal,
                    'discount'         => 0,
                    'shipping_fee'     => $shipping,
                    'tax'              => $tax,
                    'total'            => $total,
                    'currency'         => 'USD',
                    'payment_method'   => $method,
                    'payment_status'   => in_array($status, ['delivered', 'shipped'], true) ? 'paid' : 'unpaid',
                    'paid_at'          => in_array($status, ['delivered', 'shipped'], true) ? $created : null,
                    'status'           => $status,
                    'created_at'       => $created,
                    'updated_at'       => $created,
                ]);

                foreach ($lines as $line) {
                    Database::insert('order_items', [
                        'order_id'      => $orderId,
                        'product_id'    => $line['id'],
                        'variant_id'    => $line['vid'],
                        'product_name'  => $line['name'],
                        'variant_label' => $line['label'],
                        'sku'           => (string)$line['sku'],
                        'unit_price'    => $line['unit'],
                        'quantity'      => $line['qty'],
                        'line_total'    => $line['line'],
                    ]);
                }
            }
            $log[] = count($statuses) . ' sample orders created across the last three weeks.';

            /* ---- reviews ------------------------------------------------ */
            $reviewSeed = [
                [0, 'Amara Perera', 5, 'Better than the brand-name alternative', 'Comfort is superb on long flights and the noise cancelling genuinely works. Battery held up for a Colombo–London leg.', 'approved'],
                [0, 'Ruwan Silva', 4, 'Great sound, tight fit at first', 'Needed a week to break in the headband but now they disappear on my head. App is a little basic.', 'approved'],
                [2, 'Nadia Fonseka', 5, 'Battery is not a marketing claim', 'Eleven days between charges with workouts tracked daily.', 'approved'],
                [1, 'Amara Perera', 5, 'Silent and cool', 'Fanless was the selling point for me — never overheats, keyboard is excellent.', 'approved'],
                [6, 'Ruwan Silva', 5, 'Worth the money', 'Cashmere blend is noticeably softer than my wool coat and it drapes beautifully.', 'approved'],
                [10, 'Nadia Fonseka', 4, 'Beautiful hide', 'Grain is lovely and it already looks better after a month. Slightly heavy when empty.', 'approved'],
                [17, 'Amara Perera', 5, 'Compliment magnet', 'Lasts a full evening and the dry-down is gorgeous. Not a daytime scent.', 'approved'],
                [23, 'Ruwan Silva', 4, 'Handsome but heavy-ish', 'Keeps time perfectly, 72 hour reserve is real. Wears smaller than 38mm suggests.', 'approved'],
                [4, 'Nadia Fonseka', 5, 'Charges my laptop', 'Replaced two chargers on trips. Display showing wattage is genuinely useful.', 'approved'],
                [21, 'Amara Perera', 5, 'Grip is unreal', 'No sliding even in a hot room. Alignment lines are a nice touch.', 'approved'],
                [14, 'Ruwan Silva', 3, 'Nice scent, subtle throw', 'Lovely packaging but the diffuser is gentle — better for a small room.', 'pending'],
                [8, 'Nadia Fonseka', 5, 'Fades perfectly', 'Six months in and the fades are exactly where I wanted them.', 'pending'],
            ];

            $reviewerIds = [
                'Amara Perera' => $userIds['amara@example.com'] ?? null,
                'Ruwan Silva'  => $userIds['ruwan@example.com'] ?? null,
                'Nadia Fonseka' => $userIds['nadia@example.com'] ?? null,
            ];

            $reviewCount = 0;
            foreach ($reviewSeed as [$pIndex, $who, $rating, $title, $body, $status]) {
                if (!isset($productIds[$pIndex])) {
                    continue;
                }
                Database::insert('reviews', [
                    'product_id' => $productIds[$pIndex],
                    'user_id'    => $reviewerIds[$who] ?? null,
                    'name'       => $who,
                    'rating'     => $rating,
                    'title'      => $title,
                    'body'       => $body,
                    'status'     => $status,
                    'created_at' => date('Y-m-d H:i:s', strtotime('-' . random_int(1, 45) . ' days')),
                ]);
                $reviewCount++;
            }
            $log[] = $reviewCount . ' product reviews created (2 awaiting moderation).';

            if (isset($userIds['amara@example.com'])) {
                foreach ([0, 6, 23] as $index) {
                    if (isset($productIds[$index])) {
                        Database::insert('wishlists', [
                            'user_id'    => $userIds['amara@example.com'],
                            'product_id' => $productIds[$index],
                            'created_at' => now(),
                        ]);
                    }
                }
            }

            Database::insert('messages', [
                'name' => 'Dinuka Rajapaksa', 'email' => 'dinuka@example.com',
                'subject' => 'Bulk order for corporate gifting',
                'message' => "Hi ShopHub,\n\nWe would like to order 40 units of the insulated bottles with our logo. Could you quote for delivery to Kandy before the 20th?\n\nThanks,\nDinuka",
                'status' => 'new', 'created_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
            ]);
            Database::insert('messages', [
                'name' => 'Ishara Perera', 'email' => 'ishara@example.com',
                'subject' => 'Returns and refunds',
                'message' => 'I ordered the wrong shoe size. How do I arrange an exchange?',
                'status' => 'replied', 'created_at' => date('Y-m-d H:i:s', strtotime('-5 days')),
            ]);

            foreach (['tharindu@example.com', 'kavindi@example.com', 'milan@example.com'] as $email) {
                Database::insert('subscribers', ['email' => $email, 'created_at' => now()]);
            }

            foreach (seed_settings() as $key => $value) {
                // Never clobber a value an operator has already saved.
                if ((int)Database::value('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$key]) > 0) {
                    continue;
                }
                Database::insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
            }
            $log[] = 'Store, payment and mail settings initialised.';
        }

        // Always make sure any settings added by a newer version exist.
        foreach (seed_settings() as $key => $value) {
            $exists = Database::value('SELECT COUNT(*) FROM settings WHERE setting_key = ?', [$key]);
            if (!$exists) {
                Database::insert('settings', ['setting_key' => $key, 'setting_value' => $value]);
                $log[] = 'Added default setting: ' . $key;
            }
        }

        $log[] = 'Installation complete.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
} else {
    $log[] = 'Waiting for confirmation.';
}

/* -------------------------------------------------------------------- output */

if ($isCli) {
    echo "ShopHub installer\n=================\n";
    foreach ($log as $line) {
        echo ' • ' . $line . "\n";
    }
    if ($error) {
        echo "\nERROR: $error\n";
        exit(1);
    }
    echo "\nDriver: " . Database::driver() . "\n";
    echo "Store portal : " . store_url('') . "\n";
    echo "Staff portal : " . admin_url('') . "\n";
    echo "Admin login  : admin@shophub.test / admin123  (" . admin_url('login.php') . ")\n";
    echo "Customer     : amara@example.com / password123\n";
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ShopHub installer</title>
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<style>
    .setup { max-width: 820px; margin: 3rem auto; }
    .setup-log { background: var(--black-soft); border: 1px solid var(--line); border-radius: 14px; padding: 1.2rem 1.4rem; font-family: ui-monospace, monospace; font-size: .86rem; max-height: 380px; overflow: auto; }
    .setup-log li { color: var(--ink-soft); }
    .setup-table { width: 100%; border-collapse: collapse; font-size: .88rem; margin-top: 1rem; }
    .setup-table td { padding: .35rem .5rem; border-bottom: 1px solid var(--line); }
    .setup-table td:last-child { text-align: right; color: var(--gold); }
</style>
</head>
<body>
<div class="container setup">
    <a class="logo" href="<?= e(store_url('index.php')) ?>" style="margin-bottom:1.4rem">
        <svg class="logo-mark" viewBox="0 0 48 40"><rect x="6" y="6" width="36" height="28" rx="6" fill="#ffc400"/><text x="24" y="28.5" text-anchor="middle" font-family="Georgia,serif" font-weight="700" font-size="19" fill="#0b0b0d">S</text></svg>
        <span class="logo-text">Shop<span>Hub</span></span>
    </a>

    <h1>Installer</h1>
    <p class="muted">Creates the schema and loads demo content. Driver: <strong><?= e(Database::driver()) ?></strong>
        <?= Database::driver() === 'mysql' ? '(MySQL — ' . e((string)cfg('db.name')) . ')' : '(SQLite demo file)' ?>.</p>

    <?php if ($error): ?>
        <div class="flash flash-danger"><span><?= e($error) ?></span></div>
    <?php endif; ?>

    <?php if ($run || $error): ?>
        <ul class="setup-log">
            <?php foreach ($log as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
        </ul>

        <?php if (!$error): ?>
            <table class="setup-table">
                <?php foreach (['categories', 'products', 'product_options', 'product_variants', 'users', 'orders', 'order_items', 'reviews', 'coupons', 'auth_tokens', 'email_log'] as $t): ?>
                    <tr>
                        <td><?= e($t) ?></td>
                        <td><?= table_exists($t) ? (int)Database::value("SELECT COUNT(*) FROM $t") . ' rows' : 'missing' ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <div class="panel" style="margin-top:1.6rem">
                <h2>Next steps</h2>
                <ol class="muted">
                    <li>Open the <a href="<?= e(store_url('index.php')) ?>">customer store</a> and browse the catalogue.</li>
                    <li>Staff portal: <a href="<?= e(admin_url('login.php')) ?>"><?= e(admin_url('login.php')) ?></a> — <code>admin@shophub.test</code> / <code>admin123</code>.
                        <br><span class="small">It is deliberately not linked from the storefront.</span></li>
                    <li>Customer demo login — <code>amara@example.com</code> / <code>password123</code>.</li>
                    <li>Payment keys and mail settings live in the staff portal under <strong>Settings</strong>.</li>
                    <li>Delete this file (<code>install.php</code>) before putting the site online.</li>
                </ol>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <form method="post" class="panel" style="margin-top:1.6rem">
            <h2>Ready to install</h2>
            <p class="muted">The installer will create or update <?= count(schema()) ?> tables:
                <code><?= e(implode(', ', array_keys(schema()))) ?></code>.</p>
            <?php if (table_exists('users')): ?>
                <label class="switch-row">
                    <input type="checkbox" name="fresh" value="1">
                    <span>Drop existing ShopHub tables first (destroys current data)</span>
                </label>
                <label class="switch-row">
                    <input type="checkbox" name="migrate_only" value="1">
                    <span>Only apply pending migrations (no demo data)</span>
                </label>
            <?php endif; ?>
            <label class="switch-row">
                <input type="checkbox" name="confirm" value="1" required>
                <span>I understand this writes to the database</span>
            </label>
            <button class="btn btn-gold btn-lg" type="submit">Run installation</button>
        </form>
    <?php endif; ?>

    <p style="margin-top:2rem"><a class="link-more" href="<?= e(store_url('index.php')) ?>">← Back to the store</a></p>
</div>
</body>
</html>
