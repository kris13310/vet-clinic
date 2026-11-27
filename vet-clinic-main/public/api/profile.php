<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function profile_reply(int $code,array $body): never { http_response_code($code); echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
$account=$_SESSION['account']??null;
if(!is_array($account)||empty($account['id'])) profile_reply(401,['success'=>false,'message'=>'Please sign in again.']);
$uid=(int)$account['id'];
try {
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $s=$pdo->prepare('SELECT id,full_name,email,phone,address,avatar_path,role,username FROM users WHERE id=?');$s->execute([$uid]);$u=$s->fetch();
  if(!$u)profile_reply(404,['success'=>false,'message'=>'Account not found.']);
  profile_reply(200,['success'=>true,'user'=>$u]);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST'||($_GET['action']??'')!=='save')profile_reply(405,['success'=>false,'message'=>'Unsupported profile action.']);
 $data=$_POST;
 $name=trim((string)($data['full_name']??''));$email=trim((string)($data['email']??''));$phone=trim((string)($data['phone']??''));$address=trim((string)($data['address']??''));
 if($name===''||mb_strlen($name)>120||!filter_var($email,FILTER_VALIDATE_EMAIL)||mb_strlen($email)>190||mb_strlen($phone)>40||mb_strlen($address)>255)profile_reply(422,['success'=>false,'message'=>'Enter valid profile details.']);
 $check=$pdo->prepare('SELECT id FROM users WHERE LOWER(email)=LOWER(?) AND id<>?');$check->execute([$email,$uid]);if($check->fetch())profile_reply(409,['success'=>false,'message'=>'That email is already used by another account.']);
 $q=$pdo->prepare('SELECT avatar_path FROM users WHERE id=?');$q->execute([$uid]);$old=$q->fetchColumn()?:null;$avatar=$old;
 // Profile data is validated above before any replacement avatar is uploaded.
 if(!empty($_FILES['photo'])&&(int)($_FILES['photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
  try{$avatar=save_pet_photo($_FILES['photo'],'user_');}
  catch(RuntimeException $e){profile_reply(422,['success'=>false,'message'=>$e->getMessage()]);}
 }
 if(($data['remove_photo']??'0')==='1')$avatar=null;
 $pdo->prepare('UPDATE users SET full_name=?,email=?,phone=?,address=?,avatar_path=? WHERE id=?')->execute([$name,$email,$phone,$address,$avatar,$uid]);
 $_SESSION['account']['name']=$name;$_SESSION['account']['full_name']=$name;$_SESSION['account']['email']=$email;$_SESSION['account']['avatar_path']=$avatar;
 if($avatar!==$old)delete_pet_photo($old);
 profile_reply(200,['success'=>true,'message'=>'Profile updated.','user'=>['full_name'=>$name,'email'=>$email,'phone'=>$phone,'address'=>$address,'avatar_path'=>$avatar]]);
} catch(Throwable $e){error_log('Profile API error: '.$e->getMessage());profile_reply(500,['success'=>false,'message'=>'Unable to update profile. Check the database and photo storage configuration.']);}
