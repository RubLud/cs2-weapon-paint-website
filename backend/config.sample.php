<?php
// Copy to /etc/cloudhub/config.php on the VPS and point CLOUDHUB_CONFIG at it.
// Values may also come from a private PHP-FPM environment file. Never commit credentials.
define('DOMAIN_NAME', getenv('CLOUDHUB_DOMAIN') ?: 'https://skins.cloudhub.autos');
define('STEAM_API_KEY', getenv('STEAM_API_KEY') ?: '');
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'weaponpaints');
define('DB_USER', getenv('DB_USER') ?: 'cloudhub_web');
define('DB_PASS', getenv('DB_PASS') ?: '');
// The dedicated web account needs SELECT/INSERT/UPDATE/DELETE only.
// Run bin/migrate.php once with a separately authorized migration account.
if (!defined('DB_AUTO_MIGRATE')) { define('DB_AUTO_MIGRATE', false); }
define('SESSION_LIFETIME', 60 * 60 * 24 * 30);
if (getenv('CLOUDHUB_STORAGE')) { define('STORAGE_PATH', getenv('CLOUDHUB_STORAGE')); }
define('API_DOMAIN_NAME', rtrim(DOMAIN_NAME, '/') . '/api');
define('STEAM_DOMAIN_NAME', API_DOMAIN_NAME);
define('STEAM_LOGOUT_PAGE', API_DOMAIN_NAME . '/steamauth/logout.php');
define('STEAM_LOGIN_PAGE', API_DOMAIN_NAME . '/steamauth/login.php');
define('BETA_3D', false);
define('API_RATE_LIMIT', 120);
define('API_RATE_WINDOW', 60);
define('API_READ_CACHE_TTL', 5);
// For a remote DB, set DB_SSL_CA to your CA file; verification stays enabled.
// define('DB_SSL_CA', '/etc/cloudhub/database-ca.pem');

// Stage 1 is opt-in. Run cloudhub-migrate.php first; never enables purchases or skin restrictions.
define('CLOUDHUB_CORE_ENABLED', false);

// Stage 2 remains off until isolated staging tests pass. Never put real keys here.
define('CLOUDHUB_INGEST_ENABLED', false);
// Define CLOUDHUB_INGEST_KEYS in a private ingestion-only config as server_id => random key (32+ bytes).

// Stage 3: enable only after explicit staging validation and least-privilege SQL grants.
// OWNER identity is provisioned only with bin/cloudhub-owner.php after Steam verification.
define('CLOUDHUB_ADMIN_CC_ENABLED', false);

// Enable only after migration 003 on an approved staging environment.
if (!defined('CLOUDHUB_SOCIAL_ENABLED')) define('CLOUDHUB_SOCIAL_ENABLED', false);

// Stage 5: run migration 004 and bin/cloudhub-shop-catalog.php on approved staging first.
// SHOP_ENABLED shows the catalog, inventory and history; purchases need the second flag and OWNER-approved prices.
if (!defined('CLOUDHUB_SHOP_ENABLED')) define('CLOUDHUB_SHOP_ENABLED', false);
if (!defined('CLOUDHUB_SHOP_PURCHASES_ENABLED')) define('CLOUDHUB_SHOP_PURCHASES_ENABLED', false);
