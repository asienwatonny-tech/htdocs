<?php
if (session_status() === PHP_SESSION_NONE) {
  session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
  session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app.php';

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---- sign-in checks ---- */
function require_role(array $roles) {
  $u = $_SESSION['user'] ?? null;
  if ($u && time() - ($_SESSION['last'] ?? time()) > IDLE_LIMIT_SEC) {      // idle timeout
    unset($_SESSION['user'], $_SESSION['last']);
    set_flash('err', 'You were signed out after 30 minutes without activity. Please sign in again.');
    $u = null;
  }
  if (!$u || !in_array($u['role'], $roles, true)) { header('Location: /medflow/login.php'); exit; }
  if (!empty($u['must_change']) && basename($_SERVER['SCRIPT_NAME']) !== 'change_password.php') {
    header('Location: /medflow/change_password.php'); exit;                // new or reset password must be changed first
  }
  $_SESSION['last'] = time();
}

/* ---- one-time messages shown at the top of the next page ---- */
function set_flash(string $type, string $msg) { $_SESSION['flash'] = [$type, $msg]; }
function flash_html(): string {
  if (empty($_SESSION['flash'])) return '';
  [$t, $m] = $_SESSION['flash']; unset($_SESSION['flash']);
  return '<div class="msg ' . ($t === 'ok' ? 'ok' : 'err') . '">' . e($m) . '</div>';
}

/* ---- CSRF protection for every form ---- */
function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
  return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check() {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
  $t = (string)($_POST['csrf'] ?? '');
  if ($t === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $t)) {
    http_response_code(400);
    exit('Your session expired or the form was not valid. Go back, refresh the page and try again.');
  }
}

/* ---- audit trail: who did what ---- */
function audit(PDO $db, ?int $staffId, string $action, string $detail = ''): void {
  try {
    $db->prepare("INSERT INTO audit_log (staff_id,action,detail,ip) VALUES (?,?,?,?)")
       ->execute([$staffId, $action, substr($detail, 0, 255), $_SERVER['REMOTE_ADDR'] ?? '']);
  } catch (Throwable $t) { /* never block the user because logging failed */ }
}

/* ---- passwords ---- */
function make_password(int $len = 12): string {
  $sets = ['ABCDEFGHJKMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '@#$%&*'];
  $all = implode('', $sets); $chars = [];
  foreach ($sets as $s) $chars[] = $s[random_int(0, strlen($s) - 1)];
  while (count($chars) < $len) $chars[] = $all[random_int(0, strlen($all) - 1)];
  for ($i = count($chars) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]]; }
  return implode('', $chars);
}
function password_problem(string $p): string {
  if (strlen($p) < 8) return 'Use at least 8 characters.';
  if (!preg_match('/[A-Z]/', $p) || !preg_match('/[a-z]/', $p) || !preg_match('/[0-9]/', $p))
    return 'Use a mix of capital letters, small letters and numbers.';
  return '';
}
