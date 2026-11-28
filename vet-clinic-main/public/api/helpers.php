<?php
declare(strict_types=1);
/**
 * Shared helpers for the appointment availability + notification features.
 * Used by api/owner.php (pet owners) and api/appointments.php (clinic staff).
 *
 * CLINIC SCHEDULE - change these constants to match your clinic.
 */
date_default_timezone_set('Asia/Manila');

const CLINIC_OPEN         = '09:00';   // first slot starts here
const CLINIC_CLOSE        = '17:00';   // last slot ENDS here
const SLOT_MINUTES        = 30;        // length of one appointment slot
const LUNCH_START         = '12:00';   // no slots from lunch start ...
const LUNCH_END           = '13:00';   // ... until lunch end
const CLOSED_WEEKDAYS     = [0, 5, 6]; // 0 = Sunday, 5 = Friday, 6 = Saturday. Clinic is closed every Friday, Saturday and Sunday.
const BOOKING_WINDOW_DAYS = 90;        // how far ahead owners may book
const MIN_LEAD_MINUTES    = 60;        // a slot must start at least this far in the future
const OWNER_CANCEL_HOURS  = 24;       // an owner may cancel an APPROVED appointment online until this many hours before it
const SLOT_CAPACITY       = 1;         // appointments allowed per slot (1 = one booking per time)

/** Statuses that keep a slot reserved. Only Cancelled (rejected) frees the slot. */
const SLOT_BLOCKING_SQL = "status <> 'Cancelled'";

function clinic_slots(): array {
    $slots = [];
    $t = strtotime('2000-01-01 ' . CLINIC_OPEN);
    $end = strtotime('2000-01-01 ' . CLINIC_CLOSE);
    $ls = strtotime('2000-01-01 ' . LUNCH_START);
    $le = strtotime('2000-01-01 ' . LUNCH_END);
    while ($t + SLOT_MINUTES * 60 <= $end) {
        $slotEnd = $t + SLOT_MINUTES * 60;
        // skip any slot that overlaps lunch
        if (!($t < $le && $slotEnd > $ls)) $slots[] = date('H:i', $t);
        $t = $slotEnd;
    }
    return $slots;
}

function valid_date_string(string $date): bool {
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

/** True when the slot start is too soon / already gone. */
function slot_is_past(string $date, string $time): bool {
    $start = DateTime::createFromFormat('Y-m-d H:i', "$date $time");
    if (!$start) return true;
    return $start->getTimestamp() < time() + MIN_LEAD_MINUTES * 60;
}

/** Returns an error message, or null when the date/time is a bookable clinic slot. */
function validate_slot(PDO $pdo, string $date, string $time, bool $staff = false): ?string {
    if (!valid_date_string($date)) return 'Choose a valid appointment date.';
    if (!preg_match('/^\d{2}:\d{2}$/', $time) || !in_array($time, clinic_slots(), true)) return 'Choose one of the available time slots.';
    $today = date('Y-m-d');
    if ($date < $today) return 'You cannot book a date in the past.';
    if (!$staff && $date > date('Y-m-d', strtotime('+' . BOOKING_WINDOW_DAYS . ' days'))) return 'Appointments can be booked up to ' . BOOKING_WINDOW_DAYS . ' days ahead.';
    $info = day_info($date, closures_map($pdo, $date, $date)[$date] ?? []);
    if ($info['state'] !== 'open') return 'The clinic is not accepting appointments on that date (' . $info['label'] . '). Please choose another date.';
    $c = slot_closure($info['partial'], $time);
    if ($c) return 'The veterinarian is unavailable at that time' . (($c['reason'] ?? '') !== '' ? ' (' . $c['reason'] . ')' : '') . '. Please choose another time.';
    if (!$staff && slot_is_past($date, $time)) return 'That time has already passed or is too soon. Choose a later time.'; // clinic staff may record walk-ins
    return null;
}

/** Map of "HH:MM" => number of reserved appointments for one date. */
function booked_counts(PDO $pdo, string $date, int $excludeId = 0): array {
    $s = $pdo->prepare("SELECT to_char(appointment_time,'HH24:MI') AS t, COUNT(*) AS c FROM appointments WHERE appointment_date=? AND " . SLOT_BLOCKING_SQL . " AND id<>? GROUP BY t");
    $s->execute([$date, $excludeId]);
    $out = [];
    foreach ($s->fetchAll() as $r) $out[(string)$r['t']] = (int)$r['c'];
    return $out;
}

function slot_is_taken(PDO $pdo, string $date, string $time, int $excludeId = 0): bool {
    $counts = booked_counts($pdo, $date, $excludeId);
    return ($counts[$time] ?? 0) >= SLOT_CAPACITY;
}

/**
 * Serialises booking/rescheduling so two people cannot grab the same slot at the same moment.
 * Runs the work in ONE transaction guarded by a PostgreSQL advisory lock (released automatically at commit/rollback),
 * which also works behind Supabase's transaction pooler. Callers must NOT start their own transaction.
 */
function with_slot_lock(PDO $pdo, callable $fn) {
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT pg_advisory_xact_lock(727001)');
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** INSERT and return the new row's id. (PostgreSQL RETURNING; lastInsertId() is unreliable behind a connection pooler.) */
function db_insert(PDO $pdo, string $sql, array $params): int {
    $s = $pdo->prepare($sql . ' RETURNING id');
    $s->execute($params);
    return (int)$s->fetchColumn();
}

function human_date(string $date): string {
    $d = DateTime::createFromFormat('!Y-m-d', substr($date, 0, 10));
    return $d ? $d->format('M j, Y') : $date;
}
function human_time(string $time): string {
    $t = DateTime::createFromFormat('H:i', substr($time, 0, 5));
    return $t ? $t->format('g:i A') : $time;
}

/** Creates a notification row for one user. */
function create_notification(PDO $pdo, int $userId, ?int $appointmentId, string $type, string $title, string $message): void {
    $s = $pdo->prepare('INSERT INTO notifications (user_id, appointment_id, type, title, message, is_read, created_at) VALUES (?,?,?,?,?,0,?)');
    $s->execute([$userId, $appointmentId, $type, mb_substr($title, 0, 160), mb_substr($message, 0, 500), date('Y-m-d H:i:s')]);
}

/**
 * Notifies the Pet Owner account that owns the pet of an appointment.
 * The owner is found the same way the owner portal links pets: pets.owner_email = users.email.
 */
function notify_appointment_owner(PDO $pdo, int $appointmentId, string $type, string $title, string $messageTemplate): void {
    $s = $pdo->prepare("SELECT a.appointment_date, a.appointment_time, p.name AS pet_name, u.id AS user_id
        FROM appointments a JOIN pets p ON p.id=a.pet_id
        JOIN users u ON LOWER(TRIM(u.email))=LOWER(TRIM(p.owner_email)) AND u.role='Pet Owner'
        WHERE a.id=? LIMIT 1");
    $s->execute([$appointmentId]);
    $r = $s->fetch();
    if (!$r) return; // pet has no linked owner account: nothing to notify
    $msg = strtr($messageTemplate, [
        '{pet}'  => (string)$r['pet_name'],
        '{date}' => human_date((string)$r['appointment_date']),
        '{time}' => human_time((string)$r['appointment_time']),
    ]);
    $ttl = strtr($title, ['{date}' => human_date((string)$r['appointment_date'])]);
    create_notification($pdo, (int)$r['user_id'], $appointmentId, $type, $ttl, $msg);
}

/**
 * Notifies active clinic staff about an owner action on an appointment.
 * Notifications remain per-user, so each staff member can mark their own as read.
 */
function notify_clinic_staff(PDO $pdo, int $appointmentId, string $type, string $title, string $messageTemplate): void {
    $s = $pdo->prepare("SELECT a.appointment_date, a.appointment_time, p.name AS pet_name, p.owner_name
        FROM appointments a JOIN pets p ON p.id=a.pet_id WHERE a.id=?");
    $s->execute([$appointmentId]);
    $r = $s->fetch();
    if (!$r) return;
    $message = strtr($messageTemplate, [
        '{pet}' => (string)$r['pet_name'],
        '{owner}' => (string)$r['owner_name'],
        '{date}' => human_date((string)$r['appointment_date']),
        '{time}' => human_time((string)$r['appointment_time']),
    ]);
    $recipients = $pdo->query("SELECT id FROM users WHERE status='Active' AND role IN ('Administrator','Veterinarian','Veterinary Staff')")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($recipients as $recipientId) {
        create_notification($pdo, (int)$recipientId, $appointmentId, $type, $title, $message);
    }
}

/* =====================================================================
   PHILIPPINE HOLIDAYS (calculated automatically) + CLINIC CLOSURES
   ===================================================================== */

/** Easter Sunday for a year (Gregorian "Anonymous" algorithm, no PHP calendar extension needed). */
function easter_sunday(int $y): DateTimeImmutable {
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451); $n = $h + $l - 7 * $m + 114;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, intdiv($n, 31), ($n % 31) + 1));
}

/**
 * Holidays the clinic treats as CLOSED, for one year: date => [name, type].
 * Fixed-date and Holy Week holidays are calculated here. Holidays that move every year
 * (Eid'l Fitr, Eid'l Adha, Chinese New Year) or are declared by the President / the City
 * are added by the administrator on the Availability page (clinic_closures table).
 */
function ph_holidays(int $year): array {
    $reg = 'Regular holiday'; $sp = 'Special non-working day';
    $h = [
        "$year-01-01" => ['New Year\'s Day', $reg],
        "$year-04-09" => ['Araw ng Kagitingan (Day of Valor)', $reg],
        "$year-05-01" => ['Labor Day', $reg],
        "$year-06-12" => ['Independence Day', $reg],
        "$year-08-21" => ['Ninoy Aquino Day', $sp],
        "$year-11-01" => ['All Saints\' Day', $sp],
        "$year-11-02" => ['All Souls\' Day', $sp],
        "$year-11-30" => ['Bonifacio Day', $reg],
        "$year-12-08" => ['Feast of the Immaculate Conception', $sp],
        "$year-12-24" => ['Christmas Eve', $sp],
        "$year-12-25" => ['Christmas Day', $reg],
        "$year-12-30" => ['Rizal Day', $reg],
        "$year-12-31" => ['Last Day of the Year', $sp],
    ];
    $h[(new DateTimeImmutable("last monday of august $year"))->format('Y-m-d')] = ['National Heroes Day', $reg];
    $easter = easter_sunday($year);
    $h[$easter->modify('-3 days')->format('Y-m-d')] = ['Maundy Thursday', $reg];
    $h[$easter->modify('-2 days')->format('Y-m-d')] = ['Good Friday', $reg];
    $h[$easter->modify('-1 day')->format('Y-m-d')]  = ['Black Saturday', $sp];
    ksort($h);
    return $h;
}
function ph_holiday_for(string $date): ?array {
    $h = ph_holidays((int)substr($date, 0, 4))[$date] ?? null;
    return $h ? ['name' => $h[0], 'type' => $h[1]] : null;
}

const CLOSURE_TYPES = ['Doctor Unavailable', 'Holiday', 'Clinic Closed', 'Other'];

/** Administrator-defined closures between two dates: date => list of rows. */
function closures_map(PDO $pdo, string $from, string $to): array {
    $s = $pdo->prepare('SELECT id, closure_date, to_char(start_time,\'HH24:MI\') AS start_time, to_char(end_time,\'HH24:MI\') AS end_time, type, reason FROM clinic_closures WHERE closure_date BETWEEN ? AND ? ORDER BY closure_date, start_time');
    $s->execute([$from, $to]);
    $out = [];
    foreach ($s->fetchAll() as $r) $out[(string)$r['closure_date']][] = $r;
    return $out;
}

/**
 * What kind of day is this? state: open | closed (weekly day off) | holiday | unavailable (doctor away / clinic closed).
 * For an open day, 'partial' lists closures that only block part of the day.
 */
function day_info(string $date, array $closureRows): array {
    $h = ph_holiday_for($date);   // a Philippine holiday always wins, even on a Friday/Sunday, so its name is shown
    if ($h) return ['state' => 'holiday', 'label' => $h['name'], 'partial' => []];
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    if ($d && in_array((int)$d->format('w'), CLOSED_WEEKDAYS, true)) return ['state' => 'closed', 'label' => 'Clinic is closed', 'partial' => []];
    $partial = [];
    foreach ($closureRows as $r) {
        if ($r['start_time'] === null || $r['end_time'] === null) {
            $label = trim((string)($r['reason'] ?? '')) !== '' ? (string)$r['reason'] : (string)$r['type'];
            return ['state' => $r['type'] === 'Holiday' ? 'holiday' : 'unavailable', 'label' => $label, 'partial' => []];
        }
        $partial[] = $r;
    }
    return ['state' => 'open', 'label' => null, 'partial' => $partial];
}

function time_to_minutes(string $t): int { return (int)substr($t, 0, 2) * 60 + (int)substr($t, 3, 2); }

/** The partial closure that overlaps the slot starting at $time, if any. */
function slot_closure(array $partial, string $time): ?array {
    $s = time_to_minutes($time); $e = $s + SLOT_MINUTES;
    foreach ($partial as $r) {
        if ($s < time_to_minutes((string)$r['end_time']) && $e > time_to_minutes((string)$r['start_time'])) return $r;
    }
    return null;
}

/* ===== Pet pictures live in Supabase Storage (Vercel containers have no permanent disk) ===== */
function storage_bucket(): string { return (string)(getenv('SUPABASE_BUCKET') ?: 'pet-photos'); }
/** Normalize SUPABASE_URL to the project root; /rest/v1 is not part of the Storage API base URL. */
function supabase_project_url(): string {
    $url = rtrim(trim((string)getenv('SUPABASE_URL')), '/');
    $url = preg_replace('#/(?:rest/v1|storage/v1)$#i', '', $url) ?? $url;
    return rtrim($url, '/');
}
function storage_public_prefix(): string { return supabase_project_url() . '/storage/v1/object/public/' . rawurlencode(storage_bucket()) . '/'; }
/** Calls the Supabase Storage REST API with the server-side service key. Returns [httpCode, body, curlError]. */
function storage_call(string $method, string $path, ?string $body = null, ?string $mime = null): array {
    $key = trim((string)getenv('SUPABASE_SERVICE_KEY'));
    // legacy service_role keys are JWTs (start with "eyJ") and are also sent as a Bearer token; the newer sb_secret_... keys go in apikey only
    $headers = ['apikey: ' . $key];
    if (str_starts_with($key, 'eyJ')) $headers[] = 'Authorization: Bearer ' . $key;
    if ($mime) $headers[] = 'Content-Type: ' . $mime;
    $url = supabase_project_url() . '/storage/v1/' . ltrim($path, '/');
    if (!function_exists('curl_init')) {
        // Some PHP builds do not include ext-curl. Keep photo upload/delete working with streams.
        $context = stream_context_create(['http'=>[
            'method'=>$method,
            'header'=>implode("\r\n", $headers) . "\r\n",
            'content'=>$body ?? '',
            'timeout'=>20,
            'ignore_errors'=>true,
        ]]);
        $resp = @file_get_contents($url, false, $context);
        $code = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\\/\\S+\\s+(\\d{3})/', $header, $m)) $code = (int)$m[1];
        }
        $err = $resp === false ? (string)(error_get_last()['message'] ?? 'Storage request failed.') : '';
        return [$code, is_string($resp) ? $resp : '', $err];
    }
    $ch = curl_init($url);
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch);
    curl_close($ch);
    return [$code, is_string($resp) ? $resp : '', $err];
}
/** Uploads a validated image and returns its public URL (stored in pets.photo_path). */
function storage_upload(string $tmpFile, string $name, string $mime): string {
    if (!getenv('SUPABASE_URL') || !getenv('SUPABASE_SERVICE_KEY')) throw new RuntimeException('Picture storage is not configured yet.');
    $data = file_get_contents($tmpFile);
    if ($data === false) throw new RuntimeException('The picture could not be read. Please try again.');
    [$code, $resp, $err] = storage_call('POST', 'object/' . rawurlencode(storage_bucket()) . '/' . rawurlencode($name), $data, $mime);
    if ($code < 200 || $code >= 300) { error_log("Storage upload failed ($code): $err $resp"); throw new RuntimeException('The picture could not be saved. Please try again later.'); }
    return storage_public_prefix() . rawurlencode($name);
}
/** Deletes an uploaded pet picture. Only ever touches files inside our own bucket. */
function delete_pet_photo(?string $path): void {
    $prefix = storage_public_prefix();
    if (!$path || strncmp($path, $prefix, strlen($prefix)) !== 0) return;
    $name = rawurldecode(substr($path, strlen($prefix)));
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $name)) return;
    [$code, $resp, $err] = storage_call('DELETE', 'object/' . rawurlencode(storage_bucket()) . '/' . rawurlencode($name));
    // A missing object is already clean; log other failures so orphaned files can be diagnosed.
    if (!in_array($code, [200, 204, 404], true)) {
        error_log("Supabase photo deletion failed ($code): $err $resp");
    }
}

/**
 * The clinic has ONE doctor: the active user whose role is "Veterinarian".
 * Change a user's role to Veterinarian (Users page or phpMyAdmin) and that person becomes the doctor in charge.
 * If several active Veterinarian accounts exist, the oldest one is used.
 */
function current_doctor(PDO $pdo, bool $lockForBooking = false): ?array {
    // Booking callers use FOR SHARE inside their transaction so an administrator cannot
    // deactivate/reassign this veterinarian between assignment and appointment insertion.
    $sql = "SELECT id, full_name FROM users WHERE role='Veterinarian' AND status='Active' ORDER BY id LIMIT 1";
    if ($lockForBooking) $sql .= ' FOR SHARE';
    $r = $pdo->query($sql)->fetch();
    return $r ? ['id' => (int)$r['id'], 'name' => (string)$r['full_name']] : null;
}

/**
 * Doctor name shown on a record. Finished visits keep the doctor who was recorded at the time;
 * everything still open shows the doctor currently in charge.
 */
function doctor_display(?array $doctor, bool $finished, ?string $storedName, string $fallback = 'Not assigned'): string {
    $stored = ($storedName !== null && trim($storedName) !== '') ? $storedName : null;
    if ($finished) return $stored ?? ($doctor['name'] ?? $fallback);
    return $doctor['name'] ?? ($stored ?? $fallback);
}

/**
 * Can the pet owner cancel this appointment online?
 * Pending requests: any time before the start. Approved (Confirmed) appointments: until OWNER_CANCEL_HOURS before the start.
 */
function owner_can_cancel(string $status, string $date, string $time): bool {
    if (!in_array($status, ['Pending', 'Confirmed'], true)) return false;
    $start = DateTime::createFromFormat('Y-m-d H:i', substr($date, 0, 10) . ' ' . substr($time, 0, 5));
    if (!$start) return false;
    $now = time(); $t = $start->getTimestamp();
    if ($t <= $now) return false;
    return $status === 'Pending' ? true : $t >= $now + OWNER_CANCEL_HOURS * 3600;
}

/* ===== Shared by the owner portal and the staff Pet Records page ===== */
const PHOTO_MAX_BYTES = 2 * 1024 * 1024;                       // 2 MB
const PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
/** Validates an uploaded image, stores it in Supabase Storage and returns its public URL. Throws RuntimeException with a user-friendly message. */
function save_pet_photo(array $file, string $prefix = 'pet_'): string {
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('The picture is too large. Maximum size is 2 MB.');
    if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('The picture could not be uploaded. Please try again.');
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new RuntimeException('Invalid upload.');
    if ((int)($file['size'] ?? 0) > PHOTO_MAX_BYTES || filesize($tmp) > PHOTO_MAX_BYTES) throw new RuntimeException('The picture is too large. Maximum size is 2 MB.');

    // Trust the file contents, never the file name or the browser-supplied type.
    $info = @getimagesize($tmp);
    $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $real = $fi ? (string)finfo_file($fi, $tmp) : '';
        if ($fi) finfo_close($fi);
        if ($real !== '' && $real !== $mime) $mime = '';
    }
    if (!isset(PHOTO_TYPES[$mime])) throw new RuntimeException('Only JPG, PNG or WebP pictures are allowed.');
    if (($info[0] ?? 0) < 50 || ($info[1] ?? 0) < 50 || ($info[0] ?? 0) > 8000 || ($info[1] ?? 0) > 8000) throw new RuntimeException('The picture dimensions are not supported (min 50px, max 8000px).');

    if (!preg_match('/^[a-z][a-z0-9_-]{0,15}_$/', $prefix)) throw new RuntimeException('Invalid image type.');
    $name = $prefix . bin2hex(random_bytes(12)) . '.' . PHOTO_TYPES[$mime];
    return storage_upload($tmp, $name, $mime); // Supabase Storage: returns the picture's public URL
}
