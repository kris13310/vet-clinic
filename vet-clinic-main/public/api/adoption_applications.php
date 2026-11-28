<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function adoption_reply(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function adoption_input(): array {
    $v = json_decode(file_get_contents('php://input') ?: '{}', true);
    return is_array($v) ? $v : [];
}

$account = $_SESSION['account'] ?? null;
if (!is_array($account) || empty($account['id'])) adoption_reply(401, ['success'=>false,'message'=>'Please sign in again.']);
if (!in_array(($account['role'] ?? ''), ['Administrator','Veterinarian','Veterinary Staff'], true)) adoption_reply(403, ['success'=>false,'message'=>'Clinic staff access only.']);

try {
    $uid = (int)$account['id'];
    $action = (string)($_GET['action'] ?? 'list');

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
        $q = $pdo->query("SELECT x.id,x.adoption_id,x.user_id,x.applicant_name,x.email,x.phone,x.address,x.message,x.status,x.staff_notes,x.created_at,
            a.pet_name,a.species,a.breed,a.age,a.photo_path
            FROM adoption_applications x JOIN adoption_records a ON a.id=x.adoption_id
            ORDER BY CASE x.status WHEN 'Pending' THEN 0 ELSE 1 END,x.created_at DESC,x.id DESC");
        adoption_reply(200, ['success'=>true,'applications'=>$q->fetchAll()]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action !== 'review') adoption_reply(405, ['success'=>false,'message'=>'Unsupported adoption application action.']);
    $in = adoption_input();
    $id = (int)($in['id'] ?? 0);
    $decision = (string)($in['decision'] ?? '');
    $notes = trim((string)($in['staff_notes'] ?? ''));
    if ($id < 1 || !in_array($decision, ['Approved','Rejected'], true)) adoption_reply(422, ['success'=>false,'message'=>'Choose a valid application and decision.']);
    if (mb_strlen($notes) > 2000) adoption_reply(422, ['success'=>false,'message'=>'Review notes are too long.']);

    $pdo->beginTransaction();
    try {
        // Always lock the listing before its application rows. Owner submission and
        // withdrawal use the same lock order, avoiding cross-application deadlocks.
        $lookup = $pdo->prepare('SELECT adoption_id FROM adoption_applications WHERE id=?');
        $lookup->execute([$id]); $adoptionId = $lookup->fetchColumn();
        if (!$adoptionId) {
            $pdo->rollBack();
            adoption_reply(404, ['success'=>false,'message'=>'Adoption application not found.']);
        }
        $listingLock = $pdo->prepare('SELECT id,status,pet_name FROM adoption_records WHERE id=? FOR UPDATE');
        $listingLock->execute([(int)$adoptionId]); $listing = $listingLock->fetch();
        $q = $pdo->prepare("SELECT id,adoption_id,user_id,applicant_name,status
            FROM adoption_applications WHERE id=? AND adoption_id=? FOR UPDATE");
        $q->execute([$id,(int)$adoptionId]); $app = $q->fetch();
        if (!$listing || !$app || $app['status'] !== 'Pending' || $listing['status'] !== 'Pending') {
            $pdo->rollBack();
            adoption_reply(409, ['success'=>false,'message'=>'This application is no longer pending. Refresh the list and try again.']);
        }
        $app['pet_name'] = (string)$listing['pet_name'];

        if ($decision === 'Approved') {
            $pdo->prepare("UPDATE adoption_applications SET status='Approved',staff_notes=?,reviewed_by=?,reviewed_at=now() WHERE id=?")
                ->execute([$notes !== '' ? $notes : null,$uid,$id]);
            $pdo->prepare("UPDATE adoption_records SET status='Adopted',adopter_name=?,adopted_at=COALESCE(adopted_at,CURRENT_DATE) WHERE id=?")
                ->execute([$app['applicant_name'],(int)$app['adoption_id']]);

            // Once one applicant is approved, close every competing pending application
            // so no other owner is left with a permanently pending request for an adopted pet.
            $others = $pdo->prepare("SELECT id,user_id FROM adoption_applications WHERE adoption_id=? AND status='Pending' AND id<>? ORDER BY id FOR UPDATE");
            $others->execute([(int)$app['adoption_id'],$id]);
            $otherApps = $others->fetchAll();
            if ($otherApps) {
                $pdo->prepare("UPDATE adoption_applications SET status='Rejected',staff_notes='Another application was approved for this pet.',reviewed_by=?,reviewed_at=now() WHERE adoption_id=? AND status='Pending' AND id<>?")
                    ->execute([$uid,(int)$app['adoption_id'],$id]);
                $otherMessage = 'Another adoption application for '.$app['pet_name'].' was approved, so this listing is now closed. Thank you for your interest.';
                $notifyOther = $pdo->prepare("INSERT INTO notifications (user_id,appointment_id,type,title,message,is_read,created_at) VALUES (?,NULL,'adoption','Adoption listing closed',?,0,?)");
                foreach ($otherApps as $other) {
                    $notifyOther->execute([(int)$other['user_id'],$otherMessage,date('Y-m-d H:i:s')]);
                }
            }
            $title = 'Adoption application approved';
            $message = 'Your application for '.$app['pet_name'].' has been approved. Please contact the clinic for the next steps.';
        } else {
            $pdo->prepare("UPDATE adoption_applications SET status='Rejected',staff_notes=?,reviewed_by=?,reviewed_at=now() WHERE id=?")
                ->execute([$notes !== '' ? $notes : null,$uid,$id]);
            $remaining = $pdo->prepare("SELECT COUNT(*) FROM adoption_applications WHERE adoption_id=? AND status='Pending'");
            $remaining->execute([(int)$app['adoption_id']]);
            if ((int)$remaining->fetchColumn() === 0) $pdo->prepare("UPDATE adoption_records SET status='Available',adopter_name=NULL,adopted_at=NULL WHERE id=? AND status='Pending'")->execute([(int)$app['adoption_id']]);
            $title = 'Adoption application update';
            $message = 'Your application for '.$app['pet_name'].' was not approved. You can contact the clinic if you need more information.';
        }
        $pdo->prepare("INSERT INTO notifications (user_id,appointment_id,type,title,message,is_read,created_at) VALUES (?,NULL,'adoption',?,?,0,?)")
            ->execute([(int)$app['user_id'],$title,$message,date('Y-m-d H:i:s')]);

        // Keep the rest of the clinic team informed about the final review decision.
        $staff = $pdo->prepare("SELECT id FROM users WHERE status='Active' AND role IN ('Administrator','Veterinarian','Veterinary Staff') AND id<>?");
        $staff->execute([$uid]);
        $staffTitle = $decision === 'Approved' ? 'Adoption application approved' : 'Adoption application rejected';
        $staffMessage = 'Application for '.$app['pet_name'].' from '.$app['applicant_name'].' was '.$decision.'.';
        foreach ($staff->fetchAll(PDO::FETCH_COLUMN) as $staffId) {
            $pdo->prepare("INSERT INTO notifications (user_id,appointment_id,type,title,message,is_read,created_at) VALUES (?,NULL,'adoption_review',?,?,0,?)")
                ->execute([(int)$staffId,$staffTitle,$staffMessage,date('Y-m-d H:i:s')]);
        }
        $pdo->commit();
        adoption_reply(200, ['success'=>true,'message'=>$decision === 'Approved' ? 'Application approved. The pet is now marked as adopted.' : 'Application rejected. The pet is available again if no other applications are pending.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
} catch (Throwable $e) {
    error_log('Adoption application error: '.$e->getMessage());
    adoption_reply(500, ['success'=>false,'message'=>'Could not process the adoption application. Please try again.']);
}
