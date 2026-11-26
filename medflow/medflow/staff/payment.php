<?php
require '../includes/auth.php';
require '../includes/flow.php';
require_role(['cashier']);
$uid = (int)$_SESSION['user']['id'];
$vsId = (int)($_POST['vs'] ?? $_GET['vs'] ?? 0);

$q = $db->prepare("SELECT vs.*, st.name AS stage_name, st.role AS stage_role, v.queue_code, v.priority, v.emergency_reason, p.full_name
  FROM visit_stages vs JOIN stages st ON st.id=vs.stage_id JOIN visits v ON v.id=vs.visit_id JOIN patients p ON p.id=v.patient_id WHERE vs.id=?");
$q->execute([$vsId]); $c = $q->fetch();
if (!$c || $c['stage_role'] !== 'cashier') { set_flash('err', 'That patient is not in the cashier queue.'); header('Location: /medflow/staff/queue.php'); exit; }
$vid = (int)$c['visit_id']; $stage = $c['stage_name'];
$active = in_array($c['status'], ['waiting','in_service'], true);
$self = '/medflow/staff/payment.php?vs=' . $vsId;

if ($active && $c['status'] === 'waiting') {      // opening the page starts the cashier stage
  $db->prepare("UPDATE visit_stages SET status='in_service', started_at=?, updated_by=? WHERE id=? AND status='waiting'")->execute([date('Y-m-d H:i:s'), $uid, $vsId]);
}
$charges = unpaid_charges($db, $vid, gate_kinds($stage));
$total = 0.0; foreach ($charges as $ch) $total += (float)$ch['amount']; $total = round($total, 2);

function back_to(string $type, string $msg, string $url) { set_flash($type, $msg); header('Location: ' . $url); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $active) {
  csrf_check();
  $a = $_POST['action'] ?? '';
  if ($a === 'continue') {
    if ($charges) back_to('err', 'There are still charges to collect.', $self);
    $ok = run_tx($db, fn() => finish_stage($db, $c, $uid));
    back_to($ok ? 'ok' : 'err', $ok ? 'Done. The patient has moved on.' : 'Could not continue. Please try again.', '/medflow/staff/queue.php');
  }
  if (!$charges) back_to('err', 'Nothing to pay at this stage.', $self);
  $method = $_POST['method'] ?? ''; $ref = trim($_POST['reference'] ?? ''); $tender = null;
  if ($a === 'waive') {
    $method = 'waived';
    if (strlen($ref) < 5) back_to('err', 'Give a reason for waiving the charges (at least 5 characters).', $self);
  } else {
    if (!in_array($method, ['cash','mpesa','card','insurance'], true)) back_to('err', 'Choose how the patient is paying.', $self);
    if ($method === 'cash') {
      $tender = (float)($_POST['tendered'] ?? 0);
      if ($tender + 0.001 < $total) back_to('err', 'The cash received is less than the amount due (' . money($total) . ').', $self);
      $ref = null;
    } elseif ($method === 'mpesa') {
      $ref = strtoupper($ref);
      if (!preg_match('/^[A-Z0-9]{8,12}$/', $ref)) back_to('err', 'Enter the M-Pesa transaction code from the confirmation message (8 to 12 letters and numbers).', $self);
    } elseif (strlen($ref) < 4) {
      back_to('err', $method === 'card' ? 'Enter the card slip or approval number.' : 'Enter the insurance scheme and member number.', $self);
    }
  }
  $pid = 0;
  $ok = run_tx($db, function () use ($db, $vid, $stage, $method, $ref, $tender, $uid, $c, &$pid) {
    $pid = record_payment($db, $vid, $stage, $method, $ref ?: null, $tender, $uid);
    finish_stage($db, $c, $uid);
  });
  if (!$ok) back_to('err', 'Could not record the payment. Please try again.', $self);
  audit($db, $uid, $method === 'waived' ? 'charges_waived' : 'payment', $c['queue_code'] . ' ' . money($total) . ' ' . $method);
  header('Location: /medflow/payment_receipt.php?id=' . $pid); exit;
}

$title = 'Payment ' . $c['queue_code'];
require '../includes/header.php';
?>
<?php if ($c['priority'] === 'emergency'): ?><div class="emergency-banner"><b>EMERGENCY</b> <?= e($c['emergency_reason'] ?: '') ?></div><?php endif; ?>
<div class="card">
  <div class="qhead">
    <div>
      <h2 style="margin-bottom:2px"><?= e($c['full_name']) ?></h2>
      <p class="muted">Queue ID <b><?= e($c['queue_code']) ?></b> &middot; <?= e($stage) ?></p>
    </div>
    <a class="btn btn-alt" href="/medflow/staff/queue.php">Back to queue</a>
  </div>
  <?php if (!$active): ?><div class="msg">This payment stage is finished.</div><?php endif; ?>
</div>

<div class="card">
  <h3>Amount due</h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Item</th><th class="num">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($charges as $ch): ?><tr><td><?= e($ch['description']) ?></td><td class="num"><?= e(money($ch['amount'])) ?></td></tr><?php endforeach; ?>
    <?php if (!$charges): ?><tr><td colspan="2" class="empty">Nothing to collect at this stage.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><th>Total due</th><th class="num"><?= e(money($total)) ?></th></tr></tfoot>
  </table></div>
</div>

<?php if ($active && $charges): ?>
<div class="card">
  <h3>Take payment</h3>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>">
    <div class="form-row">
      <div><label>Payment method</label>
        <select name="method" id="payMethod"><option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="card">Card</option><option value="insurance">Insurance</option></select></div>
      <div id="rowTender"><label>Cash received (<?= e(CURRENCY) ?>)</label><input name="tendered" type="number" step="0.01" min="0" value="<?= e(number_format($total, 2, '.', '')) ?>"></div>
      <div id="rowRef" hidden><label id="refLabel">Reference</label><input name="reference" maxlength="100" placeholder="e.g. SGH7K2L9QX"></div>
    </div>
    <p class="muted"><small>M-Pesa: enter the transaction code from the confirmation message. In this prototype the code is recorded, not checked with Safaricom.</small></p>
    <button name="action" value="pay" class="btn-ok">Record payment of <?= e(money($total)) ?> and issue receipt</button>
  </form>
  <hr>
  <form method="post" onsubmit="return confirm('Waive these charges? This is recorded against your name.');">
    <?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="waive">
    <label>Waive the charges (for example a patient who cannot pay)</label>
    <input name="reference" maxlength="100" placeholder="Reason for waiving" required>
    <button class="btn-ghost">Waive charges</button>
  </form>
</div>
<?php elseif ($active): ?>
<div class="card"><form method="post"><?= csrf_field() ?><input type="hidden" name="vs" value="<?= $vsId ?>"><input type="hidden" name="action" value="continue"><button class="btn-ok">Continue</button></form></div>
<?php endif; ?>
<script>
(function () {
  var m = document.getElementById('payMethod'); if (!m) return;
  var t = document.getElementById('rowTender'), r = document.getElementById('rowRef'), l = document.getElementById('refLabel');
  var labels = { mpesa: 'M-Pesa transaction code', card: 'Card slip / approval number', insurance: 'Insurance scheme and member number' };
  function sync() { var v = m.value; t.hidden = v !== 'cash'; r.hidden = v === 'cash'; if (labels[v]) l.textContent = labels[v]; }
  m.addEventListener('change', sync); sync();
})();
</script>
<?php require '../includes/footer.php'; ?>
