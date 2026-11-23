<?php
require '../includes/auth.php';
require_role(['receptionist','nurse','doctor','lab','pharmacy']);
$uid = (int)$_SESSION['user']['id']; $role = $_SESSION['user']['role'];
$labels = ['receptionist'=>'Reception','nurse'=>'Nurse / Triage','doctor'=>'Doctor','lab'=>'Laboratory','pharmacy'=>'Pharmacy'];

// My numbers: stages I completed (registration counts for reception), and how long each took.
$m = $db->prepare("SELECT COUNT(*) AS total,
    COALESCE(SUM(DATE(completed_at)=CURDATE()),0) AS today,
    ROUND(AVG(TIMESTAMPDIFF(SECOND,started_at,completed_at))/60,1) AS avg_all,
    ROUND(AVG(CASE WHEN DATE(completed_at)=CURDATE() THEN TIMESTAMPDIFF(SECOND,started_at,completed_at) END)/60,1) AS avg_today
  FROM visit_stages WHERE updated_by=? AND status='completed'");
$m->execute([$uid]); $mine = $m->fetch();

$recent = $db->prepare("SELECT v.queue_code, p.full_name, st.name AS stage, vs.started_at, vs.completed_at,
    TIMESTAMPDIFF(MINUTE,vs.started_at,vs.completed_at) AS mins
  FROM visit_stages vs JOIN stages st ON st.id=vs.stage_id JOIN visits v ON v.id=vs.visit_id JOIN patients p ON p.id=v.patient_id
  WHERE vs.updated_by=? AND vs.status='completed' ORDER BY vs.completed_at DESC LIMIT 8");
$recent->execute([$uid]); $recent = $recent->fetchAll();

$inq = 0; $emerg = 0; $preview = [];
if ($role !== 'receptionist') {
  $q = $db->prepare("SELECT vs.id, v.queue_code, v.priority, p.full_name, st.name AS stage, vs.status, vs.queued_at
    FROM visit_stages vs JOIN stages st ON st.id=vs.stage_id JOIN visits v ON v.id=vs.visit_id JOIN patients p ON p.id=v.patient_id
    WHERE st.role=? AND vs.status IN ('waiting','in_service') AND (vs.assigned_to IS NULL OR vs.assigned_to=?)
    ORDER BY (v.priority='emergency') DESC, vs.queued_at ASC");
  $q->execute([$role, $uid]); $all = $q->fetchAll();
  $inq = count($all); $emerg = count(array_filter($all, fn($r) => $r['priority'] === 'emergency'));
  $preview = array_slice($all, 0, 5);
} else {
  $active = $db->query("SELECT v.queue_code, v.priority, v.status, p.full_name,
      (SELECT st.name FROM visit_stages vs JOIN stages st ON st.id=vs.stage_id
        WHERE vs.visit_id=v.id AND vs.status IN ('waiting','in_service') ORDER BY st.seq LIMIT 1) AS at_stage
    FROM visits v JOIN patients p ON p.id=v.patient_id
    WHERE v.status='active' OR v.completed_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
    ORDER BY (v.status='active') DESC, v.id DESC LIMIT 15")->fetchAll();
  $inq = (int)$db->query("SELECT COUNT(*) FROM visits WHERE status='active'")->fetchColumn();
  $emerg = (int)$db->query("SELECT COUNT(*) FROM visits WHERE status='active' AND priority='emergency'")->fetchColumn();
}
$refPending = 0;
if ($role === 'doctor') {
  $r = $db->prepare("SELECT COUNT(*) FROM referrals WHERE from_doctor_id=? AND status='pending'"); $r->execute([$uid]); $refPending = (int)$r->fetchColumn();
}
$title = 'Dashboard';
require '../includes/header.php';
?>
<?php if ($emerg): ?>
  <div class="emergency-banner"><b><?= $emerg ?> emergency patient<?= $emerg > 1 ? 's' : '' ?></b> <?= $role === 'receptionist' ? 'in the hospital now.' : 'waiting for you. They are at the top of your queue.' ?></div>
<?php endif; ?>

<div class="card">
  <h2><?= e($labels[$role]) ?> dashboard</h2>
  <p class="muted">Welcome, <?= e($_SESSION['user']['name']) ?>.</p>
  <div class="stats">
    <div class="stat hot"><small><?= $role === 'receptionist' ? 'Active visits' : 'In my queue now' ?></small><b><?= $inq ?></b></div>
    <div class="stat"><small><?= $role === 'receptionist' ? 'Registered today' : 'Attended today' ?></small><b><?= (int)$mine['today'] ?></b></div>
    <div class="stat"><small><?= $role === 'receptionist' ? 'Registered in total' : 'Attended in total' ?></small><b><?= (int)$mine['total'] ?></b></div>
    <?php if ($role !== 'receptionist'): ?>
      <div class="stat"><small>Avg time per patient today</small><b><?= $mine['avg_today'] !== null ? e($mine['avg_today']) . ' min' : '-' ?></b></div>
      <div class="stat"><small>Avg time per patient overall</small><b><?= $mine['avg_all'] !== null ? e($mine['avg_all']) . ' min' : '-' ?></b></div>
    <?php endif; ?>
    <?php if ($role === 'doctor'): ?>
      <div class="stat"><small>My referrals pending</small><b><?= $refPending ?></b></div>
    <?php endif; ?>
  </div>
  <p>
    <?php if ($role === 'receptionist'): ?><a class="btn" href="/medflow/staff/register.php">Register a patient</a>
    <?php else: ?><a class="btn" href="/medflow/staff/queue.php">Open my queue</a><?php endif; ?>
    <?php if ($role === 'doctor'): ?><a class="btn btn-alt" href="/medflow/staff/referrals.php">Referred patients</a><?php endif; ?>
  </p>
</div>

<?php if ($role === 'receptionist'): ?>
<div class="card">
  <h3>Patients in the hospital</h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Queue ID</th><th>Patient</th><th>Now at</th><th>Receipt</th></tr></thead>
    <tbody>
    <?php foreach ($active as $r): ?>
      <tr<?= $r['priority']==='emergency' ? ' class="emerg-row"' : '' ?>>
        <td><b><?= e($r['queue_code']) ?></b><?= $r['priority']==='emergency' ? ' <span class="badge b-emergency">Emergency</span>' : '' ?></td>
        <td><?= e($r['full_name']) ?></td>
        <td><?= $r['status']==='completed' ? '<span class="badge b-completed">Visit complete</span>' : e($r['at_stage'] ?? '-') ?></td>
        <td><?php if ($r['status']==='completed'): ?><a href="/medflow/receipt.php?code=<?= e($r['queue_code']) ?>" target="_blank">Print receipt</a><?php else: ?>-<?php endif; ?></td>
      </tr>
    <?php endforeach; if (!$active) echo '<tr><td colspan="4" class="empty">No visits yet.</td></tr>'; ?>
    </tbody></table></div>
</div>
<?php else: ?>
<div class="card">
  <h3>Next in my queue</h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Queue ID</th><th>Patient</th><th>Stage</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($preview as $r): ?>
      <tr<?= $r['priority']==='emergency' ? ' class="emerg-row"' : '' ?>>
        <td><b><?= e($r['queue_code']) ?></b><?= $r['priority']==='emergency' ? ' <span class="badge b-emergency">Emergency</span>' : '' ?></td>
        <td><?= e($r['full_name']) ?></td><td><?= e($r['stage']) ?></td>
        <td><span class="badge b-<?= e($r['status']) ?>"><?= e(ucfirst(str_replace('_',' ',$r['status']))) ?></span></td>
        <td><a class="btn" href="/medflow/staff/patient.php?vs=<?= (int)$r['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; if (!$preview) echo '<tr><td colspan="5" class="empty">Nobody is waiting right now.</td></tr>'; ?>
    </tbody></table></div>
</div>
<?php endif; ?>

<div class="card">
  <h3>Patients I attended recently</h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Queue ID</th><th>Patient</th><th>Stage</th><th>Finished</th><th>Minutes</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr><td><b><?= e($r['queue_code']) ?></b></td><td><?= e($r['full_name']) ?></td><td><?= e($r['stage']) ?></td>
        <td><?= e(date('d M H:i', strtotime($r['completed_at']))) ?></td><td><?= $r['mins'] !== null ? (int)$r['mins'] : '-' ?></td></tr>
    <?php endforeach; if (!$recent) echo '<tr><td colspan="5" class="empty">Nothing yet. Your finished patients will be listed here.</td></tr>'; ?>
    </tbody></table></div>
</div>
<script>setTimeout(function(){ location.reload(); }, 30000);</script>
<?php require '../includes/footer.php'; ?>
