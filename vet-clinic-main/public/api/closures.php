<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
require_once __DIR__ . '/helpers.php';

function closure_reply(int $code, array $body): never { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$account = $_SESSION['account'] ?? null;
if (!is_array($account) || empty($account['id'])) closure_reply(401, ['success'=>false,'message'=>'Please sign in again.']);
$role = (string)($account['role'] ?? '');
if (!in_array($role, ['Administrator', 'Veterinarian', 'Veterinary Staff'], true)) closure_reply(403, ['success'=>false,'message'=>'Clinic staff access only.']);
$isAdmin = $role === 'Administrator';

try {
    $action = (string)($_GET['action'] ?? 'list');

    /* ---------- List: rules, upcoming closures, upcoming Philippine holidays ---------- */
    if ($action === 'list') {
        $today = date('Y-m-d');
        $q = $pdo->prepare('SELECT id, closure_date, to_char(start_time,\'HH24:MI\') AS start_time, to_char(end_time,\'HH24:MI\') AS end_time, type, reason FROM clinic_closures WHERE closure_date >= ? ORDER BY closure_date, start_time');
        $q->execute([$today]);
        $holidays = [];
        $limit = date('Y-m-d', strtotime('+400 days'));
        for ($y = (int)date('Y'); $y <= (int)date('Y') + 1; $y++) {
            foreach (ph_holidays($y) as $date => $h) if ($date >= $today && $date <= $limit) $holidays[] = ['date'=>$date, 'name'=>$h[0], 'type'=>$h[1]];
        }
        $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $closed = array_map(static fn($w) => $names[$w], CLOSED_WEEKDAYS);
        closure_reply(200, ['success'=>true, 'can_edit'=>$isAdmin,
            'rules'=>['closed_days'=>$closed, 'open'=>CLINIC_OPEN, 'close'=>CLINIC_CLOSE, 'slot_minutes'=>SLOT_MINUTES, 'lunch_start'=>LUNCH_START, 'lunch_end'=>LUNCH_END],
            'closures'=>$q->fetchAll(), 'holidays'=>$holidays]);
    }

    /* ---------- Calendar marks for one month: holidays, weekly days off, doctor away ---------- */
    if ($action === 'calendar') {
        $month = (string)($_GET['month'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) closure_reply(422, ['success'=>false,'message'=>'Invalid month.']);
        $first = $month . '-01'; $n = (int)date('t', strtotime($first)); $lastDay = $month . '-' . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
        $map = closures_map($pdo, $first, $lastDay); $days = [];
        for ($d = 1; $d <= $n; $d++) {
            $date = $month . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
            $info = day_info($date, $map[$date] ?? []);
            if ($info['state'] !== 'open') { $days[$date] = ['state'=>$info['state'], 'label'=>$info['label']]; continue; }
            if ($info['partial']) { $r = $info['partial'][0]; $days[$date] = ['state'=>'partial', 'label'=>trim(((string)($r['reason'] ?: $r['type'])) . ' ' . $r['start_time'] . '–' . $r['end_time'])]; }
        }
        closure_reply(200, ['success'=>true, 'days'=>$days]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') closure_reply(405, ['success'=>false,'message'=>'Use POST for this action.']);
    if (!$isAdmin) closure_reply(403, ['success'=>false,'message'=>'Only the administrator can change clinic availability.']);
    $in = json_decode(file_get_contents('php://input') ?: '{}', true); if (!is_array($in)) $in = [];

    /* ---------- Add a closure (holiday / doctor unavailable / clinic closed) ---------- */
    if ($action === 'add') {
        $date = trim((string)($in['date'] ?? '')); $type = (string)($in['type'] ?? 'Doctor Unavailable'); $reason = trim((string)($in['reason'] ?? ''));
        $whole = !empty($in['whole']); $start = substr(trim((string)($in['start'] ?? '')), 0, 5); $end = substr(trim((string)($in['end'] ?? '')), 0, 5);
        if (!valid_date_string($date) || $date < date('Y-m-d')) closure_reply(422, ['success'=>false,'message'=>'Choose a valid date today or later.']);
        if (!in_array($type, CLOSURE_TYPES, true)) closure_reply(422, ['success'=>false,'message'=>'Choose a valid type.']);
        if (mb_strlen($reason) > 190) closure_reply(422, ['success'=>false,'message'=>'The reason is too long (max 190 characters).']);
        if (!$whole) {
            if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || time_to_minutes($start) >= time_to_minutes($end)) closure_reply(422, ['success'=>false,'message'=>'Choose a start time that is earlier than the end time.']);
        }
        // Serialize closure changes with booking and rescheduling. The closure row,
        // affected-appointment lookup, and owner notifications commit as one operation.
        $n = with_slot_lock($pdo, function () use ($pdo, $date, $whole, $start, $end, $type, $reason, $account) {
            $pdo->prepare('INSERT INTO clinic_closures (closure_date, start_time, end_time, type, reason, created_by, created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$date, $whole ? null : $start . ':00', $whole ? null : $end . ':00', $type, $reason !== '' ? $reason : null, (int)$account['id'], date('Y-m-d H:i:s')]);

            // Owners who already have an active appointment inside the blocked time are asked to reschedule.
            $sql = "SELECT id FROM appointments WHERE appointment_date=? AND status IN ('Pending','Confirmed')";
            $params = [$date];
            if (!$whole) { $sql .= ' AND appointment_time >= ? AND appointment_time < ?'; array_push($params, $start . ':00', $end . ':00'); }
            $aff = $pdo->prepare($sql); $aff->execute($params);
            $ids = array_map('intval', array_column($aff->fetchAll(), 'id'));
            $why = $reason !== '' ? $reason : $type;
            foreach ($ids as $aid) {
                notify_appointment_owner($pdo, $aid, 'closure', 'Clinic unavailable on {date}',
                    'The clinic is unavailable on {date} (' . $why . '). Your appointment for {pet} at {time} needs a new schedule. Please book another date or wait for the clinic to reschedule it.');
            }
            return count($ids);
        });
        closure_reply(201, ['success'=>true, 'affected'=>$n, 'message'=>'Saved. Pet owners can no longer book that ' . ($whole ? 'day' : 'time') . '.' . ($n ? " $n existing appointment" . ($n === 1 ? '' : 's') . ' affected: the owner' . ($n === 1 ? ' was' : 's were') . ' notified, so reschedule ' . ($n === 1 ? 'it' : 'them') . ' from the Appointments page.' : '')]);
    }

    /* ---------- Remove a closure ---------- */
    if ($action === 'delete') {
        $id = (int)($in['id'] ?? 0);
        if (!$id) closure_reply(422, ['success'=>false,'message'=>'Invalid closure.']);
        $deleted = with_slot_lock($pdo, function () use ($pdo, $id) {
            $d = $pdo->prepare('DELETE FROM clinic_closures WHERE id=?');
            $d->execute([$id]);
            return $d->rowCount();
        });
        if (!$deleted) closure_reply(404, ['success'=>false,'message'=>'That closure was not found.']);
        closure_reply(200, ['success'=>true,'message'=>'Closure removed. That time can be booked again.']);
    }

    closure_reply(400, ['success'=>false,'message'=>'Unknown action.']);
} catch (Throwable $e) {
    error_log('Closures API error: ' . $e->getMessage());
    closure_reply(500, ['success'=>false,'message'=>'Could not process availability. Check the database connection and that supabase/schema.sql was run.']);
}
