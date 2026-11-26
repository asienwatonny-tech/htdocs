<?php
require '../includes/auth.php';
require '../includes/flow.php';
require '../includes/timeline.php';
require_role(['nurse','doctor','lab','pharmacy']);
$uid = (int)$_SESSION['user']['id']; $role = $_SESSION['user']['role'];
$vsId = (int)($_POST['vs'] ?? $_GET['vs'] ?? 0);

$q = $db->prepare("SELECT vs.*, st.name AS stage_name, st.role AS stage_role,
    v.queue_code, v.priority, v.emergency_reason, v.needed_specialty_id, v.assigned_doctor_id, v.patient_id, v.follow_up,
    p.full_name, p.gender, p.date_of_birth
  FROM visit_stages vs JOIN stages st ON st.id=vs.stage_id
  JOIN visits v ON v.id=vs.visit_id JOIN patients p ON p.id=v.patient_id WHERE vs.id=?");
$q->execute([$vsId]); $c = $q->fetch();
// Only the matching role, and for doctors only the assigned doctor, may open the case.
if (!$c || $c['stage_role'] !== $role || ($c['assigned_to'] !== null && (int)$c['assigned_to'] !== $uid)) {
  set_flash('err', 'That patient is not in your queue.');
  header('Location: /medflow/staff/queue.php'); exit;
}
$vid = (int)$c['visit_id']; $stage = $c['stage_name'];
$active = in_array($c['status'], ['waiting','in_service'], true);
$self = '/medflow/staff/patient.php?vs=' . $vsId;

function back_to(string $type, string $msg, string $url) { set_flash($type, $msg); header('Location: ' . $url); exit; }
function num_or_null(string $raw, float $lo, float $hi, &$bad) {
  $raw = trim($raw);
  if ($raw === '') return null;
  if (!is_numeric($raw) || $raw < $lo || $raw > $hi) { $bad = true; return null; }
  return $raw + 0;
}
function one(PDO $db, string $sql, array $args) { $s = $db->prepare($sql); $s->execute($args); return $s->fetchColumn(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $a = $_POST['action'] ?? '';
  $allowed = [
    'nurse'    => ['start','vitals','emergency','complete'],
    'doctor'   => ['start','dx','dx_final','dx_del','lab_add','lab_del','rx_add','rx_del','refer','followup','complete'],
    'lab'      => ['start','lab_result','complete','skip'],
    'pharmacy' => ['start','dispense','dispense_all','complete'],
  ];
  if (!in_array($a, $allowed[$role], true)) back_to('err', 'That action is not allowed for your role.', $self);
  if (!$active) back_to('err', 'This stage is already finished.', $self);
  if ($a !== 'start' && $c['status'] !== 'in_service') back_to('err', 'Press Start first.', $self);

  /* ---- start ---- */
  if ($a === 'start') {
    if ($c['status'] === 'waiting') {
      $db->prepare("UPDATE visit_stages SET status='in_service', started_at=?, updated_by=?, assigned_to=COALESCE(assigned_to,?) WHERE id=? AND status='waiting'")
         ->execute([date('Y-m-d H:i:s'), $uid, $role === 'doctor' ? $uid : null, $vsId]);
      if ($role === 'doctor' && $stage === 'Doctor')
        $db->prepare("UPDATE visits SET assigned_doctor_id=COALESCE(assigned_doctor_id,?) WHERE id=?")->execute([$uid, $vid]);
    }
    back_to('ok', 'Started.', $self);
  }

  /* ---- nurse: measurements, allergies and anything else tested ---- */
  if ($a === 'vitals') {
    $bad = false;
    $f = [
      'weight_kg'     => num_or_null($_POST['weight_kg'] ?? '', 0.5, 400, $bad),
      'height_cm'     => num_or_null($_POST['height_cm'] ?? '', 30, 250, $bad),
      'bp_systolic'   => num_or_null($_POST['bp_systolic'] ?? '', 40, 300, $bad),
      'bp_diastolic'  => num_or_null($_POST['bp_diastolic'] ?? '', 20, 200, $bad),
      'temperature_c' => num_or_null($_POST['temperature_c'] ?? '', 30, 45, $bad),
      'pulse'         => num_or_null($_POST['pulse'] ?? '', 20, 250, $bad),
      'spo2'          => num_or_null($_POST['spo2'] ?? '', 50, 100, $bad),
    ];
    if ($bad) back_to('err', 'One of the measurements is outside a believable range. Please check the values.', $self);
    if (($f['bp_systolic'] === null) !== ($f['bp_diastolic'] === null)) back_to('err', 'Enter both blood pressure numbers (top and bottom).', $self);
    if ($f['bp_systolic'] !== null && $f['bp_systolic'] <= $f['bp_diastolic']) back_to('err', 'The top blood pressure number must be higher than the bottom one.', $self);
    $labels = is_array($_POST['obs_label'] ?? null) ? $_POST['obs_label'] : [];
    $vals = is_array($_POST['obs_value'] ?? null) ? $_POST['obs_value'] : [];
    $extras = [];
    foreach ($labels as $i => $lab) {
      $lab = trim((string)$lab); $val = trim((string)($vals[$i] ?? ''));
      if ($lab !== '' && $val !== '' && count($extras) < 10) $extras[] = [substr($lab, 0, 60), substr($val, 0, 100)];
    }
    $allergies = substr(trim($_POST['allergies'] ?? ''), 0, 255);
    if (count(array_filter($f, fn($x) => $x !== null)) === 0 && !$extras) back_to('err', 'Enter at least one measurement.', $self);
    $ok = run_tx($db, function () use ($db, $vid, $f, $extras, $uid, $allergies) {
      $db->prepare("INSERT INTO vitals (visit_id,weight_kg,height_cm,bp_systolic,bp_diastolic,temperature_c,pulse,spo2,complaint,allergies,notes,recorded_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
         ->execute([$vid, $f['weight_kg'], $f['height_cm'], $f['bp_systolic'], $f['bp_diastolic'], $f['temperature_c'], $f['pulse'], $f['spo2'],
                    substr(trim($_POST['complaint'] ?? ''), 0, 255) ?: null, $allergies ?: null, trim($_POST['notes'] ?? '') ?: null, $uid]);
      $ins = $db->prepare("INSERT INTO observations (visit_id,label,value,recorded_by) VALUES (?,?,?,?)");
      foreach ($extras as $x) $ins->execute([$vid, $x[0], $x[1], $uid]);
    });
    if ($ok) audit($db, $uid, 'vitals', $c['queue_code']);
    back_to($ok ? 'ok' : 'err', $ok ? 'Measurements saved. The doctor will see them.' : 'Could not save. Please try again.', $self);
  }
  /* nurse decides EMERGENCY only. Referral to a specialist is the doctor's decision. */
  if ($a === 'emergency') {
    $em = !empty($_POST['emergency']); $why = trim(substr($_POST['reason'] ?? '', 0, 150));
    if ($em && $why === '') back_to('err', 'Give the emergency reason.', $self);
    $db->prepare("UPDATE visits SET priority=?, emergency_reason=? WHERE id=?")->execute([$em ? 'emergency' : 'normal', $em ? $why : null, $vid]);
    audit($db, $uid, 'emergency_flag', $c['queue_code'] . ($em ? ' ON' : ' OFF'));
    back_to('ok', 'Emergency status saved.', $self);
  }

  /* ---- doctor: diagnosis (provisional or final), tests, medicine, referral, follow-up ---- */
  if ($a === 'dx') {
    $d = trim($_POST['diagnosis'] ?? '');
    $stt = ($_POST['dx_status'] ?? 'final') === 'provisional' ? 'provisional' : 'final';
    if ($d === '' || strlen($d) > 255) back_to('err', 'Enter the diagnosis (up to 255 characters).', $self);
    $db->prepare("INSERT INTO diagnoses (visit_id,stage_id,doctor_id,diagnosis,notes,status) VALUES (?,?,?,?,?,?)")
       ->execute([$vid, $c['stage_id'], $uid, $d, trim($_POST['notes'] ?? '') ?: null, $stt]);
    audit($db, $uid, 'diagnosis_' . $stt, $c['queue_code']);
    back_to('ok', ucfirst($stt) . ' diagnosis recorded.', $self);
  }
  if ($a === 'dx_final') {
    $db->prepare("UPDATE diagnoses SET status='final' WHERE id=? AND visit_id=? AND doctor_id=?")->execute([(int)($_POST['id'] ?? 0), $vid, $uid]);
    back_to('ok', 'Diagnosis marked as final.', $self);
  }
  if ($a === 'dx_del') {
    $db->prepare("DELETE FROM diagnoses WHERE id=? AND visit_id=? AND doctor_id=? AND stage_id=?")->execute([(int)($_POST['id'] ?? 0), $vid, $uid, $c['stage_id']]);
    back_to('ok', 'Diagnosis removed.', $self);
  }
  if ($a === 'lab_add') {
    $t = $db->prepare("SELECT * FROM lab_tests WHERE id=? AND active=1"); $t->execute([(int)($_POST['test_id'] ?? 0)]); $t = $t->fetch();
    $why = trim(substr($_POST['clinical_info'] ?? '', 0, 255));
    if (!$t) back_to('err', 'Choose a test from the list.', $self);
    if ($why === '') back_to('err', 'Write the reason for the test. Share only what the laboratory needs, not the full diagnosis.', $self);
    if (one($db, "SELECT COUNT(*) FROM lab_orders WHERE visit_id=? AND test_id=? AND status='ordered'", [$vid, $t['id']])) back_to('err', 'That test is already requested.', $self);
    $ok = run_tx($db, function () use ($db, $vid, $uid, $t, $why) {
      $db->prepare("INSERT INTO lab_orders (visit_id,ordered_by,test_id,test_name,instructions,clinical_info,price) VALUES (?,?,?,?,?,?,?)")
         ->execute([$vid, $uid, $t['id'], $t['name'], substr(trim($_POST['instructions'] ?? ''), 0, 255) ?: null, $why, $t['price']]);
      add_charge($db, $vid, 'lab', $t['name'], (float)$t['price'], 'lab_order', (int)$db->lastInsertId());
    });
    back_to($ok ? 'ok' : 'err', $ok ? 'Test requested. It is billed at the cashier before the laboratory.' : 'Could not save. Please try again.', $self);
  }
  if ($a === 'lab_del') {
    $oid = (int)($_POST['id'] ?? 0);
    $ch = charge_for_ref($db, $vid, 'lab_order', $oid);
    if ($ch && $ch['status'] !== 'unpaid') back_to('err', 'This test was already paid for at the cashier and cannot be removed here.', $self);
    run_tx($db, function () use ($db, $vid, $uid, $oid) {
      $db->prepare("DELETE FROM charges WHERE visit_id=? AND ref_table='lab_order' AND ref_id=? AND status='unpaid'")->execute([$vid, $oid]);
      $db->prepare("DELETE FROM lab_orders WHERE id=? AND visit_id=? AND ordered_by=? AND status='ordered'")->execute([$oid, $vid, $uid]);
    });
    back_to('ok', 'Test request removed.', $self);
  }
  if ($a === 'rx_add') {
    if (!one($db, "SELECT COUNT(*) FROM diagnoses WHERE visit_id=?", [$vid])) back_to('err', 'Record a diagnosis (provisional or final) before prescribing medicine.', $self);
    $m = $db->prepare("SELECT * FROM medicines WHERE id=? AND active=1"); $m->execute([(int)($_POST['medicine_id'] ?? 0)]); $m = $m->fetch();
    $qty = (int)($_POST['quantity'] ?? 0); $dose = trim($_POST['dosage'] ?? '');
    if (!$m) back_to('err', 'Choose a medicine from the list.', $self);
    if ($qty < 1 || $qty > 999) back_to('err', 'Enter how many units to dispense (1 to 999).', $self);
    if ($dose === '') back_to('err', 'Enter the dosage.', $self);
    run_tx($db, function () use ($db, $vid, $uid, $m, $qty, $dose) {
      $db->prepare("INSERT INTO prescriptions (visit_id,prescribed_by,medicine_id,medication,quantity,unit_price,dosage,instructions) VALUES (?,?,?,?,?,?,?,?)")
         ->execute([$vid, $uid, $m['id'], $m['name'], $qty, $m['unit_price'], substr($dose, 0, 100), substr(trim($_POST['instructions'] ?? ''), 0, 255) ?: null]);
      add_charge($db, $vid, 'medication', $m['name'] . ' x ' . $qty, (float)$m['unit_price'] * $qty, 'prescription', (int)$db->lastInsertId());
    });
    back_to('ok', 'Prescription added. It is billed at the cashier before the pharmacy.', $self);
  }
  if ($a === 'rx_del') {
    $rid = (int)($_POST['id'] ?? 0);
    $ch = charge_for_ref($db, $vid, 'prescription', $rid);
    if ($ch && $ch['status'] !== 'unpaid') back_to('err', 'This medicine was already paid for at the cashier and cannot be removed here.', $self);
    run_tx($db, function () use ($db, $vid, $uid, $rid) {
      $db->prepare("DELETE FROM charges WHERE visit_id=? AND ref_table='prescription' AND ref_id=? AND status='unpaid'")->execute([$vid, $rid]);
      $db->prepare("DELETE FROM prescriptions WHERE id=? AND visit_id=? AND prescribed_by=? AND dispensed=0")->execute([$rid, $vid, $uid]);
    });
    back_to('ok', 'Prescription removed.', $self);
  }
  if ($a === 'refer') {
    if ($stage !== 'Doctor') back_to('err', 'Referrals are made during the doctor consultation.', $self);
    $sp = (int)($_POST['specialty'] ?? 0); $why = trim(substr($_POST['reason'] ?? '', 0, 255));
    $mySpec = (int)one($db, "SELECT specialty_id FROM staff WHERE id=?", [$uid]);
    if (one($db, "SELECT COUNT(*) FROM referrals WHERE visit_id=? AND status='pending'", [$vid])) back_to('err', 'This patient already has a pending referral.', $self);
    if ($sp <= 0 || $sp === $mySpec) back_to('err', 'Choose a different specialty from your own.', $self);
    if ($why === '') back_to('err', 'Give the reason for the referral.', $self);
    $sr = $db->prepare("SELECT name,consult_fee FROM specialties WHERE id=?"); $sr->execute([$sp]); $sr = $sr->fetch();
    if (!$sr) back_to('err', 'Choose a valid specialty.', $self);
    $to = pick_doctor($db, $sp);
    if ($to === null) back_to('err', 'No doctor of that specialty is on duty right now. Ask the administrator to add one or set them on duty.', $self);
    run_tx($db, function () use ($db, $vid, $uid, $sp, $to, $why, $sr) {
      $db->prepare("INSERT INTO referrals (visit_id,from_doctor_id,to_specialty_id,to_doctor_id,reason) VALUES (?,?,?,?,?)")->execute([$vid, $uid, $sp, $to, $why]);
      add_charge($db, $vid, 'specialist', 'Specialist consultation (' . $sr['name'] . ')', (float)$sr['consult_fee'], 'referral', (int)$db->lastInsertId());
    });
    audit($db, $uid, 'referral', $c['queue_code']);
    back_to('ok', 'Referral created. When you complete this consultation the patient goes to the specialist with your findings.', $self);
  }
  if ($a === 'followup') {
    $db->prepare("UPDATE visits SET follow_up=? WHERE id=?")->execute([substr(trim($_POST['follow_up'] ?? ''), 0, 255) ?: null, $vid]);
    back_to('ok', 'Follow-up advice saved. It appears on the patient\'s receipt.', $self);
  }

  /* ---- laboratory ---- */
  if ($a === 'lab_result') {
    $r = trim($_POST['result'] ?? '');
    if ($r === '') back_to('err', 'Enter the result.', $self);
    $db->prepare("UPDATE lab_orders SET status='done', result=?, result_by=?, resulted_at=? WHERE id=? AND visit_id=? AND status='ordered'")
       ->execute([$r, $uid, date('Y-m-d H:i:s'), (int)($_POST['id'] ?? 0), $vid]);
    audit($db, $uid, 'lab_result', $c['queue_code']);
    back_to('ok', 'Result saved. The doctor will see it.', $self);
  }

  /* ---- pharmacy ---- */
  if ($a === 'dispense' || $a === 'dispense_all') {
    if ($a === 'dispense')
      $db->prepare("UPDATE prescriptions SET dispensed=1, dispensed_by=?, dispensed_at=? WHERE id=? AND visit_id=?")->execute([$uid, date('Y-m-d H:i:s'), (int)($_POST['id'] ?? 0), $vid]);
    else
      $db->prepare("UPDATE prescriptions SET dispensed=1, dispensed_by=?, dispensed_at=? WHERE visit_id=? AND dispensed=0")->execute([$uid, date('Y-m-d H:i:s'), $vid]);
    back_to('ok', 'Marked as dispensed.', $self);
  }

  /* ---- finish the stage ---- */
  if ($a === 'skip') {
    if (one($db, "SELECT COUNT(*) FROM lab_orders WHERE visit_id=?", [$vid])) back_to('err', 'This patient has test requests. Enter the results instead of skipping.', $self);
    $ok = run_tx($db, fn() => skip_lab($db, $c, $uid));
    back_to($ok ? 'ok' : 'err', $ok ? 'Laboratory skipped.' : 'Could not skip. Please try again.', '/medflow/staff/queue.php');
  }
  if ($a === 'complete') {
    $why = '';
    if ($stage === 'Nurse/Triage') {
      if (!one($db, "SELECT COUNT(*) FROM vitals WHERE visit_id=?", [$vid])) $why = 'Record the patient\'s measurements first.';
    } elseif ($stage === 'Doctor' || $stage === 'Specialist') {
      // Either order is fine: diagnose first, or request tests first and diagnose when the results are back.
      $labs = (int)one($db, "SELECT COUNT(*) FROM lab_orders WHERE visit_id=? AND status='ordered'", [$vid]);
      $final = (int)one($db, "SELECT COUNT(*) FROM diagnoses WHERE visit_id=? AND doctor_id=? AND stage_id=? AND status='final'", [$vid, $uid, $c['stage_id']]);
      $any = (int)one($db, "SELECT COUNT(*) FROM diagnoses WHERE visit_id=? AND doctor_id=? AND stage_id=?", [$vid, $uid, $c['stage_id']]);
      if ($labs === 0 && $final === 0) $why = $any ? 'Your diagnosis is only provisional. Mark it as final, or request tests, before completing.' : 'Record a diagnosis, or request tests first, before completing.';
    } elseif ($stage === 'Results') {
      if (!one($db, "SELECT COUNT(*) FROM diagnoses WHERE visit_id=? AND status='final'", [$vid])) $why = 'Record your final diagnosis from the test results before completing.';
    } elseif ($stage === 'Laboratory') {
      if (one($db, "SELECT COUNT(*) FROM lab_orders WHERE visit_id=? AND status='ordered'", [$vid])) $why = 'Some tests still have no result.';
    } elseif ($stage === 'Pharmacy') {
      if (one($db, "SELECT COUNT(*) FROM prescriptions WHERE visit_id=? AND dispensed=0", [$vid])) $why = 'Some medicines are not marked as dispensed yet.';
    }
    if ($why !== '') back_to('err', $why, $self);
    $ok = run_tx($db, fn() => finish_stage($db, $c, $uid));
    if ($ok) audit($db, $uid, 'complete_' . $stage, $c['queue_code']);
    back_to($ok ? 'ok' : 'err', $ok ? 'Stage completed. The patient has moved on.' : 'Could not complete. Please try again.', '/medflow/staff/queue.php');
  }
}

/* ---------- data for the page: each role only loads what it is allowed to see ---------- */
function rows(PDO $db, string $sql, array $args): array { $s = $db->prepare($sql); $s->execute($args); return $s->fetchAll(); }
$isDoc = $role === 'doctor';
$vitAll = ($isDoc || $role === 'nurse' || $role === 'pharmacy')
  ? rows($db, "SELECT vi.*, s.full_name AS nurse FROM vitals vi LEFT JOIN staff s ON s.id=vi.recorded_by WHERE vi.visit_id=? ORDER BY vi.id", [$vid]) : [];
$vit = $vitAll ? end($vitAll) : null;
$obs = ($isDoc || $role === 'nurse') ? rows($db, "SELECT label,value FROM observations WHERE visit_id=? ORDER BY id", [$vid]) : [];
$dx = $isDoc ? rows($db, "SELECT d.*, s.full_name AS doctor, st.name AS stage FROM diagnoses d JOIN staff s ON s.id=d.doctor_id LEFT JOIN stages st ON st.id=d.stage_id WHERE d.visit_id=? ORDER BY d.id", [$vid]) : [];
$labs = ($isDoc || $role === 'lab') ? rows($db, "SELECT l.*, s.full_name AS doctor FROM lab_orders l LEFT JOIN staff s ON s.id=l.ordered_by WHERE l.visit_id=? ORDER BY l.id", [$vid]) : [];
$rx = ($isDoc || $role === 'pharmacy') ? rows($db, "SELECT r.*, s.full_name AS doctor FROM prescriptions r JOIN staff s ON s.id=r.prescribed_by WHERE r.visit_id=? ORDER BY r.id", [$vid]) : [];
$refs = $isDoc ? rows($db, "SELECT r.*, sp.name AS specialty, f.full_name AS from_name, t.full_name AS to_name FROM referrals r
                   JOIN specialties sp ON sp.id=r.to_specialty_id JOIN staff f ON f.id=r.from_doctor_id LEFT JOIN staff t ON t.id=r.to_doctor_id
                   WHERE r.visit_id=? ORDER BY r.id", [$vid]) : [];
$specs = $db->query("SELECT id,name FROM specialties ORDER BY id")->fetchAll();
$tests = $isDoc ? $db->query("SELECT id,name FROM lab_tests WHERE active=1 ORDER BY name")->fetchAll() : [];
$meds  = $isDoc ? $db->query("SELECT id,name,unit FROM medicines WHERE active=1 ORDER BY name")->fetchAll() : [];
$history = $isDoc ? rows($db, "SELECT v.queue_code, v.created_at,
    (SELECT GROUP_CONCAT(d.diagnosis SEPARATOR '; ') FROM diagnoses d WHERE d.visit_id=v.id AND d.status='final') AS dx
  FROM visits v WHERE v.patient_id=? AND v.id<>? AND v.status='completed' ORDER BY v.id DESC LIMIT 5", [(int)$c['patient_id'], $vid]) : [];
$timeline = $isDoc ? care_timeline($vitAll, $dx, $labs, $rx, $refs) : [];
$mySpec = $isDoc ? (int)one($db, "SELECT specialty_id FROM staff WHERE id=?", [$uid]) : null;
$pendingRef = false; foreach ($refs as $r) if ($r['status'] === 'pending') $pendingRef = true;
$age = age_from_dob($c['date_of_birth']);
$inService = $c['status'] === 'in_service';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_SESSION['viewed'][$vsId])) {      // log that this case was opened (once per session)
  $_SESSION['viewed'][$vsId] = 1; audit($db, $uid, 'case_open', $c['queue_code'] . ' ' . $stage);
}
$title = 'Patient ' . $c['queue_code'];
require '../includes/header.php';
?>
<?php if ($c['priority'] === 'emergency'): ?>
  <div class="emergency-banner"><b>EMERGENCY</b> <?= e($c['emergency_reason'] ?: '') ?></div>
<?php endif; ?>

<div class="card">
  <div class="qhead">
    <div>
      <h2 style="margin-bottom:2px"><?= e($c['full_name']) ?></h2>
      <p class="muted">Queue ID <b><?= e($c['queue_code']) ?></b> &middot; Stage: <b><?= e($stage) ?></b>
        <?php if ($age !== null): ?>&middot; Age <?= (int)$age ?><?php endif; ?>
        <?php if ($c['gender']): ?>&middot; <?= e(ucfirst($c['gender'])) ?><?php endif; ?></p>
    </div>
    <div>
      <span class="badge b-<?= e($c['status']) ?>"><?= e(ucfirst(str_replace('_', ' ', $c['status']))) ?></span>
      <a class="btn btn-alt" href="/medflow/staff/queue.php">Back to queue</a>
    </div>
  </div>
  <?php if ($active && !$inService): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="start">
      <button class="btn-ok">Start <?= e($stage) ?></button></form>
  <?php elseif (!$active): ?>
    <div class="msg">This stage is finished. You can still read the record below.</div>
  <?php endif; ?>
</div>

<?php if ($role === 'lab'): ?>
  <div class="msg privacy">Confidential: you see only the tests requested and the reason the doctor chose to share. Diagnoses, measurements and notes are visible to doctors only.</div>
<?php elseif ($role === 'pharmacy'): ?>
  <div class="msg privacy">Confidential: you see only the prescription and the safety details below. Diagnoses and clinical notes are visible to doctors only.</div>
<?php endif; ?>

<?php if ($role === 'pharmacy'): ?>
<div class="card">
  <h3>Safety information</h3>
  <div class="stats">
    <div class="stat"><small>Weight</small><b><?= $vit && $vit['weight_kg'] !== null ? e($vit['weight_kg']) . ' <small>kg</small>' : '-' ?></b></div>
    <div class="stat<?= $vit && $vit['allergies'] ? ' hot' : '' ?>"><small>Known allergies</small><b style="font-size:1rem"><?= $vit && $vit['allergies'] ? e($vit['allergies']) : 'None recorded' ?></b></div>
  </div>
</div>
<?php endif; ?>

<?php if ($isDoc || $role === 'nurse'): ?>
<div class="card">
  <h3>Measurements from the nurse</h3>
  <?php if ($vit): ?>
    <div class="stats">
      <?php
      $cells = [['Weight', $vit['weight_kg'], 'kg'], ['Height', $vit['height_cm'], 'cm'],
                ['Blood pressure', $vit['bp_systolic'] !== null ? $vit['bp_systolic'] . '/' . $vit['bp_diastolic'] : null, 'mmHg'],
                ['Temperature', $vit['temperature_c'], '°C'], ['Pulse', $vit['pulse'], 'bpm'], ['Oxygen (SpO2)', $vit['spo2'], '%']];
      foreach ($cells as $cell): if ($cell[1] !== null): ?>
        <div class="stat"><small><?= e($cell[0]) ?></small><b><?= e($cell[1]) ?> <small><?= e($cell[2]) ?></small></b></div>
      <?php endif; endforeach; ?>
    </div>
    <?php if ($vit['complaint']): ?><p><b>Complaint:</b> <?= e($vit['complaint']) ?></p><?php endif; ?>
    <?php if ($vit['allergies']): ?><p><b>Known allergies:</b> <?= e($vit['allergies']) ?></p><?php endif; ?>
    <?php if ($vit['notes']): ?><p><b>Nurse notes:</b> <?= nl2br(e($vit['notes'])) ?></p><?php endif; ?>
    <?php if ($obs): ?><ul class="plain"><?php foreach ($obs as $o): ?><li><b><?= e($o['label']) ?>:</b> <?= e($o['value']) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php else: ?><p class="muted">No measurements recorded yet.</p><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($role === 'nurse' && $active && $inService): ?>
<div class="card">
  <h3>Record measurements</h3>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="vitals">
    <div class="form-row">
      <div><label>Weight (kg)</label><input name="weight_kg" inputmode="decimal"></div>
      <div><label>Height (cm)</label><input name="height_cm" inputmode="decimal"></div>
      <div><label>BP top (systolic)</label><input name="bp_systolic" inputmode="numeric"></div>
      <div><label>BP bottom (diastolic)</label><input name="bp_diastolic" inputmode="numeric"></div>
      <div><label>Temperature (°C)</label><input name="temperature_c" inputmode="decimal"></div>
      <div><label>Pulse (bpm)</label><input name="pulse" inputmode="numeric"></div>
      <div><label>Oxygen SpO2 (%)</label><input name="spo2" inputmode="numeric"></div>
    </div>
    <label>Main complaint</label><input name="complaint" maxlength="255" placeholder="e.g. Headache for three days">
    <label>Known allergies</label><input name="allergies" maxlength="255" placeholder="e.g. Penicillin, or leave blank if none">
    <label>Other tests done at this stage</label>
    <div id="obsRows">
      <div class="form-row obs-row"><div><input name="obs_label[]" placeholder="Test, e.g. Blood sugar" maxlength="60"></div><div><input name="obs_value[]" placeholder="Result, e.g. 5.6 mmol/L" maxlength="100"></div></div>
      <div class="form-row obs-row"><div><input name="obs_label[]" placeholder="Test, e.g. Malaria rapid test" maxlength="60"></div><div><input name="obs_value[]" placeholder="Result, e.g. Negative" maxlength="100"></div></div>
    </div>
    <p><button type="button" class="btn-ghost" id="addObs">+ Add another test</button></p>
    <label>Notes for the doctor</label><textarea name="notes" rows="3"></textarea>
    <button>Save measurements</button>
  </form>
</div>

<div class="card">
  <h3>Is this an emergency?</h3>
  <p class="muted">You decide only whether the patient is an emergency. Whether the patient needs a specialist is the doctor's decision.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="emergency">
    <label class="check"><input type="checkbox" name="emergency" value="1"<?= $c['priority'] === 'emergency' ? ' checked' : '' ?>> <b>Emergency</b></label>
    <div id="emergencyReason" hidden><label>Emergency reason</label><input name="reason" maxlength="150" value="<?= e($c['emergency_reason'] ?? '') ?>"></div>
    <button>Save</button>
  </form>
</div>
<?php endif; ?>

<?php if ($isDoc && $history): ?>
<div class="card">
  <h3>Previous visits</h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Visit</th><th>Final diagnosis</th></tr></thead>
    <tbody><?php foreach ($history as $h): ?><tr><td><?= e(date('d M Y', strtotime($h['created_at']))) ?></td><td><?= e($h['queue_code']) ?></td><td><?= e($h['dx'] ?: '-') ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($isDoc && $timeline): ?>
<div class="card">
  <h3>Timeline of this visit</h3>
  <p class="muted">In the order things happened.</p>
  <ol class="timeline">
    <?php foreach ($timeline as $t): ?>
      <li><span class="when"><?= e(date('H:i', strtotime($t[0]))) ?></span> <b><?= e($t[1]) ?></b> &ndash; <?= e($t[2]) ?></li>
    <?php endforeach; ?>
  </ol>
</div>
<?php endif; ?>

<?php if ($isDoc): ?>
<div class="card">
  <h3>Diagnoses</h3>
  <?php foreach ($dx as $d): ?>
    <div class="entry">
      <b><?= e($d['diagnosis']) ?></b> <span class="badge b-<?= $d['status'] === 'final' ? 'completed' : 'waiting' ?>"><?= e(ucfirst($d['status'])) ?></span>
      <small class="muted">(<?= e($d['doctor']) ?>, <?= e($d['stage'] ?? '') ?>, <?= e(date('d M H:i', strtotime($d['created_at']))) ?>)</small>
      <?php if ($d['notes']): ?><br><?= nl2br(e($d['notes'])) ?><?php endif; ?>
      <?php if ($active && $inService && (int)$d['doctor_id'] === $uid): ?>
        <?php if ($d['status'] === 'provisional'): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="dx_final"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn-ok">Mark as final</button></form>
        <?php endif; ?>
        <?php if ((int)$d['stage_id'] === (int)$c['stage_id']): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="dx_del"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn-ghost">Remove</button></form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; if (!$dx) echo '<p class="muted">No diagnosis recorded yet. You may diagnose now, or request tests first and diagnose from the results.</p>'; ?>
  <?php if ($active && $inService && in_array($stage, ['Doctor','Specialist','Results'], true)): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="dx">
      <label>Diagnosis</label><input name="diagnosis" maxlength="255" required placeholder="e.g. Uncomplicated malaria">
      <label>Type</label>
      <select name="dx_status"><option value="final"<?= $stage === 'Results' ? ' selected' : '' ?>>Final</option><option value="provisional"<?= $stage !== 'Results' ? ' selected' : '' ?>>Provisional (waiting for tests)</option></select>
      <label>Notes and treatment plan</label><textarea name="notes" rows="3"></textarea>
      <button>Add diagnosis</button></form>
  <?php endif; ?>
</div>

<?php if ($refs): ?>
<div class="card">
  <h3>Referral</h3>
  <?php foreach ($refs as $r): ?>
    <div class="entry"><b><?= e($r['specialty']) ?></b> &middot; <?= e($r['from_name']) ?> &rarr; <?= e($r['to_name'] ?? 'unassigned') ?>
      <span class="badge b-<?= $r['status'] === 'pending' ? 'waiting' : 'completed' ?>"><?= e(ucfirst($r['status'])) ?></span><br>Reason: <?= e($r['reason']) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($isDoc || $role === 'lab'): ?>
<div class="card">
  <h3>Laboratory tests</h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Test</th><th>Reason for the test</th><th>Status / result</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($labs as $l): ?>
      <tr>
        <td><b><?= e($l['test_name']) ?></b><?= $l['instructions'] ? '<br><small class="muted">' . e($l['instructions']) . '</small>' : '' ?></td>
        <td><?= e($l['clinical_info'] ?? '-') ?></td>
        <td><?php if ($l['status'] === 'done'): ?><span class="badge b-completed">Done</span><br><?= nl2br(e($l['result'])) ?>
            <?php elseif ($role === 'lab' && $active && $inService): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="lab_result"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                <textarea name="result" rows="2" placeholder="Result" required></textarea><button class="btn-ok">Save result</button></form>
            <?php else: ?><span class="badge b-waiting">Awaiting result</span><?php endif; ?></td>
        <td><?php if ($isDoc && $active && $inService && $l['status'] === 'ordered' && (int)$l['ordered_by'] === $uid): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="lab_del"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn-ghost">Remove</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; if (!$labs) echo '<tr><td colspan="4" class="empty">No tests requested.</td></tr>'; ?>
    </tbody></table></div>
  <?php if ($isDoc && $active && $inService): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="lab_add">
      <div class="form-row">
        <div><label>Request a test</label><select name="test_id" required><option value="">Choose a test</option><?php foreach ($tests as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div><label>Reason for the test (shared with the laboratory)</label><input name="clinical_info" maxlength="255" required placeholder="e.g. Fever for three days, suspected malaria"></div>
        <div><label>Instructions (optional)</label><input name="instructions" maxlength="255" placeholder="e.g. Fasting sample"></div>
      </div>
      <p class="muted"><small>Write only what the laboratory needs. They will not see your diagnosis or notes.</small></p>
      <button>Request test</button></form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isDoc || $role === 'pharmacy'): ?>
<div class="card">
  <h3>Prescriptions</h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Medicine</th><th>Quantity</th><th>Dosage</th><th>Instructions</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rx as $r): ?>
      <tr><td><b><?= e($r['medication']) ?></b></td><td><?= (int)$r['quantity'] ?></td><td><?= e($r['dosage']) ?></td><td><?= e($r['instructions'] ?? '') ?></td>
        <td><?= $r['dispensed'] ? '<span class="badge b-completed">Dispensed</span>' : '<span class="badge b-waiting">Not yet</span>' ?></td>
        <td>
        <?php if ($role === 'pharmacy' && $active && $inService && !$r['dispensed']): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="dispense"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn-ok">Dispensed</button></form>
        <?php elseif ($isDoc && $active && $inService && !$r['dispensed'] && (int)$r['prescribed_by'] === $uid): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="rx_del"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn-ghost">Remove</button></form>
        <?php endif; ?></td></tr>
    <?php endforeach; if (!$rx) echo '<tr><td colspan="6" class="empty">No medicine prescribed.</td></tr>'; ?>
    </tbody></table></div>
  <?php if ($role === 'pharmacy' && $active && $inService && array_filter($rx, fn($r) => !$r['dispensed'])): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="dispense_all"><button class="btn-alt">Mark all as dispensed</button></form>
  <?php endif; ?>
  <?php if ($isDoc && $active && $inService): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="rx_add">
      <div class="form-row">
        <div><label>Medicine</label><select name="medicine_id" required><option value="">Choose a medicine</option><?php foreach ($meds as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['name']) ?> (<?= e($m['unit']) ?>)</option><?php endforeach; ?></select></div>
        <div><label>Quantity to dispense</label><input name="quantity" type="number" min="1" max="999" value="1" required></div>
        <div><label>Dosage</label><input name="dosage" maxlength="100" required placeholder="e.g. 4 tablets twice daily for 3 days"></div>
        <div><label>Instructions</label><input name="instructions" maxlength="255" placeholder="e.g. After meals"></div>
      </div>
      <button>Add prescription</button></form>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isDoc && $active && $inService && $stage === 'Doctor' && !$pendingRef): ?>
<div class="card">
  <h3>Refer to a specialist</h3>
  <p class="muted">Only the doctor decides this. MedFlow picks the specialist on duty with the fewest patients. They will see your diagnosis, measurements and tests, and you can follow the patient's progress under Referrals. A specialist fee is added to the bill.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="refer">
    <div class="form-row">
      <div><label>Specialty</label><select name="specialty"><?php foreach ($specs as $s): if ((int)$s['id'] === $mySpec) continue; ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Reason</label><input name="reason" maxlength="255" required placeholder="e.g. Suspected glaucoma"></div>
    </div>
    <button class="btn-alt">Create referral</button></form>
</div>
<?php endif; ?>

<?php if ($isDoc && $active && $inService): ?>
<div class="card">
  <h3>Follow-up advice</h3>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="followup">
    <input name="follow_up" maxlength="255" value="<?= e($c['follow_up'] ?? '') ?>" placeholder="e.g. Review in 2 weeks, return sooner if fever continues">
    <button class="btn-alt">Save follow-up advice</button></form>
</div>
<?php endif; ?>

<?php if ($active && $inService): ?>
<div class="card finish">
  <h3>Finish this stage</h3>
  <p class="muted"><?php
    if ($stage === 'Nurse/Triage') echo 'The patient will be matched with the general doctor who has the fewest patients and moved to the doctor queue.';
    elseif ($stage === 'Doctor') echo 'The patient goes to the specialist if you referred them. Otherwise they pay for any tests and go to the laboratory, or go to pharmacy.';
    elseif ($stage === 'Specialist') echo 'The patient continues to the laboratory or pharmacy.';
    elseif ($stage === 'Laboratory') echo 'The patient goes back to the doctor with the results.';
    elseif ($stage === 'Results') echo 'The patient goes to pharmacy, or the visit ends if no medicine was prescribed.';
    else echo 'This completes the patient\'s visit and makes their receipt available.'; ?></p>
  <form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>">
    <button name="action" value="complete" class="btn-ok">Complete <?= e($stage) ?></button>
    <?php if ($role === 'lab' && !$labs): ?><button name="action" value="skip" class="btn-ghost">Skip (no tests)</button><?php endif; ?>
  </form>
</div>
<?php endif; ?>
<?php require '../includes/footer.php'; ?>
