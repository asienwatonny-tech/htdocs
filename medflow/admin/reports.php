<?php
require '../includes/auth.php';
require_role(['admin']);
$perStage = $db->query("SELECT st.name,
  ROUND(AVG(TIMESTAMPDIFF(MINUTE,vs.queued_at,vs.started_at)),1) AS avg_wait,
  ROUND(AVG(TIMESTAMPDIFF(MINUTE,vs.started_at,vs.completed_at)),1) AS avg_service,
  SUM(vs.status IN ('waiting','in_service')) AS in_queue,
  SUM(vs.status='completed') AS done
  FROM stages st LEFT JOIN visit_stages vs ON vs.stage_id=st.id GROUP BY st.id ORDER BY st.seq")->fetchAll();
$tot = $db->query("SELECT COUNT(*) total, COALESCE(SUM(status='completed'),0) done, COALESCE(SUM(status='active'),0) active FROM visits")->fetch();
$maxQ = max(array_map(fn($r) => (int)$r['in_queue'], $perStage));
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reports</title></head>
<body style="font-family:sans-serif;max-width:800px;margin:30px auto;padding:0 12px">
<h2>MedFlow Reports</h2>
<p><a href="reports.php">Reports</a> | <a href="staff.php">Manage staff</a> | <a href="../logout.php">Logout</a></p>
<p><b>Visits:</b> <?= (int)$tot['total'] ?> &nbsp; <b>Completed:</b> <?= (int)$tot['done'] ?> &nbsp; <b>Active:</b> <?= (int)$tot['active'] ?></p>
<table border="1" cellpadding="8" cellspacing="0" width="100%">
<tr><th>Stage</th><th>Avg wait (min)</th><th>Avg service (min)</th><th>Currently queued</th><th>Completed</th></tr>
<?php foreach ($perStage as $r): $hot = $maxQ > 0 && (int)$r['in_queue'] === $maxQ; ?>
<tr<?= $hot ? ' style="background:#ffe9e9"' : '' ?>>
<td><?= e($r['name']) ?><?= $hot ? ' ⚠ longest queue' : '' ?></td>
<td><?= e($r['avg_wait'] ?? '-') ?></td><td><?= e($r['avg_service'] ?? '-') ?></td>
<td><?= (int)$r['in_queue'] ?></td><td><?= (int)$r['done'] ?></td></tr>
<?php endforeach; ?>
</table>
<p><small>The highlighted row is the stage with the most patients currently waiting, a likely bottleneck.</small></p>
</body></html>