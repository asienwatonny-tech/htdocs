<?php
require '../includes/auth.php';
require '../includes/flow.php';
require_role(['receptionist','admin']);
$msg = ''; $ok = false; $newCode = ''; $emerg = false;
$specs = $db->query("SELECT id,name FROM specialties ORDER BY id")->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $name = trim($_POST['full_name'] ?? ''); $phone = trim($_POST['phone'] ?? '');
  $emerg = !empty($_POST['emergency']); $reason = trim(substr($_POST['reason'] ?? '', 0, 150));
  $spec = (int)($_POST['specialty'] ?? 1);
  $gender = $_POST['gender'] ?? ''; $dob = trim($_POST['dob'] ?? '');
  $okSpec = in_array($spec, array_map(fn($s) => (int)$s['id'], $specs), true);
  if ($name === '') {
    $msg = 'Enter the patient\'s name.';
  } elseif ($phone === '' && !$emerg) {
    $msg = 'Enter a phone number. It is where the patient\'s one-time code is sent.';
  } elseif ($phone !== '' && !preg_match('/^[0-9+]{9,15}$/', $phone)) {
    $msg = 'Phone number must be 9 to 15 digits.';
  } elseif (!$okSpec) {
    $msg = 'Choose the type of care needed.';
  } elseif ($emerg && $reason === '') {
    $msg = 'Give the emergency reason (for example road accident).';
  } elseif ($dob !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || $dob > date('Y-m-d'))) {
    $msg = 'Check the date of birth.';
  } else {
    $genderVal = in_array($gender, ['female','male','other'], true) ? $gender : null;
    $ok = run_tx_register($db, $name, $phone, $genderVal, $dob ?: null, $spec, $emerg, $reason, (int)$_SESSION['user']['id'], $newCode);
    if (!$ok) $msg = 'Could not register the patient. Please try again.';
    else audit($db, (int)$_SESSION['user']['id'], 'register', $newCode . ($emerg ? ' EMERGENCY' : ''));
  }
}

function run_tx_register(PDO $db, $name, $phone, $gender, $dob, $spec, $emerg, $reason, $uid, &$code): bool {
  try {
    $db->beginTransaction();
    $db->prepare("INSERT INTO patients (full_name,phone,gender,date_of_birth) VALUES (?,?,?,?)")->execute([$name, $phone, $gender, $dob]);
    $pid = $db->lastInsertId();
    $db->prepare("INSERT INTO visits (patient_id,priority,emergency_reason,needed_specialty_id) VALUES (?,?,?,?)")
       ->execute([$pid, $emerg ? 'emergency' : 'normal', $emerg ? $reason : null, $spec]);
    $vid = (int)$db->lastInsertId();
    $code = 'Q-' . str_pad((string)$vid, 4, '0', STR_PAD_LEFT);
    $db->prepare("UPDATE visits SET queue_code=? WHERE id=?")->execute([$code, $vid]);
    $stages = $db->query("SELECT id,seq FROM stages WHERE optional=0 ORDER BY seq")->fetchAll();
    $ins = $db->prepare("INSERT INTO visit_stages (visit_id,stage_id,status,queued_at,started_at,completed_at,updated_by) VALUES (?,?,?,?,?,?,?)");
    $now = date('Y-m-d H:i:s');
    foreach ($stages as $st) {
      if ((int)$st['seq'] === 1)      $ins->execute([$vid, $st['id'], 'completed', $now, $now, $now, $uid]);
      elseif ((int)$st['seq'] === 2)  $ins->execute([$vid, $st['id'], 'waiting', $now, null, null, null]);
      else                            $ins->execute([$vid, $st['id'], 'pending', null, null, null, null]);
    }
    $db->commit();
    return true;
  } catch (Throwable $t) {
    if ($db->inTransaction()) $db->rollBack();
    error_log($t->getMessage());
    return false;
  }
}

$recent = $db->query("SELECT v.queue_code, v.priority, p.full_name, v.created_at FROM visits v JOIN patients p ON p.id=v.patient_id ORDER BY v.id DESC LIMIT 6")->fetchAll();
$title = 'Register patient';
require '../includes/header.php';
?>
<div class="card">
  <h2>Register patient</h2>
  <p class="muted">Create a visit and give the patient their Queue ID. The patient later proves who they are with a one-time code sent to their phone.</p>

  <?php if ($ok): ?>
    <div class="msg ok">
      Patient registered. Queue ID: <span class="qid"><?= e($newCode) ?></span><?= $emerg ? ' <span class="badge b-emergency">Emergency</span>' : '' ?><br>
      <small>Tell the patient to keep this Queue ID. To track their visit they enter it on the tracking page and receive a one-time code by SMS.</small>
    </div>
  <?php elseif ($msg): ?>
    <div class="msg err"><?= e($msg) ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <div class="form-row">
      <div><label>Full name</label><input name="full_name" placeholder="e.g. Jane Wanjiku" required></div>
      <div><label>Phone number</label><input name="phone" placeholder="e.g. 0712345678" inputmode="numeric"></div>
      <div><label>Date of birth (optional)</label><input name="dob" type="date" max="<?= e(date('Y-m-d')) ?>"></div>
      <div><label>Gender (optional)</label>
        <select name="gender"><option value="">Not stated</option><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option></select></div>
    </div>
    <label>Type of care needed</label>
    <select name="specialty">
      <?php foreach ($specs as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
    </select>
    <p class="muted"><small>MedFlow sends the patient to the doctor of this type who has the fewest patients waiting.</small></p>

    <label class="check"><input type="checkbox" name="emergency" value="1"> <b>Emergency</b> (accident, severe bleeding, difficulty breathing, and so on)</label>
    <div id="emergencyReason" hidden>
      <label>Emergency reason</label>
      <input name="reason" maxlength="150" placeholder="e.g. Road accident, head injury">
      <p class="muted"><small>Emergency patients are placed ahead of everyone else. A phone number is optional for them.</small></p>
    </div>
    <button>Register and create Queue ID</button>
  </form>
</div>

<div class="card">
  <h3>Recent registrations</h3>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Queue ID</th><th>Patient</th><th>Registered</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr><td><b><?= e($r['queue_code']) ?></b> <?= $r['priority']==='emergency' ? '<span class="badge b-emergency">Emergency</span>' : '' ?></td><td><?= e($r['full_name']) ?></td><td><?= e($r['created_at']) ?></td></tr>
    <?php endforeach; if (!$recent) echo '<tr><td colspan="3" class="empty">No patients registered yet</td></tr>'; ?>
    </tbody>
  </table>
  </div>
</div>
<?php require '../includes/footer.php'; ?>
