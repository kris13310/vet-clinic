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
if (!in_array($action, ['register','login'], true)) {
    auth_reply(400, ['success'=>false, 'message'=>'Unknown authentication action.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    auth_reply(405, ['success'=>false, 'message'=>'Use POST for this action.']);
}
$data = auth_input();
$username = trim((string)($data['username'] ?? ''));
$password = (string)($data['password'] ?? '');
if ($username === '' || $password === '') {
    auth_reply(422, ['success'=>false, 'message'=>'Enter your username and password.']);
}

try {

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
    session_regenerate_id(true);
    $_SESSION['account'] = public_user($row);
    auth_reply(200, ['success'=>true, 'user'=>$_SESSION['account']]);
} catch (Throwable $e) {
    error_log('Authentication endpoint error: '.$e->getMessage());
    auth_reply(500, ['success'=>false, 'message'=>'The server could not process your request. Check the Supabase connection settings in Vercel and that supabase/schema.sql was run.']);
}
