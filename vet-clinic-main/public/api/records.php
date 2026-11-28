<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
require_once __DIR__ . '/helpers.php';

/**
 * Clinic records for staff: pets, vaccinations, adoption, plus small lookup data.
 *   GET  records.php?resource=meta|pets|vaccinations|adoption            -> list
 *   POST records.php?resource=pets|vaccinations|adoption&action=save     -> create (no id) or update (id)
 *   POST records.php?resource=pets|vaccinations|adoption&action=delete   -> delete {id}
 *   POST records.php?resource=adoption&action=status                     -> {id, status, adopter_name?}
 *   GET/POST records.php?resource=settings                               -> clinic information (save: administrator only)
 */
function rec_reply(int $code, array $body): never { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function rec_fail(string $m, int $code = 422): never { rec_reply($code, ['success'=>false,'message'=>$m]); }
function rec_text(array $in, string $key, int $max, bool $required = false, string $label = ''): ?string {
    $v = trim((string)($in[$key] ?? ''));
    if ($v === '') { if ($required) rec_fail(($label ?: $key) . ' is required.'); return null; }
    if (mb_strlen($v) > $max) rec_fail(($label ?: $key) . " is too long (max $max characters).");
    return $v;
}

$account = $_SESSION['account'] ?? null;
if (!is_array($account) || empty($account['id'])) rec_fail('Please sign in again.', 401);
if (!in_array(($account['role'] ?? ''), ['Administrator', 'Veterinarian', 'Veterinary Staff'], true)) rec_fail('Clinic staff access only.', 403);
$uid = (int)$account['id'];
$resource = (string)($_GET['resource'] ?? ''); $action = (string)($_GET['action'] ?? 'list');

try {

    /* ============================ LISTS ============================ */
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($resource === 'meta') {
            rec_reply(200, ['success'=>true, 'doctor'=>current_doctor($pdo), 'slots'=>clinic_slots()]);
        }
        if ($resource === 'pets') {
            $q = $pdo->query('SELECT id,name,species,breed,owner_name,owner_email,owner_phone,address,age,gender,notes,photo_path,created_at FROM pets ORDER BY created_at DESC, id DESC');
            rec_reply(200, ['success'=>true, 'pets'=>$q->fetchAll()]);
        }
        if ($resource === 'vaccinations') {
            $q = $pdo->query("SELECT v.id,v.pet_id,p.name AS pet_name,v.vaccine,v.date_given,v.next_due,v.veterinarian_id,u.full_name AS stored_vet,v.notes FROM vaccinations v JOIN pets p ON p.id=v.pet_id LEFT JOIN users u ON u.id=v.veterinarian_id ORDER BY v.date_given DESC, v.id DESC");
            $doctor = current_doctor($pdo);
            $rows = array_map(static function ($r) use ($doctor) { $r['vet_name'] = doctor_display($doctor, true, $r['stored_vet'], ''); unset($r['stored_vet']); return $r; }, $q->fetchAll());
            rec_reply(200, ['success'=>true, 'vaccinations'=>$rows]);
        }
        if ($resource === 'adoption') {
            $q = $pdo->query('SELECT id,pet_name,species,breed,age,gender,health_status,status,reason,adopter_name,adopted_at,photo_path FROM adoption_records ORDER BY created_at DESC, id DESC');
            rec_reply(200, ['success'=>true, 'adoption'=>$q->fetchAll()]);
        }
        if ($resource === 'settings') {
            $q = $pdo->query('SELECT clinic_name AS name, email, phone, address FROM clinic_settings WHERE id = 1');
            $row = $q->fetch() ?: ['name'=>'', 'email'=>'', 'phone'=>'', 'address'=>''];
            rec_reply(200, ['success'=>true, 'settings'=>array_map(static fn($v) => (string)($v ?? ''), $row)]);
        }
        rec_fail('Unknown resource.', 400);
    }

    $isMultipart = stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false;
    if ($isMultipart) {
        if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) rec_fail('The upload is too large. Maximum picture size is 2 MB.', 413);
        $in = $_POST;
    } else { $in = json_decode(file_get_contents('php://input') ?: '{}', true); if (!is_array($in)) $in = []; }
    $id = (int)($in['id'] ?? 0);

    /* ============================ PETS ============================ */
    if ($resource === 'pets') {
        if ($action === 'save') {
            $name = rec_text($in, 'name', 120, true, 'Pet name'); $species = rec_text($in, 'species', 60, true, 'Type of animal');
            $breed = rec_text($in, 'breed', 120); $owner = rec_text($in, 'owner', 120, true, 'Owner name');
            $email = rec_text($in, 'ownerEmail', 190); $phone = rec_text($in, 'phone', 40); $address = rec_text($in, 'address', 255);
            $age = rec_text($in, 'age', 40); $notes = rec_text($in, 'notes', 2000); $gender = (string)($in['gender'] ?? 'Unknown');
            if (!in_array($gender, ['Male', 'Female', 'Unknown'], true)) rec_fail('Choose a valid gender.');
            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) rec_fail('Enter a valid owner email address.');
            $d = $pdo->prepare("SELECT id FROM pets WHERE LOWER(name)=LOWER(?) AND LOWER(owner_name)=LOWER(?) AND LOWER(COALESCE(owner_email,''))=LOWER(?) AND id<>? LIMIT 1");
            $d->execute([$name, $owner, (string)$email, $id]);
            if ($d->fetch()) rec_fail('This owner already has a pet with that name.', 409);
            $existing = null;
            if ($id) {
                $e = $pdo->prepare('SELECT id, photo_path FROM pets WHERE id=?'); $e->execute([$id]); $existing = $e->fetch();
                if (!$existing) rec_fail('Pet not found.', 404);
            }
            $newPhoto = null;
            if (!empty($_FILES['photo']) && (int)$_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                try { $newPhoto = save_pet_photo($_FILES['photo']); }
                catch (RuntimeException $ex) { rec_fail($ex->getMessage()); }
            }
            try {
                if ($existing) {
                    $photo = $existing['photo_path'];
                    if ($newPhoto) $photo = $newPhoto; elseif (!empty($in['remove_photo'])) $photo = null;
                    $pdo->prepare('UPDATE pets SET name=?,species=?,breed=?,owner_name=?,owner_email=?,owner_phone=?,address=?,age=?,gender=?,notes=?,photo_path=? WHERE id=?')
                        ->execute([$name, $species, $breed, $owner, $email, $phone, $address, $age, $gender, $notes, $photo, $id]);
                    if ($photo !== $existing['photo_path']) delete_pet_photo($existing['photo_path']);
                    rec_reply(200, ['success'=>true, 'message'=>'Changes saved.', 'id'=>$id]);
                }
                $newId = db_insert($pdo, 'INSERT INTO pets (name,species,breed,owner_name,owner_email,owner_phone,address,age,gender,notes,photo_path,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [$name, $species, $breed, $owner, $email, $phone, $address, $age, $gender, $notes, $newPhoto, $uid]);
                rec_reply(201, ['success'=>true, 'message'=>'Record added.', 'id'=>$newId]);
            } catch (Throwable $ex) {
                if ($newPhoto) delete_pet_photo($newPhoto);
                throw $ex;
            }
        }
        if ($action === 'delete') {
            $e = $pdo->prepare('SELECT photo_path FROM pets WHERE id=?'); $e->execute([$id]); $pet = $e->fetch();
            if (!$pet) rec_fail('Pet not found.', 404);
            $history = $pdo->prepare("SELECT (SELECT COUNT(*) FROM medical_reports WHERE pet_id=?) + (SELECT COUNT(*) FROM vaccinations WHERE pet_id=?) + (SELECT COUNT(*) FROM appointments WHERE pet_id=? AND status IN ('Confirmed','In Progress','Completed'))");
            $history->execute([$id, $id, $id]);
            if ((int)$history->fetchColumn() > 0) rec_fail('This pet has clinic medical history or an approved appointment, so it cannot be deleted. Keep the record to preserve its history.', 409);
            $pdo->prepare('DELETE FROM pets WHERE id=?')->execute([$id]);
            delete_pet_photo($pet['photo_path']);
            rec_reply(200, ['success'=>true, 'message'=>'Record deleted.']);
        }
    }

    /* ======================== VACCINATIONS ======================== */
    if ($resource === 'vaccinations') {
        if ($action === 'save') {
            $petId = (int)($in['petId'] ?? 0); $vaccine = rec_text($in, 'vaccine', 120, true, 'Vaccine');
            $given = trim((string)($in['given'] ?? '')); $next = trim((string)($in['next'] ?? '')); $docId = current_doctor($pdo)['id'] ?? null; // the clinic's one doctor
            if (!valid_date_string($given)) rec_fail('Enter a valid date given.');
            if ($given > date('Y-m-d')) rec_fail('Date given cannot be in the future.');
            if (!valid_date_string($next)) rec_fail('Enter a valid next due date.');
            if ($next <= $given) rec_fail('Next due date must be after the date given.');
            $p = $pdo->prepare('SELECT id FROM pets WHERE id=?'); $p->execute([$petId]);
            if (!$p->fetch()) rec_fail('Choose a registered pet.');
            if ($id) {
                $e = $pdo->prepare('SELECT id FROM vaccinations WHERE id=?'); $e->execute([$id]);
                if (!$e->fetch()) rec_fail('Vaccination record not found.', 404);
                $pdo->prepare('UPDATE vaccinations SET pet_id=?,vaccine=?,date_given=?,next_due=?,veterinarian_id=COALESCE(veterinarian_id, ?) WHERE id=?')->execute([$petId, $vaccine, $given, $next, $docId, $id]);
                rec_reply(200, ['success'=>true, 'message'=>'Changes saved.', 'id'=>$id]);
            }
            $newId = db_insert($pdo, 'INSERT INTO vaccinations (pet_id,vaccine,date_given,next_due,veterinarian_id) VALUES (?,?,?,?,?)', [$petId, $vaccine, $given, $next, $docId]);
            rec_reply(201, ['success'=>true, 'message'=>'Record added.', 'id'=>$newId]);
        }
        if ($action === 'delete') {
            $d = $pdo->prepare('DELETE FROM vaccinations WHERE id=?'); $d->execute([$id]);
            if (!$d->rowCount()) rec_fail('Vaccination record not found.', 404);
            rec_reply(200, ['success'=>true, 'message'=>'Record deleted.']);
        }
    }

    /* ========================== ADOPTION ========================== */
    if ($resource === 'adoption') {
        if ($action === 'save') {
            $name = rec_text($in, 'name', 120, true, 'Name'); $species = rec_text($in, 'species', 40, true, 'Type of animal');
            $breed = rec_text($in, 'breed', 120); $age = rec_text($in, 'age', 40); $health = rec_text($in, 'health', 80); $reason = rec_text($in, 'reason', 2000);
            $gender = (string)($in['gender'] ?? 'Unknown');
            if (!in_array($gender, ['Male', 'Female', 'Unknown'], true)) rec_fail('Choose a valid gender.');
            $existing = null;
            if ($id) {
                $e = $pdo->prepare('SELECT status,adopted_at,photo_path FROM adoption_records WHERE id=?'); $e->execute([$id]); $existing = $e->fetch();
                if (!$existing) rec_fail('Adoption record not found.', 404);
            }
            // Existing listing status is controlled by owner application review, not the edit form.
            $status = $existing ? (string)$existing['status'] : 'Available';
            $adoptedAt = $status === 'Adopted' ? (string)($existing['adopted_at'] ?? date('Y-m-d')) : null;
            $newPhoto = null;
            if (!empty($_FILES['photo']) && (int)$_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                try { $newPhoto = save_pet_photo($_FILES['photo'], 'adoption_'); }
                catch (RuntimeException $ex) { rec_fail($ex->getMessage()); }
            }
            try {
                if ($existing) {
                    $photo = $existing['photo_path'];
                    if ($newPhoto) $photo = $newPhoto; elseif (!empty($in['remove_photo'])) $photo = null;
                    $pdo->prepare("UPDATE adoption_records SET pet_name=?,species=?,breed=?,age=?,gender=?,health_status=?,status=?,reason=?,adopter_name=CASE WHEN ?='Adopted' THEN adopter_name ELSE NULL END,adopted_at=?,photo_path=? WHERE id=?")
                        ->execute([$name, $species, $breed, $age, $gender, $health ?? 'Unknown', $status, $reason, $status, $adoptedAt, $photo, $id]);
                    if ($photo !== $existing['photo_path']) delete_pet_photo($existing['photo_path']);
                    rec_reply(200, ['success'=>true, 'message'=>'Changes saved.', 'id'=>$id]);
                }
                $newId = db_insert($pdo, 'INSERT INTO adoption_records (pet_name,species,breed,age,gender,health_status,status,reason,adopted_at,photo_path) VALUES (?,?,?,?,?,?,?,?,?,?)', [$name, $species, $breed, $age, $gender, $health ?? 'Unknown', $status, $reason, $adoptedAt, $newPhoto]);
                rec_reply(201, ['success'=>true, 'message'=>'Record added.', 'id'=>$newId]);
            } catch (Throwable $ex) {
                if ($newPhoto) delete_pet_photo($newPhoto);
                throw $ex;
            }
        }
        // Adoption status is changed only by adoption_applications.php after an application review.
        if ($action === 'delete') {
            $d = $pdo->prepare('DELETE FROM adoption_records WHERE id=?'); $d->execute([$id]);
            if (!$d->rowCount()) rec_fail('Adoption record not found.', 404);
            rec_reply(200, ['success'=>true, 'message'=>'Record deleted.']);
        }
    }

    /* ======================== CLINIC SETTINGS ======================== */
    if ($resource === 'settings' && $action === 'save') {
        if (($account['role'] ?? '') !== 'Administrator') rec_fail('Only the administrator can change clinic information.', 403);
        $name = rec_text($in, 'name', 160, true, 'Clinic name'); $email = rec_text($in, 'email', 190); $phone = rec_text($in, 'phone', 40); $address = rec_text($in, 'address', 255);
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) rec_fail('Enter a valid contact email address.');
        $pdo->prepare('INSERT INTO clinic_settings (id, clinic_name, email, phone, address) VALUES (1,?,?,?,?) ON CONFLICT (id) DO UPDATE SET clinic_name=EXCLUDED.clinic_name, email=EXCLUDED.email, phone=EXCLUDED.phone, address=EXCLUDED.address')
            ->execute([$name, $email, $phone, $address]);
        rec_reply(200, ['success'=>true, 'message'=>'Settings saved.']);
    }

    rec_fail('Unknown records action.', 400);
} catch (Throwable $e) {
    error_log('Records API error: ' . $e->getMessage());
    rec_fail('Could not process the request. Check the database connection and that supabase/schema.sql was run.', 500);
}
