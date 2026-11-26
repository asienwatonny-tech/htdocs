<?php
require '../includes/auth.php';
require_role(['admin']);
$me = (int)$_SESSION['user']['id'];
$msg = ''; $ok = false;

function price_ok($v): ?float {
  $v = trim((string)$v);
  if ($v === '' || !is_numeric($v) || $v < 0 || $v > 1000000) return null;
  return round((float)$v, 2);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $a = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0); $price = price_ok($_POST['price'] ?? '');
  $name = trim($_POST['name'] ?? '');
  try {
    if ($a === 'fee') {
      if ($price === null) throw new InvalidArgumentException('Enter a valid price.');
      $db->prepare("UPDATE specialties SET consult_fee=? WHERE id=?")->execute([$price, $id]);
    } elseif ($a === 'lab_save') {
      if ($price === null) throw new InvalidArgumentException('Enter a valid price.');
      $db->prepare("UPDATE lab_tests SET price=?, active=? WHERE id=?")->execute([$price, empty($_POST['active']) ? 0 : 1, $id]);
    } elseif ($a === 'lab_add') {
      if ($name === '' || $price === null) throw new InvalidArgumentException('Enter the test name and a valid price.');
      $db->prepare("INSERT INTO lab_tests (name,price) VALUES (?,?)")->execute([substr($name, 0, 100), $price]);
    } elseif ($a === 'med_save') {
      if ($price === null) throw new InvalidArgumentException('Enter a valid price.');
      $db->prepare("UPDATE medicines SET unit_price=?, active=? WHERE id=?")->execute([$price, empty($_POST['active']) ? 0 : 1, $id]);
    } elseif ($a === 'med_add') {
      $unit = trim($_POST['unit'] ?? '') ?: 'tablet';
      if ($name === '' || $price === null) throw new InvalidArgumentException('Enter the medicine name and a valid price.');
      $db->prepare("INSERT INTO medicines (name,unit,unit_price) VALUES (?,?,?)")->execute([substr($name, 0, 100), substr($unit, 0, 20), $price]);
    }
    $ok = true; $msg = 'Saved. New prices apply to charges created from now on; existing bills keep the price they were created with.';
    audit($db, $me, 'price_' . $a, (string)($name ?: $id));
  } catch (InvalidArgumentException $ex) { $msg = $ex->getMessage(); }
  catch (PDOException $ex) { $msg = 'That name already exists.'; }
}
$fees = $db->query("SELECT id,name,consult_fee FROM specialties ORDER BY id")->fetchAll();
$tests = $db->query("SELECT * FROM lab_tests ORDER BY name")->fetchAll();
$meds = $db->query("SELECT * FROM medicines ORDER BY name")->fetchAll();
$title = 'Prices';
require '../includes/header.php';
?>
<div class="card">
  <h2>Prices</h2>
  <p class="muted">What patients are charged. The doctor picks tests and medicines from these lists, and the bill is created automatically.</p>
  <?php if ($msg): ?><div class="msg <?= $ok ? 'ok' : 'err' ?>"><?= e($msg) ?></div><?php endif; ?>
</div>

<div class="card">
  <h3>Consultation fees</h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Service</th><th>Fee (<?= e(CURRENCY) ?>)</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($fees as $f): ?>
      <tr><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="fee"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
        <td><b><?= e($f['name']) ?></b><?= (int)$f['id'] === 1 ? ' <small class="muted">(charged at registration)</small>' : ' <small class="muted">(charged when a doctor refers)</small>' ?></td>
        <td><input name="price" type="number" step="0.01" min="0" value="<?= e(number_format((float)$f['consult_fee'], 2, '.', '')) ?>"></td>
        <td><button class="btn-alt">Save</button></td></form></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

<div class="card">
  <h3>Laboratory tests</h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Test</th><th>Price (<?= e(CURRENCY) ?>)</th><th>Available</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tests as $t): ?>
      <tr<?= $t['active'] ? '' : ' class="off"' ?>><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="lab_save"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <td><b><?= e($t['name']) ?></b></td>
        <td><input name="price" type="number" step="0.01" min="0" value="<?= e(number_format((float)$t['price'], 2, '.', '')) ?>"></td>
        <td><label class="check"><input type="checkbox" name="active" value="1"<?= $t['active'] ? ' checked' : '' ?>> Yes</label></td>
        <td><button class="btn-alt">Save</button></td></form></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="lab_add">
    <div class="form-row"><div><label>New test</label><input name="name" maxlength="100" required placeholder="e.g. Cholesterol test"></div>
    <div><label>Price (<?= e(CURRENCY) ?>)</label><input name="price" type="number" step="0.01" min="0" required></div></div>
    <button>Add test</button></form>
</div>

<div class="card">
  <h3>Medicines</h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Medicine</th><th>Unit</th><th>Price per unit (<?= e(CURRENCY) ?>)</th><th>Available</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($meds as $m): ?>
      <tr<?= $m['active'] ? '' : ' class="off"' ?>><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="med_save"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
        <td><b><?= e($m['name']) ?></b></td><td><?= e($m['unit']) ?></td>
        <td><input name="price" type="number" step="0.01" min="0" value="<?= e(number_format((float)$m['unit_price'], 2, '.', '')) ?>"></td>
        <td><label class="check"><input type="checkbox" name="active" value="1"<?= $m['active'] ? ' checked' : '' ?>> Yes</label></td>
        <td><button class="btn-alt">Save</button></td></form></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="med_add">
    <div class="form-row"><div><label>New medicine</label><input name="name" maxlength="100" required placeholder="e.g. Doxycycline 100 mg"></div>
    <div><label>Unit</label><input name="unit" maxlength="20" placeholder="tablet, bottle, sachet"></div>
    <div><label>Price per unit (<?= e(CURRENCY) ?>)</label><input name="price" type="number" step="0.01" min="0" required></div></div>
    <button>Add medicine</button></form>
</div>
<?php require '../includes/footer.php'; ?>
