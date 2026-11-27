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
function auth_client_ip(): string {
    $candidate = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : 'unknown';
}
function auth_rate_limit(PDO $pdo, string $key, int $max, int $windowSeconds): bool {
    $sql = "INSERT INTO auth_rate_limits (bucket_key, window_started_at, attempts) VALUES (:bucket, now(), 1)
      ON CONFLICT (bucket_key) DO UPDATE SET
      attempts = CASE WHEN auth_rate_limits.window_started_at <= now() - (CAST(:window AS INTEGER) * INTERVAL '1 second') THEN 1 ELSE auth_rate_limits.attempts + 1 END,
      window_started_at = CASE WHEN auth_rate_limits.window_started_at <= now() - (CAST(:window AS INTEGER) * INTERVAL '1 second') THEN now() ELSE auth_rate_limits.window_started_at END
      RETURNING attempts";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['bucket'=>$key, 'window'=>$windowSeconds]);
    return (int)$stmt->fetchColumn() <= $max;
}
function auth_send_reset_email(string $to, string $token): bool {
    $apiKey = (string)getenv('RESEND_API_KEY');
    $from = (string)getenv('MAIL_FROM');
    if ($apiKey === '' || $from === '') return false;
    $appUrl = rtrim((string)(getenv('APP_URL') ?: 'https://vet-clinic-one-pied.vercel.app'), '/');
    $link = $appUrl . '/#reset-token=' . rawurlencode($token);
    $payload = json_encode(['from'=>$from,'to'=>[$to],'subject'=>'Reset your Veterinary Clinic password',
      'text'=>"A password reset was requested for your clinic account.\n\nOpen this link to choose a new password:\n{$link}\n\nThis link expires in 60 minutes. If you did not request this, ignore this email."], JSON_UNESCAPED_SLASHES);
    if ($payload === false) return false;
    $context = stream_context_create(['http'=>['method'=>'POST','header'=>"Authorization: Bearer {$apiKey}\r\nContent-Type: application/json\r\n",'content'=>$payload,'timeout'=>10,'ignore_errors'=>true]]);
    $response = @file_get_contents('https://api.resend.com/emails', false, $context);
    $statusLine = $http_response_header[0] ?? '';
    return $response !== false && preg_match('/\s2\d\d\s/', $statusLine) === 1;
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
    ];
}

$action = (string)($_GET['action'] ?? '');
if ($action === 'me') {
    $current = $_SESSION['account'] ?? null;
    if (!is_array($current) || empty($current['id'])) {
        auth_reply(401, ['success'=>false, 'message'=>'You are not signed in.']);
    }
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
if (!in_array($action, ['register','login','forgot','reset'], true)) {
    auth_reply(400, ['success'=>false, 'message'=>'Unknown authentication action.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auth_reply(405, ['success'=>false, 'message'=>'Use POST for this action.']);
}
$data = auth_input();
if ($action === 'forgot') {
    $email = trim((string)($data['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) auth_reply(422, ['success'=>false, 'message'=>'Enter a valid email address.']);
    try {
        $ip = auth_client_ip();
        if (!auth_rate_limit($pdo, 'forgot-ip:'.hash('sha256',$ip), 10, 3600) ||
            !auth_rate_limit($pdo, 'forgot-email:'.hash('sha256',strtolower($email)), 3, 3600)) {
            auth_reply(429, ['success'=>false, 'message'=>'Too many password reset requests. Please try again later.']);
        }
        if ((string)getenv('RESEND_API_KEY') === '' || (string)getenv('MAIL_FROM') === '') {
            auth_reply(503, ['success'=>false, 'message'=>'Password recovery email is not configured yet. Please contact the clinic administrator.']);
        }
        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
        $stmt->execute(['email'=>$email]);
        $user = $stmt->fetch();
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE users SET password_reset_token_hash = :hash, password_reset_expires = now() + interval '60 minutes' WHERE id = :id")
                ->execute(['hash'=>hash('sha256',$token), 'id'=>$user['id']]);
            if (!auth_send_reset_email((string)$user['email'], $token)) {
                $pdo->prepare('UPDATE users SET password_reset_token_hash = NULL, password_reset_expires = NULL WHERE id = :id')->execute(['id'=>$user['id']]);
                error_log('Password reset email delivery failed.');
                auth_reply(200, ['success'=>true, 'message'=>'If an account exists for that email, a reset link has been sent.']);
            }
        }
        auth_reply(200, ['success'=>true, 'message'=>'If an account exists for that email, a reset link has been sent.']);
    } catch (Throwable $e) {
        error_log('Password recovery error: '.$e->getMessage());
        auth_reply(500, ['success'=>false, 'message'=>'Unable to process the password reset request.']);
    }
}
if ($action === 'reset') {
    $token = trim((string)($data['token'] ?? ''));
    $newPassword = (string)($data['password'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/i', $token) || strlen($newPassword) < 8) auth_reply(422, ['success'=>false, 'message'=>'Enter a valid reset token and a password of at least 8 characters.']);
    try {
        if (!auth_rate_limit($pdo, 'reset-ip:'.hash('sha256',auth_client_ip()), 10, 900)) auth_reply(429, ['success'=>false, 'message'=>'Too many reset attempts. Please try again later.']);
        $stmt = $pdo->prepare('UPDATE users SET password_hash = :password, password_reset_token_hash = NULL, password_reset_expires = NULL WHERE password_reset_token_hash = :hash AND password_reset_expires > now() RETURNING id');
        $stmt->execute(['password'=>password_hash($newPassword, PASSWORD_DEFAULT), 'hash'=>hash('sha256',$token)]);
        if (!$stmt->fetchColumn()) auth_reply(400, ['success'=>false, 'message'=>'This reset link is invalid or has expired. Request a new one.']);
        auth_reply(200, ['success'=>true, 'message'=>'Your password has been updated. You can sign in now.']);
    } catch (Throwable $e) {
        error_log('Password reset error: '.$e->getMessage());
        auth_reply(500, ['success'=>false, 'message'=>'Unable to reset the password.']);
    }
}
$username = trim((string)($data['username'] ?? ''));
$password = (string)($data['password'] ?? '');
if ($username === '' || $password === '') {
    auth_reply(422, ['success'=>false, 'message'=>'Enter your username and password.']);
}

try {
    $ip = auth_client_ip();
    $userKey = hash('sha256', strtolower($username));
    if ($action === 'login' && (!auth_rate_limit($pdo, 'login-ip:'.hash('sha256',$ip), 30, 900) ||
        !auth_rate_limit($pdo, 'login-user:'.hash('sha256',$ip.':'.$userKey), 5, 900))) {
        auth_reply(429, ['success'=>false, 'message'=>'Too many sign-in attempts. Please try again in 15 minutes.']);
    }
    if ($action === 'register' && !auth_rate_limit($pdo, 'register-ip:'.hash('sha256',$ip), 5, 3600)) {
        auth_reply(429, ['success'=>false, 'message'=>'Too many registration attempts. Please try again later.']);
    }

    if ($action === 'register') {
        $name = trim((string)($data['name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        if ($name === '' || $email === '') auth_reply(422, ['success'=>false, 'message'=>'Enter your full name and email.']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) auth_reply(422, ['success'=>false, 'message'=>'Enter a valid email address.']);
        if (mb_strlen($name) > 120 || mb_strlen($username) > 50 || !preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
            auth_reply(422, ['success'=>false, 'message'=>'Use a username of up to 50 letters, numbers, dots, underscores, or hyphens.']);
        }
        if (strlen($password) < 8) auth_reply(422, ['success'=>false, 'message'=>'Password must be at least 8 characters.']);

        $check = $pdo->prepare('SELECT username, email FROM users WHERE LOWER(username) = LOWER(:username) OR LOWER(email) = LOWER(:email) LIMIT 1');
        $check->execute(['username'=>$username, 'email'=>$email]);
        $existing = $check->fetch();
        if ($existing) {
            if (strcasecmp((string)$existing['username'], $username) === 0) auth_reply(409, ['success'=>false, 'message'=>'That username is already taken.']);
            auth_reply(409, ['success'=>false, 'message'=>'An account with that email already exists.']);
        }
        // Public registration must never create staff or administrator accounts.
        $id = db_insert($pdo, "INSERT INTO users (full_name, username, email, password_hash, role, status) VALUES (:name, :username, :email, :hash, 'Pet Owner', 'Active')", ['name'=>$name, 'username'=>$username, 'email'=>$email, 'hash'=>password_hash($password, PASSWORD_DEFAULT)]);
        $row = ['id'=>$id, 'full_name'=>$name, 'username'=>$username, 'email'=>$email, 'role'=>'Pet Owner', 'status'=>'Active'];
        session_regenerate_id(true);
        $_SESSION['account'] = public_user($row);
        auth_reply(201, ['success'=>true, 'user'=>$_SESSION['account'], 'message'=>'Account created successfully.']);
    }

    $stmt = $pdo->prepare('SELECT id, full_name, username, email, password_hash, role, status FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1');
    $stmt->execute(['username'=>$username]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, (string)$row['password_hash'])) {
        auth_reply(401, ['success'=>false, 'message'=>'Incorrect username or password.']);
    }
    if (($row['status'] ?? 'Active') !== 'Active') {
        auth_reply(403, ['success'=>false, 'message'=>'This account is inactive. Please contact the administrator.']);
    }
    $pdo->prepare('DELETE FROM auth_rate_limits WHERE bucket_key IN (:ip_key, :user_key)')->execute([
        'ip_key'=>'login-ip:'.hash('sha256',$ip),
        'user_key'=>'login-user:'.hash('sha256',$ip.':'.$userKey),
    ]);
    session_regenerate_id(true);
    $_SESSION['account'] = public_user($row);
    auth_reply(200, ['success'=>true, 'user'=>$_SESSION['account']]);
} catch (Throwable $e) {
    error_log('Authentication endpoint error: '.$e->getMessage());
    auth_reply(500, ['success'=>false, 'message'=>'The server could not process your request. Check the Supabase connection settings in Vercel and that supabase/schema.sql was run.']);
}
