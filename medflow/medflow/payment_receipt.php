<?php
require 'includes/auth.php';
require 'includes/billing.php';
$pid = (int)($_GET['id'] ?? 0);
$s = $db->prepare("SELECT pay.*, v.queue_code, v.id AS visit_id, p.full_name, p.phone, u.full_name AS cashier
  FROM payments pay JOIN visits v ON v.id=pay.visit_id JOIN patients p ON p.id=v.patient_id LEFT JOIN staff u ON u.id=pay.received_by WHERE pay.id=?");
$s->execute([$pid]); $pay = $s->fetch();
$role = $_SESSION['user']['role'] ?? '';
$isStaff = !empty($_SESSION['user']) && empty($_SESSION['user']['must_change']) && in_array($role, ['cashier','receptionist','admin'], true);
$isPatient = $pay && (($_SESSION['track'][(int)$pay['visit_id']] ?? 0) > time());
if (!$pay || (!$isStaff && !$isPatient)) { http_response_code(403); exit('Receipt not available.'); }

$items = $db->prepare("SELECT description, amount, status FROM charges WHERE payment_id=? ORDER BY id"); $items->execute([$pid]); $items = $items->fetchAll();
$bal = bill_summary($db, (int)$pay['visit_id']);
$methods = ['cash'=>'Cash','mpesa'=>'M-Pesa','card'=>'Card','insurance'=>'Insurance','waived'=>'Waived'];
$change = ($pay['method'] === 'cash' && $pay['tendered'] !== null) ? max(0, (float)$pay['tendered'] - (float)$pay['amount']) : null;
$download = !empty($_GET['download']);
if ($download) {
  header('Content-Type: text/html; charset=utf-8');
  header('Content-Disposition: attachment; filename="medflow-' . $pay['receipt_no'] . '.html"');
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payment receipt <?= e($pay['receipt_no']) ?> | MedFlow</title>
<style><?= file_get_contents(__DIR__ . '/assets/css/receipt.css') ?></style></head><body>
<?php if (!$download): ?>
<div class="toolbar no-print">
  <button onclick="window.print()">Print or save as PDF</button>
  <a class="tb" href="?id=<?= (int)$pid ?>&amp;download=1">Download file</a>
  <a class="tb" href="<?= $isStaff ? '/medflow/staff/queue.php' : '/medflow/track.php?code=' . e($pay['queue_code']) ?>">Back</a>
</div>
<?php endif; ?>
<div class="sheet">
  <div class="rhead">
    <div><h1><?= e(HOSPITAL_NAME) ?></h1><p>Payment receipt</p></div>
    <div class="rmeta"><b><?= e($pay['receipt_no']) ?></b><br><?= e(date('d M Y H:i', strtotime($pay['paid_at']))) ?></div>
  </div>
  <table class="kv">
    <tr><th>Patient</th><td><?= e($pay['full_name']) ?></td><th>Queue ID</th><td><?= e($pay['queue_code']) ?></td></tr>
    <tr><th>Paid for</th><td><?= e($pay['stage']) ?></td><th>Received by</th><td><?= e($pay['cashier'] ?? '-') ?></td></tr>
  </table>
  <h2>Items</h2>
  <table class="grid"><tr><th>Item</th><th>Amount</th></tr>
    <?php foreach ($items as $it): ?><tr><td><?= e($it['description']) ?></td><td><?= e(money($it['amount'])) ?></td></tr><?php endforeach; ?>
    <tr><th>Total</th><th><?= e(money($pay['amount'])) ?></th></tr></table>
  <table class="kv">
    <tr><th>Method</th><td><?= e($methods[$pay['method']] ?? $pay['method']) ?></td><th><?= $pay['method'] === 'waived' ? 'Reason' : 'Reference' ?></th><td><?= e($pay['reference'] ?? '-') ?></td></tr>
    <?php if ($pay['method'] === 'cash' && $pay['tendered'] !== null): ?>
      <tr><th>Cash received</th><td><?= e(money($pay['tendered'])) ?></td><th>Change given</th><td><?= e(money($change)) ?></td></tr>
    <?php endif; ?>
  </table>
  <p class="small">Balance still to pay on this visit: <b><?= e(money($bal['unpaid'])) ?></b></p>
  <div class="rfoot"><p class="small">Keep this receipt as proof of payment. It is produced by the MedFlow prototype.</p></div>
</div>
</body></html>
