<?php
require '../includes/auth.php';
require_role(['admin']);
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (($_POST['action'] ?? '') === 'add') {
    $name = trim($_POST['full_name']); $user = trim($_POST['username']);
    $role = $_POST['role']; $pass = $_POST['password'];
    $roles = ['receptionist','nurse','doctor','lab','pharmacy','admin'];
    if ($name === '' || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $user) || !in_array($role, $roles) || strlen($pass) < 8) {
      $msg = 'Check the details: username 3-30 letters/numbers, password at least 8 characters.';
    } else {
      try {
        $db->prepare("INSERT INTO staff (full_name,username,password_hash,role) VALUES (?,?,?,?)")
           ->execute([$name, $user, password_hash($pass, PASSWORD_DEFAULT), $role]);
        $msg = 'Staff account created.';
      } catch (PDOException $ex) { $msg = 'That username already exists.'; }
    }
  } elseif (($_POST['action'] ?? '') === 'toggle') {
    $id = (int)$_POST['id'];
    if ($id !== (int)$_SESSION['user']['id']) {            // admin cannot deactivate themselves
      $db->prepare("UPDATE staff SET active = 1 - active WHERE id=?")->execute([$id]);
    }
  }
}
$staff = $db->query("SELECT id,full_name,username,role,active FROM staff ORDER BY role,full_name")->fetchAll();
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Manage Staff</title></head>
<body style="font-family:sans-serif;max-width:800px;margin:30px auto;padding:0 12px">
<h2>Manage Staff</h2>
<p><a href="reports.php">Reports</a> | <a href="staff.php">Manage staff</a> | <a href="../logout.php">Logout</a></p>
<p style="color:#06c"><?= e($msg) ?></p>
<h3>Add staff</h3>
<form method="post">
  <input type="hidden" name="action" value="add">
  <input name="full_name" placeholder="Full name" required style="padding:8px;margin:4px 0">
  <input name="username" placeholder="Username" required style="padding:8px;margin:4px 0">
  <input name="password" type="password" placeholder="Password (8+ chars)" required style="padding:8px;margin:4px 0">
  <select name="role" style="padding:8px">
    <option>receptionist</option><option>nurse</option><option>doctor</option>
    <option>lab</option><option>pharmacy</option><option>admin</option>
  </select>
  <button style="padding:8px 16px">Add</button>
</form>
<h3>Staff accounts</h3>
<table border="1" cellpadding="8" cellspacing="0" width="100%">
<tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Action</th></tr>
<?php foreach ($staff as $s): ?>
<tr><td><?= e($s['full_name']) ?></td><td><?= e($s['username']) ?></td><td><?= e($s['role']) ?></td>
<td><?= $s['active'] ? 'Active' : 'Deactivated' ?></td>
<td><?php if ((int)$s['id'] !== (int)$_SESSION['user']['id']): ?>
<form method="post" style="display:inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $s['id'] ?>">
<button><?= $s['active'] ? 'Deactivate' : 'Activate' ?></button></form><?php else: ?>(you)<?php endif; ?></td></tr>
<?php endforeach; ?>
</table>
</body></html>