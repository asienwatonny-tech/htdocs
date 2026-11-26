document.addEventListener('DOMContentLoaded', function () {
  // stop double-clicks creating duplicate records
  document.querySelectorAll('form').forEach(function (f) {
    f.addEventListener('submit', function () {
      f.querySelectorAll('button').forEach(function (b) { setTimeout(function () { b.disabled = true; }, 0); });
    });
  });
  // phone fields: keep only digits and +
  document.querySelectorAll('input[name="phone"]').forEach(function (i) {
    i.addEventListener('input', function () { i.value = i.value.replace(/[^0-9+]/g, ''); });
  });
  // one-time code field: digits only, max 6
  document.querySelectorAll('input[name="otp"]').forEach(function (i) {
    i.addEventListener('input', function () { i.value = i.value.replace(/\D/g, '').slice(0, 6); });
  });
  // emergency reason box appears only when the emergency box is ticked
  document.querySelectorAll('input[name="emergency"]').forEach(function (cb) {
    var box = document.getElementById('emergencyReason');
    if (!box) return;
    function sync() { box.hidden = !cb.checked; }
    cb.addEventListener('change', sync); sync();
  });
  // let nurses add more "other test" rows
  var add = document.getElementById('addObs');
  if (add) add.addEventListener('click', function () {
    var wrap = document.getElementById('obsRows');
    var row = document.createElement('div'); row.className = 'form-row obs-row';
    row.innerHTML = '<div><input name="obs_label[]" placeholder="Test, e.g. Blood sugar" maxlength="60"></div>' +
                    '<div><input name="obs_value[]" placeholder="Result, e.g. 5.6 mmol/L" maxlength="100"></div>';
    wrap.appendChild(row);
  });
});
