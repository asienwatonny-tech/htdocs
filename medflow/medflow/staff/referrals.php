<?php
require '../includes/auth.php';
require_role(['doctor']);
$uid = (int)$_SESSION['user']['id'];
function rows(PDO $db, string $sql, array $args): array { $s = $db->prepare($sql); $s->execute($args); return $s->fetchAll(); }

$cur = "(SELECT st.name FROM visit_stages vs2 JOIN stages st ON st.id=vs2.stage_id
          WHERE vs2.visit_id=r.visit_id AND vs2.status IN ('waiting','in_service') ORDER BY st.seq LIMIT 1)";
$sent = rows($db, "SELECT r.*, v.queue_code, v.status AS visit_status, p.full_name, sp.name AS specialty, t.full_name AS to_name, $cur AS now_at
    FROM referrals r JOIN visits v ON v.id=r.visit_id JOIN patients p ON p.id=v.patient_id
    JOIN specialties sp ON sp.id=r.to_specialty_id LEFT JOIN staff t ON t.id=r.to_doctor_id
    WHERE r.from_doctor_id=? ORDER BY (r.status='pending') DESC, r.id DESC LIMIT 30", [$uid]);
$got = rows($db, "SELECT r.*, v.queue_code, v.priority, p.full_name, sp.name AS specialty, f.full_name AS from_name, $cur AS now_at,
      (SELECT vs3.id FROM visit_stages vs3 JOIN stages s3 ON s3.id=vs3.stage_id
        WHERE vs3.visit_id=r.visit_id AND s3.name='Specialist' AND vs3.status IN ('waiting','in_service') AND vs3.assigned_to=? LIMIT 1) AS open_vs
    FROM referrals r JOIN visits v ON v.id=r.visit_id JOIN patients p ON p.id=v.patient_id
    JOIN specialties sp ON sp.id=r.to_specialty_id JOIN staff f ON f.id=r.from_doctor_id
    WHERE r.to_doctor_id=? ORDER BY (r.status='pending') DESC, r.id DESC LIMIT 30", [$uid, $uid]);
$specStage = (int)$db->query("SELECT id FROM stages WHERE name='Specialist'")->fetchColumn();

$title = 'Referrals';
require '../includes/header.php';
?>
<div class="card">
  <h2>Patients I referred</h2>
  <p class="muted">Follow special cases here: where the patient is now and what the specialist found.</p>
  <?php foreach ($sent as $r):
    $found = rows($db, "SELECT d.diagnosis, d.notes, s.full_name FROM diagnoses d JOIN staff s ON s.id=d.doctor_id WHERE d.visit_id=? AND d.stage_id=? ORDER BY d.id", [$r['visit_id'], $specStage]);
    $labs = rows($db, "SELECT test_name, status, result FROM lab_orders WHERE visit_id=? ORDER BY id", [$r['visit_id']]); ?>
    <details class="case"<?= $r['status'] === 'pending' ? ' open' : '' ?>>
      <summary><b><?= e($r['full_name']) ?></b> (<?= e($r['queue_code']) ?>) &rarr; <?= e($r['specialty']) ?>
        <span class="badge b-<?= $r['status'] === 'pending' ? 'waiting' : 'completed' ?>"><?= e(ucfirst($r['status'])) ?></span></summary>
      <p><b>Specialist:</b> <?= e($r['to_name'] ?? 'not assigned') ?> &middot; <b>Now at:</b>
        <?= $r['visit_status'] === 'completed' ? 'Visit complete' : e($r['now_at'] ?? '-') ?><br><b>Reason:</b> <?= e($r['reason']) ?></p>
      <?php if ($found): foreach ($found as $f): ?>
        <div class="entry"><b>Specialist finding:</b> <?= e($f['diagnosis']) ?> <small class="muted">(<?= e($f['full_name']) ?>)</small><?= $f['notes'] ? '<br>' . nl2br(e($f['notes'])) : '' ?></div>
      <?php endforeach; else: ?><p class="muted">No specialist finding recorded yet.</p><?php endif; ?>
      <?php foreach ($labs as $l): ?><div class="entry"><b><?= e($l['test_name']) ?>:</b> <?= $l['status'] === 'done' ? nl2br(e($l['result'])) : 'awaiting result' ?></div><?php endforeach; ?>
    </details>
  <?php endforeach; if (!$sent) echo '<p class="muted">You have not referred any patient yet.</p>'; ?>
</div>

<div class="card">
  <h2>Referred to me</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Patient</th><th>From</th><th>Reason</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($got as $r): ?>
      <tr<?= $r['priority']==='emergency' ? ' class="emerg-row"' : '' ?>>
        <td><b><?= e($r['full_name']) ?></b><br><small class="muted"><?= e($r['queue_code']) ?></small></td>
        <td><?= e($r['from_name']) ?></td><td><?= e($r['reason']) ?></td>
        <td><span class="badge b-<?= $r['status'] === 'pending' ? 'waiting' : 'completed' ?>"><?= e(ucfirst($r['status'])) ?></span><br><small class="muted">Now at: <?= e($r['now_at'] ?? '-') ?></small></td>
        <td><?php if ($r['open_vs']): ?><a class="btn" href="/medflow/staff/patient.php?vs=<?= (int)$r['open_vs'] ?>">Open</a><?php endif; ?></td>
      </tr>
    <?php endforeach; if (!$got) echo '<tr><td colspan="5" class="empty">Nobody has been referred to you.</td></tr>'; ?>
    </tbody></table></div>
</div>
<?php require '../includes/footer.php'; ?>
