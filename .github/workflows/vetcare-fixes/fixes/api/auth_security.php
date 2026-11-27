<?php
/**
 * VETCARE — auth.php security add-on
 * ====================================
 * Drop-in module for the existing api/auth.php action router.
 *
 * Adds the four server-side fixes:
 *   1. Login rate limiting            (action=login)
 *   2. Registration gating            (action=register) — new accounts need admin approval
 *   3. Password recovery              (action=forgot / action=reset)
 *   4. Safe hashing enforcement       (password_hash / password_verify only)
 *
 * ASSUMED SCHEMA (users table) — adjust column names if different:
 *   id INT PRIMARY KEY AUTO_INCREMENT
 *   name VARCHAR(120), email VARCHAR(190), username VARCHAR(50) UNIQUE
 *   password VARCHAR(255)           -- bcrypt/argon2 hash only
 *   role VARCHAR(50)               -- e.g. 'Veterinary Staff', 'Pet Owner'
 *   approved TINYINT(1) DEFAULT 0   -- NEW column for gating
 *   reset_token VARCHAR(64) NULL    -- NEW columns for reset flow
 *   reset_expires DATETIME NULL
 *
 * CONFIG: edit the constants below (especially DB credentials if they differ
 * from what auth.php already uses — ideally reuse auth.php's existing $pdo).
 */

declare(strict_types=1);

// ---------- Configuration -------------------------------------------------
define('APP_REGISTER_OPEN', false);   // false = registration requires admin approval
define('RESET_TOKEN_TTL', 3600);       // seconds a reset token stays valid (1 hour)
define('LOGIN_MAX_ATTEMPTS', 5);       // attempts allowed per window
define('LOGIN_WINDOW_SECONDS', 900);   // rate-limit window (15 min)
define('FORGOT_MAX_PER_HOUR', 3);      // email-reset requests per email per hour
define('RATE_LIMIT_DIR', __DIR__ . '/../storage/ratelimit'); // writable dir

// Reuse the existing PDO connection from auth.php when available.
$vetcare_pdo = $pdo ?? null;
if (!$vetcare_pdo instanceof PDO) {
    $vetcare_pdo = new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'localhost') .
        ';dbname=' . (getenv('DB_NAME') ?: 'vetcare') .
        ';charset=utf8mb4',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASS') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

// ---------- Helpers ------------------------------------------------------

/** JSON response + exit. */
function vetcare_json(bool $success, string $message = '', array $data = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

/**
 * File-based rate limiter (no schema changes needed).
 * $key identifies the subject, e.g. "login:1.2.3.4:alice".
 */
function vetcare_rate_limit(string $key, int $max, int $windowSeconds): bool
{
    if (!is_dir(RATE_LIMIT_DIR)) {
        @mkdir(RATE_LIMIT_DIR, 0750, true);
    }
    $file = RATE_LIMIT_DIR . '/' . preg_replace('/[^a-z0-9:._-]/i', '', $key) . '.json';
    $now = time();
    $state = ['count' => 0, 'window_start' => $now];
    if (is_file($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded) && isset($decoded['window_start']) && $decoded['window_start'] > $now - $windowSeconds) {
            $state = $decoded;
        }
    }
    if ($state['window_start'] <= $now - $windowSeconds) {
        $state = ['count' => 0, 'window_start' => $now];
    }
    $state['count']++;
    // Atomic-ish write: write to temp file then rename.
    @file_put_contents($file . '.tmp', json_encode($state));
    @rename($file . '.tmp', $file);
    @chmod($file, 0640);
    return $state['count'] <= $max;
}

function vetcare_client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', (string) $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return 'unknown';
}

function vetcare_find_user_by_login(PDO $pdo, string $username): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    return $u ?: null;
}

// ---------- Action: login (with rate limiting + approval check) ----------
if (($_GET['action'] ?? '') === 'login') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($username === '' || $password === '') {
        vetcare_json(false, 'Please enter your username and password.');
    }
    if (!vetcare_rate_limit('login:' . vetcare_client_ip() . ':' . strtolower($username), LOGIN_MAX_ATTEMPTS, LOGIN_WINDOW_SECONDS)) {
        vetcare_json(false, 'Too many sign-in attempts. Please try again later.');
    }

    $user = vetcare_find_user_by_login($vetcare_pdo, strtolower($username));
    // Same generic message for unknown user and wrong password (no user enumeration).
    if (!$user || !password_verify($password, $user['password'])) {
        vetcare_json(false, 'Invalid username or password.');
    }
    if ((int) ($user['approved'] ?? 0) !== 1) {
        vetcare_json(false, 'Your account is pending administrator approval. Please contact the clinic.');
    }
    // Existing session bootstrap from auth.php can follow here (regenerate session id).
    session_regenerate_id(true);
    // ... set $_SESSION['user_id'] / role as auth.php already does ...
    vetcare_json(true, 'Signed in.', ['user' => ['username' => $user['username'], 'role' => $user['role']]]);
}

// ---------- Action: register (with approval gating) --------------------
if (($_GET['action'] ?? '') === 'register') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $name     = trim((string) ($body['name'] ?? ''));
    $email    = trim((string) ($body['email'] ?? ''));
    $username = trim((string) ($body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($name === '' || $email === '' || $username === '' || strlen($password) < 8) {
        vetcare_json(false, 'All fields are required and the password must be at least 8 characters.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        vetcare_json(false, 'Please provide a valid email address.');
    }
    if (!preg_match('/^[a-z0-9_.-]{3,50}$/i', $username)) {
        vetcare_json(false, 'Username must be 3–50 characters (letters, numbers, _ . -).');
    }

    $stmt = $vetcare_pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([strtolower($username)]);
    if ($stmt->fetch()) {
        vetcare_json(false, 'That username is already taken.');
    }

    // Gating: approved = 0 unless registration is explicitly open.
    $approved = APP_REGISTER_OPEN ? 1 : 0;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $vetcare_pdo->prepare(
        'INSERT INTO users (name, email, username, password, role, approved)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$name, $email, strtolower($username), $hash, 'Pet Owner', $approved]);

    if (!APP_REGISTER_OPEN) {
        // Optional: notify admins of the pending account.
        @mail(getenv('ADMIN_EMAIL') ?: '', 'New VETCARE registration pending',
            "A new account was registered:\nname: $name\nemail: $email\nusername: $username");
        vetcare_json(true, 'Account created. It requires administrator approval before you can sign in.',
            ['user' => ['username' => strtolower($username), 'approved' => 0]]);
    }
    vetcare_json(true, 'Account created. You can sign in now.',
        ['user' => ['username' => strtolower($username), 'approved' => 1]]);
}

// ---------- Action: forgot (request password reset) ---------------------
if (($_GET['action'] ?? '') === 'forgot') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $email = trim((string) ($body['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        vetcare_json(false, 'Please provide a valid email address.');
    }
    if (!vetcare_rate_limit('forgot:' . vetcare_client_ip(), FORGOT_MAX_PER_HOUR * 5, 3600) ||
        !vetcare_rate_limit('forgot:' . $email, FORGOT_MAX_PER_HOUR, 3600)) {
        vetcare_json(false, 'Too many reset requests. Please try again later.');
    }

    $stmt = $vetcare_pdo->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + RESET_TOKEN_TTL);
        $stmt = $vetcare_pdo->prepare(
            'UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?'
        );
        $stmt->execute([$token, $expires, $user['id']]);

        $link = rtrim(getenv('APP_URL') ?: 'https://vet-clinic-one-pied.vercel.app', '/')
              . '/#reset-token=' . $token;
        // Send email if a mail transport is configured; always report generic success.
        $sent = @mail($user['email'], 'Reset your VETCARE password',
            "A password reset was requested for your VETCARE account.\n\n"
            . "Reset token: $token\nOr open this link:\n$link\n\n"
            . "This token expires in " . (RESET_TOKEN_TTL / 60) . " minutes. If you did not "
            . "request this, ignore this email — your password stays unchanged.");
    }
    // Generic message either way: no account enumeration.
    vetcare_json(true, 'If an account exists for that email, a reset link has been sent.');
}

// ---------- Action: reset (apply the new password) -----------------------
if (($_GET['action'] ?? '') === 'reset') {
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $token    = trim((string) ($body['token'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($token === '' || strlen($password) < 8) {
        vetcare_json(false, 'A reset token and a password of at least 8 characters are required.');
    }
    if (!vetcare_rate_limit('reset:' . vetcare_client_ip(), LOGIN_MAX_ATTEMPTS, LOGIN_WINDOW_SECONDS)) {
        vetcare_json(false, 'Too many reset attempts. Please try again later.');
    }

    // Tokens are stored as-is here; switch to storing hashes if you prefer.
    $stmt = $vetcare_pdo->prepare('SELECT * FROM users WHERE reset_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user || empty($user['reset_expires']) || strtotime($user['reset_expires']) < time()) {
        vetcare_json(false, 'The reset token is invalid or has expired. Please request a new one.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $vetcare_pdo->prepare(
        'UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?'
    );
    $stmt->execute([$hash, $user['id']]);

    vetcare_json(true, 'Your password has been updated. You can sign in now.');
}

/*
 * INTEGRATION
 * -----------
 * Keep auth.php's existing router for 'me', 'logout' etc., and dispatch the
 * four actions above to this module (or include this file from auth.php after
 * its own action handling). Recommended next steps:
 *   - ALTER TABLE users ADD COLUMN approved TINYINT(1) DEFAULT 0,
 *                        ADD COLUMN reset_token VARCHAR(64) NULL,
 *                        ADD COLUMN reset_expires DATETIME NULL;
 *   - Set a real mail transport (or an email queue) so reset links actually arrive.
 *   - Add an admin UI/endpoint to approve users and list pending registrations.
 */
