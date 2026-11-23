<?php
require 'includes/auth.php';
require 'includes/estimate.php';
require 'includes/sms.php';

$err = ''; $info = ''; $demoOtp = null; $stage = 'request'; $payload = null; $v = null;

function find_visit(PDO $db, string $code) {
  $s = $db->prepare("SELECT v.id, v.queue_code, v.priority, v.status, p.full_name, p.phone
                     FROM visits v JOIN patients p ON p.id=v.patient_id WHERE v.queue_code=?");
  $s->execute([$code]);
  return $s->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $act = $_POST['act'] ?? '';
  if ($act === 'request') {
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $f = find_visit($db, $code);
    $_SESSION['otp_visit'] = $f ? (int)$f['id'] : 0;
    $_SESSION['otp_code'] = $code;
    $stage = 'verify';
    $info = 'If that Queue ID exists, a 6-digit code has been sent to the phone number given at registration.';
    if ($f) {
      if ($f['phone'] === '') {
        $err = 'No phone number was recorded for this visit. Please ask at reception for help.'; $stage = 'request';
      } elseif (($wait = otp_cooldown_left($db, (int)$f['id'])) > 0) {
        $info = 'A code was sent a moment ago. You can ask for another in ' . $wait . ' seconds.';
      } else {
        $demoOtp = issue_otp($db, (int)$f['id'], $f['phone']);
      }
    }
  } elseif ($act === 'verify') {
    $vid = (int)($_SESSION['otp_visit'] ?? 0);
    if ($vid && verify_otp($db, $vid, trim($_POST['otp'] ?? ''))) {
      $_SESSION['track'][$vid] = time() + TRACK_SESSION_MIN * 60;
      unset($_SESSION['otp_visit']);
      header('Location: /medflow/track.php?code=' . urlencode((string)($_SESSION['otp_code'] ?? ''))); exit;
    }
    $err = 'That code is not correct or has expired. Check the message and try again, or request a new code.';
    $stage = 'verify';
  } elseif ($act === 'close') {
    unset($_SESSION['track'], $_SESSION['otp_visit']);
    header('Location: /medflow/track.php'); exit;
  }
}

$codeGet = strtoupper(trim($_GET['code'] ?? ''));
if ($codeGet !== '') {
  $f = find_visit($db, $codeGet);
  if ($f && ($_SESSION['track'][(int)$f['id']] ?? 0) > time()) {
    $v = $f;
    $j = journey($db, (int)$v['id']);
    $n = $db->prepare("SELECT message,created_at FROM notifications WHERE visit_id=? ORDER BY id DESC LIMIT 3");
    $n->execute([$v['id']]);
    $payload = ['stages' => $j['stages'], 'remaining' => $j['remaining'], 'notes' => $n->fetchAll(),
                'completed' => $v['status'] === 'completed'];
  } else {
    $info = 'Please verify with a one-time code to see this visit.';
  }
}
$title = 'Track my visit';
require 'includes/header.php';

if (!$v): ?>
<div class="hero">
  <img src="/medflow/assets/img/ambulance.svg" alt="">
  <div>
    <h1>Smarter patient flow,<br>better healthcare</h1>
    <p>Know where you are in your hospital visit and how long it will take.</p>
  </div>
</div>

<div class="card narrow">
  <h2>Track my visit</h2>
  <?php if ($err): ?><div class="msg err"><?= e($err) ?></div><?php endif; ?>
  <?php if ($info): ?><div class="msg"><?= e($info) ?></div><?php endif; ?>
  <?php if (SMS_DEMO && $demoOtp): ?>
    <div class="demo-box">Demo mode: your code is <b><?= e($demoOtp) ?></b><br><small>In real use this appears only in the text message.</small></div>
  <?php endif; ?>

  <?php if ($stage === 'verify'): ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="act" value="verify">
      <label>Enter the 6-digit code</label>
      <input name="otp" class="otp" maxlength="6" inputmode="numeric" placeholder="000000" required autofocus>
      <button>Verify and track</button>
    </form>
    <form method="post" class="again">
      <?= csrf_field() ?><input type="hidden" name="act" value="request">
      <input type="hidden" name="code" value="<?= e($_SESSION['otp_code'] ?? '') ?>">
      <button class="btn-ghost">Send a new code</button>
    </form>
  <?php else: ?>
    <p class="muted">Enter the Queue ID you were given at reception. We will text a one-time code to the phone number you registered with.</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="act" value="request">
      <label>Queue ID</label>
      <input name="code" placeholder="e.g. Q-0001" required value="<?= e($codeGet) ?>">
      <button>Send me a code</button>
    </form>
  <?php endif; ?>
</div>

<?php else: ?>
<div class="card" id="tracker" data-code="<?= e($v['queue_code']) ?>">
  <div class="qhead">
    <div>
      <h2 style="margin-bottom:2px"><?= e($v['full_name']) ?></h2>
      <p class="muted">Queue ID <b><?= e($v['queue_code']) ?></b> <?= $v['priority'] === 'emergency' ? '<span class="badge b-emergency">Emergency</span>' : '' ?></p>
      <p class="muted" id="doctorLine"></p>
    </div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="close"><button class="btn-alt">Close tracking</button></form>
  </div>

  <div id="banner" class="status-banner">Loading your status...</div>

  <div class="stats">
    <div class="stat"><small>Current stage</small><b id="curStage">-</b></div>
    <div class="stat"><small>Patients ahead</small><b id="ahead">-</b></div>
    <div class="stat"><small>Wait at this stage</small><b id="wait">-</b></div>
    <div class="stat hot"><small>Total time left</small><b id="remaining">-</b></div>
  </div>

  <div class="steps" id="steps"></div>

  <div class="table-wrap">
  <table>
    <thead><tr><th>Stage</th><th>Status</th><th>Information</th></tr></thead>
    <tbody id="rows"></tbody>
  </table>
  </div>

  <div id="receiptBox" hidden>
    <h3 style="margin-top:18px">Your receipt</h3>
    <p>Your visit is complete. Your receipt shows your journey and diagnoses.</p>
    <a class="btn" href="/medflow/receipt.php?code=<?= e($v['queue_code']) ?>">View receipt</a>
    <a class="btn btn-alt" href="/medflow/receipt.php?code=<?= e($v['queue_code']) ?>&amp;download=1">Download</a>
  </div>

  <h3 style="margin-top:18px">Updates</h3>
  <div id="notes"><p class="muted">No updates yet.</p></div>

  <p class="muted" style="margin-top:14px"><small>Estimates are approximate, not guaranteed. <span id="updated"></span></small></p>
</div>

<script type="application/json" id="init"><?= json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/medflow/assets/js/track.js"></script>
<?php endif;
require 'includes/footer.php'; ?>
