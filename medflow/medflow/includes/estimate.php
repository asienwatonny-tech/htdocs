<?php
function avg_minutes(PDO $db, int $stageId): float {
  $s = $db->prepare("SELECT AVG(TIMESTAMPDIFF(SECOND,started_at,completed_at))/60 FROM
    (SELECT started_at,completed_at FROM visit_stages
     WHERE stage_id=? AND status='completed' AND started_at IS NOT NULL
     ORDER BY completed_at DESC LIMIT 20) t");
  $s->execute([$stageId]);
  $a = $s->fetchColumn();
  if ($a === null || $a < 1) {
    $d = $db->prepare("SELECT default_minutes FROM stages WHERE id=?");
    $d->execute([$stageId]);
    return (float)$d->fetchColumn();
  }
  return (float)$a;
}

/* Journey for one visit: each stage with patients ahead and an estimated wait.
   Emergency patients go ahead of normal ones, and a doctor's stage only counts
   the patients waiting for the SAME doctor. */
function journey(PDO $db, int $visitId): array {
  $pv = $db->prepare("SELECT priority FROM visits WHERE id=?");
  $pv->execute([$visitId]);
  $myPriority = (string)$pv->fetchColumn();

  $s = $db->prepare("SELECT vs.*, st.name, st.seq FROM visit_stages vs
                     JOIN stages st ON st.id=vs.stage_id WHERE vs.visit_id=? ORDER BY st.seq");
  $s->execute([$visitId]);
  $rows = $s->fetchAll();
  $total = 0;
  foreach ($rows as &$r) {
    $avg = avg_minutes($db, (int)$r['stage_id']);
    $r['ahead'] = 0; $r['est'] = 0;
    if ($r['status'] === 'in_service') {
      $r['est'] = (int)round($avg);
    } elseif ($r['status'] === 'waiting') {
      $c = $db->prepare("SELECT o.status, o.queued_at, o.id, ov.priority
        FROM visit_stages o JOIN visits ov ON ov.id=o.visit_id
        WHERE o.stage_id=? AND o.status IN ('waiting','in_service') AND o.id<>? AND o.assigned_to <=> ?");
      $c->execute([$r['stage_id'], $r['id'], $r['assigned_to']]);
      $ahead = 0;
      foreach ($c->fetchAll() as $o) {
        if ($o['status'] === 'in_service') $ahead++;
        elseif ($o['priority'] === 'emergency' && $myPriority !== 'emergency') $ahead++;
        elseif ($o['priority'] === $myPriority && ($o['queued_at'] < $r['queued_at'] ||
                ($o['queued_at'] === $r['queued_at'] && (int)$o['id'] < (int)$r['id']))) $ahead++;
      }
      $r['ahead'] = $ahead;
      $r['est'] = (int)round(($ahead + 1) * $avg);
    } elseif ($r['status'] === 'pending') {
      $r['est'] = (int)round($avg);
    }
    $total += $r['est'];
  }
  unset($r);
  return ['stages' => $rows, 'remaining' => $total];
}
