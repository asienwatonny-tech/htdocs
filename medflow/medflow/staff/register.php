<?php
require '../includes/auth.php';
require '../includes/flow.php';
require '../includes/sms.php';
require_role(['receptionist','admin']);
$uid = (int)$_SESSION['user']['id'];
$err = ''; $info = '';
$verifyCode = strtoupper(trim($_GET['verify'] ?? ''));
$doneCode   = strtoupper(trim($_GET['done'] ?? ''));

function visit_by_code(PDO $db, string $code) {
  $s = $db->prepare("SELECT v.id, v.queue_code, v.priority, v.verify_status, v.patient_id, p.full_name, p.phone
                     FROM visits v JOIN patients p ON p.id=v.patient_id WHERE v.queue_code=?");
  $s->execute([$code]); return $s->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = $_POST['action'] ?? 'register';

  /* ---- 1. register a visit ---- */
  if ($act === 'register') {
    $pid = (int)($_POST['patient_id'] ?? 0); $old = null;
    if ($pid > 0) { $g = $db->prepare("SELECT * FROM patients WHERE id=?"); $g->execute([$pid]); $old = $g->fetch() ?: null; }
    $name = $old ? $old['full_name'] : trim($_POST['full_name'] ?? '');
    $phone = $old ? $old['phone'] : trim($_POST['phone'] ?? '');
    $gender = $_POST['gender'] ?? ''; $dob = trim($_POST['dob'] ?? '');
    $emerg = !empty($_POST['emergency']); $reason = trim(substr($_POST['reason'] ?? '', 0, 150));
    $noPhone = !empty($_POST['no_phone']);
    if ($name === '')                                                     $err = 'Enter the patient\'s name.';
    elseif ($phone === '' && !$emerg && !$noPhone)                        $err = 'Enter a phone number, or tick "no working phone".';
    elseif ($phone !== '' && !preg_match('/^[0-9+]{9,15}$/', $phone))     $err = 'Phone number must be 9 to 15 digits.';
    elseif ($emerg && $reason === '')                                     $err = 'Give the emergency reason (for example road accident).';
    elseif (!$old && $dob !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || $dob > date('Y-m-d'))) $err = 'Check the date of birth.';
    else {
      $alreadyVerified = $old && $old['phone_verified_at'] && $phone !== '';
      $needVerify = !$emerg && !$noPhone && $phone !== '' && !$alreadyVerified;
      $code = ''; $vid = 0;
      $ok = run_tx($db, function () use ($db, $old, $name, $phone, $gender, $dob, $emerg, $reason, $needVerify, $uid, &$code, &$vid) {
        if ($old) { $patId = (int)$old['id']; }
        else {
          $gv = in_array($gender, ['female','male','other'], true) ? $gender : null;
          $db->prepare("INSERT INTO patients (full_name,phone,gender,date_of_birth) VALUES (?,?,?,?)")->execute([$name, $phone, $gv, $dob ?: null]);
          $patId = (int)$db->lastInsertId();
        }
        $db->prepare("INSERT INTO visits (patient_id,priority,emergency_reason,needed_specialty_id,verify_status) VALUES (?,?,?,1,?)")
           ->execute([$patId, $emerg ? 'emergency' : 'normal', $emerg ? $reason : null, $needVerify ? 'pending' : 'not_needed']);
        $vid = (int)$db->lastInsertId();
        $code = 'Q-' . str_pad((string)$vid, 4, '0', STR_PAD_LEFT);
        $db->prepare("UPDATE visits SET queue_code=? WHERE id=?")->execute([$code, $vid]);
        $now = date('Y-m-d H:i:s');
        $ins = $db->prepare("INSERT INTO visit_stages (visit_id,stage_id,status,queued_at,started_at,completed_at,updated_by) VALUES (?,?,?,?,?,?,?)");
        foreach ($db->query("SELECT id,name FROM stages WHERE optional=0 ORDER BY seq")->fetchAll() as $st) {
          if ($st['name'] === 'Registration') $ins->execute([$vid, $st['id'], 'completed', $now, $now, $now, $uid]);
          else                                $ins->execute([$vid, $st['id'], 'pending', null, null, null, null]);
        }
        $fee = $db->query("SELECT consult_fee FROM specialties WHERE id=1")->fetchColumn();
        add_charge($db, $vid, 'consultation', 'Consultation fee (General Medicine)', (float)$fee);
        if (!$needVerify) start_journey($db, $vid);
      });
      if (!$ok) { $err = 'Could not register the patient. Please try again.'; }
      else {
        audit($db, $uid, 'register', $code . ($emerg ? ' EMERGENCY' : '') . ($noPhone ? ' no-phone' : ''));
        if ($needVerify) {
          $otp = issue_otp($db, $vid, $phone, 'register');
          if (SMS_DEMO) $_SESSION['demo_otp'][$code] = $otp;
          header('Location: /medflow/staff/register.php?verify=' . urlencode($code)); exit;
        }
        header('Location: /medflow/staff/register.php?done=' . urlencode($code)); exit;
      }
    }
  }

  /* ---- 2. verify the phone number with the code the patient received ---- */
  if ($act === 'verify' || $act === 'resend' || $act === 'skipverify') {
    $verifyCode = strtoupper(trim($_POST['code'] ?? ''));
    $v = visit_by_code($db, $verifyCode);
    if (!$v || $v['verify_status'] !== 'pending') { $err = 'That visit is not waiting for phone verification.'; $verifyCode = ''; }
    elseif ($act === 'verify') {
      if (verify_otp($db, (int)$v['id'], trim($_POST['otp'] ?? ''), 'register')) {
        $ok = run_tx($db, function () use ($db, $v) {
          $db->prepare("UPDATE visits SET verify_status='verified' WHERE id=?")->execute([$v['id']]);
          $db->prepare("UPDATE patients SET phone_verified_at=NOW() WHERE id=?")->execute([$v['patient_id']]);
          start_journey($db, (int)$v['id']);
        });
        if ($ok) { unset($_SESSION['demo_otp'][$v['queue_code']]); audit($db, $uid, 'phone_verified', $v['queue_code']);
                   header('Location: /medflow/staff/register.php?done=' . urlencode($v['queue_code'])); exit; }
        $err = 'Could not continue. Please try again.';
      } else { $err = 'That code is not correct or has expired. Ask the patient to read it again, or send a new code.'; }
    } elseif ($act === 'resend') {
      if (($w = otp_cooldown_left($db, (int)$v['id'], 'register')) > 0) $err = 'Please wait ' . $w . ' seconds before sending another code.';
      else { $otp = issue_otp($db, (int)$v['id'], $v['phone'], 'register'); if (SMS_DEMO) $_SESSION['demo_otp'][$v['queue_code']] = $otp; $info = 'A new code was sent.'; }
    } else {   // skipverify: the patient cannot receive the code
      $ok = run_tx($db, function () use ($db, $v) {
        $db->prepare("UPDATE visits SET verify_status='not_needed' WHERE id=?")->execute([$v['id']]);
        start_journey($db, (int)$v['id']);
      });
      if ($ok) { audit($db, $uid, 'verification_skipped', $v['queue_code']);
                 header('Location: /medflow/staff/register.php?done=' . urlencode($v['queue_code'])); exit; }
      $err = 'Could not continue. Please try again.';
    }
  }
}

/* ---- data for the page ---- */
$pending = $verifyCode !== '' ? visit_by_code($db, $verifyCode) : null;
if ($pending && $pending['verify_status'] !== 'pending') $pending = null;
$done = $doneCode !== '' ? visit_by_code($db, $doneCode) : null;
$prefill = null;
if ($err && !empty($_POST['patient_id'])) $_GET['pid'] = $_POST['patient_id'];
if (!empty($_GET['pid'])) { $g = $db->prepare("SELECT * FROM patients WHERE id=?"); $g->execute([(int)$_GET['pid']]); $prefill = $g->fetch() ?: null; }
$search = trim($_GET['q'] ?? ''); $found = [];
if (strlen($search) >= 2) {
  $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $search) . '%';
  $f = $db->prepare("SELECT id,full_name,phone,date_of_birth,phone_verified_at FROM patients WHERE full_name LIKE ? OR phone LIKE ? ORDER BY id DESC LIMIT 6");
  $f->execute([$like, $like]); $found = $f->fetchAll();
}
$recent = $db->query("SELECT v.queue_code, v.priority, v.verify_status, p.full_name, v.created_at FROM visits v JOIN patients p ON p.id=v.patient_id ORDER BY v.id DESC LIMIT 6")->fetchAll();
$fee = (float)$db->query("SELECT consult_fee FROM specialties WHERE id=1")->fetchColumn();
$title = 'Register patient';
require '../includes/header.php';
?>
<?php if ($done): ?>
  <div class="card">
    <div class="msg ok">
      Patient registered. Queue ID: <span class="qid"><?= e($done['queue_code']) ?></span>
      <?= $done['priority'] === 'emergency' ? ' <span class="badge b-emergency">Emergency</span>' : '' ?><br>
      <small><?= $done['priority'] === 'emergency'
        ? 'Emergency: the patient goes straight to the nurse. Payment is collected at the end of the visit.'
        : 'Next step: send the patient to the cashier to pay the consultation fee, then to the nurse. To track their visit they enter the Queue ID on the tracking page and receive a one-time code by SMS.' ?></small>
    </div>
    <p><a class="btn" href="/medflow/staff/register.php">Register another patient</a></p>
  </div>
<?php endif; ?>

<?php if ($pending): ?>
  <div class="card">
    <h2>Verify the patient's phone number</h2>
    <p class="muted">A 6-digit code was sent to <b><?= e($pending['phone']) ?></b> for <b><?= e($pending['full_name']) ?></b> (<?= e($pending['queue_code']) ?>).
      Ask the patient to read it out. This proves the number belongs to the patient, so their one-time tracking codes reach the right person.</p>
    <?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>
    <?php if ($info): ?><div class="msg ok"><?= e($info) ?></div><?php endif; ?>
    <?php if (SMS_DEMO && !empty($_SESSION['demo_otp'][$pending['queue_code']])): ?>
      <div class="demo-box">Demo mode: the code sent is <b><?= e($_SESSION['demo_otp'][$pending['queue_code']]) ?></b></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="code" value="<?= e($pending['queue_code']) ?>">
      <label>Code the patient received</label>
      <input name="otp" class="otp" maxlength="6" inputmode="numeric" placeholder="000000" required autofocus>
      <button>Verify phone and continue</button>
    </form>
    <form method="post" class="actions">
      <?= csrf_field() ?><input type="hidden" name="code" value="<?= e($pending['queue_code']) ?>">
      <button name="action" value="resend" class="btn-ghost">Send a new code</button>
      <button name="action" value="skipverify" class="btn-ghost" onclick="return confirm('Continue without verifying? Use this only if the patient cannot receive the SMS.');">Patient cannot receive the code</button>
    </form>
  </div>
<?php else: ?>
<div class="card">
  <h2>Register patient</h2>
  <p class="muted">Search first so a returning patient is not registered twice.</p>
  <form method="get" class="inline-search">
    <input name="q" value="<?= e($search) ?>" placeholder="Search by name or phone number" minlength="2">
    <button class="btn-alt">Search</button>
  </form>
  <?php if ($search !== ''): ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Name</th><th>Phone</th><th>Phone verified</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($found as $p): ?>
        <tr><td><b><?= e($p['full_name']) ?></b></td><td><?= e($p['phone']) ?></td>
          <td><?= $p['phone_verified_at'] ? 'Yes' : 'No' ?></td>
          <td><a class="btn" href="?pid=<?= (int)$p['id'] ?>">New visit for this patient</a></td></tr>
      <?php endforeach; if (!$found) echo '<tr><td colspan="4" class="empty">No match. Register a new patient below.</td></tr>'; ?>
      </tbody></table></div>
  <?php endif; ?>

  <?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="register">
    <?php if ($prefill): ?>
      <input type="hidden" name="patient_id" value="<?= (int)$prefill['id'] ?>">
      <div class="msg">Returning patient: <b><?= e($prefill['full_name']) ?></b> (<?= e($prefill['phone']) ?>)
        <?= $prefill['phone_verified_at'] ? ' &middot; phone already verified' : '' ?>
        &middot; <a href="/medflow/staff/register.php">choose someone else</a></div>
    <?php else: ?>
      <div class="form-row">
        <div><label>Full name</label><input name="full_name" placeholder="e.g. Jane Wanjiku" required></div>
        <div><label>Phone number</label><input name="phone" placeholder="e.g. 0712345678" inputmode="numeric"></div>
        <div><label>Date of birth (optional)</label><input name="dob" type="date" max="<?= e(date('Y-m-d')) ?>"></div>
        <div><label>Gender (optional)</label>
          <select name="gender"><option value="">Not stated</option><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option></select></div>
      </div>
      <label class="check"><input type="checkbox" name="no_phone" value="1"> Patient has no working phone (the number cannot be verified and tracking by SMS will not be possible)</label>
    <?php endif; ?>

    <p class="muted"><small>Every patient first sees a general doctor. Only the doctor decides whether to refer to a specialist. Consultation fee: <b><?= e(money($fee)) ?></b>, paid at the cashier.</small></p>
    <label class="check"><input type="checkbox" name="emergency" value="1"> <b>Emergency</b> (accident, severe bleeding, difficulty breathing, and so on)</label>
    <div id="emergencyReason" hidden>
      <label>Emergency reason</label>
      <input name="reason" maxlength="150" placeholder="e.g. Road accident, head injury">
      <p class="muted"><small>Emergency patients go straight to the nurse and ahead of everyone else. Payment and phone verification are not required first.</small></p>
    </div>
    <button>Register and create Queue ID</button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h3>Recent registrations</h3>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Queue ID</th><th>Patient</th><th>Phone</th><th>Registered</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr><td><b><?= e($r['queue_code']) ?></b> <?= $r['priority']==='emergency' ? '<span class="badge b-emergency">Emergency</span>' : '' ?></td>
        <td><?= e($r['full_name']) ?></td>
        <td><?= $r['verify_status']==='pending' ? '<a href="?verify=' . e($r['queue_code']) . '">Awaiting verification</a>' : ($r['verify_status']==='verified' ? 'Verified' : '-') ?></td>
        <td><?= e($r['created_at']) ?></td></tr>
    <?php endforeach; if (!$recent) echo '<tr><td colspan="4" class="empty">No patients registered yet</td></tr>'; ?>
    </tbody>
  </table>
  </div>
</div>
<?php require '../includes/footer.php'; ?>
