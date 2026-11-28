<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/helpers.php';

function owner_reply(int $code, array $data): never { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function owner_input(): array { $v = json_decode(file_get_contents('php://input') ?: '{}', true); return is_array($v) ? $v : []; }

const PET_GENDERS = ['Male', 'Female', 'Unknown'];

function iso_time(string $dt): string { return (new DateTime($dt, new DateTimeZone('Asia/Manila')))->format('c'); }

$account = $_SESSION['account'] ?? null;
if (!is_array($account) || empty($account['id'])) owner_reply(401, ['success'=>false,'message'=>'Please sign in again.']);
if (($account['role'] ?? '') !== 'Pet Owner') owner_reply(403, ['success'=>false,'message'=>'Pet owner access only.']);
$action = (string)($_GET['action'] ?? 'data');

try {
    $uid = (int)$account['id'];
    $email = (string)($account['email'] ?? '');
    $unread = static function () use ($pdo, $uid): int {
        $c = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0'); $c->execute([$uid]); return (int)$c->fetchColumn();
    };

    /* =========================== GET actions =========================== */
    if ($action === 'data') {
        $p = $pdo->prepare('SELECT id,name,species,breed,age,gender,notes,photo_path,created_at FROM pets WHERE LOWER(TRIM(owner_email))=LOWER(TRIM(?)) ORDER BY name');
        $p->execute([$email]); $pets = $p->fetchAll();
        $ids = array_map(static fn($x) => (int)$x['id'], $pets);
        $profile = $pdo->prepare('SELECT full_name AS name,email,phone,address,avatar_path FROM users WHERE id=?'); $profile->execute([$uid]); $prof = $profile->fetch() ?: [];
        $doctor = current_doctor($pdo);
        $base = ['success'=>true,'profile'=>$prof,'doctor'=>$doctor ? ['name'=>$doctor['name']] : null,'pets'=>$pets,'appointments'=>[],'vaccinations'=>[],'medical_reports'=>[],'unread'=>$unread(),'today'=>date('Y-m-d'),
                 'schedule'=>['open'=>CLINIC_OPEN,'close'=>CLINIC_CLOSE,'slot_minutes'=>SLOT_MINUTES,'window_days'=>BOOKING_WINDOW_DAYS]];
        if (!$ids) owner_reply(200, $base);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $a = $pdo->prepare("SELECT a.id,a.pet_id,a.appointment_date,a.appointment_time,a.purpose,a.status,a.cancelled_by,a.notes,v.full_name AS stored_vet,p.name pet_name,p.species pet_species,p.photo_path pet_photo FROM appointments a JOIN pets p ON p.id=a.pet_id LEFT JOIN users v ON v.id=a.veterinarian_id WHERE a.pet_id IN ($marks) ORDER BY a.appointment_date DESC,a.appointment_time DESC");
        $a->execute($ids);
        $v = $pdo->prepare("SELECT x.id,x.pet_id,x.vaccine,x.date_given,x.next_due,x.notes,u.full_name AS stored_vet,p.name pet_name,p.photo_path pet_photo,p.species pet_species FROM vaccinations x JOIN pets p ON p.id=x.pet_id LEFT JOIN users u ON u.id=x.veterinarian_id WHERE x.pet_id IN ($marks) ORDER BY x.date_given DESC");
        $v->execute($ids);
        $base['appointments'] = array_map(static function ($r) use ($doctor) { $r['veterinarian'] = doctor_display($doctor, $r['status'] === 'Completed', $r['stored_vet']); $r['can_cancel'] = owner_can_cancel((string)$r['status'], (string)$r['appointment_date'], (string)$r['appointment_time']); unset($r['stored_vet']); return $r; }, $a->fetchAll());
        $base['vaccinations'] = array_map(static function ($r) use ($doctor) { $r['veterinarian'] = doctor_display($doctor, true, $r['stored_vet'], 'Not recorded'); unset($r['stored_vet']); return $r; }, $v->fetchAll());
        $reports = $pdo->prepare("SELECT r.id,r.appointment_id,r.pet_id,r.diagnosis,r.symptoms,r.observations,r.treatment,r.medication,r.recommendations,r.follow_up_date,r.created_at,u.full_name AS veterinarian,p.name AS pet_name,p.photo_path AS pet_photo,p.species AS pet_species,a.appointment_date,a.appointment_time,a.purpose
            FROM medical_reports r JOIN pets p ON p.id=r.pet_id JOIN appointments a ON a.id=r.appointment_id LEFT JOIN users u ON u.id=r.veterinarian_id
            WHERE r.pet_id IN ($marks) ORDER BY r.created_at DESC");
        $reports->execute($ids);
        $base['medical_reports'] = $reports->fetchAll();
        owner_reply(200, $base);
    }

    if ($action === 'availability') {
        $today = date('Y-m-d'); $last = date('Y-m-d', strtotime('+' . BOOKING_WINDOW_DAYS . ' days'));
        $slots = clinic_slots();
        if (isset($_GET['month'])) {                                   // calendar overview for one month
            $month = (string)$_GET['month'];
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) owner_reply(422, ['success'=>false,'message'=>'Invalid month.']);
            $first = $month . '-01'; $daysInMonth = (int)date('t', strtotime($first)); $lastDay = $month . '-' . str_pad((string)$daysInMonth, 2, '0', STR_PAD_LEFT);
            $s = $pdo->prepare('SELECT appointment_date d, COUNT(*) c FROM appointments WHERE appointment_date BETWEEN ? AND ? AND ' . SLOT_BLOCKING_SQL . ' GROUP BY appointment_date');
            $s->execute([$first, $lastDay]);
            $booked = []; foreach ($s->fetchAll() as $r) $booked[(string)$r['d']] = (int)$r['c'];
            $closures = closures_map($pdo, $first, $lastDay);
            $days = [];
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $date = $month . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
                if ($date < $today)  { $days[$date] = ['state'=>'past', 'free'=>0]; continue; }
                if ($date > $last)   { $days[$date] = ['state'=>'closed', 'free'=>0, 'label'=>'Too far ahead']; continue; }
                $info = day_info($date, $closures[$date] ?? []);
                if ($info['state'] !== 'open') { $days[$date] = ['state'=>$info['state'], 'free'=>0, 'label'=>$info['label']]; continue; }
                $free = max(0, count($slots) * SLOT_CAPACITY - ($booked[$date] ?? 0));
                if ($date === $today || $info['partial']) { // some slots may already be past or blocked: count one by one
                    $counts = booked_counts($pdo, $date); $free = 0;
                    foreach ($slots as $t) if (!slot_is_past($date, $t) && !slot_closure($info['partial'], $t) && ($counts[$t] ?? 0) < SLOT_CAPACITY) $free++;
                }
                $days[$date] = ['state'=>$free > 0 ? 'available' : 'full', 'free'=>$free];
            }
            owner_reply(200, ['success'=>true,'today'=>$today,'last'=>$last,'days'=>$days,'total'=>count($slots)]);
        }
        $date = trim((string)($_GET['date'] ?? ''));                   // time slots for one day
        if (!valid_date_string($date)) owner_reply(422, ['success'=>false,'message'=>'Choose a valid date.']);
        if ($date < $today)  owner_reply(422, ['success'=>false,'message'=>'You cannot book a date in the past.']);
        $info = day_info($date, closures_map($pdo, $date, $date)[$date] ?? []);
        $tooFar = $date > $last;
        $open = !$tooFar && $info['state'] === 'open';
        $counts = booked_counts($pdo, $date);
        $out = [];
        foreach ($slots as $t) {
            $reason = null;
            if ($tooFar) $reason = 'closed';
            elseif ($info['state'] !== 'open') $reason = $info['state'];
            elseif (slot_closure($info['partial'], $t)) $reason = 'unavailable';
            elseif (slot_is_past($date, $t)) $reason = 'past';
            elseif (($counts[$t] ?? 0) >= SLOT_CAPACITY) $reason = 'booked';
            $out[] = ['time'=>$t, 'available'=>$reason === null, 'reason'=>$reason];
        }
        owner_reply(200, ['success'=>true,'date'=>$date,'open'=>$open,'state'=>$tooFar ? 'closed' : $info['state'],'label'=>$tooFar ? 'Too far ahead' : $info['label'],'slots'=>$out]);
    }

    if ($action === 'adoption_list') {
        $q = $pdo->prepare("SELECT a.id AS adoption_id,a.pet_name,a.species,a.breed,a.age,a.gender,a.health_status,a.reason,a.photo_path,a.status AS pet_status,
            x.id AS application_id,x.status AS application_status,x.message,x.created_at AS applied_at,x.staff_notes
            FROM adoption_applications x JOIN adoption_records a ON a.id=x.adoption_id
            WHERE x.user_id=? ORDER BY x.created_at DESC,x.id DESC");
        $q->execute([$uid]);
        $availableStmt = $pdo->prepare("SELECT a.id,a.pet_name,a.species,a.breed,a.age,a.gender,a.health_status,a.reason,a.photo_path FROM adoption_records a WHERE a.status IN ('Available','Pending') AND NOT EXISTS (SELECT 1 FROM adoption_applications x WHERE x.adoption_id=a.id AND x.user_id=? AND x.status='Pending') ORDER BY a.created_at DESC,a.id DESC");
        $availableStmt->execute([$uid]);
        $available = $availableStmt->fetchAll();
        owner_reply(200, ['success'=>true,'applications'=>$q->fetchAll(),'available'=>$available]);
    }

    if ($action === 'notifications') {
        $n = $pdo->prepare('SELECT id,appointment_id,type,title,message,is_read,created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC, id DESC LIMIT 50');
        $n->execute([$uid]);
        $rows = array_map(static function ($r) { $r['id'] = (int)$r['id']; $r['is_read'] = (int)$r['is_read']; $r['created_at'] = iso_time((string)$r['created_at']); return $r; }, $n->fetchAll());
        owner_reply(200, ['success'=>true,'notifications'=>$rows,'unread'=>$unread()]);
    }

    /* =========================== POST actions =========================== */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') owner_reply(405, ['success'=>false,'message'=>'Use POST for this action.']);
    $isMultipart = stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false;
    $data = $isMultipart ? $_POST : owner_input();
    if ($isMultipart && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) owner_reply(413, ['success'=>false,'message'=>'The upload is too large. Maximum picture size is 2 MB.']);

    /* ---- Submit an adoption application ---- */
    if ($action === 'adoption_apply') {
        $adoptionId = (int)($data['adoption_id'] ?? 0);
        $phone = trim((string)($data['phone'] ?? ''));
        $address = trim((string)($data['address'] ?? ''));
        $message = trim((string)($data['message'] ?? ''));
        $name = trim((string)($data['name'] ?? $account['name'] ?? $account['full_name'] ?? ''));
        if ($adoptionId < 1 || $name === '' || $email === '' || $phone === '' || $address === '') owner_reply(422, ['success'=>false,'message'=>'Complete your name, email, phone number and address before applying.']);
        if (mb_strlen($name) > 120 || mb_strlen($phone) > 40 || mb_strlen($address) > 255 || mb_strlen($message) > 2000) owner_reply(422, ['success'=>false,'message'=>'One or more application fields are too long.']);
        $pdo->beginTransaction();
        try {
            $pet = $pdo->prepare("SELECT pet_name FROM adoption_records WHERE id=? AND status IN ('Available','Pending') FOR UPDATE");
            $pet->execute([$adoptionId]); $listed = $pet->fetch();
            if (!$listed) { $pdo->rollBack(); owner_reply(409, ['success'=>false,'message'=>'This pet is no longer available for adoption.']); }
            $exists = $pdo->prepare("SELECT id FROM adoption_applications WHERE adoption_id=? AND user_id=? AND status='Pending'");
            $exists->execute([$adoptionId,$uid]);
            if ($exists->fetch()) { $pdo->rollBack(); owner_reply(409, ['success'=>false,'message'=>'You already have a pending application for this pet.']); }
            $appId = db_insert($pdo, "INSERT INTO adoption_applications (adoption_id,user_id,applicant_name,email,phone,address,message,status) VALUES (?,?,?,?,?,?,?,'Pending')",
                [$adoptionId,$uid,$name,$email,$phone,$address,$message !== '' ? $message : null]);
            $pdo->prepare("UPDATE adoption_records SET status='Pending' WHERE id=?")->execute([$adoptionId]);
            $pdo->prepare("INSERT INTO notifications (user_id,appointment_id,type,title,message,is_read,created_at) VALUES (?,NULL,'adoption','Application submitted',?,0,?)")
                ->execute([$uid,'Your adoption application for '.$listed['pet_name'].' was sent to the clinic for review.',date('Y-m-d H:i:s')]);
            $staff = $pdo->query("SELECT id FROM users WHERE status='Active' AND role IN ('Administrator','Veterinarian','Veterinary Staff')")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($staff as $staffId) {
                $pdo->prepare("INSERT INTO notifications (user_id,appointment_id,type,title,message,is_read,created_at) VALUES (?,NULL,'adoption_application','New adoption application',?,0,?)")
                    ->execute([(int)$staffId,$name.' submitted an application for '.$listed['pet_name'].'. Review it in Adoption.',date('Y-m-d H:i:s')]);
            }
            $pdo->commit();
            owner_reply(201, ['success'=>true,'message'=>'Your adoption application has been submitted. The clinic will contact you after reviewing it.','application_id'=>$appId]);
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /* ---- Withdraw a pending adoption application ---- */
    if ($action === 'adoption_withdraw') {
        $appId = (int)($data['id'] ?? 0);
        $pdo->beginTransaction();
        try {
            // Lock the listing first, matching application submission and staff review.
            $lookup = $pdo->prepare("SELECT adoption_id FROM adoption_applications WHERE id=? AND user_id=?");
            $lookup->execute([$appId,$uid]); $adoptionId = $lookup->fetchColumn();
            if (!$adoptionId) { $pdo->rollBack(); owner_reply(404, ['success'=>false,'message'=>'Pending application not found.']); }
            $listingLock = $pdo->prepare('SELECT id,pet_name FROM adoption_records WHERE id=? FOR UPDATE');
            $listingLock->execute([(int)$adoptionId]); $listing = $listingLock->fetch();
            $q = $pdo->prepare("SELECT id,adoption_id FROM adoption_applications WHERE id=? AND user_id=? AND adoption_id=? AND status='Pending' FOR UPDATE");
            $q->execute([$appId,$uid,(int)$adoptionId]); $app = $q->fetch();
            if (!$listing || !$app) { $pdo->rollBack(); owner_reply(404, ['success'=>false,'message'=>'Pending application not found.']); }
            $app['pet_name'] = (string)$listing['pet_name'];
            $pdo->prepare("UPDATE adoption_applications SET status='Withdrawn' WHERE id=?")->execute([$appId]);
            $remaining = $pdo->prepare("SELECT COUNT(*) FROM adoption_applications WHERE adoption_id=? AND status='Pending'"); $remaining->execute([(int)$app['adoption_id']]);
            if ((int)$remaining->fetchColumn() === 0) $pdo->prepare("UPDATE adoption_records SET status='Available' WHERE id=? AND status='Pending'")->execute([(int)$app['adoption_id']]);
            $pdo->prepare("INSERT INTO notifications (user_id,appointment_id,type,title,message,is_read,created_at) VALUES (?,NULL,'adoption','Application withdrawn',?,0,?)")
                ->execute([$uid,'Your application for '.$app['pet_name'].' was withdrawn.',date('Y-m-d H:i:s')]);
            // Let the clinic know when an owner withdraws so staff can review the remaining applications.
            $staff = $pdo->query("SELECT id FROM users WHERE status='Active' AND role IN ('Administrator','Veterinarian','Veterinary Staff')")->fetchAll(PDO::FETCH_COLUMN);
            $staffMessage = $app['pet_name'].' has an adoption application withdrawn. Check the remaining applications in Adoption.';
            foreach ($staff as $staffId) {
                $pdo->prepare("INSERT INTO notifications (user_id,appointment_id,type,title,message,is_read,created_at) VALUES (?,NULL,'adoption_withdrawn','Adoption application withdrawn',?,0,?)")
                    ->execute([(int)$staffId,$staffMessage,date('Y-m-d H:i:s')]);
            }
            $pdo->commit();
            owner_reply(200, ['success'=>true,'message'=>'Your adoption application was withdrawn.']);
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    /* ---- Mark notifications as read ---- */
    if ($action === 'mark_read') {
        if (!empty($data['all'])) $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0')->execute([$uid]);
        else { $nid = (int)($data['id'] ?? 0); if (!$nid) owner_reply(422, ['success'=>false,'message'=>'Invalid notification.']); $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([$nid, $uid]); }
        owner_reply(200, ['success'=>true,'unread'=>$unread()]);
    }

    /* ---- Request an appointment ---- */
    if ($action === 'request_appointment') {
        $petValue = trim((string)($data['pet_id'] ?? '')); $petId = ctype_digit($petValue) ? (int)$petValue : 0;
        $date = trim((string)($data['appointment_date'] ?? '')); $time = substr(trim((string)($data['appointment_time'] ?? '')), 0, 5);
        $purpose = trim((string)($data['purpose'] ?? '')); $notes = trim((string)($data['notes'] ?? ''));
        if (!$date || !$time || $purpose === '') owner_reply(422, ['success'=>false,'message'=>'Choose a pet, date, time, and visit reason.']);
        $slotErr = validate_slot($pdo, $date, $time);
        if ($slotErr) owner_reply(422, ['success'=>false,'message'=>$slotErr]);
        if (mb_strlen($purpose) > 255 || mb_strlen($notes) > 5000) owner_reply(422, ['success'=>false,'message'=>'Please shorten the visit reason or notes.']);

        $ownerName = trim((string)($account['name'] ?? $account['full_name'] ?? $account['username'] ?? 'Pet Owner'));
        if ($petId > 0) {
            $own = $pdo->prepare('SELECT id FROM pets WHERE id=? AND LOWER(TRIM(owner_email))=LOWER(TRIM(?))'); $own->execute([$petId, $email]);
            if (!$own->fetch()) owner_reply(403, ['success'=>false,'message'=>'That pet is not linked to your account. Select a pet registered to your email or register a new pet.']);
        } else {
            $petName = trim((string)($data['new_pet_name'] ?? ''));
            if ($petName === '') owner_reply(422, ['success'=>false,'message'=>'Enter the name of your pet.']);
            if (mb_strlen($petName) > 120) owner_reply(422, ['success'=>false,'message'=>'Pet name is too long.']);
        }

        $result = with_slot_lock($pdo, function () use ($pdo, $data, $date, $time, $purpose, $notes, $email, $ownerName, $uid, $petId) {
            $slotError = validate_slot($pdo, $date, $time);
            if ($slotError) return [422, $slotError, null];
            $doctor = current_doctor($pdo, true);
            if (!$doctor) return [409, 'The clinic has no active veterinarian assigned yet. Please contact the clinic before booking.', null];
            $docId = (int)$doctor['id'];
            if (slot_is_taken($pdo, $date, $time)) return [409, 'Sorry, that time slot was just reserved. Please choose another time.', null];
            $pid = $petId;
            if ($pid <= 0) {
                $prof = $pdo->prepare('SELECT phone,address FROM users WHERE id=?'); $prof->execute([$uid]); $pr = $prof->fetch() ?: [];
                $species = mb_substr(trim((string)($data['new_pet_species'] ?? '')), 0, 60); if ($species === '') $species = 'Other';
                $breed = trim((string)($data['new_pet_breed'] ?? '')); $age = trim((string)($data['new_pet_age'] ?? ''));
                $pid = db_insert($pdo, 'INSERT INTO pets (name,species,breed,owner_name,owner_email,owner_phone,address,age,created_by) VALUES (?,?,?,?,?,?,?,?,?)', [trim((string)$data['new_pet_name']), $species, $breed !== '' ? mb_substr($breed, 0, 120) : null, $ownerName, $email, (string)($pr['phone'] ?? ''), (string)($pr['address'] ?? ''), $age !== '' ? mb_substr($age, 0, 40) : null, $uid]);
            } else {
                $dup = $pdo->prepare("SELECT id FROM appointments WHERE pet_id=? AND appointment_date=? AND status IN ('Pending','Confirmed','In Progress') LIMIT 1");
                $dup->execute([$pid, $date]);
                if ($dup->fetch()) { return [409, 'This pet already has an appointment on that date. Choose another date.', null]; }
            }
            $aid = db_insert($pdo, "INSERT INTO appointments (pet_id, veterinarian_id, appointment_date, appointment_time, purpose, status, notes) VALUES (?,?,?,?,?, 'Pending', ?)", [$pid, $docId, $date, $time . ':00', $purpose, $notes !== '' ? $notes : null]);
            create_notification($pdo, $uid, $aid, 'request', 'Request received', 'Your appointment request for ' . human_date($date) . ' at ' . human_time($time) . ' was sent. It stays Pending until the clinic approves it.');
            notify_clinic_staff($pdo, $aid, 'owner_request', 'New appointment request', '{owner} requested an appointment for {pet} on {date} at {time}. Review it in Appointments.');
            return [201, 'Appointment request sent successfully. It is Pending until clinic staff approve it.', $aid];
        });
        owner_reply($result[0], ['success'=>$result[0] === 201,'message'=>$result[1]]);
    }

    /* ---- Cancel one of my own appointments (frees the time slot) ---- */
    if ($action === 'cancel_appointment') {
        $aid = (int)($data['id'] ?? 0);
        $result = with_slot_lock($pdo, function () use ($pdo, $aid, $email, $uid) {
            $s = $pdo->prepare('SELECT a.id, a.status, a.appointment_date, a.appointment_time, p.name AS pet_name FROM appointments a JOIN pets p ON p.id=a.pet_id WHERE a.id=? AND LOWER(TRIM(p.owner_email))=LOWER(TRIM(?)) FOR UPDATE OF a');
            $s->execute([$aid, $email]); $r = $s->fetch();
            if (!$r) return [403, 'That appointment is not linked to your account.'];
            if (!owner_can_cancel((string)$r['status'], (string)$r['appointment_date'], (string)$r['appointment_time'])) {
                return [409, $r['status'] === 'Confirmed'
                    ? 'This appointment is less than ' . OWNER_CANCEL_HOURS . ' hours away, so it can no longer be cancelled online. Please contact the clinic.'
                    : 'This appointment can no longer be cancelled online.'];
            }
            $pdo->prepare("UPDATE appointments SET status='Cancelled', cancelled_by='Owner' WHERE id=?")->execute([$aid]);
            create_notification($pdo, $uid, $aid, 'cancelled', 'Appointment cancelled', 'You cancelled the appointment for ' . $r['pet_name'] . ' on ' . human_date((string)$r['appointment_date']) . ' at ' . human_time((string)$r['appointment_time']) . '. The time is available to others again.');
            notify_clinic_staff($pdo, $aid, 'owner_cancelled', 'Owner cancelled an appointment', '{owner} cancelled the appointment for {pet} on {date} at {time}. The time slot is available again.');
            return [200, 'Appointment cancelled. The time slot is available again.'];
        });
        owner_reply($result[0], ['success'=>$result[0] === 200, 'message'=>$result[1]]);
    }

    /* ---- Add / edit a pet (multipart form with optional picture) ---- */
    if ($action === 'pet_save') {
        $petId = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? '')); $species = trim((string)($data['species'] ?? '')); $breed = trim((string)($data['breed'] ?? ''));
        $age = trim((string)($data['age'] ?? '')); $gender = (string)($data['gender'] ?? 'Unknown'); $notes = trim((string)($data['notes'] ?? ''));
        if ($name === '') owner_reply(422, ['success'=>false,'message'=>'Enter your pet\'s name.']);
        if (mb_strlen($name) > 120 || mb_strlen($breed) > 120 || mb_strlen($age) > 40 || mb_strlen($notes) > 2000) owner_reply(422, ['success'=>false,'message'=>'One of the fields is too long.']);
        if ($species === '') owner_reply(422, ['success'=>false,'message'=>'Enter what type of animal your pet is (for example Dog, Cat or Hamster).']);
        if (mb_strlen($species) > 60) owner_reply(422, ['success'=>false,'message'=>'The type of animal is too long (max 60 characters).']);
        if (!in_array($gender, PET_GENDERS, true)) owner_reply(422, ['success'=>false,'message'=>'Choose a valid gender.']);

        $dup = $pdo->prepare('SELECT id FROM pets WHERE LOWER(TRIM(owner_email))=LOWER(TRIM(?)) AND LOWER(name)=LOWER(?) AND id<>? LIMIT 1'); $dup->execute([$email, $name, $petId]);
        if ($dup->fetch()) owner_reply(409, ['success'=>false,'message'=>'You already have a pet with that name.']);

        $existing = null;
        if ($petId > 0) {
            $ex = $pdo->prepare('SELECT id, photo_path FROM pets WHERE id=? AND LOWER(TRIM(owner_email))=LOWER(TRIM(?))'); $ex->execute([$petId, $email]); $existing = $ex->fetch();
            if (!$existing) owner_reply(403, ['success'=>false,'message'=>'That pet is not linked to your account.']);
        }

        $newPhoto = null;
        if (!empty($_FILES['photo']) && (int)$_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            try { $newPhoto = save_pet_photo($_FILES['photo']); }
            catch (RuntimeException $e) { owner_reply(422, ['success'=>false,'message'=>$e->getMessage()]); }
        }
        try {
            if ($existing) {
                $photo = $existing['photo_path'];
                if ($newPhoto) $photo = $newPhoto; elseif (!empty($data['remove_photo'])) $photo = null;
                $pdo->prepare('UPDATE pets SET name=?,species=?,breed=?,age=?,gender=?,notes=?,photo_path=? WHERE id=?')
                    ->execute([$name, $species, $breed !== '' ? $breed : null, $age !== '' ? $age : null, $gender, $notes !== '' ? $notes : null, $photo, $petId]);
                if ($photo !== $existing['photo_path']) delete_pet_photo($existing['photo_path']);
                owner_reply(200, ['success'=>true,'message'=>'Pet profile updated.','id'=>$petId]);
            }
            $prof = $pdo->prepare('SELECT full_name,phone,address FROM users WHERE id=?'); $prof->execute([$uid]); $pr = $prof->fetch() ?: [];
            $newId = db_insert($pdo, 'INSERT INTO pets (name,species,breed,owner_name,owner_email,owner_phone,address,age,gender,notes,photo_path,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [$name, $species, $breed !== '' ? $breed : null, (string)($pr['full_name'] ?? $account['name'] ?? 'Pet Owner'), $email, (string)($pr['phone'] ?? ''), (string)($pr['address'] ?? ''), $age !== '' ? $age : null, $gender, $notes !== '' ? $notes : null, $newPhoto, $uid]);
            owner_reply(201, ['success'=>true,'message'=>'Pet added to your profile.','id'=>$newId]);
        } catch (Throwable $e) {
            if ($newPhoto) delete_pet_photo($newPhoto); // do not leave an orphan file if the database write failed
            throw $e;
        }
    }

    /* ---- Delete a pet ---- */
    if ($action === 'pet_delete') {
        $petId = (int)($data['id'] ?? 0);
        $ex = $pdo->prepare('SELECT id, photo_path FROM pets WHERE id=? AND LOWER(TRIM(owner_email))=LOWER(TRIM(?))'); $ex->execute([$petId, $email]); $pet = $ex->fetch();
        if (!$pet) owner_reply(403, ['success'=>false,'message'=>'That pet is not linked to your account.']);
        // Clinic medical history must not disappear because an owner removed a profile.
        // Preserve every appointment row: the live database may use RESTRICT even for pending or cancelled appointments.
        $hist = $pdo->prepare("SELECT (SELECT COUNT(*) FROM medical_reports WHERE pet_id=?) + (SELECT COUNT(*) FROM vaccinations WHERE pet_id=?) + (SELECT COUNT(*) FROM appointments WHERE pet_id=?) AS c");
        $hist->execute([$petId, $petId, $petId]);
        if ((int)$hist->fetchColumn() > 0) owner_reply(409, ['success'=>false,'message'=>'This pet has appointment or clinic medical history, so it cannot be deleted online. Please contact the clinic to archive it.']);
        $pdo->prepare('DELETE FROM pets WHERE id=?')->execute([$petId]);
        delete_pet_photo($pet['photo_path']);
        owner_reply(200, ['success'=>true,'message'=>'Pet deleted.']);
    }

    /* ---- Change the signed-in pet owner's password ---- */
    if ($action === 'password') {
        $currentPassword = (string)($data['current_password'] ?? '');
        $newPassword = (string)($data['new_password'] ?? '');
        $confirmPassword = (string)($data['confirm_password'] ?? '');
        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') owner_reply(422, ['success'=>false,'message'=>'Complete all password fields.']);
        if (strlen($newPassword) < 8) owner_reply(422, ['success'=>false,'message'=>'Your new password must be at least 8 characters.']);
        if (!hash_equals($newPassword, $confirmPassword)) owner_reply(422, ['success'=>false,'message'=>'The new passwords do not match.']);
        if (hash_equals($currentPassword, $newPassword)) owner_reply(422, ['success'=>false,'message'=>'Choose a password different from your current one.']);
        $check = $pdo->prepare('SELECT password_hash FROM users WHERE id=?');
        $check->execute([$uid]);
        $hash = $check->fetchColumn();
        if (!$hash || !password_verify($currentPassword, (string)$hash)) owner_reply(403, ['success'=>false,'message'=>'Your current password is incorrect.']);
        $updated = password_hash($newPassword, PASSWORD_DEFAULT);
        $versionUpdate = $pdo->prepare('UPDATE users SET password_hash=?, auth_version=auth_version+1 WHERE id=? RETURNING auth_version');
        $versionUpdate->execute([$updated, $uid]);
        // Keep this browser session valid while the increment revokes other sessions.
        $_SESSION['account']['auth_version'] = (int)$versionUpdate->fetchColumn();
        owner_reply(200, ['success'=>true,'message'=>'Your password has been updated.']);
    }

    /* ---- Update personal information ---- */
    if ($action === 'profile') {
        $name = trim((string)($data['name'] ?? '')); $newEmail = trim((string)($data['email'] ?? '')); $phone = trim((string)($data['phone'] ?? '')); $address = trim((string)($data['address'] ?? ''));
        if ($name === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) owner_reply(422, ['success'=>false,'message'=>'Enter a valid name and email.']);
        if (mb_strlen($name) > 120 || mb_strlen($newEmail) > 190 || mb_strlen($phone) > 40 || mb_strlen($address) > 255) owner_reply(422, ['success'=>false,'message'=>'One of the fields is too long.']);
        $check = $pdo->prepare('SELECT id FROM users WHERE LOWER(email)=LOWER(?) AND id<>?'); $check->execute([$newEmail, $uid]);
        if ($check->fetch()) owner_reply(409, ['success'=>false,'message'=>'That email is already used by another account.']);
        $current = $pdo->prepare('SELECT avatar_path FROM users WHERE id=?'); $current->execute([$uid]); $oldAvatar = $current->fetchColumn() ?: null;
        $newAvatar = $oldAvatar; $newUpload = null;
        if (!empty($_FILES['photo']) && (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) { $newUpload = save_pet_photo($_FILES['photo'], 'user_'); $newAvatar = $newUpload; }
        if (!empty($data['remove_photo']) && $data['remove_photo'] === '1') { $newAvatar = null; if ($newUpload) delete_pet_photo($newUpload); $newUpload = null; }
        $pdo->beginTransaction();
        try {
            $versionUpdate = $pdo->prepare('UPDATE users SET full_name=?,email=?,phone=?,address=?,avatar_path=?,auth_version=auth_version+1 WHERE id=? RETURNING auth_version');
            $versionUpdate->execute([$name, $newEmail, $phone, $address, $newAvatar, $uid]);
            $updatedAuthVersion = (int)$versionUpdate->fetchColumn();
            // Pets are linked by email, so keep them linked when the owner changes it.
            $pdo->prepare('UPDATE pets SET owner_email=?,owner_name=?,owner_phone=?,address=? WHERE LOWER(TRIM(owner_email))=LOWER(TRIM(?))')->execute([$newEmail, $name, $phone, $address, $email]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($newUpload) delete_pet_photo($newUpload);
            throw $e;
        }
        $_SESSION['account']['name'] = $name; $_SESSION['account']['full_name'] = $name; $_SESSION['account']['email'] = $newEmail;
        $_SESSION['account']['avatar_path'] = $newAvatar;
        // Refresh this session after revoking other sessions on profile changes.
        $_SESSION['account']['auth_version'] = $updatedAuthVersion;
        if ($newAvatar !== $oldAvatar) delete_pet_photo($oldAvatar);
        owner_reply(200, ['success'=>true,'user'=>['name'=>$name,'email'=>$newEmail,'phone'=>$phone,'address'=>$address,'avatar_path'=>$newAvatar,'username'=>(string)($account['username'] ?? '')]]);
    }

    owner_reply(400, ['success'=>false,'message'=>'Unknown owner action.']);
} catch (Throwable $e) {
    error_log('Owner API error: ' . $e->getMessage());
    owner_reply(500, ['success'=>false,'message'=>'Server error while processing your request. Check the database connection and that supabase/schema.sql was run.']);
}
