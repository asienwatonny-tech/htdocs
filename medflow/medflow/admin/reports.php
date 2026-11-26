<?php
require '../includes/auth.php';
require '../includes/billing.php';
require_role(['admin']);
$perStage = $db->query("SELECT st.name,
  ROUND(AVG(TIMESTAMPDIFF(MINUTE,vs.queued_at,vs.started_at)),1) AS avg_wait,
  ROUND(AVG(TIMESTAMPDIFF(MINUTE,vs.started_at,vs.completed_at)),1) AS avg_service,
  COALESCE(SUM(vs.status IN ('waiting','in_service')),0) AS in_queue,
  COALESCE(SUM(vs.status='completed'),0) AS done
  FROM stages st LEFT JOIN visit_stages vs ON vs.stage_id=st.id GROUP BY st.id ORDER BY st.seq")->fetchAll();
$tot = $db->query("SELECT COUNT(*) total, COALESCE(SUM(status='completed'),0) done, COALESCE(SUM(status='active'),0) active,
  COALESCE(SUM(priority='emergency'),0) emerg_all,
  COALESCE(SUM(priority='emergency' AND status='active'),0) emerg_now FROM visits")->fetch();
$maxQ = max(array_map(fn($r) => (int)$r['in_queue'], $perStage));

$perStaff = $db->query("SELECT s.full_name, s.role, sp.name AS specialty,
    COUNT(vs.id) AS attended,
    COALESCE(SUM(DATE(vs.completed_at)=CURDATE()),0) AS today,
    ROUND(AVG(CASE WHEN vs.started_at<>vs.completed_at THEN TIMESTAMPDIFF(SECOND,vs.started_at,vs.completed_at) END)/60,1) AS avg_min
  FROM staff s LEFT JOIN specialties sp ON sp.id=s.specialty_id
  LEFT JOIN visit_stages vs ON vs.updated_by=s.id AND vs.status='completed'
  WHERE s.role<>'admin' GROUP BY s.id ORDER BY s.role, s.full_name")->fetchAll();

$docs = $db->query("SELECT s.full_name, sp.name AS specialty, s.on_duty, s.active,
    (SELECT COUNT(*) FROM visit_stages vs WHERE vs.assigned_to=s.id AND vs.status IN ('waiting','in_service')) AS load_now
  FROM staff s LEFT JOIN specialties sp ON sp.id=s.specialty_id WHERE s.role='doctor' ORDER BY sp.id, s.full_name")->fetchAll();

$emerg = $db->query("SELECT v.queue_code, v.emergency_reason, v.status, v.created_at
  FROM visits v WHERE v.priority='emergency' ORDER BY v.id DESC LIMIT 8")->fetchAll();

$rev = $db->query("SELECT
    COALESCE(SUM(CASE WHEN method<>'waived' AND DATE(paid_at)=CURDATE() THEN amount END),0) AS today,
    COALESCE(SUM(CASE WHEN method<>'waived' THEN amount END),0) AS total,
    COALESCE(SUM(CASE WHEN method='waived' THEN amount END),0) AS waived FROM payments")->fetch();
$unpaid = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM charges WHERE status='unpaid'")->fetchColumn();
$byMethod = $db->query("SELECT method, COUNT(*) n, SUM(amount) amt FROM payments WHERE method<>'waived' GROUP BY method ORDER BY amt DESC")->fetchAll();
$byKind = $db->query("SELECT kind, SUM(amount) amt FROM charges WHERE status='paid' GROUP BY kind ORDER BY amt DESC")->fetchAll();
$audit = $db->query("SELECT a.created_at, a.action, a.detail, s.username FROM audit_log a LEFT JOIN staff s ON s.id=a.staff_id ORDER BY a.id DESC LIMIT 10")->fetchAll();
$title = 'Reports';
require '../includes/header.php';
?>
<?php if ((int)$tot['emerg_now']): ?>
  <div class="emergency-banner"><b><?= (int)$tot['emerg_now'] ?> emergency visit<?= (int)$tot['emerg_now'] > 1 ? 's' : '' ?></b> in progress now.</div>
<?php endif; ?>
<div class="card">
  <h2>Hospital overview</h2>
  <p class="muted">Live figures from all patient visits.</p>
  <div class="stats">
    <div class="stat"><small>Total visits</small><b><?= (int)$tot['total'] ?></b></div>
    <div class="stat"><small>Completed</small><b><?= (int)$tot['done'] ?></b></div>
    <div class="stat"><small>Active now</small><b><?= (int)$tot['active'] ?></b></div>
    <div class="stat"><small>Emergency visits</small><b><?= (int)$tot['emerg_all'] ?></b></div>
    <div class="stat hot"><small>Waiting right now</small><b><?= array_sum(array_map(fn($r) => (int)$r['in_queue'], $perStage)) ?></b></div>
  </div>
</div>

<div class="card">
  <h2>Revenue</h2>
  <div class="stats">
    <div class="stat hot"><small>Collected today</small><b><?= e(money($rev['today'])) ?></b></div>
    <div class="stat"><small>Collected in total</small><b><?= e(money($rev['total'])) ?></b></div>
    <div class="stat"><small>Still unpaid</small><b><?= e(money($unpaid)) ?></b></div>
    <div class="stat"><small>Waived</small><b><?= e(money($rev['waived'])) ?></b></div>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>Payment method</th><th>Payments</th><th>Amount</th></tr></thead>
    <tbody>
    <?php foreach ($byMethod as $r): ?><tr><td><?= e(ucfirst($r['method'])) ?></td><td><?= (int)$r['n'] ?></td><td><?= e(money($r['amt'])) ?></td></tr><?php endforeach; if (!$byMethod) echo '<tr><td colspan="3" class="empty">No payments yet.</td></tr>'; ?>
    </tbody></table></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Income by service</th><th>Amount</th></tr></thead>
    <tbody>
    <?php foreach ($byKind as $r): ?><tr><td><?= e(ucfirst($r['kind'])) ?></td><td><?= e(money($r['amt'])) ?></td></tr><?php endforeach; if (!$byKind) echo '<tr><td colspan="2" class="empty">Nothing yet.</td></tr>'; ?>
    </tbody></table></div>
</div>

<div class="card">
  <h2>Performance by stage</h2>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Stage</th><th>Avg wait (min)</th><th>Avg service (min)</th><th>Currently queued</th><th>Completed</th></tr></thead>
    <tbody>
    <?php foreach ($perStage as $r): $hot = $maxQ > 0 && (int)$r['in_queue'] === $maxQ; ?>
      <tr<?= $hot ? ' class="hotrow"' : '' ?>>
        <td><b><?= e($r['name']) ?></b><?= $hot ? ' <span class="badge b-waiting">Longest queue</span>' : '' ?></td>
        <td><?= e($r['avg_wait'] ?? '-') ?></td>
        <td><?= e($r['avg_service'] ?? '-') ?></td>
        <td><?= (int)$r['in_queue'] ?><div class="bar"><i style="width:<?= $maxQ ? round(100 * (int)$r['in_queue'] / $maxQ) : 0 ?>%"></i></div></td>
        <td><?= (int)$r['done'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted"><small>The highlighted stage has the most patients currently waiting, a likely bottleneck.</small></p>
</div>

<div class="card">
  <h2>Staff performance</h2>
  <p class="muted">Patients each member of staff has attended and the average time they take.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Name</th><th>Role</th><th>Attended today</th><th>Attended in total</th><th>Avg minutes per patient</th></tr></thead>
    <tbody>
    <?php foreach ($perStaff as $r): ?>
      <tr><td><b><?= e($r['full_name']) ?></b><?= $r['specialty'] && $r['role']==='doctor' ? '<br><small class="muted">' . e($r['specialty']) . '</small>' : '' ?></td>
        <td><span class="role"><?= e(ucfirst($r['role'])) ?></span></td><td><?= (int)$r['today'] ?></td><td><?= (int)$r['attended'] ?></td>
        <td><?= $r['avg_min'] !== null ? e($r['avg_min']) : '-' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

<div class="card">
  <h2>Doctor workload</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Doctor</th><th>Specialty</th><th>Duty</th><th>Patients waiting</th></tr></thead>
    <tbody>
    <?php foreach ($docs as $d): ?>
      <tr<?= $d['active'] ? '' : ' class="off"' ?>><td><b><?= e($d['full_name']) ?></b></td><td><?= e($d['specialty'] ?? 'General Medicine') ?></td>
        <td><span class="badge <?= $d['on_duty'] ? 'b-completed' : 'b-skipped' ?>"><?= $d['on_duty'] ? 'On duty' : 'Off duty' ?></span></td><td><?= (int)$d['load_now'] ?></td></tr>
    <?php endforeach; if (!$docs) echo '<tr><td colspan="4" class="empty">No doctors yet.</td></tr>'; ?>
    </tbody></table></div>
</div>

<?php if ($emerg): ?>
<div class="card">
  <h2>Recent emergencies</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Queue ID</th><th>Reason</th><th>Status</th><th>Time</th></tr></thead>
    <tbody>
    <?php foreach ($emerg as $r): ?>
      <tr class="emerg-row"><td><b><?= e($r['queue_code']) ?></b></td><td><?= e($r['emergency_reason'] ?? '-') ?></td>
        <td><?= e(ucfirst($r['status'])) ?></td><td><?= e($r['created_at']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>

<div class="card">
  <h2>Recent activity</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Time</th><th>Who</th><th>Action</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($audit as $a): ?>
      <tr><td><?= e($a['created_at']) ?></td><td><?= e($a['username'] ?? 'patient') ?></td><td><?= e($a['action']) ?></td><td><?= e($a['detail']) ?></td></tr>
    <?php endforeach; if (!$audit) echo '<tr><td colspan="4" class="empty">Nothing recorded yet.</td></tr>'; ?>
    </tbody></table></div>
</div>
<?php require '../includes/footer.php'; ?>
