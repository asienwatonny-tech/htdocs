<?php
// Charges, payments and receipts. Money is stored as DECIMAL and handled as cents-safe floats.
require_once __DIR__ . '/auth.php';

function money($n): string { return CURRENCY . ' ' . number_format((float)$n, 2); }

/* Which kinds of charge each cashier stage collects. */
function gate_kinds(string $payStage): ?array {
  $map = [
    'Consultation Payment' => ['consultation'],
    'Lab Payment'          => ['lab', 'specialist'],
    'Pharmacy Payment'     => ['medication', 'specialist'],
    'Settlement'           => null,            // everything still unpaid
  ];
  return $map[$payStage] ?? null;
}

function add_charge(PDO $db, int $visitId, string $kind, string $desc, float $amount, ?string $refTable = null, ?int $refId = null): int {
  $db->prepare("INSERT INTO charges (visit_id,kind,description,amount,ref_table,ref_id) VALUES (?,?,?,?,?,?)")
     ->execute([$visitId, $kind, substr($desc, 0, 150), round($amount, 2), $refTable, $refId]);
  return (int)$db->lastInsertId();
}

function unpaid_charges(PDO $db, int $visitId, ?array $kinds = null): array {
  $sql = "SELECT * FROM charges WHERE visit_id=? AND status='unpaid'"; $args = [$visitId];
  if ($kinds) { $sql .= " AND kind IN (" . implode(',', array_fill(0, count($kinds), '?')) . ")"; $args = array_merge($args, $kinds); }
  $s = $db->prepare($sql . " ORDER BY id"); $s->execute($args);
  return $s->fetchAll();
}

function unpaid_total(PDO $db, int $visitId, ?array $kinds = null): float {
  $t = 0.0;
  foreach (unpaid_charges($db, $visitId, $kinds) as $c) $t += (float)$c['amount'];
  return round($t, 2);
}

/* A charge tied to an order can only be removed while it is still unpaid. */
function charge_for_ref(PDO $db, int $visitId, string $refTable, int $refId) {
  $s = $db->prepare("SELECT * FROM charges WHERE visit_id=? AND ref_table=? AND ref_id=? LIMIT 1");
  $s->execute([$visitId, $refTable, $refId]);
  return $s->fetch();
}

/* Take payment (or waive) for the charges a cashier stage collects. Returns the payment id. */
function record_payment(PDO $db, int $visitId, string $payStage, string $method, ?string $reference, ?float $tendered, int $uid): int {
  $charges = unpaid_charges($db, $visitId, gate_kinds($payStage));
  if (!$charges) throw new RuntimeException('Nothing to pay.');
  $total = 0.0; foreach ($charges as $c) $total += (float)$c['amount'];
  $total = round($total, 2);
  $db->prepare("INSERT INTO payments (visit_id,amount,method,reference,tendered,stage,received_by) VALUES (?,?,?,?,?,?,?)")
     ->execute([$visitId, $total, $method, $reference, $tendered, $payStage, $uid]);
  $pid = (int)$db->lastInsertId();
  $db->prepare("UPDATE payments SET receipt_no=? WHERE id=?")->execute(['RCP-' . str_pad((string)$pid, 5, '0', STR_PAD_LEFT), $pid]);
  $set = $db->prepare("UPDATE charges SET status=?, payment_id=? WHERE id=?");
  foreach ($charges as $c) $set->execute([$method === 'waived' ? 'waived' : 'paid', $pid, $c['id']]);
  return $pid;
}

function bill_summary(PDO $db, int $visitId): array {
  $s = $db->prepare("SELECT COALESCE(SUM(amount),0) AS total,
      COALESCE(SUM(CASE WHEN status='paid' THEN amount END),0) AS paid,
      COALESCE(SUM(CASE WHEN status='waived' THEN amount END),0) AS waived,
      COALESCE(SUM(CASE WHEN status='unpaid' THEN amount END),0) AS unpaid FROM charges WHERE visit_id=?");
  $s->execute([$visitId]);
  return $s->fetch();
}
