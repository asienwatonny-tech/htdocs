<?php
// Text messages and one-time codes (OTP).
// No SMS company is connected in this prototype: messages are saved in the sms_outbox table and
// shown on the "SMS outbox" page. To go live, call your SMS provider (for example Africa's Talking)
// inside send_sms() below.
require_once __DIR__ . '/auth.php';

function send_sms(PDO $db, string $phone, string $message, ?int $visitId = null): void {
  $db->prepare("INSERT INTO sms_outbox (visit_id,phone,message) VALUES (?,?,?)")->execute([$visitId, $phone, $message]);
  // ---- real gateway goes here ----
}

/* Create a fresh 6-digit code for a visit, store only its hash, and text it to the patient. */
function issue_otp(PDO $db, int $visitId, string $phone): string {
  $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  $db->prepare("UPDATE otp_codes SET used=1 WHERE visit_id=? AND used=0")->execute([$visitId]);
  $db->prepare("INSERT INTO otp_codes (visit_id,code_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL " . (int)OTP_TTL_MIN . " MINUTE))")
     ->execute([$visitId, password_hash($code, PASSWORD_DEFAULT)]);
  send_sms($db, $phone, 'MedFlow code: ' . $code . '. It expires in ' . OTP_TTL_MIN . ' minutes. Do not share it.', $visitId);
  return $code;
}

function otp_cooldown_left(PDO $db, int $visitId): int {
  $s = $db->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM otp_codes WHERE visit_id=? ORDER BY id DESC LIMIT 1");
  $s->execute([$visitId]); $age = $s->fetchColumn();
  if ($age === false || $age === null) return 0;
  return max(0, OTP_RESEND_SEC - (int)$age);
}

function verify_otp(PDO $db, int $visitId, string $code): bool {
  $s = $db->prepare("SELECT * FROM otp_codes WHERE visit_id=? AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 1");
  $s->execute([$visitId]); $o = $s->fetch();
  if (!$o) return false;
  if ((int)$o['attempts'] >= OTP_MAX_TRIES) {
    $db->prepare("UPDATE otp_codes SET used=1 WHERE id=?")->execute([$o['id']]);
    return false;
  }
  $db->prepare("UPDATE otp_codes SET attempts=attempts+1 WHERE id=?")->execute([$o['id']]);
  if (!preg_match('/^[0-9]{6}$/', $code) || !password_verify($code, $o['code_hash'])) return false;
  $db->prepare("UPDATE otp_codes SET used=1 WHERE id=?")->execute([$o['id']]);
  return true;
}
