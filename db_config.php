<?php
/**
 * YT Content Library — Database Config & Setup
 * ─────────────────────────────────────────────
 * 1. Copy this file to your server
 * 2. Fill in your credentials below
 * 3. Visit https://yourdomain.com/db_config_x7k.php?setup=1  to create all tables
 * 4. Delete or protect (deny all) this file after setup
 */

// ─── Database ────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'your_database_name');
define('DB_USER',    'your_database_user');
define('DB_PASS',    'your_database_password');
define('DB_CHARSET', 'utf8mb4');

// ─── SMTP (Brevo) ────────────────────────────
// Sign up at https://app.brevo.com → SMTP & API → SMTP tab
define('SMTP_HOST',      'smtp-relay.brevo.com');
define('SMTP_PORT',      465);
define('SMTP_USER',      'your_brevo_smtp_login');        // e.g. abc123@smtp-brevo.com
define('SMTP_PASS',      'your_brevo_smtp_password');     // long key from Brevo dashboard
define('SMTP_FROM',      'noreply@yourdomain.com');       // must be a verified sender in Brevo
define('SMTP_FROM_NAME', 'YT Content Library');

// ─── App ─────────────────────────────────────
define('APP_URL',  'https://yourdomain.com');             // no trailing slash
define('APP_NAME', 'YT Content Library');

// ─── YouTube Data API v3 ─────────────────────
// Get your key at https://console.cloud.google.com → APIs & Services → Credentials
// Enable "YouTube Data API v3" for the project first
define('YT_API_KEY', 'your_youtube_data_api_v3_key');

// ─── Rate Limiting ───────────────────────────
// Max new playlist fetches per user per day
define('MAX_PLAYLIST_FETCHES_PER_DAY', 20);

// ─────────────────────────────────────────────
//  PDO Connection (singleton)
// ─────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

// ─────────────────────────────────────────────
//  One-time Setup — creates all tables
//  Visit: https://yourdomain.com/db_config_x7k.php?setup=1
//  Delete this file after running setup.
// ─────────────────────────────────────────────
if (isset($_GET['setup']) && $_GET['setup'] === '1') {
    try {
        $db = getDB();

        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username      VARCHAR(40)  NOT NULL UNIQUE,
                email         VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                verified      TINYINT(1)   NOT NULL DEFAULT 0,
                verify_token  VARCHAR(64)  NULL,
                reset_token   VARCHAR(64)  NULL,
                reset_expires DATETIME     NULL,
                created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS sessions (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id    INT UNSIGNED NOT NULL,
                token      VARCHAR(64)  NOT NULL UNIQUE,
                expires_at DATETIME     NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS playlists (
                id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id        INT UNSIGNED NOT NULL,
                yt_playlist_id VARCHAR(100) NOT NULL,
                name           VARCHAR(255) NOT NULL,
                fetched_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_playlist (user_id, yt_playlist_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS videos (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                playlist_id  INT UNSIGNED NOT NULL,
                user_id      INT UNSIGNED NOT NULL,
                video_id     VARCHAR(20)  NOT NULL,
                title        VARCHAR(500) NOT NULL,
                description  TEXT,
                tags         TEXT,
                thumb        VARCHAR(500),
                published_at DATETIME     NULL,
                position     INT          NOT NULL DEFAULT 0,
                UNIQUE KEY uq_user_video_playlist (user_id, video_id, playlist_id),
                FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS user_videos (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id     INT UNSIGNED NOT NULL,
                video_id    VARCHAR(20)  NOT NULL,
                watched     TINYINT(1)   NOT NULL DEFAULT 0,
                notes       TEXT,
                custom_tags TEXT,
                updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_video (user_id, video_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS fetch_log (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id    INT UNSIGNED NOT NULL,
                fetched_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        echo '<pre style="font-family:monospace;padding:20px">';
        echo "✅ All tables created successfully.\n\n";
        echo "Next steps:\n";
        echo "1. ⚠️  Delete or block access to this file immediately\n";
        echo "2. Upload api.php and index.html to the same directory\n";
        echo "3. Visit " . APP_URL . " to start using the app\n";
        echo '</pre>';
    } catch (Exception $e) {
        echo '<pre style="color:red">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</pre>';
    }
    exit;
}
