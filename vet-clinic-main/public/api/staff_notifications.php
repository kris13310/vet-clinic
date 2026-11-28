<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function staff_notif_reply(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$account = $_SESSION['account'] ?? null;
if (!is_array($account) || empty($account['id'])) staff_notif_reply(401, ['success'=>false,'message'=>'Please sign in again.']);
if (!in_array(($account['role'] ?? ''), ['Administrator','Veterinarian','Veterinary Staff'], true)) staff_notif_reply(403, ['success'=>false,'message'=>'Clinic staff access only.']);
$uid = (int)$account['id'];

try {
    $action = (string)($_GET['action'] ?? 'list');
    $unread = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
    $unread->execute([$uid]);
    $unreadCount = (int)$unread->fetchColumn();

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
        $q = $pdo->prepare('SELECT id,appointment_id,type,title,message,is_read,created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 50');
        $q->execute([$uid]);
        $rows = array_map(static function ($row) {
            $row['id'] = (int)$row['id'];
            $row['appointment_id'] = $row['appointment_id'] === null ? null : (int)$row['appointment_id'];
            $row['is_read'] = (int)$row['is_read'];
            return $row;
        }, $q->fetchAll());
        staff_notif_reply(200, ['success'=>true,'notifications'=>$rows,'unread'=>$unreadCount]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'mark_read') {
        $input = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($input)) $input = [];
        if (!empty($input['all'])) {
            $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0')->execute([$uid]);
        } else {
            $id = (int)($input['id'] ?? 0);
            if ($id < 1) staff_notif_reply(422, ['success'=>false,'message'=>'Invalid notification.']);
            $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([$id, $uid]);
        }
        $unread->execute([$uid]);
        staff_notif_reply(200, ['success'=>true,'unread'=>(int)$unread->fetchColumn()]);
    }

    staff_notif_reply(405, ['success'=>false,'message'=>'Unsupported notification action.']);
} catch (Throwable $e) {
    error_log('Staff notifications API error: '.$e->getMessage());
    staff_notif_reply(500, ['success'=>false,'message'=>'Unable to load or update notifications.']);
}
