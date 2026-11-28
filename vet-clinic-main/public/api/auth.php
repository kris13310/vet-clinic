<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function auth_reply(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function auth_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}
function public_user(array $row): array {
    return [
        'id' => (int)($row['id'] ?? 0),
        'name' => (string)($row['full_name'] ?? $row['name'] ?? ''),
        'full_name' => (string)($row['full_name'] ?? $row['name'] ?? ''),
        'username' => (string)($row['username'] ?? ''),
        'email' => (string)($row['email'] ?? ''),
        'role' => (string)($row['role'] ?? 'Pet Owner'),
        'status' => (string)($row['status'] ?? 'Active'),
        'auth_version' => (int)($row['auth_version'] ?? 1),
    ];
}
function auth_rate_limit(PDO $pdo, string $bucket, int $maxAttempts, int $windowSeconds): bool {
    $sql = "INSERT INTO public.auth_rate_limits (bucket_key, window_started_at, attempts)
            VALUES (?, now(), 1)
            ON CONFLICT (bucket_key) DO UPDATE SET
              attempts = CASE WHEN public.auth_rate_limits.window_started_at <= now() - make_interval(secs => CAST(? AS integer))
                THEN 1 ELSE public.auth_rate_limits.attempts + 1 END,
              window_started_at = CASE WHEN public.auth_rate_limits.window_started_at <= now() - make_interval(secs => CAST(? AS integer))
                THEN now() ELSE public.auth_rate_limits.window_started_at END
            RETURNING attempts";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$bucket, $windowSeconds, $windowSeconds]);
    return (int)$stmt->fetchColumn() <= $maxAttempts;
}
function auth_client_ip(): string {
    foreach ([$_SERVER['HTTP_CF_CONNECTING_IP'] ?? '', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '', $_SERVER['REMOTE_ADDR'] ?? ''] as $candidate) {
        $ip = trim(explode(',', (string)$candidate)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return 'unknown';
}
function auth_send_reset_email(string $to, string $token): bool {
    $apiKey = trim((string)getenv('RESEND_API_KEY'));
    $from = trim((string)getenv('MAIL_FROM'));
    $appUrl = rtrim(trim((string)(getenv('APP_URL') ?: 'https://vet-clinic-one-pied.vercel.app')), '/');
    if ($apiKey === '' || $from === '') return false;
    $link = $appUrl . '/#reset-token=' . rawurlencode($token);
    $payload = json_encode([
        'from' => $from, 'to' => [$to], 'subject' => 'Reset your VETCARE password',
        'text' => "A password reset was requested for your VETCARE account.\n\nOpen this link to choose a new password:\n$link\n\nThis link expires in 60 minutes. If you did not request this, ignore this email.",
        'html' => '<p>A password reset was requested for your VETCARE account.</p><p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Reset your password</a></p><p>This link expires in 60 minutes. If you did not request this, ignore this email.</p>',
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) return false;
    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>$payload, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>15]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return is_string($response) && $status >= 200 && $status < 300;
    }
    $context = stream_context_create(['http'=>['method'=>'POST',
        'header'=>"Authorization: Bearer {$apiKey}\r\nContent-Type: application/json\r\n",
        'content'=>$payload, 'timeout'=>15, 'ignore_errors'=>true]]);
    $response = @file_get_contents('https://api.resend.com/emails', false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) $status = (int)$m[1];
    return is_string($response) && $status >= 200 && $status < 300;
}

$action = (string)($_GET['action'] ?? '');
if ($action === 'me') {
    $current = $_SESSION['account'] ?? null;
    if (!is_array($current) || empty($current['id'])) auth_reply(401, ['success'=>false, 'message'=>'You are not signed in.']);
    auth_reply(200, ['success'=>true, 'user'=>$current]);
}
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    auth_reply(200, ['success'=>true, 'message'=>'Signed out.']);
}
if (!in_array($action, ['register','login','forgot','reset'], true)) auth_reply(400, ['success'=>false, 'message'=>'Unknown authentication action.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') auth_reply(405, ['success'=>false, 'message'=>'Use POST for this action.']);
$data = auth_input();

try {
    $ipKey = hash('sha256', auth_client_ip());
    if ($action === 'forgot') {
        $email = trim((string)($data['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) auth_reply(422, ['success'=>false, 'message'=>'Enter a valid email address.']);
        if (trim((string)getenv('RESEND_API_KEY')) === '' || trim((string)getenv('MAIL_FROM')) === '') auth_reply(503, ['success'=>false, 'message'=>'Password recovery email is not configured yet. Please contact the clinic administrator.']);
        $emailKey = hash('sha256', strtolower($email));
        if (!auth_rate_limit($pdo, 'forgot-ip:' . $ipKey, 10, 3600) || !auth_rate_limit($pdo, 'forgot-email:' . $emailKey, 3, 3600)) {
            auth_reply(429, ['success'=>false, 'message'=>'Too many password reset requests. Please try again later.']);
        }
        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $save = $pdo->prepare("UPDATE users SET password_reset_token_hash = ?, password_reset_expires = now() + interval '60 minutes' WHERE id = ?");
            $save->execute([$tokenHash, (int)$user['id']]);
            if (!auth_send_reset_email((string)$user['email'], $token)) {
                $clear = $pdo->prepare('UPDATE users SET password_reset_token_hash = NULL, password_reset_expires = NULL WHERE id = ? AND password_reset_token_hash = ?');
                $clear->execute([(int)$user['id'], $tokenHash]);
                error_log('VETCARE password reset email delivery failed. Check Resend configuration and sender verification.');
                auth_reply(503, ['success'=>false, 'message'=>'The password reset email could not be sent. Please try again later or contact the clinic.']);
            }
        }
        auth_reply(200, ['success'=>true, 'message'=>'If an account exists for that email, a password reset link has been sent.']);
    }
    if ($action === 'reset') {
        $token = trim((string)($data['token'] ?? ''));
        $password = (string)($data['password'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/i', $token) || strlen($password) < 8 || strlen($password) > 4096) auth_reply(422, ['success'=>false, 'message'=>'Enter a valid reset link and a password of at least 8 characters.']);
        if (!auth_rate_limit($pdo, 'reset-ip:' . $ipKey, 10, 900)) auth_reply(429, ['success'=>false, 'message'=>'Too many reset attempts. Please try again later.']);
        $tokenHash = hash('sha256', $token);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, password_reset_token_hash = NULL, password_reset_expires = NULL, auth_version = auth_version + 1 WHERE password_reset_token_hash = ? AND password_reset_expires > now() RETURNING id");
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $tokenHash]);
        if (!$stmt->fetchColumn()) auth_reply(400, ['success'=>false, 'message'=>'This password reset link is invalid or has expired. Request a new one.']);
        auth_reply(200, ['success'=>true, 'message'=>'Your password has been updated. You can now sign in.']);
    }

    $username = trim((string)($data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');
    if ($username === '' || $password === '') auth_reply(422, ['success'=>false, 'message'=>'Enter your username and password.']);
    if ($action === 'register') {
        if (!auth_rate_limit($pdo, 'register-ip:' . $ipKey, 10, 3600)) {
            auth_reply(429, ['success'=>false, 'message'=>'Too many registration attempts. Please try again in an hour.']);
        }
        $name = trim((string)($data['name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        if ($name === '' || $email === '') auth_reply(422, ['success'=>false, 'message'=>'Enter your full name and email.']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) auth_reply(422, ['success'=>false, 'message'=>'Enter a valid email address (up to 190 characters).']);
        if (mb_strlen($name) > 120 || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) auth_reply(422, ['success'=>false, 'message'=>'Use a username of 3-50 letters, numbers, dots, underscores, or hyphens.']);
        if (strlen($password) < 8) auth_reply(422, ['success'=>false, 'message'=>'Password must be at least 8 characters.']);
        $check = $pdo->prepare('SELECT username, email FROM users WHERE LOWER(username) = LOWER(:username) OR LOWER(email) = LOWER(:email) LIMIT 1');
        $check->execute(['username'=>$username, 'email'=>$email]);
        $existing = $check->fetch();
        if ($existing) {
            if (strcasecmp((string)$existing['username'], $username) === 0) auth_reply(409, ['success'=>false, 'message'=>'That username is already taken.']);
            auth_reply(409, ['success'=>false, 'message'=>'An account with that email already exists.']);
        }
        // Public registrations are held inactive until an administrator approves them.
        // The existing Users screen lets an administrator activate Pet Owner accounts.
        $id = db_insert($pdo, "INSERT INTO users (full_name, username, email, password_hash, role, status) VALUES (:name, :username, :email, :hash, 'Pet Owner', 'Inactive')", ['name'=>$name, 'username'=>$username, 'email'=>$email, 'hash'=>password_hash($password, PASSWORD_DEFAULT)]);
        $row = ['id'=>$id, 'full_name'=>$name, 'username'=>$username, 'email'=>$email, 'role'=>'Pet Owner', 'status'=>'Inactive'];
        auth_reply(201, ['success'=>true, 'user'=>public_user($row), 'message'=>'Your account has been created and is waiting for administrator approval. You can sign in after the clinic activates it.']);
    }

    $loginKey = hash('sha256', strtolower($username));
    // Limit by account as well as IP+account, so rotating/spoofing forwarded IPs
    // cannot bypass the protection for one targeted username.
    if (!auth_rate_limit($pdo, 'login-account:' . $loginKey, 5, 900)
        || !auth_rate_limit($pdo, 'login:' . $ipKey . ':' . $loginKey, 5, 900)) {
        auth_reply(429, ['success'=>false, 'message'=>'Too many sign-in attempts. Please try again in 15 minutes.']);
    }
    $stmt = $pdo->prepare('SELECT id, full_name, username, email, password_hash, role, status, auth_version FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, (string)$row['password_hash'])) auth_reply(401, ['success'=>false, 'message'=>'Incorrect username or password.']);
    if (($row['status'] ?? 'Active') !== 'Active') auth_reply(403, ['success'=>false, 'message'=>'This account is inactive or awaiting administrator approval. Please contact the clinic administrator.']);
    // Successful authentication must not count toward the failed-login throttle.
    $pdo->prepare('DELETE FROM public.auth_rate_limits WHERE bucket_key IN (?, ?)')
        ->execute(['login-account:' . $loginKey, 'login:' . $ipKey . ':' . $loginKey]);
    session_regenerate_id(true);
    $_SESSION['account'] = public_user($row);
    // The session store retains inactive sessions for eight hours; persist the browser cookie for the same period only when requested.
    if (!empty($data['remember'])) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires' => time() + 28800,
            'path' => $cookie['path'] ?: '/',
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Lax',
        ]);
    }
    auth_reply(200, ['success'=>true, 'user'=>$_SESSION['account']]);
} catch (Throwable $e) {
    error_log('Authentication endpoint error: '.$e->getMessage());
    auth_reply(500, ['success'=>false, 'message'=>'The server could not process your request. Check the Supabase connection settings in Vercel and that supabase/schema.sql was run.']);
}
