<?php
require 'includes/auth.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $s = $db->prepare("SELECT * FROM staff WHERE username=? AND active=1");
  $s->execute([trim($_POST['username'] ?? '')]);
  $u = $s->fetch();
  if ($u && $u['locked_until'] && strtotime($u['locked_until']) > time()) {
    $err = 'Too many wrong passwords. This account is locked for ' . LOCK_MINUTES . ' minutes.';
  } elseif ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
    session_regenerate_id(true);
    $db->prepare("UPDATE staff SET failed_logins=0, locked_until=NULL, last_login=NOW() WHERE id=?")->execute([$u['id']]);
    $_SESSION['user'] = ['id'=>(int)$u['id'], 'name'=>$u['full_name'], 'role'=>$u['role'], 'must_change'=>(int)$u['must_change_password']];
    $_SESSION['last'] = time();
    audit($db, (int)$u['id'], 'login', $u['username']);
    if ($u['must_change_password']) { header('Location: /medflow/change_password.php'); exit; }
    header('Location: /medflow/' . ($u['role']==='admin' ? 'admin/reports.php' : 'staff/dashboard.php'));
    exit;
  } else {
    if ($u) {
      $n = (int)$u['failed_logins'] + 1;
      if ($n >= MAX_LOGIN_TRIES) {
        $db->prepare("UPDATE staff SET failed_logins=0, locked_until=DATE_ADD(NOW(), INTERVAL " . (int)LOCK_MINUTES . " MINUTE) WHERE id=?")->execute([$u['id']]);
        audit($db, (int)$u['id'], 'account_locked', $u['username']);
      } else {
        $db->prepare("UPDATE staff SET failed_logins=? WHERE id=?")->execute([$n, $u['id']]);
      }
    }
    $err = 'Invalid username or password';
  }
}
$title = 'Staff login';
require 'includes/header.php';
?>
<div class="hero">
  <img src="/medflow/assets/img/ambulance.svg" alt="">
  <div>
    <h1>Smarter patient flow,<br>better healthcare</h1>
    <p>Know where you are in your hospital visit and how long it will take.</p>
  </div>
</div>

<div class="card narrow">
  <h2>Staff login</h2>
  <?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <label>Username</label><input name="username" required>
    <label>Password</label><input name="password" type="password" required>
    <button>Login</button>
  </form>
  <p><a href="track.php">Patient? Track your visit</a></p>
</div>

<?php require 'includes/footer.php'; ?>
