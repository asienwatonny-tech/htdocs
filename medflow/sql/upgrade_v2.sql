-- MedFlow upgrade v2  (run once in phpMyAdmin: select `medflow` > SQL tab > paste > Go)
-- Safe to run again: every step checks whether it is already done.
-- Your existing patients, visits, staff and passwords are kept.

SET NAMES utf8mb4;

-- 1. Specialties (for matching patients to the right doctor) ---------------
CREATE TABLE IF NOT EXISTS specialties (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  UNIQUE KEY uq_specialty_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO specialties (id, name) VALUES
  (1, 'General Medicine'), (2, 'Optometry (Eye)'), (3, 'Dental'), (4, 'ENT'), (5, 'Paediatrics');

-- 2. Staff: specialty, duty status, forced password change, login lock -------
ALTER TABLE staff
  ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS specialty_id INT NULL,
  ADD COLUMN IF NOT EXISTS on_duty TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS failed_logins INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL,
  ADD COLUMN IF NOT EXISTS last_login DATETIME NULL;

UPDATE staff SET specialty_id = 1 WHERE role = 'doctor' AND specialty_id IS NULL;

-- 3. Patients: optional details ---------------------------------------------
ALTER TABLE patients
  ADD COLUMN IF NOT EXISTS gender ENUM('female','male','other') NULL,
  ADD COLUMN IF NOT EXISTS date_of_birth DATE NULL;

-- 4. Visits: emergency flag, requested specialty, assigned doctor -----------
ALTER TABLE visits
  ADD COLUMN IF NOT EXISTS priority ENUM('normal','emergency') NOT NULL DEFAULT 'normal',
  ADD COLUMN IF NOT EXISTS emergency_reason VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS needed_specialty_id INT NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS assigned_doctor_id INT NULL,
  ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL;

-- 5. Stages: add the Specialist stage (only used when a doctor refers) ------
ALTER TABLE stages ADD COLUMN IF NOT EXISTS optional TINYINT(1) NOT NULL DEFAULT 0;

INSERT INTO stages (seq, name, role, default_minutes, optional)
SELECT 4, 'Specialist', 'doctor', 20, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM stages WHERE name = 'Specialist');

UPDATE stages SET seq = 5 WHERE name = 'Laboratory';
UPDATE stages SET seq = 6 WHERE name = 'Results';
UPDATE stages SET seq = 7 WHERE name = 'Pharmacy';

-- 6. Visit stages: which doctor a stage is assigned to ----------------------
ALTER TABLE visit_stages ADD COLUMN IF NOT EXISTS assigned_to INT NULL;
ALTER TABLE visit_stages ADD INDEX IF NOT EXISTS idx_vs_assigned (assigned_to, status);

-- 7. Clinical records -------------------------------------------------------
CREATE TABLE IF NOT EXISTS vitals (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  visit_id INT NOT NULL,
  weight_kg DECIMAL(5,2) NULL,
  height_cm DECIMAL(5,1) NULL,
  bp_systolic SMALLINT NULL,
  bp_diastolic SMALLINT NULL,
  temperature_c DECIMAL(4,1) NULL,
  pulse SMALLINT NULL,
  spo2 SMALLINT NULL,
  complaint VARCHAR(255) NULL,
  notes TEXT NULL,
  recorded_by INT NULL,
  recorded_at DATETIME NOT NULL DEFAULT current_timestamp(),
  KEY idx_vitals_visit (visit_id),
  CONSTRAINT fk_vitals_visit FOREIGN KEY (visit_id) REFERENCES visits(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS observations (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  visit_id INT NOT NULL,
  label VARCHAR(60) NOT NULL,
  value VARCHAR(100) NOT NULL,
  recorded_by INT NULL,
  recorded_at DATETIME NOT NULL DEFAULT current_timestamp(),
  KEY idx_obs_visit (visit_id),
  CONSTRAINT fk_obs_visit FOREIGN KEY (visit_id) REFERENCES visits(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS diagnoses (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  visit_id INT NOT NULL,
  stage_id INT NULL,
  doctor_id INT NOT NULL,
  diagnosis VARCHAR(255) NOT NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  KEY idx_dx_visit (visit_id),
  CONSTRAINT fk_dx_visit FOREIGN KEY (visit_id) REFERENCES visits(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lab_orders (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  visit_id INT NOT NULL,
  ordered_by INT NOT NULL,
  test_name VARCHAR(100) NOT NULL,
  instructions VARCHAR(255) NULL,
  status ENUM('ordered','done') NOT NULL DEFAULT 'ordered',
  result TEXT NULL,
  result_by INT NULL,
  ordered_at DATETIME NOT NULL DEFAULT current_timestamp(),
  resulted_at DATETIME NULL,
  KEY idx_lab_visit (visit_id),
  CONSTRAINT fk_lab_visit FOREIGN KEY (visit_id) REFERENCES visits(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS prescriptions (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  visit_id INT NOT NULL,
  prescribed_by INT NOT NULL,
  medication VARCHAR(100) NOT NULL,
  dosage VARCHAR(100) NOT NULL,
  instructions VARCHAR(255) NULL,
  dispensed TINYINT(1) NOT NULL DEFAULT 0,
  dispensed_by INT NULL,
  dispensed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  KEY idx_rx_visit (visit_id),
  CONSTRAINT fk_rx_visit FOREIGN KEY (visit_id) REFERENCES visits(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS referrals (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  visit_id INT NOT NULL,
  from_doctor_id INT NOT NULL,
  to_specialty_id INT NOT NULL,
  to_doctor_id INT NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  completed_at DATETIME NULL,
  KEY idx_ref_visit (visit_id),
  KEY idx_ref_from (from_doctor_id),
  KEY idx_ref_to (to_doctor_id),
  CONSTRAINT fk_ref_visit FOREIGN KEY (visit_id) REFERENCES visits(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Security: one-time codes, SMS outbox, audit log ------------------------
CREATE TABLE IF NOT EXISTS otp_codes (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  visit_id INT NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts SMALLINT NOT NULL DEFAULT 0,
  used TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  KEY idx_otp_visit (visit_id, used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sms_outbox (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  visit_id INT NULL,
  phone VARCHAR(20) NOT NULL,
  message VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  staff_id INT NULL,
  action VARCHAR(40) NOT NULL,
  detail VARCHAR(255) NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  KEY idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
