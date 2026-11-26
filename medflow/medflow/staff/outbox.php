<?php
require '../includes/auth.php';
require_role(['receptionist','admin']);
$rows = $db->query("SELECT o.created_at, o.phone, o.message, v.queue_code FROM sms_outbox o LEFT JOIN visits v ON v.id=o.visit_id ORDER BY o.id DESC LIMIT 40")->fetchAll();
$title = 'SMS outbox';
require '../includes/header.php';
?>
<div class="card">
  <h2>SMS outbox</h2>
  <p class="muted">Every text message MedFlow sends (patient one-time codes and new staff passwords). No SMS company is connected in this prototype, so messages are listed here instead of being delivered. <?= SMS_DEMO ? 'Demo mode is ON.' : '' ?></p>
  <div class="table-wrap"><table>
    <thead><tr><th>Time</th><th>To</th><th>Queue ID</th><th>Message</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= e($r['created_at']) ?></td><td><?= e($r['phone']) ?></td><td><?= e($r['queue_code'] ?? '-') ?></td><td><?= e($r['message']) ?></td></tr>
    <?php endforeach; if (!$rows) echo '<tr><td colspan="4" class="empty">No messages yet.</td></tr>'; ?>
    </tbody></table></div>
</div>
<?php require '../includes/footer.php'; ?>
