<?php
declare(strict_types=1);
/**
 * Loaded first by every API file. Vercel containers are temporary and not shared, so PHP's default
 * file-based sessions would log people out at random. Sessions are stored in the php_sessions table instead.
 */
/* Always answer with JSON, even when something crashes, so the website can show a useful message instead of
   "Invalid server response". Add the environment variable APP_DEBUG=1 in Vercel to see the technical detail. */
ob_start();
$__fail = static function (string $detail): void {
    error_log('Clinic API error: ' . $detail);
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['success' => false, 'message' => 'Server error. Check the DB_* settings in Vercel and that supabase/schema.sql was run.' . (getenv('APP_DEBUG') ? ' [' . $detail . ']' : '')]);
};
set_exception_handler(static function (Throwable $e) use ($__fail): void { $__fail(get_class($e) . ': ' . $e->getMessage()); });
register_shutdown_function(static function () use ($__fail): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) $__fail($e['message'] . ' @ ' . basename((string)$e['file']) . ':' . $e['line']);
});

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/helpers.php';

final class PgSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface {
    public function __construct(private PDO $pdo) {}
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false {
        try {
            $s = $this->pdo->prepare('SELECT data FROM php_sessions WHERE id = ?'); $s->execute([$id]);
            $d = $s->fetchColumn();
            return $d === false ? '' : (string)base64_decode((string)$d, true);
        } catch (Throwable $e) { error_log('Session read failed (is the php_sessions table created?): ' . $e->getMessage()); return ''; }
    }
    public function write(string $id, string $data): bool {
        try {
            $this->pdo->prepare('INSERT INTO php_sessions (id, data, updated_at) VALUES (?, ?, now()) ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, updated_at = now()')
                ->execute([$id, base64_encode($data)]);
            return true;
        } catch (Throwable $e) { error_log('Session write failed: ' . $e->getMessage()); return false; }
    }
    public function destroy(string $id): bool {
        try { $this->pdo->prepare('DELETE FROM php_sessions WHERE id = ?')->execute([$id]); } catch (Throwable $e) { error_log('Session destroy failed: ' . $e->getMessage()); }
        return true;
    }
    public function gc(int $max_lifetime): int|false {
        try { $n = max(60, $max_lifetime); return (int)$this->pdo->exec("DELETE FROM php_sessions WHERE updated_at < now() - interval '$n seconds'"); }
        catch (Throwable $e) { error_log('Session gc failed: ' . $e->getMessage()); return 0; }
    }
    public function validateId(string $id): bool {
        try { $s = $this->pdo->prepare('SELECT 1 FROM php_sessions WHERE id = ?'); $s->execute([$id]); return (bool)$s->fetchColumn(); }
        catch (Throwable $e) { return false; }
    }
    public function updateTimestamp(string $id, string $data): bool {
        try { $this->pdo->prepare('UPDATE php_sessions SET updated_at = now() WHERE id = ?')->execute([$id]); } catch (Throwable $e) { error_log('Session touch failed: ' . $e->getMessage()); }
        return true;
    }
}

$https = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['HTTPS'] ?? '') === 'on');
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', '28800');   // sessions last up to 8 hours
ini_set('session.gc_probability', '1'); ini_set('session.gc_divisor', '50');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_set_save_handler(new PgSessionHandler($pdo), true);
session_start();


/*
 * Revalidate authenticated sessions against the current account record.
 * Account deactivation, role changes and password changes revoke old sessions.
 */
$sessionAccount = $_SESSION['account'] ?? null;
$authAction = (string)($_GET['action'] ?? '');
$authEndpoint = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'auth.php';
$publicAuthAction = $authEndpoint && in_array($authAction, ['login', 'register', 'forgot', 'reset', 'logout'], true);
if (!$publicAuthAction && is_array($sessionAccount) && !empty($sessionAccount['id'])) {
    try {
        $accountCheck = $pdo->prepare('SELECT role, status, auth_version FROM users WHERE id = ? LIMIT 1');
        $accountCheck->execute([(int)$sessionAccount['id']]);
        $currentAccount = $accountCheck->fetch();
        $sessionVersion = $sessionAccount['auth_version'] ?? null;
        $validSession = $currentAccount
            && ($currentAccount['status'] ?? '') === 'Active'
            && $sessionVersion !== null
            && (int)$sessionVersion === (int)$currentAccount['auth_version'];
        if (!$validSession) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $cookie = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
            }
            session_destroy();
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['success'=>false, 'message'=>'Your session has expired or your account changed. Please sign in again.']);
            exit;
        }
        $_SESSION['account']['role'] = (string)$currentAccount['role'];
    } catch (Throwable $e) {
        error_log('Session account validation failed: ' . $e->getMessage());
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['success'=>false, 'message'=>'Unable to verify your session. Please try again.']);
        exit;
    }
}
