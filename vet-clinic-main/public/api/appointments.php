<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
require_once __DIR__ . '/helpers.php';

function appt_reply(int $code, array $body): never { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$account = $_SESSION['account'] ?? null;
if (!is_array($account) || empty($account['id'])) appt_reply(401, ['success'=>false,'message'=>'Please sign in again.']);
if (!in_array(($account['role'] ?? ''), ['Administrator','Veterinarian','Veterinary Staff'], true)) appt_reply(403, ['success'=>false,'message'=>'Clinic staff access only.']);

try {
    $action = (string)($_GET['action'] ?? 'list');

    /* ---------- List every appointment (unchanged behaviour) ---------- */
    if ($action === 'list') {
        $q = $pdo->query("SELECT a.id,a.pet_id,a.veterinarian_id,a.appointment_date,a.appointment_time,a.purpose,a.status,a.cancelled_by,a.notes,p.name pet_name,p.owner_name,p.owner_email,p.owner_phone,p.address,u.full_name AS stored_vet FROM appointments a JOIN pets p ON p.id=a.pet_id LEFT JOIN users u ON u.id=a.veterinarian_id ORDER BY a.appointment_date DESC,a.appointment_time DESC");
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
        if (!in_array($status, ['Pending','Confirmed','In Progress'], true)) appt_reply(422, ['success'=>false,'message'=>'Choose a valid status.']);
        $err = validate_slot($pdo, $date, $time, true);
        if ($err) appt_reply(422, ['success'=>false,'message'=>$err]);

        $result = with_slot_lock($pdo, function () use ($pdo, $petId, $date, $time, $purpose, $status) {
            $p = $pdo->prepare('SELECT id FROM pets WHERE id=?'); $p->execute([$petId]);
            if (!$p->fetch()) return [404, 'That pet was not found.'];
            if (slot_is_taken($pdo, $date, $time)) return [409, 'That date and time is already reserved. Choose another slot.'];
            $aid = db_insert($pdo, 'INSERT INTO appointments (pet_id, veterinarian_id, appointment_date, appointment_time, purpose, status) VALUES (?,?,?,?,?,?)', [$petId, current_doctor($pdo)['id'] ?? null, $date, $time . ':00', $purpose, $status]); // the clinic's one doctor is assigned automatically
            notify_appointment_owner($pdo, $aid, 'approved', 'Appointment scheduled', 'The clinic scheduled an appointment for {pet} on {date} at {time}.');
            return [201, 'Appointment saved.'];
        });
        appt_reply($result[0], ['success'=>$result[0] === 201, 'message'=>$result[1]]);
    }

    /* ---------- Change status: Approve (Confirmed), Reject (Cancelled), In Progress, Completed ---------- */
    if ($action === 'status') {
        $status = (string)($input['status'] ?? '');
        if (!$id || !in_array($status, ['Confirmed','Cancelled','In Progress','Completed'], true)) appt_reply(422, ['success'=>false,'message'=>'Invalid appointment or status.']);

        $result = with_slot_lock($pdo, function () use ($pdo, $id, $status) {
            $cur = $pdo->prepare('SELECT id, status, appointment_date, appointment_time FROM appointments WHERE id=?');
            $cur->execute([$id]);
            $row = $cur->fetch();
            if (!$row) return [404, 'Appointment not found.'];
            if ($status === 'Completed') {
                $report = $pdo->prepare('SELECT 1 FROM medical_reports WHERE appointment_id=?');
                $report->execute([$id]);
                if (!$report->fetchColumn()) return [409, 'Submit the veterinarian medical report before completing this appointment.'];
            }
            if ($row['status'] === $status) return [200, 'Appointment status updated.']; // nothing changed -> no duplicate notification

            // Re-opening a rejected request must not collide with someone who took the slot meanwhile.
            if ($row['status'] === 'Cancelled' && $status !== 'Cancelled') {
                $time = substr((string)$row['appointment_time'], 0, 5);
                if (slot_is_taken($pdo, (string)$row['appointment_date'], $time, $id)) return [409, 'That date and time is already reserved by another appointment. Reschedule it instead.'];
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

        $result = with_slot_lock($pdo, function () use ($pdo, $id, $date, $time) {
            $cur = $pdo->prepare('SELECT id, status FROM appointments WHERE id=?');
            $cur->execute([$id]);
            $row = $cur->fetch();
            if (!$row) return [404, 'Appointment not found.'];
            if (in_array($row['status'], ['Completed','Cancelled'], true)) return [409, 'Completed or rejected appointments cannot be rescheduled.'];
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
