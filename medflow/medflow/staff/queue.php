<?php
require '../includes/auth.php';
require_role(['cashier','nurse','doctor','lab','pharmacy']);
$uid = (int)$_SESSION['user']['id']; $role = $_SESSION['user']['role'];

// Patients waiting for my kind of stage. A doctor sees only patients assigned to them (or not yet assigned).
$q = $db->prepare("SELECT vs.id, vs.status, vs.queued_at, vs.assigned_to, v.queue_code, v.priority, v.emergency_reason,
    p.full_name, st.name AS stage
  FROM visit_stages vs JOIN stages st ON st.id=vs.stage_id
  JOIN visits v ON v.id=vs.visit_id JOIN patients p ON p.id=v.patient_id
  WHERE st.role=? AND vs.status IN ('waiting','in_service') AND (vs.assigned_to IS NULL OR vs.assigned_to=?)
  ORDER BY (v.priority='emergency') DESC, vs.queued_at ASC, vs.id ASC");
$q->execute([$role, $uid]); $list = $q->fetchAll();
$emergencies = count(array_filter($list, fn($r) => $r['priority'] === 'emergency'));
$labels = ['cashier'=>'Cashier','nurse'=>'Nurse / Triage','doctor'=>'Doctor','lab'=>'Laboratory','pharmacy'=>'Pharmacy'];
$page = $role === 'cashier' ? 'payment.php' : 'patient.php';
$title = 'My queue';
require '../includes/header.php';
?>
<?php if ($emergencies): ?>
  <div class="emergency-banner"><b><?= $emergencies ?> emergency patient<?= $emergencies > 1 ? 's' : '' ?></b> waiting. Emergency patients are listed first.</div>
<?php endif; ?>
<div class="card">
  <div class="qhead">
    <div>
      <h2><?= e($labels[$role] ?? ucfirst($role)) ?> queue</h2>
      <p class="muted">Signed in as <?= e($_SESSION['user']['name']) ?>. This list refreshes every 20 seconds.</p>
    </div>
    <div class="count"><?= count($list) ?><small>in queue</small></div>
  </div>

  <div class="table-wrap">
  <table>
    <thead><tr><th>#</th><th>Queue ID</th><th>Patient</th><th>Stage</th><th>Waiting since</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $i => $r): ?>
      <tr<?= $r['priority']==='emergency' ? ' class="emerg-row"' : '' ?>>
        <td><?= $i + 1 ?></td>
        <td><b><?= e($r['queue_code']) ?></b><?= $r['priority']==='emergency' ? ' <span class="badge b-emergency">Emergency</span>' : '' ?></td>
        <td><?= e($r['full_name']) ?><?= $r['priority']==='emergency' && $r['emergency_reason'] ? '<br><small class="muted">' . e($r['emergency_reason']) . '</small>' : '' ?></td>
        <td><?= e($r['stage']) ?></td>
        <td><?= e(date('H:i', strtotime($r['queued_at']))) ?></td>
        <td><span class="badge b-<?= e($r['status']) ?>"><?= e(ucfirst(str_replace('_',' ',$r['status']))) ?></span></td>
        <td><a class="btn" href="/medflow/staff/<?= $page ?>?vs=<?= (int)$r['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; if (!$list) echo '<tr><td colspan="7" class="empty">No patients waiting. New patients will appear here.</td></tr>'; ?>
    </tbody>
  </table>
  </div>
</div>
<script>setTimeout(function(){ location.reload(); }, 20000);</script>
<?php require '../includes/footer.php'; ?>
