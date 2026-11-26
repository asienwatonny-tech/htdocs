<?php
require '../includes/auth.php';
require '../includes/estimate.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$s = $db->prepare("SELECT v.id, v.status, v.assigned_doctor_id FROM visits v WHERE v.queue_code=?");
$s->execute([strtoupper(trim($_POST['code'] ?? ''))]);
$v = $s->fetch();
// The patient must have verified a one-time code for THIS visit in their own browser session.
if (!$v || ($_SESSION['track'][(int)$v['id']] ?? 0) <= time()) {
  http_response_code(403); echo json_encode(['error' => 'denied']); exit;
}
$j = journey($db, (int)$v['id']);
$n = $db->prepare("SELECT message,created_at FROM notifications WHERE visit_id=? ORDER BY id DESC LIMIT 3");
$n->execute([$v['id']]);
$doctor = null;
if ($v['assigned_doctor_id']) {
  $d = $db->prepare("SELECT s.full_name, sp.name AS specialty FROM staff s LEFT JOIN specialties sp ON sp.id=s.specialty_id WHERE s.id=?");
  $d->execute([$v['assigned_doctor_id']]); $doctor = $d->fetch() ?: null;
}
echo json_encode(['stages' => $j['stages'], 'remaining' => $j['remaining'], 'notes' => $n->fetchAll(),
                  'completed' => $v['status'] === 'completed', 'doctor' => $doctor]);
