<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function report_reply(int $code, array $body): never { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function report_text(array $input, string $key, int $max, bool $required = false): string {
    $value = trim((string)($input[$key] ?? ''));
    if ($required && $value === '') report_reply(422, ['success'=>false,'message'=>ucfirst(str_replace('_',' ',$key)).' is required.']);
    if (mb_strlen($value) > $max) report_reply(422, ['success'=>false,'message'=>ucfirst(str_replace('_',' ',$key))." must be at most $max characters."]);
    return $value;
}
$account = $_SESSION['account'] ?? null;
if (!is_array($account) || empty($account['id'])) report_reply(401, ['success'=>false,'message'=>'Please sign in again.']);
$uid = (int)$account['id']; $role = (string)($account['role'] ?? ''); $action = (string)($_GET['action'] ?? 'list');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
        if (!in_array($role, ['Administrator','Veterinarian','Veterinary Staff'], true)) report_reply(403, ['success'=>false,'message'=>'Clinic staff access only.']);
        $sql = "SELECT r.*, p.name AS pet_name, a.appointment_date, a.appointment_time, u.full_name AS veterinarian_name
                FROM medical_reports r
                JOIN pets p ON p.id=r.pet_id
                JOIN appointments a ON a.id=r.appointment_id
                JOIN users u ON u.id=r.veterinarian_id";
        if ($role === 'Veterinarian') {
            $q = $pdo->prepare($sql . " WHERE r.veterinarian_id=? ORDER BY r.created_at DESC");
            $q->execute([$uid]);
        } else {
            $q = $pdo->query($sql . " ORDER BY r.created_at DESC");
        }
        report_reply(200, ['success'=>true,'reports'=>$q->fetchAll()]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action !== 'save') report_reply(405, ['success'=>false,'message'=>'Unsupported report action.']);
    if ($role !== 'Veterinarian') report_reply(403, ['success'=>false,'message'=>'Only the assigned veterinarian can submit medical reports.']);
    $input = json_decode(file_get_contents('php://input') ?: '{}', true); if (!is_array($input)) $input = [];
    $appointmentId = (int)($input['appointment_id'] ?? 0); if ($appointmentId < 1) report_reply(422, ['success'=>false,'message'=>'Select a valid appointment.']);
    $diagnosis=report_text($input,'diagnosis',5000,true); $symptoms=report_text($input,'symptoms',5000,true); $observations=report_text($input,'observations',5000);
    $treatment=report_text($input,'treatment',5000,true); $medication=report_text($input,'medication',5000); $recommendations=report_text($input,'recommendations',5000);
    $followUp=trim((string)($input['follow_up_date'] ?? '')); if ($followUp !== '' && !valid_date_string($followUp)) report_reply(422,['success'=>false,'message'=>'Enter a valid follow-up date.']);
    $s=$pdo->prepare('SELECT id,pet_id,veterinarian_id,status FROM appointments WHERE id=?'); $s->execute([$appointmentId]); $a=$s->fetch();
    if (!$a) report_reply(404,['success'=>false,'message'=>'Appointment not found.']);
    if ((int)$a['veterinarian_id'] !== $uid) report_reply(403,['success'=>false,'message'=>'This appointment is not assigned to your veterinarian account.']);
    if ($a['status']==='Cancelled') report_reply(409,['success'=>false,'message'=>'A cancelled appointment cannot receive a medical report.']);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO medical_reports (appointment_id,pet_id,veterinarian_id,diagnosis,symptoms,observations,treatment,medication,recommendations,follow_up_date) VALUES (?,?,?,?,?,?,?,?,?,?) ON CONFLICT (appointment_id) DO UPDATE SET pet_id=EXCLUDED.pet_id,veterinarian_id=EXCLUDED.veterinarian_id,diagnosis=EXCLUDED.diagnosis,symptoms=EXCLUDED.symptoms,observations=EXCLUDED.observations,treatment=EXCLUDED.treatment,medication=EXCLUDED.medication,recommendations=EXCLUDED.recommendations,follow_up_date=EXCLUDED.follow_up_date,updated_at=now()")
            ->execute([$appointmentId,(int)$a['pet_id'],$uid,$diagnosis,$symptoms,$observations,$treatment,$medication,$recommendations,$followUp!==''?$followUp:null]);
        $pdo->prepare("UPDATE appointments SET status='Completed' WHERE id=?")->execute([$appointmentId]);
        // Keep the report, appointment completion, and owner notification atomic.
        notify_appointment_owner($pdo,$appointmentId,'medical_report','Medical report available','The veterinarian has added a medical report for {pet}. You can view it in Medical Records.');
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    report_reply(200,['success'=>true,'message'=>'Medical report saved. The appointment is now completed.']);
} catch(Throwable $e) { error_log('Medical report API error: '.$e->getMessage()); report_reply(500,['success'=>false,'message'=>'Unable to save the medical report. Check that the medical_reports table exists.']); }
