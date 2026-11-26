<?php
// MedFlow settings. Change these in one place.
define('HOSPITAL_NAME', 'MedFlow Hospital');
define('SMS_DEMO', true);            // true = OTP codes are also shown on screen (demo only). Set to false for real use.
define('OTP_TTL_MIN', 5);            // a one-time code works for 5 minutes
define('OTP_MAX_TRIES', 5);          // wrong guesses allowed per code
define('OTP_RESEND_SEC', 30);        // wait before another code can be requested
define('TRACK_SESSION_MIN', 120);    // a verified patient stays signed in for 2 hours
define('IDLE_LIMIT_SEC', 1800);      // staff are signed out after 30 minutes idle
define('MAX_LOGIN_TRIES', 5);        // wrong passwords before the account is locked
define('LOCK_MINUTES', 10);          // how long the lock lasts
define('RECEIPT_SECRET', 'change-this-text-before-real-use');   // used to make receipt verification codes
define('CURRENCY', 'KES');                // money is shown as KES 1,500.00
