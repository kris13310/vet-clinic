<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
require_once __DIR__ . '/helpers.php';

function appt_reply(int $code, array $body): never { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$account = $_SESSION['account'] ?? null;
if (!is_array($account) || empty($account['id'])) appt_reply(401, ['success'=>false,'message'=>'Please sign in again.']);
if (!in_array(($account['role'] ?? ''), ['Administrator','Veterinarian','Veterinary Staff'], true)) appt_reply(403, ['success'=>false,'message'=>'Clinic staff access only.']);
$role = (string)($account['role'] ?? '');
$uid = (int)$account['id'];

try {
    $action = (string)($_GET['action'] ?? 'list');

    /* ---------- List every appointment (unchanged behaviour) ---------- */
    if ($action === 'list') {
        $sql = "SELECT a.id,a.pet_id,a.veterinarian_id,a.appointment_date,a.appointment_time,a.purpose,a.status,a.cancelled_by,a.notes,p.name pet_name,p.owner_name,p.owner_email,p.owner_phone,p.address,u.full_name AS stored_vet
                FROM appointments a
                JOIN pets p ON p.id=a.pet_id
                LEFT JOIN users u ON u.id=a.veterinarian_id";
        if (($account['role'] ?? '') === 'Veterinarian') {
            $q = $pdo->prepare($sql . " WHERE a.veterinarian_id=? ORDER BY a.appointment_date DESC,a.appointment_time DESC");
            $q->execute([(int)$account['id']]);
        } else {
            $q = $pdo->query($sql . " ORDER BY a.appointment_date DESC,a.appointment_time DESC");
        }
        $doctor = current_doctor($pdo);
        $rows = array_map(static function ($r) use ($doctor) {
            $r['veterinarian'] = doctor_display($doctor, $r['status'] === 'Completed', $r['stored_vet']);
            unset($r['stored_vet']);
            return $r;
        }, $q->fetchAll());
        appt_reply(200, ['success'=>true,'appointments'=>$rows]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') appt_reply(405, ['success'=>false,'message'=>'Use POST for this action.']);
    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($input)) $input = [];
    $id = (int)($input['id'] ?? 0);

    /* ---------- Staff creates an appointment (walk-in / phone booking) ---------- */
    if ($action === 'create') {
        $petId = (int)($input['pet_id'] ?? 0);
        $date = trim((string)($input['date'] ?? '')); $time = substr(trim((string)($input['time'] ?? '')), 0, 5);
        $purpose = trim((string)($input['purpose'] ?? '')); $status = (string)($input['status'] ?? 'Pending');
        if (!$petId || $purpose === '') appt_reply(422, ['success'=>false,'message'=>'Choose a pet and enter the purpose of the visit.']);
        if (mb_strlen($purpose) > 255) appt_reply(422, ['success'=>false,'message'=>'The purpose is too long (max 255 characters).']);
        if (!in_array($status, ['Pending','Confirmed'], true)) appt_reply(422, ['success'=>false,'message'=>'New appointments must start as Pending or Confirmed.']);
        $err = validate_slot($pdo, $date, $time, true);
        if ($err) appt_reply(422, ['success'=>false,'message'=>$err]);

        $result = with_slot_lock($pdo, function () use ($pdo, $petId, $date, $time, $purpose, $status) {
            $p = $pdo->prepare('SELECT id FROM pets WHERE id=?'); $p->execute([$petId]);
            if (!$p->fetch()) return [404, 'That pet was not found.'];
            $sameDay = $pdo->prepare("SELECT 1 FROM appointments WHERE pet_id=? AND appointment_date=? AND status IN ('Pending','Confirmed','In Progress') LIMIT 1");
            $sameDay->execute([$petId, $date]);
            if ($sameDay->fetchColumn()) return [409, 'This pet already has an active appointment on that date. Choose another date.'];
            if (slot_is_taken($pdo, $date, $time)) return [409, 'That date and time is already reserved. Choose another slot.'];
            $doctor = current_doctor($pdo);
            if (!$doctor) return [409, 'No active veterinarian is assigned to the clinic. Add or activate a veterinarian before booking appointments.'];
            $aid = db_insert($pdo, 'INSERT INTO appointments (pet_id, veterinarian_id, appointment_date, appointment_time, purpose, status) VALUES (?,?,?,?,?,?)', [$petId, $doctor['id'], $date, $time . ':00', $purpose, $status]); // the clinic's one doctor is assigned automatically
            if ($status === 'Confirmed') {
                notify_appointment_owner($pdo, $aid, 'approved', 'Appointment approved', 'The clinic scheduled and approved an appointment for {pet} on {date} at {time}.');
            } else {
                notify_appointment_owner($pdo, $aid, 'request', 'Appointment request recorded', 'The clinic recorded an appointment request for {pet} on {date} at {time}. It is Pending until approved.');
            }
            return [201, 'Appointment saved.'];
        });
        appt_reply($result[0], ['success'=>$result[0] === 201, 'message'=>$result[1]]);
    }

    /* ---------- Change status: Approve (Confirmed), Reject (Cancelled), In Progress, Completed ---------- */
    if ($action === 'status') {
        $status = (string)($input['status'] ?? '');
        if (!$id || !in_array($status, ['Confirmed','Cancelled','In Progress','Completed'], true)) appt_reply(422, ['success'=>false,'message'=>'Invalid appointment or status.']);

        $result = with_slot_lock($pdo, function () use ($pdo, $id, $status, $role, $uid) {
            $cur = $pdo->prepare('SELECT id, veterinarian_id, status, appointment_date, appointment_time FROM appointments WHERE id=?');
            $cur->execute([$id]);
            $row = $cur->fetch();
            if (!$row) return [404, 'Appointment not found.'];
            if ($role === 'Veterinarian' && (int)$row['veterinarian_id'] !== $uid) return [403, 'This appointment is not assigned to your veterinarian account.'];
            if ($row['status'] === $status) return [200, 'Appointment status is already up to date.'];
            $allowedTransitions = [
                'Pending' => ['Confirmed', 'Cancelled'],
                'Confirmed' => ['In Progress', 'Cancelled'],
                'In Progress' => ['Completed'],
                'Cancelled' => [],
                'Completed' => [],
            ];
            if (!in_array($status, $allowedTransitions[(string)$row['status']] ?? [], true)) {
                return [409, 'That appointment status change is not allowed. Follow Pending → Confirmed → In Progress → Medical Report → Completed.'];
            }
            if ($status === 'In Progress') {
                $assignedVet = $pdo->prepare("SELECT 1 FROM users WHERE id=? AND role='Veterinarian' AND status='Active'");
                $assignedVet->execute([(int)($row['veterinarian_id'] ?? 0)]);
                if (!$assignedVet->fetchColumn()) return [409, 'This appointment has no active assigned veterinarian. Ask the administrator to assign an active veterinarian first.'];
            }
            if ($status === 'Completed') {
                if ($role !== 'Veterinarian' || (int)$row['veterinarian_id'] !== $uid) {
                    return [403, 'Only the veterinarian assigned to this appointment can complete it.'];
                }
                $report = $pdo->prepare('SELECT 1 FROM medical_reports WHERE appointment_id=? AND veterinarian_id=?');
                $report->execute([$id, $uid]);
                if (!$report->fetchColumn()) return [409, 'Submit the assigned veterinarian medical report before completing this appointment.'];
            }
            $pdo->prepare('UPDATE appointments SET status=?, cancelled_by=? WHERE id=?')->execute([$status, $status === 'Cancelled' ? 'Clinic' : null, $id]);

            if ($status === 'Confirmed')      notify_appointment_owner($pdo, $id, 'approved',  'Appointment approved',  'Good news! Your appointment for {pet} on {date} at {time} has been approved.');
            elseif ($status === 'Cancelled')  notify_appointment_owner($pdo, $id, 'rejected',  'Appointment rejected',  'Sorry, your appointment request for {pet} on {date} at {time} could not be accepted. Please book another date or time.');
            elseif ($status === 'Completed')  notify_appointment_owner($pdo, $id, 'completed', 'Appointment completed', 'Your appointment for {pet} on {date} at {time} is completed. Thank you for visiting the clinic!');
            return [200, 'Appointment status updated.'];
        });
        appt_reply($result[0], ['success'=>$result[0] === 200, 'message'=>$result[1]]);
    }

    /* ---------- Reschedule to a new date/time (owner is notified) ---------- */
    if ($action === 'reschedule') {
        $date = trim((string)($input['date'] ?? '')); $time = substr(trim((string)($input['time'] ?? '')), 0, 5);
        if (!$id) appt_reply(422, ['success'=>false,'message'=>'Invalid appointment.']);
        $err = validate_slot($pdo, $date, $time);
        if ($err) appt_reply(422, ['success'=>false,'message'=>$err]);

        $result = with_slot_lock($pdo, function () use ($pdo, $id, $date, $time, $role, $uid) {
            $cur = $pdo->prepare('SELECT id, pet_id, veterinarian_id, status FROM appointments WHERE id=?');
            $cur->execute([$id]);
            $row = $cur->fetch();
            if (!$row) return [404, 'Appointment not found.'];
            if ($role === 'Veterinarian' && (int)$row['veterinarian_id'] !== $uid) return [403, 'This appointment is not assigned to your veterinarian account.'];
            if (!in_array($row['status'], ['Pending','Confirmed'], true)) return [409, 'Only Pending or Confirmed appointments can be rescheduled.'];
            $sameDay = $pdo->prepare("SELECT 1 FROM appointments WHERE pet_id=? AND appointment_date=? AND status IN ('Pending','Confirmed','In Progress') AND id<>? LIMIT 1");
            $sameDay->execute([(int)$row['pet_id'], $date, $id]);
            if ($sameDay->fetchColumn()) return [409, 'This pet already has another active appointment on that date. Choose another date.'];
            if (slot_is_taken($pdo, $date, $time, $id)) return [409, 'That date and time is already reserved. Choose another slot.'];
            $pdo->prepare('UPDATE appointments SET appointment_date=?, appointment_time=? WHERE id=?')->execute([$date, $time . ':00', $id]);
            notify_appointment_owner($pdo, $id, 'rescheduled', 'Appointment rescheduled', 'The clinic moved your appointment for {pet} to {date} at {time}. Please contact the clinic if this time does not work for you.');
            return [200, 'Appointment rescheduled and the owner was notified.'];
        });
        appt_reply($result[0], ['success'=>$result[0] === 200, 'message'=>$result[1]]);
    }

    /* ---------- Slot availability for the staff reschedule dialog ---------- */
    if ($action === 'slots') {
        // handled as POST so it shares the JSON parsing above: {date, exclude_id}
        $date = trim((string)($input['date'] ?? ''));
        if (!valid_date_string($date)) appt_reply(422, ['success'=>false,'message'=>'Choose a valid date.']);
        $counts = booked_counts($pdo, $date, (int)($input['exclude_id'] ?? 0));
        $info = day_info($date, closures_map($pdo, $date, $date)[$date] ?? []);
        $open = $info['state'] === 'open' && $date >= date('Y-m-d');
        $slots = [];
        foreach (clinic_slots() as $t) {
            $ok = $open && !slot_is_past($date, $t) && !slot_closure($info['partial'], $t) && ($counts[$t] ?? 0) < SLOT_CAPACITY;
            $slots[] = ['time'=>$t, 'available'=>$ok];
        }
        appt_reply(200, ['success'=>true,'open'=>$open,'label'=>$info['label'],'slots'=>$slots]);
    }

    appt_reply(400, ['success'=>false,'message'=>'Unknown appointment action.']);
} catch (Throwable $e) {
    error_log('Appointments API error: ' . $e->getMessage());
    appt_reply(500, ['success'=>false,'message'=>'Unable to load or update appointments. Check the database connection and that supabase/schema.sql was run.']);
}
