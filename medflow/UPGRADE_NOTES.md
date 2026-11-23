# MedFlow v2 upgrade

## Install (10 minutes)
1. **Back up first.** Copy `C:\xampp\htdocs\medflow` to `medflow_backup`, and in phpMyAdmin export the `medflow` database again.
2. Start Apache and MySQL in XAMPP.
3. phpMyAdmin > `medflow` > **SQL** tab > paste the whole of `sql/upgrade_v2.sql` > **Go**. Your patients, visits, staff and passwords are kept. It is safe to run twice.
4. Copy the contents of this `medflow` folder over `C:\xampp\htdocs\medflow` (replace files when asked). `config/db.php` is not included, so your database settings stay as they are.
5. Open http://localhost/medflow/ and sign in as `admin`.

## Add the specialist doctors first
Admin > Staff > add a doctor and choose a specialty (for example *Optometry (Eye)*). The system gives a generated password, shown once. Existing doctors were set to General Medicine.

## Test it in this order
1. Reception: register a patient, choose "Optometry (Eye)" as the care needed.
2. Track page: enter the Queue ID. Demo mode shows the 6-digit code on screen (and in Reception > SMS outbox). Enter it.
3. Nurse: Open the patient > Start > record weight, BP and any extra test > Complete. The patient is matched to the optometry doctor with the fewest patients.
4. Doctor: Open > Start > add a diagnosis, request a lab test, prescribe, or refer to another specialty > Complete.
5. Lab: enter the result > Complete. Doctor: Results stage > Complete. Pharmacy: mark dispensed > Complete.
6. Track page: "View receipt" and "Download" appear when the visit is complete.
7. Emergency: register a patient with the Emergency box ticked. They appear first in every queue with a red banner.

## Settings
`config/app.php`: set `SMS_DEMO` to `false` before any real use, and change `RECEIPT_SECRET`.

## Notes
- Real SMS needs a provider (for example Africa's Talking). Put its call inside `send_sms()` in `includes/sms.php`.
- The receipt is a print-ready page: "Print or save as PDF" gives a PDF, "Download file" gives an HTML copy.
