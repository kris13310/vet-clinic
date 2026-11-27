<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Accept common session shapes used by PHP authentication handlers.
$sessionUser = $_SESSION['user'] ?? $_SESSION['account'] ?? [];
if (!is_array($sessionUser)) $sessionUser = [];
$role = (string)($sessionUser['role'] ?? $_SESSION['role'] ?? '');
$loggedIn = !empty($_SESSION['user_id']) || !empty($_SESSION['userID']) || !empty($_SESSION['logged_in']) || !empty($sessionUser['id']) || !empty($sessionUser['user_id']) || !empty($sessionUser['username']);
if (!$loggedIn) respond(401, ['success'=>false, 'message'=>'Please sign in again.']);
if ($role !== 'Administrator') respond(403, ['success'=>false, 'message'=>'Only administrators can manage registered users.']);
$meId = (int)($sessionUser['id'] ?? $_SESSION['user_id'] ?? $_SESSION['userID'] ?? 0);

const STAFF_ROLES = ['Veterinarian', 'Veterinary Staff'];

try {

    /* ---------- List (unchanged behaviour) ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query('SELECT id, full_name, username, email, role, status, created_at FROM users ORDER BY created_at DESC, id DESC');
        respond(200, ['success'=>true, 'users'=>$stmt->fetchAll()]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['success'=>false, 'message'=>'Method not allowed.']);

    $in = json_decode(file_get_contents('php://input') ?: '{}', true); if (!is_array($in)) $in = [];
    $action = (string)($_GET['action'] ?? 'save');
    $id = (int)($in['id'] ?? 0);

    /* ---------- Create or update a user ---------- */
    if ($action === 'save') {
        $name = trim((string)($in['name'] ?? '')); $username = trim((string)($in['username'] ?? '')); $email = trim((string)($in['email'] ?? ''));
        $newRole = (string)($in['role'] ?? ''); $status = (string)($in['status'] ?? 'Active'); $password = (string)($in['password'] ?? '');
        if ($name === '' || mb_strlen($name) > 120) respond(422, ['success'=>false, 'message'=>'Enter the full name (up to 120 characters).']);
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) respond(422, ['success'=>false, 'message'=>'Username must be 3-50 letters, numbers, dots, underscores or hyphens.']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) respond(422, ['success'=>false, 'message'=>'Enter a valid email address.']);
        if (!in_array($status, ['Active', 'Inactive'], true)) respond(422, ['success'=>false, 'message'=>'Choose a valid status.']);
        if ($password !== '' && strlen($password) < 8) respond(422, ['success'=>false, 'message'=>'Password must be at least 8 characters.']);

        $dup = $pdo->prepare('SELECT username, email FROM users WHERE (LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)) AND id <> ? LIMIT 1');
        $dup->execute([$username, $email, $id]);
        if ($row = $dup->fetch()) respond(409, ['success'=>false, 'message'=>strcasecmp((string)$row['username'], $username) === 0 ? 'That username is already taken.' : 'An account with that email already exists.']);

        if ($id === 0) {                                                   // create: only clinic staff roles, never Administrator
            if (!in_array($newRole, STAFF_ROLES, true)) respond(422, ['success'=>false, 'message'=>'Choose Veterinarian or Veterinary Staff.']);
            if ($password === '') respond(422, ['success'=>false, 'message'=>'Enter a password of at least 8 characters.']);
            $newId = db_insert($pdo, 'INSERT INTO users (full_name, username, email, password_hash, role, status) VALUES (?,?,?,?,?,?)', [$name, $username, $email, password_hash($password, PASSWORD_DEFAULT), $newRole, $status]);
            respond(201, ['success'=>true, 'message'=>'User added.', 'id'=>$newId]);
        }

        $cur = $pdo->prepare('SELECT id, role FROM users WHERE id = ?'); $cur->execute([$id]); $row = $cur->fetch();
        if (!$row) respond(404, ['success'=>false, 'message'=>'User not found.']);
        if ($id === $meId && $status !== 'Active') respond(422, ['success'=>false, 'message'=>'You cannot deactivate your own account.']);
        $finalRole = (string)$row['role'];
        if (in_array($finalRole, STAFF_ROLES, true)) {                    // staff may switch between the two staff roles only
            if (!in_array($newRole, STAFF_ROLES, true)) respond(422, ['success'=>false, 'message'=>'Choose Veterinarian or Veterinary Staff.']);
            $finalRole = $newRole;
        }                                                                 // Administrator / Pet Owner roles are never changed here
        $pdo->prepare('UPDATE users SET full_name=?, username=?, email=?, role=?, status=? WHERE id=?')->execute([$name, $username, $email, $finalRole, $status, $id]);
        if ($password !== '') $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        respond(200, ['success'=>true, 'message'=>$password !== '' ? 'Changes saved and the password was reset.' : 'Changes saved.', 'id'=>$id]);
    }

    /* ---------- Delete a staff account (owners and administrators are deactivated instead) ---------- */
    if ($action === 'delete') {
        if ($id === $meId) respond(422, ['success'=>false, 'message'=>'You cannot delete your own account.']);
        $cur = $pdo->prepare('SELECT role FROM users WHERE id = ?'); $cur->execute([$id]); $row = $cur->fetch();
        if (!$row) respond(404, ['success'=>false, 'message'=>'User not found.']);
        if (!in_array($row['role'], STAFF_ROLES, true)) respond(403, ['success'=>false, 'message'=>'Administrator and pet owner accounts cannot be deleted. Set them to Inactive instead.']);

        // Keep staff accounts that are referenced by clinic history. The schema intentionally
        // restricts medical-report deletion and nulls appointment/vaccination references, so
        // deleting these accounts would either fail or erase the historical staff attribution.
        $history = $pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM appointments WHERE veterinarian_id = ?) +
                (SELECT COUNT(*) FROM vaccinations WHERE veterinarian_id = ?) +
                (SELECT COUNT(*) FROM medical_reports WHERE veterinarian_id = ?) AS total'
        );
        $history->execute([$id, $id, $id]);
        if ((int)$history->fetchColumn() > 0) {
            respond(409, ['success'=>false, 'message'=>'This staff account is linked to clinic history. Set the account to Inactive instead so past appointments and medical records keep their veterinarian information.']);
        }

        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        respond(200, ['success'=>true, 'message'=>'User deleted.']);
    }

    respond(400, ['success'=>false, 'message'=>'Unknown user action.']);
} catch (Throwable $e) {
    error_log('Users API error: '.$e->getMessage());
    respond(500, ['success'=>false, 'message'=>'Unable to process the request. Check the database connection and users table.']);
}
