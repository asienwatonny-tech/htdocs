<?php
// One chronological list of what happened in a visit (oldest first). Clinical staff and the patient only.
function care_timeline(array $vitals, array $dx, array $labs, array $rx, array $refs): array {
  $ev = [];
  foreach ($vitals as $v) {
    $bits = [];
    if ($v['bp_systolic'] !== null) $bits[] = 'BP ' . $v['bp_systolic'] . '/' . $v['bp_diastolic'];
    if ($v['temperature_c'] !== null) $bits[] = 'temp ' . $v['temperature_c'] . ' °C';
    if ($v['pulse'] !== null) $bits[] = 'pulse ' . $v['pulse'];
    if ($v['weight_kg'] !== null) $bits[] = $v['weight_kg'] . ' kg';
    $ev[] = [$v['recorded_at'], 'Measurements', implode(', ', $bits) ?: 'recorded'];
  }
  foreach ($dx as $d) $ev[] = [$d['created_at'], ucfirst($d['status']) . ' diagnosis', $d['diagnosis'] . ' (' . $d['doctor'] . ')'];
  foreach ($labs as $l) {
    $ev[] = [$l['ordered_at'], 'Test requested', $l['test_name'] . (isset($l['doctor']) && $l['doctor'] ? ' (' . $l['doctor'] . ')' : '')];
    if (!empty($l['resulted_at'])) $ev[] = [$l['resulted_at'], 'Test result', $l['test_name'] . ': ' . mb_strimwidth((string)$l['result'], 0, 90, '...')];
  }
  foreach ($refs as $r) $ev[] = [$r['created_at'], 'Referral', $r['specialty'] . ': ' . $r['reason']];
  foreach ($rx as $r) {
    $ev[] = [$r['created_at'], 'Prescribed', $r['medication'] . ' x ' . $r['quantity'] . ', ' . $r['dosage']];
    if (!empty($r['dispensed_at'])) $ev[] = [$r['dispensed_at'], 'Dispensed', $r['medication']];
  }
  usort($ev, fn($a, $b) => strtotime($a[0]) <=> strtotime($b[0]));
  return $ev;
}
