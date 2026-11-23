<?php
require 'includes/auth.php';
$code = strtoupper(trim($_GET['code'] ?? ''));
$s = $db->prepare("SELECT v.*, p.full_name, p.phone, p.gender, p.date_of_birth, sp.name AS specialty
                   FROM visits v JOIN patients p ON p.id=v.patient_id LEFT JOIN specialties sp ON sp.id=v.needed_specialty_id WHERE v.queue_code=?");
$s->execute([$code]); $v = $s->fetch();
$vid = $v ? (int)$v['id'] : 0;

// Who may open a receipt: signed-in staff, or the patient after verifying their one-time code.
$isStaff = !empty($_SESSION['user']) && empty($_SESSION['user']['must_change']);
$isPatient = $v && (($_SESSION['track'][$vid] ?? 0) > time());
if (!$v || (!$isStaff && !$isPatient)) {
  http_response_code(403);
  exit('Receipt not available. Patients: open it from the tracking page after verifying your one-time code.');
}
$complete = $v['status'] === 'completed';
if (!$complete && !$isStaff) { http_response_code(403); exit('Your receipt is available once your visit is complete.'); }
function rows(PDO $db, string $sql, array $args): array { $s = $db->prepare($sql); $s->execute($args); return $s->fetchAll(); }

$stages = rows($db, "SELECT vs.*, st.name, st.seq, u.full_name AS by_name FROM visit_stages vs JOIN stages st ON st.id=vs.stage_id
                     LEFT JOIN staff u ON u.id=vs.updated_by WHERE vs.visit_id=? ORDER BY st.seq", [$vid]);
$vit  = rows($db, "SELECT * FROM vitals WHERE visit_id=? ORDER BY id DESC LIMIT 1", [$vid])[0] ?? null;
$obs  = rows($db, "SELECT label,value FROM observations WHERE visit_id=? ORDER BY id", [$vid]);
$dx   = rows($db, "SELECT d.diagnosis,d.notes,s.full_name AS doctor,st.name AS stage FROM diagnoses d JOIN staff s ON s.id=d.doctor_id LEFT JOIN stages st ON st.id=d.stage_id WHERE d.visit_id=? ORDER BY d.id", [$vid]);
$labs = rows($db, "SELECT test_name,status,result FROM lab_orders WHERE visit_id=? ORDER BY id", [$vid]);
$rx   = rows($db, "SELECT medication,dosage,instructions,dispensed FROM prescriptions WHERE visit_id=? ORDER BY id", [$vid]);
$refs = rows($db, "SELECT r.reason, r.status, sp.name AS specialty, t.full_name AS to_name FROM referrals r JOIN specialties sp ON sp.id=r.to_specialty_id LEFT JOIN staff t ON t.id=r.to_doctor_id WHERE r.visit_id=? ORDER BY r.id", [$vid]);
$doc = null;
if ($v['assigned_doctor_id']) { $d = $db->prepare("SELECT full_name FROM staff WHERE id=?"); $d->execute([$v['assigned_doctor_id']]); $doc = $d->fetchColumn(); }

$end = $v['completed_at'];
if (!$end) foreach ($stages as $st) if ($st['completed_at'] && (!$end || $st['completed_at'] > $end)) $end = $st['completed_at'];
$receiptNo = 'R-' . substr($v['queue_code'], 2) . '-' . date('ymd', strtotime($end ?: $v['created_at']));
$verify = strtoupper(substr(hash_hmac('sha256', $v['queue_code'] . '|' . $vid . '|' . ($end ?: ''), RECEIPT_SECRET), 0, 10));
$age = null;
if ($v['date_of_birth']) { try { $age = (new DateTime($v['date_of_birth']))->diff(new DateTime('today'))->y; } catch (Throwable $t) {} }
$mins = function ($a, $b) { return ($a && $b) ? max(0, (int)round((strtotime($b) - strtotime($a)) / 60)) . ' min' : '-'; };
$t = function ($x) { return $x ? date('d M Y H:i', strtotime($x)) : '-'; };

$download = !empty($_GET['download']);
if ($download) {
  header('Content-Type: text/html; charset=utf-8');
  header('Content-Disposition: attachment; filename="medflow-receipt-' . $v['queue_code'] . '.html"');
}
audit($db, $isStaff ? (int)$_SESSION['user']['id'] : null, $download ? 'receipt_download' : 'receipt_view', $v['queue_code']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Receipt <?= e($receiptNo) ?> | MedFlow</title>
<style><?= file_get_contents(__DIR__ . '/assets/css/receipt.css') ?></style></head><body>
<?php if (!$download): ?>
<div class="toolbar no-print">
  <button onclick="window.print()">Print or save as PDF</button>
  <a class="tb" href="?code=<?= e($v['queue_code']) ?>&amp;download=1">Download file</a>
  <a class="tb" href="<?= $isStaff ? '/medflow/staff/dashboard.php' : '/medflow/track.php?code=' . e($v['queue_code']) ?>">Back</a>
</div>
<?php endif; ?>
<div class="sheet">
  <div class="rhead">
    <div><h1><?= e(HOSPITAL_NAME) ?></h1><p>Outpatient visit receipt</p></div>
    <div class="rmeta"><b><?= e($receiptNo) ?></b><br>Issued <?= e(date('d M Y H:i')) ?></div>
  </div>
  <?php if (!$complete): ?><p class="warn">Provisional copy: this visit is not complete yet.</p><?php endif; ?>
  <?php if ($v['priority'] === 'emergency'): ?><p class="warn">Emergency visit<?= $v['emergency_reason'] ? ': ' . e($v['emergency_reason']) : '' ?></p><?php endif; ?>

  <h2>Patient</h2>
  <table class="kv">
    <tr><th>Name</th><td><?= e($v['full_name']) ?></td><th>Queue ID</th><td><?= e($v['queue_code']) ?></td></tr>
    <tr><th>Age / gender</th><td><?= $age !== null ? (int)$age . ' years' : '-' ?><?= $v['gender'] ? ' / ' . e(ucfirst($v['gender'])) : '' ?></td><th>Visit date</th><td><?= e($t($v['created_at'])) ?></td></tr>
    <tr><th>Care needed</th><td><?= e($v['specialty'] ?? '-') ?></td><th>Attending doctor</th><td><?= e($doc ?: '-') ?></td></tr>
  </table>

  <?php if ($vit || $obs): ?>
  <h2>Measurements</h2>
  <table class="kv">
    <tr>
      <th>Weight</th><td><?= $vit && $vit['weight_kg'] !== null ? e($vit['weight_kg']) . ' kg' : '-' ?></td>
      <th>Blood pressure</th><td><?= $vit && $vit['bp_systolic'] !== null ? e($vit['bp_systolic'] . '/' . $vit['bp_diastolic']) . ' mmHg' : '-' ?></td>
    </tr>
    <tr>
      <th>Temperature</th><td><?= $vit && $vit['temperature_c'] !== null ? e($vit['temperature_c']) . ' °C' : '-' ?></td>
      <th>Pulse / SpO2</th><td><?= $vit ? e(($vit['pulse'] ?? '-') . ' bpm / ' . ($vit['spo2'] ?? '-') . '%') : '-' ?></td>
    </tr>
    <?php foreach ($obs as $o): ?><tr><th><?= e($o['label']) ?></th><td colspan="3"><?= e($o['value']) ?></td></tr><?php endforeach; ?>
  </table>
  <?php endif; ?>

  <h2>Diagnosis</h2>
  <?php foreach ($dx as $d): ?>
    <p class="item"><b><?= e($d['diagnosis']) ?></b> <span class="small">(<?= e($d['doctor']) ?>, <?= e($d['stage'] ?? '') ?>)</span><?= $d['notes'] ? '<br>' . nl2br(e($d['notes'])) : '' ?></p>
  <?php endforeach; if (!$dx) echo '<p class="small">No diagnosis recorded.</p>'; ?>

  <?php if ($refs): ?><h2>Specialist referral</h2>
    <?php foreach ($refs as $r): ?><p class="item"><b><?= e($r['specialty']) ?></b> (<?= e($r['to_name'] ?? 'specialist') ?>) &ndash; <?= e($r['reason']) ?> <span class="small">[<?= e($r['status']) ?>]</span></p><?php endforeach; ?>
  <?php endif; ?>

  <?php if ($labs): ?><h2>Laboratory tests</h2>
    <?php foreach ($labs as $l): ?><p class="item"><b><?= e($l['test_name']) ?>:</b> <?= $l['status'] === 'done' ? nl2br(e($l['result'])) : 'result pending' ?></p><?php endforeach; ?>
  <?php endif; ?>

  <?php if ($rx): ?><h2>Medication</h2>
    <table class="grid"><tr><th>Medicine</th><th>Dosage</th><th>Instructions</th><th>Dispensed</th></tr>
    <?php foreach ($rx as $r): ?><tr><td><?= e($r['medication']) ?></td><td><?= e($r['dosage']) ?></td><td><?= e($r['instructions'] ?? '') ?></td><td><?= $r['dispensed'] ? 'Yes' : 'No' ?></td></tr><?php endforeach; ?></table>
  <?php endif; ?>

  <h2>Visit journey</h2>
  <table class="grid"><tr><th>Stage</th><th>Status</th><th>Started</th><th>Finished</th><th>Time</th></tr>
  <?php foreach ($stages as $st): if ($st['status'] === 'pending') continue; ?>
    <tr><td><?= e($st['name']) ?></td><td><?= e(ucfirst($st['status'])) ?></td><td><?= e($t($st['started_at'])) ?></td><td><?= e($t($st['completed_at'])) ?></td><td><?= e($mins($st['started_at'], $st['completed_at'])) ?></td></tr>
  <?php endforeach; ?></table>
  <p class="small">Total time in hospital: <?= e($mins($v['created_at'], $end)) ?></p>

  <div class="rfoot">
    <p>Verification code: <b><?= e($verify) ?></b> &middot; Receipt <?= e($receiptNo) ?></p>
    <p class="small">This receipt summarises your visit. Keep it for your records and show it to your doctor at your next visit. It is produced by the MedFlow prototype.</p>
  </div>
</div>
</body></html>
