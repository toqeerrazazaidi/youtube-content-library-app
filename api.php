<?php
/**
 * ================================================================
 *  YT Content Library — API (api.php)
 *  Version: 2.0
 *  Description: Single-file REST API backend handling:
 *    - User authentication (register, login, logout, sessions)
 *    - Email verification via Brevo SMTP
 *    - Password management (forgot/reset/change)
 *    - Playlist CRUD with YouTube Data API v3 proxy
 *    - Video state tracking (watched, notes, custom tags)
 *  Requires: db_config_x7k.php (database + SMTP + API key config)
 * ================================================================
 */

// Suppress PHP errors from leaking into JSON responses
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/db_config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . APP_URL);
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ─────────────────────────────────────────────
//  Router — maps ?action= query param to handler functions
//  All POST body data is read as JSON from php://input
// ─────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    switch ($action) {
        // Auth
        case 'register':        require_method('POST'); handle_register($body);       break;
        case 'verify_email':    require_method('GET');  handle_verify_email();        break;
        case 'login':           require_method('POST'); handle_login($body);          break;
        case 'logout':          require_method('POST'); handle_logout();              break;
        case 'forgot_password': require_method('POST'); handle_forgot_password($body);break;
        case 'reset_password':  require_method('POST'); handle_reset_password($body); break;
        case 'me':              require_method('GET');  handle_me();                  break;
        case 'change_password': require_method('POST'); handle_change_password($body); break;

        // Playlists
        case 'playlists':
            if ($method === 'GET')    handle_get_playlists();
            elseif ($method === 'POST')   handle_add_playlist($body);
            elseif ($method === 'DELETE') handle_remove_playlist();
            else method_not_allowed();
            break;

        // Videos
        case 'videos':          require_method('GET');  handle_get_videos();          break;

        // User video state (watched + notes)
        case 'video_state':     require_method('POST'); handle_video_state($body);    break;

        // YouTube proxy
        case 'yt':              require_method('GET');  handle_yt_proxy();            break;

        default: json_error('Unknown action', 404);
    }
} catch (Throwable $e) {
    json_error('Server error: ' . $e->getMessage(), 500);
}

// ─────────────────────────────────────────────
//  Auth Handlers
//  Handles: register, verify_email, login, logout,
//           forgot_password, reset_password, me
// ─────────────────────────────────────────────
function handle_register(array $body): void {
    $db       = getDB();
    $username = trim($body['username'] ?? '');
    $email    = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';

    if (!$username || !$email || !$password)
        json_error('All fields are required');
    if (!preg_match('/^[a-zA-Z0-9_]{3,40}$/', $username))
        json_error('Username must be 3–40 characters, letters/numbers/underscores only');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        json_error('Invalid email address');
    if (strlen($password) < 8)
        json_error('Password must be at least 8 characters');

    // Check uniqueness
    $st = $db->prepare('SELECT id FROM users WHERE email=? OR username=?');
    $st->execute([$email, $username]);
    if ($st->fetch()) json_error('Username or email already registered');

    $hash  = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $token = bin2hex(random_bytes(32));

    $db->prepare('INSERT INTO users (username, email, password_hash, verified, verify_token) VALUES (?,?,?,0,?)')
       ->execute([$username, $email, $hash, $token]);

    send_verification_email($email, $username, $token);

    json_ok(['message' => 'Account created! Please check your email to verify your account before logging in.']);
}

function handle_verify_email(): void {
    $db    = getDB();
    $token = $_GET['token'] ?? '';
    if (!$token) json_error('Missing token');

    $st = $db->prepare('SELECT id FROM users WHERE verify_token=? AND verified=0');
    $st->execute([$token]);
    $user = $st->fetch();
    if (!$user) json_error('Invalid or expired verification link');

    $db->prepare('UPDATE users SET verified=1, verify_token=NULL WHERE id=?')
       ->execute([$user['id']]);

    // Redirect to app with success flag
    header('Location: ' . APP_URL . '/?verified=1');
    exit;
}

function handle_login(array $body): void {
    $db       = getDB();
    $login    = strtolower(trim($body['email'] ?? ''));
    $password = $body['password'] ?? '';

    if (!$login || !$password) json_error('Email/username and password are required');

    $st = $db->prepare('SELECT id, username, email, password_hash, verified FROM users WHERE email=? OR LOWER(username)=?');
    $st->execute([$login, $login]);
    $user = $st->fetch();

    if (!$user || !password_verify($password, $user['password_hash']))
        json_error('Invalid email or password', 401);
    if (!$user['verified'])
        json_error('Please verify your email before logging in. Check your inbox (and spam folder).', 403);

    // Create session (30-day rolling)
    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    $db->prepare('INSERT INTO sessions (user_id, token, expires_at) VALUES (?,?,?)')
       ->execute([$user['id'], $token, $expires]);

    // Clean old sessions for this user
    $db->prepare('DELETE FROM sessions WHERE user_id=? AND expires_at < NOW()')
       ->execute([$user['id']]);

    json_ok([
        'token' => $token,
        'user'  => ['id' => $user['id'], 'username' => $user['username'], 'email' => $user['email']],
    ]);
}

function handle_logout(): void {
    $db    = getDB();
    $token = get_token();
    if ($token) {
        $db->prepare('DELETE FROM sessions WHERE token=?')->execute([$token]);
    }
    json_ok(['message' => 'Logged out']);
}

function handle_forgot_password(array $body): void {
    $db    = getDB();
    $email = strtolower(trim($body['email'] ?? ''));
    if (!$email) json_error('Email is required');

    $st = $db->prepare('SELECT id, username FROM users WHERE email=? AND verified=1');
    $st->execute([$email]);
    $user = $st->fetch();

    // Always return success — never leak whether email exists
    if ($user) {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $db->prepare('UPDATE users SET reset_token=?, reset_expires=? WHERE id=?')
           ->execute([$token, $expires, $user['id']]);
        send_reset_email($email, $user['username'], $token);
    }

    json_ok(['message' => 'If that email is registered, a reset link has been sent. Check your inbox.']);
}

function handle_reset_password(array $body): void {
    $db       = getDB();
    $token    = $body['token']    ?? '';
    $password = $body['password'] ?? '';

    if (!$token || !$password) json_error('Token and new password are required');
    if (strlen($password) < 8)  json_error('Password must be at least 8 characters');

    $st = $db->prepare('SELECT id FROM users WHERE reset_token=? AND reset_expires > NOW()');
    $st->execute([$token]);
    $user = $st->fetch();
    if (!$user) json_error('Reset link is invalid or has expired');

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare('UPDATE users SET password_hash=?, reset_token=NULL, reset_expires=NULL WHERE id=?')
       ->execute([$hash, $user['id']]);

    // Invalidate all sessions
    $db->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$user['id']]);

    json_ok(['message' => 'Password updated. Please log in with your new password.']);
}

function handle_me(): void {
    $user = require_auth();
    json_ok(['user' => ['id' => $user['id'], 'username' => $user['username'], 'email' => $user['email']]]);
}

// ─────────────────────────────────────────────
//  Change Password (authenticated — requires current password)
// ─────────────────────────────────────────────
function handle_change_password(array $body): void {
    $user            = require_auth();
    $db              = getDB();
    $currentPassword = $body['current_password'] ?? '';
    $newPassword     = $body['new_password']     ?? '';

    if (!$currentPassword || !$newPassword)
        json_error('Current and new passwords are required');
    if (strlen($newPassword) < 8)
        json_error('New password must be at least 8 characters');

    // Fetch current hash to verify existing password
    $st = $db->prepare('SELECT password_hash FROM users WHERE id=?');
    $st->execute([$user['id']]);
    $row = $st->fetch();

    if (!$row || !password_verify($currentPassword, $row['password_hash']))
        json_error('Current password is incorrect', 401);

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare('UPDATE users SET password_hash=? WHERE id=?')
       ->execute([$newHash, $user['id']]);

    json_ok(['message' => 'Password updated successfully']);
}

// ─────────────────────────────────────────────
//  Playlist Handlers
//  GET    — list all playlists for authenticated user
//  POST   — add new playlist (with videos array from frontend)
//  DELETE — remove playlist and all associated videos
// ─────────────────────────────────────────────
function handle_get_playlists(): void {
    $user = require_auth();
    $db   = getDB();
    $st   = $db->prepare('SELECT id, yt_playlist_id, name, fetched_at FROM playlists WHERE user_id=? ORDER BY fetched_at DESC');
    $st->execute([$user['id']]);
    json_ok(['playlists' => $st->fetchAll()]);
}

function handle_add_playlist(array $body): void {
    $user        = require_auth();
    $db          = getDB();
    $ytId        = trim($body['yt_playlist_id'] ?? '');
    $name        = trim($body['name'] ?? '');
    $videos_json = $body['videos'] ?? [];

    if (!$ytId || !$name) json_error('Playlist ID and name are required');

    // Check duplicate
    $st = $db->prepare('SELECT id FROM playlists WHERE user_id=? AND yt_playlist_id=?');
    $st->execute([$user['id'], $ytId]);
    if ($st->fetch()) json_error('Playlist already in your library');

    // Rate limit: check fetch_log
    $st = $db->prepare('SELECT COUNT(*) as cnt FROM fetch_log WHERE user_id=? AND fetched_at > DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $st->execute([$user['id']]);
    $row = $st->fetch();
    if ((int)$row['cnt'] >= MAX_PLAYLIST_FETCHES_PER_DAY)
        json_error('Daily playlist fetch limit reached (' . MAX_PLAYLIST_FETCHES_PER_DAY . '/day). Try again tomorrow.');

    // Insert playlist
    $db->prepare('INSERT INTO playlists (user_id, yt_playlist_id, name) VALUES (?,?,?)')
       ->execute([$user['id'], $ytId, $name]);
    $playlistDbId = $db->lastInsertId();

    // Log fetch
    $db->prepare('INSERT INTO fetch_log (user_id) VALUES (?)')->execute([$user['id']]);

    // Insert videos
    $inserted = 0;
    $stmt = $db->prepare('
        INSERT IGNORE INTO videos (playlist_id, user_id, video_id, title, description, tags, thumb, published_at, position)
        VALUES (?,?,?,?,?,?,?,?,?)
    ');
    foreach ($videos_json as $v) {
        $stmt->execute([
            $playlistDbId,
            $user['id'],
            $v['videoId']     ?? '',
            $v['title']       ?? '',
            $v['desc']        ?? '',
            json_encode($v['tags'] ?? []),
            $v['thumb']       ?? '',
            !empty($v['publishedAt']) ? date('Y-m-d H:i:s', strtotime($v['publishedAt'])) : null,
            $v['position']    ?? 0,
        ]);
        $inserted++;
    }

    json_ok([
        'playlist' => ['id' => $playlistDbId, 'yt_playlist_id' => $ytId, 'name' => $name],
        'inserted' => $inserted,
    ]);
}

function handle_remove_playlist(): void {
    $user = require_auth();
    $db   = getDB();
    $id   = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Playlist ID required');

    // Verify ownership
    $st = $db->prepare('SELECT id FROM playlists WHERE id=? AND user_id=?');
    $st->execute([$id, $user['id']]);
    if (!$st->fetch()) json_error('Playlist not found', 404);

    $db->prepare('DELETE FROM playlists WHERE id=?')->execute([$id]);
    json_ok(['message' => 'Playlist removed']);
}

// ─────────────────────────────────────────────
//  Videos Handler
// ─────────────────────────────────────────────
function handle_get_videos(): void {
    $user        = require_auth();
    $db          = getDB();
    $playlistId  = isset($_GET['playlist_id']) ? (int)$_GET['playlist_id'] : null;

    if ($playlistId) {
        $st = $db->prepare('
            SELECT v.*, uv.watched, uv.notes, uv.custom_tags
            FROM videos v
            LEFT JOIN user_videos uv ON uv.user_id=? AND uv.video_id=v.video_id
            WHERE v.playlist_id=? AND v.user_id=?
            ORDER BY v.position ASC
        ');
        $st->execute([$user['id'], $playlistId, $user['id']]);
    } else {
        $st = $db->prepare('
            SELECT v.*, uv.watched, uv.notes, uv.custom_tags
            FROM videos v
            LEFT JOIN user_videos uv ON uv.user_id=? AND uv.video_id=v.video_id
            WHERE v.user_id=?
            ORDER BY v.position ASC
        ');
        $st->execute([$user['id'], $user['id']]);
    }

    $videos = array_map(function($v) {
        $v['tags']        = json_decode($v['tags'] ?? '[]', true) ?: [];
        $v['watched']     = (bool)($v['watched'] ?? false);
        $v['notes']       = $v['notes'] ?? '';
        $v['custom_tags'] = $v['custom_tags'] ?? '[]';
        return $v;
    }, $st->fetchAll());

    json_ok(['videos' => $videos]);
}

// ─────────────────────────────────────────────
//  Video State — upserts watched, notes, custom_tags per user per video
//  Partial updates supported: only provided fields are updated
// ─────────────────────────────────────────────
function handle_video_state(array $body): void {
    $user    = require_auth();
    $db      = getDB();
    $videoId = trim($body['video_id'] ?? '');
    if (!$videoId) json_error('video_id required');

    $watched     = isset($body['watched']) ? (int)(bool)$body['watched'] : null;
    $custom_tags = array_key_exists('custom_tags', $body) ? $body['custom_tags'] : null;
    $notes   = $body['notes'] ?? null;

    // Upsert
    $existing = $db->prepare('SELECT id, watched, notes, custom_tags FROM user_videos WHERE user_id=? AND video_id=?');
    $existing->execute([$user['id'], $videoId]);
    $row = $existing->fetch();

    if ($row) {
        $newWatched    = $watched     !== null ? $watched     : $row['watched'];
        $newNotes      = $notes       !== null ? $notes       : $row['notes'];
        $newCustomTags = $custom_tags !== null ? $custom_tags : $row['custom_tags'];
        $db->prepare('UPDATE user_videos SET watched=?, notes=?, custom_tags=? WHERE user_id=? AND video_id=?')
           ->execute([$newWatched, $newNotes, $newCustomTags, $user['id'], $videoId]);
    } else {
        $newWatched    = $watched     ?? 0;
        $newNotes      = $notes       ?? '';
        $newCustomTags = $custom_tags ?? '[]';
        $db->prepare('INSERT INTO user_videos (user_id, video_id, watched, notes, custom_tags) VALUES (?,?,?,?,?)')
           ->execute([$user['id'], $videoId, $newWatched, $newNotes, $newCustomTags]);
    }

    json_ok(['video_id' => $videoId, 'watched' => (bool)$newWatched, 'notes' => $newNotes, 'custom_tags' => $newCustomTags]);
}

// ─────────────────────────────────────────────
//  YouTube API Proxy
//  Routes frontend requests through server-side to:
//    1. Inject the API key (never exposed to browser)
//    2. Avoid CORS issues on shared hosting
//  Whitelisted endpoints: playlists, playlistItems, videos
// ─────────────────────────────────────────────
function handle_yt_proxy(): void {
    require_auth(); // Must be logged in to use proxy

    $endpoint = $_GET['endpoint'] ?? '';
    $allowed  = ['playlists', 'playlistItems', 'videos'];
    if (!in_array($endpoint, $allowed)) json_error('Endpoint not allowed', 403);

    $params = $_GET;
    unset($params['action'], $params['endpoint']);
    $params['key'] = YT_API_KEY; // inject server-side key

    $qs  = http_build_query($params);
    $url = "https://www.googleapis.com/youtube/v3/{$endpoint}?{$qs}";

    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 15, 'header' => "User-Agent: YTContentLibrary/2.0\r\n"],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        $err = error_get_last();
        json_error('YouTube API unreachable: ' . ($err['message'] ?? 'unknown error'), 502);
    }

    // Pass through as-is
    echo $response;
}

// ─────────────────────────────────────────────
//  Email Helpers — sends transactional email via Brevo SMTP
//  Uses raw PHP socket on ssl://smtp-relay.brevo.com:465
//  No external dependencies required
// ─────────────────────────────────────────────
function send_verification_email(string $email, string $username, string $token): void {
    $link    = APP_URL . '/api.php?action=verify_email&token=' . urlencode($token);
    $subject = 'Verify your ' . APP_NAME . ' account';
    $body    = "Hi {$username},\r\n\r\nPlease verify your email by clicking the link below:\r\n\r\n{$link}\r\n\r\nThis link does not expire.\r\n\r\nIf you didn't create an account, you can ignore this email.\r\n\r\n— " . APP_NAME;
    send_email($email, $subject, $body);
}

function send_reset_email(string $email, string $username, string $token): void {
    $link    = APP_URL . '/?reset_token=' . urlencode($token);
    $subject = APP_NAME . ' — Password Reset';
    $body    = "Hi {$username},\r\n\r\nClick the link below to reset your password. This link expires in 1 hour.\r\n\r\n{$link}\r\n\r\nIf you didn't request a reset, you can ignore this email.\r\n\r\n— " . APP_NAME;
    send_email($email, $subject, $body);
}

function send_email(string $to, string $subject, string $body): void {
    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $user = SMTP_USER;
    $pass = SMTP_PASS;
    $from = SMTP_FROM;
    $name = SMTP_FROM_NAME;

    $errno = 0; $errstr = '';
    $socket = @fsockopen('ssl://' . $host, 465, $errno, $errstr, 15);
    if (!$socket) {
        error_log("SMTP connect failed: {$errstr} ({$errno})");
        throw new Exception("SMTP connect failed: {$errstr} ({$errno})");
    }

    $read = function() use ($socket) { return fgets($socket, 515); };
    $send = function(string $cmd) use ($socket) { fwrite($socket, $cmd . "\r\n"); };

    $read(); // 220 greeting
    $send("EHLO " . parse_url(APP_URL, PHP_URL_HOST));
    while (true) { $line = $read(); if ($line[3] === ' ') break; } // read all EHLO lines

    $send("AUTH LOGIN");
    $read();
    $send(base64_encode($user));
    $read();
    $send(base64_encode($pass));
    $r = $read();
    if (strpos($r, '235') === false) {
        error_log("SMTP auth failed: {$r}");
        fclose($socket);
        throw new Exception("SMTP auth failed: {$r}");
    }

    $send("MAIL FROM:<{$from}>");
    $read();
    $send("RCPT TO:<{$to}>");
    $read();
    $send("DATA");
    $read();

    $headers  = "From: {$name} <{$from}>\r\n";
    $headers .= "To: {$to}\r\n";
    $headers .= "Subject: {$subject}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Date: " . date('r') . "\r\n";

    $send($headers . "\r\n" . $body . "\r\n.");
    $read();
    $send("QUIT");
    fclose($socket);
}

// ─────────────────────────────────────────────
//  Auth Middleware
//  Validates X-Auth-Token header against sessions table
//  Rolls session expiry by 30 days on each valid request
//  Returns user array or sends 401 JSON error
// ─────────────────────────────────────────────
function require_auth(): array {
    $db    = getDB();
    $token = get_token();
    if (!$token) json_error('Authentication required', 401);

    $st = $db->prepare('
        SELECT u.id, u.username, u.email
        FROM sessions s
        JOIN users u ON u.id = s.user_id
        WHERE s.token=? AND s.expires_at > NOW()
    ');
    $st->execute([$token]);
    $user = $st->fetch();
    if (!$user) json_error('Session expired or invalid. Please log in again.', 401);

    // Roll expiry
    $db->prepare('UPDATE sessions SET expires_at=? WHERE token=?')
       ->execute([date('Y-m-d H:i:s', strtotime('+30 days')), $token]);

    return $user;
}

function get_token(): ?string {
    $h = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if ($h) return $h;
    return $_GET['token'] ?? null;
}

// ─────────────────────────────────────────────
//  Utility Helpers
//  require_method() — enforces HTTP method or returns 405
//  json_ok()        — outputs success JSON and exits
//  json_error()     — outputs error JSON with HTTP status and exits
// ─────────────────────────────────────────────
function require_method(string $m): void {
    if ($_SERVER['REQUEST_METHOD'] !== $m) method_not_allowed();
}
function method_not_allowed(): never {
    json_error('Method not allowed', 405);
}
function json_ok(array $data): never {
    echo json_encode(['ok' => true, ...$data]);
    exit;
}
function json_error(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
