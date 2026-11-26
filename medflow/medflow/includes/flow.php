<?php
// Patient flow rules: doctor matching, payment gates, moving a patient to the next stage, emergencies.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/billing.php';

function stage_id(PDO $db, string $name): int {
  static $cache = [];
  if (!isset($cache[$name])) {
    $s = $db->prepare("SELECT id FROM stages WHERE name=?");
    $s->execute([$name]);
    $cache[$name] = (int)$s->fetchColumn();
  }
  return $cache[$name];
}

function notify(PDO $db, int $visitId, string $msg): void {
  $db->prepare("INSERT INTO notifications (visit_id,message) VALUES (?,?)")->execute([$visitId, $msg]);
}

function is_emergency(PDO $db, int $visitId): bool {
  $s = $db->prepare("SELECT priority FROM visits WHERE id=?"); $s->execute([$visitId]);
  return $s->fetchColumn() === 'emergency';
}

/* Doctor with the fewest waiting patients. Only active doctors who are on duty.
   Ties go to the doctor who has been free the longest. $specialtyId null = any specialty. */
function pick_doctor(PDO $db, ?int $specialtyId): ?int {
  $where = "s.role='doctor' AND s.active=1 AND s.on_duty=1"; $args = [];
  if ($specialtyId !== null) { $where .= " AND s.specialty_id=?"; $args[] = $specialtyId; }
  $sql = "SELECT s.id,
      (SELECT COUNT(*) FROM visit_stages vs WHERE vs.assigned_to=s.id AND vs.status IN ('waiting','in_service')) AS load_now,
      (SELECT MAX(v2.completed_at) FROM visit_stages v2 WHERE v2.updated_by=s.id AND v2.status='completed') AS last_done
    FROM staff s WHERE $where
    ORDER BY load_now ASC, (last_done IS NOT NULL) ASC, last_done ASC, s.id ASC LIMIT 1";
  $q = $db->prepare($sql); $q->execute($args);
  $id = $q->fetchColumn();
  return $id === false ? null : (int)$id;
}

/* First visit: a general doctor. If none is on duty, any doctor. (Only a doctor can refer to a specialist.) */
function match_doctor(PDO $db, int $specialtyId): ?int {
  $d = pick_doctor($db, $specialtyId);
  if ($d === null && $specialtyId !== 1) $d = pick_doctor($db, 1);
  if ($d === null) $d = pick_doctor($db, null);
  return $d;
}

/* Put the patient in a stage's queue (creates the row if the stage was optional). */
function queue_stage(PDO $db, int $visitId, string $stageName, ?int $assignTo = null): void {
  $sid = stage_id($db, $stageName); $now = date('Y-m-d H:i:s');
  $f = $db->prepare("SELECT id FROM visit_stages WHERE visit_id=? AND stage_id=? LIMIT 1");
  $f->execute([$visitId, $sid]); $id = $f->fetchColumn();
  if ($id) {
    $db->prepare("UPDATE visit_stages SET status='waiting', queued_at=?, started_at=NULL, completed_at=NULL, assigned_to=? WHERE id=?")
       ->execute([$now, $assignTo, $id]);
  } else {
    $db->prepare("INSERT INTO visit_stages (visit_id,stage_id,status,queued_at,assigned_to) VALUES (?,?,'waiting',?,?)")
       ->execute([$visitId, $sid, $now, $assignTo]);
  }
}

function skip_stage(PDO $db, int $visitId, string $stageName): void {
  $sid = stage_id($db, $stageName); $now = date('Y-m-d H:i:s');
  $db->prepare("UPDATE visit_stages SET status='skipped', queued_at=COALESCE(queued_at,?), completed_at=? WHERE visit_id=? AND stage_id=? AND status IN ('pending','waiting')")
     ->execute([$now, $now, $visitId, $sid]);
}

/* Payment gate: send the patient to the cashier if something is unpaid, otherwise straight on.
   Emergency patients are treated first and pay at the end (Settlement). */
function gate_to(PDO $db, int $visitId, string $payStage, string $nextStage): void {
  $due = unpaid_total($db, $visitId, gate_kinds($payStage));
  if ($due > 0 && !is_emergency($db, $visitId)) {
    queue_stage($db, $visitId, $payStage);
    notify($db, $visitId, 'Please go to the cashier and pay ' . money($due) . ' to continue.');
  } else {
    queue_stage($db, $visitId, $nextStage);
    notify($db, $visitId, 'You have moved to the next stage. Check your journey.');
  }
}

/* After registration (and phone verification): consultation fee, then triage. */
function start_journey(PDO $db, int $visitId): void {
  gate_to($db, $visitId, 'Consultation Payment', 'Nurse/Triage');
}

/* End of the visit: anything still unpaid goes to Settlement first. */
function finish_visit(PDO $db, int $visitId): void {
  $due = unpaid_total($db, $visitId);
  if ($due > 0) {
    queue_stage($db, $visitId, 'Settlement');
    notify($db, $visitId, 'Almost done. Please go to the cashier and settle ' . money($due) . ' to complete your visit.');
    return;
  }
  $db->prepare("UPDATE visits SET status='completed', completed_at=? WHERE id=?")->execute([date('Y-m-d H:i:s'), $visitId]);
  notify($db, $visitId, 'Your visit is complete. Your receipt is ready to view and download.');
}

/* Medicine prescribed: pay at the cashier, then pharmacy. No medicine: the visit can finish. */
function route_to_pharmacy_or_finish(PDO $db, int $visitId): void {
  $n = $db->prepare("SELECT COUNT(*) FROM prescriptions WHERE visit_id=?");
  $n->execute([$visitId]);
  if ((int)$n->fetchColumn() > 0) {
    gate_to($db, $visitId, 'Pharmacy Payment', 'Pharmacy');
  } else {
    skip_stage($db, $visitId, 'Pharmacy');
    finish_visit($db, $visitId);
  }
}

/* After the doctor (or specialist): tests go through the cashier to the laboratory, otherwise straight on. */
function route_after_consult(PDO $db, int $visitId): void {
  $n = $db->prepare("SELECT COUNT(*) FROM lab_orders WHERE visit_id=? AND status='ordered'");
  $n->execute([$visitId]);
  if ((int)$n->fetchColumn() > 0) {
    gate_to($db, $visitId, 'Lab Payment', 'Laboratory');
  } else {
    skip_stage($db, $visitId, 'Laboratory');
    skip_stage($db, $visitId, 'Results');
    route_to_pharmacy_or_finish($db, $visitId);
  }
}

/* Move the patient on after a stage is finished. */
function advance_after(PDO $db, int $visitId, string $done): void {
  $st = $db->prepare("SELECT * FROM visits WHERE id=?");
  $st->execute([$visitId]); $visit = $st->fetch();
  $moved = 'You have moved to the next stage. Check your journey.';
  switch ($done) {
    case 'Consultation Payment':
      queue_stage($db, $visitId, 'Nurse/Triage');
      notify($db, $visitId, $moved);
      break;
    case 'Nurse/Triage':
      $doc = match_doctor($db, (int)$visit['needed_specialty_id']);
      $db->prepare("UPDATE visits SET assigned_doctor_id=? WHERE id=?")->execute([$doc, $visitId]);
      queue_stage($db, $visitId, 'Doctor', $doc);
      notify($db, $visitId, $moved);
      break;
    case 'Doctor':
      $r = $db->prepare("SELECT * FROM referrals WHERE visit_id=? AND status='pending' ORDER BY id LIMIT 1");
      $r->execute([$visitId]); $ref = $r->fetch();
      if ($ref) {
        queue_stage($db, $visitId, 'Specialist', $ref['to_doctor_id'] !== null ? (int)$ref['to_doctor_id'] : null);
        notify($db, $visitId, 'Your doctor has referred you to a specialist. Check your journey.');
      } else {
        route_after_consult($db, $visitId);
      }
      break;
    case 'Specialist':
      $db->prepare("UPDATE referrals SET status='completed', completed_at=? WHERE visit_id=? AND status='pending'")
         ->execute([date('Y-m-d H:i:s'), $visitId]);
      route_after_consult($db, $visitId);
      break;
    case 'Lab Payment':
      queue_stage($db, $visitId, 'Laboratory');
      notify($db, $visitId, 'Payment received. Please go to the laboratory.');
      break;
    case 'Laboratory':
      $doc = $visit['assigned_doctor_id'] !== null ? (int)$visit['assigned_doctor_id'] : null;
      queue_stage($db, $visitId, 'Results', $doc);
      notify($db, $visitId, 'Your results are ready for your doctor to review. Check your journey.');
      break;
    case 'Results':
      route_to_pharmacy_or_finish($db, $visitId);
      break;
    case 'Pharmacy Payment':
      queue_stage($db, $visitId, 'Pharmacy');
      notify($db, $visitId, 'Payment received. Please go to the pharmacy.');
      break;
    case 'Pharmacy':
    case 'Settlement':
      finish_visit($db, $visitId);
      break;
  }
}

/* Tell the next waiting patient (same stage, same doctor) that they are next. Emergencies first. */
function notify_next(PDO $db, int $stageId, ?int $assignedTo): void {
  $nx = $db->prepare("SELECT vs.visit_id, st.name FROM visit_stages vs
      JOIN stages st ON st.id=vs.stage_id JOIN visits v ON v.id=vs.visit_id
      WHERE vs.stage_id=? AND vs.status='waiting' AND vs.assigned_to <=> ?
      ORDER BY (v.priority='emergency') DESC, vs.queued_at ASC, vs.id ASC LIMIT 1");
  $nx->execute([$stageId, $assignedTo]);
  if ($w = $nx->fetch()) {
    $msg = 'You are next at ' . $w['name'] . '. Please be ready.';
    $dup = $db->prepare("SELECT COUNT(*) FROM notifications WHERE visit_id=? AND message=?");
    $dup->execute([$w['visit_id'], $msg]);
    if (!$dup->fetchColumn()) notify($db, (int)$w['visit_id'], $msg);
  }
}

/* Finish the stage row and move the patient on. $c is the case row loaded by the staff page. */
function finish_stage(PDO $db, array $c, int $uid): void {
  $db->prepare("UPDATE visit_stages SET status='completed', completed_at=?, started_at=COALESCE(started_at,?), updated_by=? WHERE id=?")
     ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $uid, $c['id']]);
  advance_after($db, (int)$c['visit_id'], $c['stage_name']);
  notify_next($db, (int)$c['stage_id'], $c['assigned_to'] !== null ? (int)$c['assigned_to'] : null);
}

/* Laboratory "skip": no tests to do. Skip Laboratory and Results, then pharmacy or finish. */
function skip_lab(PDO $db, array $c, int $uid): void {
  $db->prepare("UPDATE visit_stages SET status='skipped', completed_at=?, updated_by=? WHERE id=?")
     ->execute([date('Y-m-d H:i:s'), $uid, $c['id']]);
  skip_stage($db, (int)$c['visit_id'], 'Results');
  route_to_pharmacy_or_finish($db, (int)$c['visit_id']);
  notify_next($db, (int)$c['stage_id'], null);
}

/* Run a block of database changes all-or-nothing. */
function run_tx(PDO $db, callable $fn): bool {
  try { $db->beginTransaction(); $fn(); $db->commit(); return true; }
  catch (Throwable $t) { if ($db->inTransaction()) $db->rollBack(); error_log($t->getMessage()); return false; }
}

function age_from_dob(?string $dob): ?int {
  if (!$dob) return null;
  try { return (new DateTime($dob))->diff(new DateTime('today'))->y; } catch (Throwable $t) { return null; }
}
