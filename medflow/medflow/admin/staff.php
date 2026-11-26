<?php
require '../includes/auth.php';
require '../includes/sms.php';
require_role(['admin']);
$me = (int)$_SESSION['user']['id'];
$msg = ''; $ok = false; $newPass = null; $newUser = '';
$roles = ['receptionist' => 'Receptionist', 'cashier' => 'Cashier', 'nurse' => 'Nurse / Triage', 'doctor' => 'Doctor', 'lab' => 'Laboratory', 'pharmacy' => 'Pharmacy', 'admin' => 'Administrator'];
$specs = $db->query("SELECT id,name FROM specialties ORDER BY id")->fetchAll();
$specIds = array_map(fn($s) => (int)$s['id'], $specs);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = $_POST['action'] ?? '';
  if ($act === 'add') {
    $name = trim($_POST['full_name'] ?? ''); $user = trim($_POST['username'] ?? '');
    $role = $_POST['role'] ?? ''; $phone = trim($_POST['phone'] ?? '');
    $spec = $role === 'doctor' ? (int)($_POST['specialty'] ?? 1) : null;
    if ($name === '' || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $user) || !isset($roles[$role])) {
      $msg = 'Check the details: a name, a username of 3-30 letters, numbers or underscores, and a role.';
    } elseif ($role === 'doctor' && !in_array($spec, $specIds, true)) {
      $msg = 'Choose the doctor\'s specialty.';
    } elseif ($phone !== '' && !preg_match('/^[0-9+]{9,15}$/', $phone)) {
      $msg = 'Phone number must be 9 to 15 digits.';
    } else {
      $pass = make_password(12);
      try {
        $db->prepare("INSERT INTO staff (full_name,username,password_hash,role,specialty_id,phone,must_change_password) VALUES (?,?,?,?,?,?,1)")
           ->execute([$name, $user, password_hash($pass, PASSWORD_DEFAULT), $role, $spec, $phone ?: null]);
        $newPass = $pass; $newUser = $user; $ok = true;
        $msg = 'Account created for ' . $name . '. The password below is shown only once.';
        if ($phone !== '') send_sms($db, $phone, 'MedFlow account created. Username: ' . $user . ' Password: ' . $pass . ' You must change it when you first sign in.');
        audit($db, $me, 'staff_add', $user . ' (' . $role . ')');
      } catch (PDOException $ex) { $msg = 'That username already exists. Choose another.'; }
    }
  } elseif ($act === 'toggle' || $act === 'duty' || $act === 'reset') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id === $me) {
      $msg = 'Use the Password page to change your own password, and you cannot deactivate yourself.';
    } elseif ($act === 'toggle') {
      $db->prepare("UPDATE staff SET active = 1 - active WHERE id=?")->execute([$id]);
      audit($db, $me, 'staff_toggle', (string)$id);
    } elseif ($act === 'duty') {
      $db->prepare("UPDATE staff SET on_duty = 1 - on_duty WHERE id=? AND role='doctor'")->execute([$id]);
      audit($db, $me, 'staff_duty', (string)$id);
    } else {
      $g = $db->prepare("SELECT full_name,username,phone FROM staff WHERE id=?"); $g->execute([$id]); $t = $g->fetch();
      if ($t) {
        $pass = make_password(12);
        $db->prepare("UPDATE staff SET password_hash=?, must_change_password=1, failed_logins=0, locked_until=NULL WHERE id=?")
           ->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        $newPass = $pass; $newUser = $t['username']; $ok = true;
        $msg = 'New password generated for ' . $t['full_name'] . '. It is shown only once.';
        if ($t['phone']) send_sms($db, $t['phone'], 'MedFlow password reset. New password: ' . $pass . ' You must change it when you sign in.');
        audit($db, $me, 'staff_reset', $t['username']);
      }
    }
  }
}
$staff = $db->query("SELECT s.id,s.full_name,s.username,s.role,s.active,s.on_duty,s.must_change_password,sp.name AS specialty,
    (SELECT COUNT(*) FROM visit_stages vs WHERE vs.assigned_to=s.id AND vs.status IN ('waiting','in_service')) AS load_now
  FROM staff s LEFT JOIN specialties sp ON sp.id=s.specialty_id ORDER BY s.active DESC, s.role, s.full_name")->fetchAll();
$title = 'Manage staff';
require '../includes/header.php';
?>
<?php if ($newPass !== null): ?>
<div class="card pass-card">
  <h3>Temporary password for <b><?= e($newUser) ?></b></h3>
  <p class="pass"><?= e($newPass) ?></p>
  <p class="muted">Give this to the staff member safely. It is not stored anywhere readable and will not be shown again. They must choose their own password at first sign-in.</p>
</div>
<?php endif; ?>

<div class="card">
  <h2>Add staff</h2>
  <p class="muted">MedFlow generates a strong password for the new account. The staff member must change it the first time they sign in.</p>
  <?php if ($msg && $newPass === null): ?><div class="msg <?= $ok ? 'ok' : 'err' ?>"><?= e($msg) ?></div><?php elseif ($msg): ?><div class="msg ok"><?= e($msg) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="form-row">
      <div><label>Full name</label><input name="full_name" placeholder="e.g. Mary Achieng" required autocomplete="off"></div>
      <div><label>Username</label><input name="username" placeholder="e.g. mary_a" required autocomplete="off"></div>
      <div><label>Role</label>
        <select name="role" id="roleSel"><?php foreach ($roles as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div><label>Doctor specialty</label>
        <select name="specialty"><?php foreach ($specs as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Phone (optional)</label><input name="phone" inputmode="numeric" placeholder="to text the password"></div>
    </div>
    <p class="muted"><small>Specialty is used only for doctors, so patients can be matched to an optician, dentist and so on.</small></p>
    <button>Create account</button>
  </form>
</div>

<div class="card">
  <h2>Staff accounts <small class="muted">(<?= count($staff) ?>)</small></h2>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Specialty / duty</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($staff as $s): $self = (int)$s['id'] === $me; ?>
      <tr<?= $s['active'] ? '' : ' class="off"' ?>>
        <td><b><?= e($s['full_name']) ?></b></td>
        <td><?= e($s['username']) ?></td>
        <td><span class="role"><?= e($roles[$s['role']] ?? $s['role']) ?></span></td>
        <td><?php if ($s['role'] === 'doctor'): ?><?= e($s['specialty'] ?? 'General Medicine') ?><br>
              <span class="badge <?= $s['on_duty'] ? 'b-completed' : 'b-skipped' ?>"><?= $s['on_duty'] ? 'On duty' : 'Off duty' ?></span>
              <small class="muted"><?= (int)$s['load_now'] ?> waiting</small><?php else: ?>-<?php endif; ?></td>
        <td><span class="badge <?= $s['active'] ? 'b-completed' : 'b-skipped' ?>"><?= $s['active'] ? 'Active' : 'Deactivated' ?></span>
            <?= $s['must_change_password'] ? '<br><small class="muted">Must change password</small>' : '' ?></td>
        <td>
          <?php if (!$self): ?>
            <form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <button name="action" value="toggle" class="<?= $s['active'] ? 'btn-danger' : 'btn-ok' ?>"><?= $s['active'] ? 'Deactivate' : 'Activate' ?></button>
              <button name="action" value="reset" class="btn-ghost">Reset password</button>
              <?php if ($s['role'] === 'doctor'): ?><button name="action" value="duty" class="btn-ghost"><?= $s['on_duty'] ? 'Set off duty' : 'Set on duty' ?></button><?php endif; ?>
            </form>
          <?php else: ?><span class="muted">(you)</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php require '../includes/footer.php'; ?>
