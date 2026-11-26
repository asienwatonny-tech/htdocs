-- MedFlow upgrade v3  (run AFTER upgrade_v2.sql: phpMyAdmin > `medflow` > SQL tab > paste > Go)
-- Adds: cashier role and payments, price lists, phone verification at registration,
-- confidentiality fields for lab and pharmacy, provisional/final diagnoses, follow-up advice.
-- Safe to run again.

SET NAMES utf8mb4;

-- 1. New role: cashier --------------------------------------------------------
ALTER TABLE staff  MODIFY role ENUM('receptionist','cashier','nurse','doctor','lab','pharmacy','admin') NOT NULL;
ALTER TABLE stages MODIFY role ENUM('receptionist','cashier','nurse','doctor','lab','pharmacy') NOT NULL;

-- 2. Payment stages and the new stage order -----------------------------------
INSERT INTO stages (seq, name, role, default_minutes, optional)
SELECT 2, 'Consultation Payment', 'cashier', 5, 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM stages WHERE name='Consultation Payment');
INSERT INTO stages (seq, name, role, default_minutes, optional)
SELECT 6, 'Lab Payment', 'cashier', 5, 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM stages WHERE name='Lab Payment');
INSERT INTO stages (seq, name, role, default_minutes, optional)
SELECT 9, 'Pharmacy Payment', 'cashier', 5, 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM stages WHERE name='Pharmacy Payment');
INSERT INTO stages (seq, name, role, default_minutes, optional)
SELECT 11, 'Settlement', 'cashier', 5, 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM stages WHERE name='Settlement');

UPDATE stages SET seq = 1  WHERE name = 'Registration';
UPDATE stages SET seq = 2  WHERE name = 'Consultation Payment';
UPDATE stages SET seq = 3  WHERE name = 'Nurse/Triage';
UPDATE stages SET seq = 4  WHERE name = 'Doctor';
UPDATE stages SET seq = 5  WHERE name = 'Specialist';
UPDATE stages SET seq = 6  WHERE name = 'Lab Payment';
UPDATE stages SET seq = 7  WHERE name = 'Laboratory';
UPDATE stages SET seq = 8  WHERE name = 'Results';
UPDATE stages SET seq = 9  WHERE name = 'Pharmacy Payment';
UPDATE stages SET seq = 10 WHERE name = 'Pharmacy';
UPDATE stages SET seq = 11 WHERE name = 'Settlement';

-- 3. Phone verification at registration ---------------------------------------
ALTER TABLE patients ADD COLUMN IF NOT EXISTS phone_verified_at DATETIME NULL;
ALTER TABLE visits
  ADD COLUMN IF NOT EXISTS verify_status ENUM('not_needed','pending','verified') NOT NULL DEFAULT 'not_needed',
  ADD COLUMN IF NOT EXISTS follow_up VARCHAR(255) NULL;
ALTER TABLE otp_codes ADD COLUMN IF NOT EXISTS purpose ENUM('track','register') NOT NULL DEFAULT 'track';

-- 4. Clinical records: allergies, provisional/final diagnosis, reason for test ----
ALTER TABLE vitals ADD COLUMN IF NOT EXISTS allergies VARCHAR(255) NULL;
ALTER TABLE diagnoses ADD COLUMN IF NOT EXISTS status ENUM('provisional','final') NOT NULL DEFAULT 'final';

-- 5. Price lists --------------------------------------------------------------
ALTER TABLE specialties ADD COLUMN IF NOT EXISTS consult_fee DECIMAL(10,2) NOT NULL DEFAULT 500.00;
UPDATE specialties SET consult_fee = 1000.00 WHERE id IN (2,3,4) AND consult_fee = 500.00;
UPDATE specialties SET consult_fee = 800.00  WHERE id = 5 AND consult_fee = 500.00;

CREATE TABLE IF NOT EXISTS lab_tests (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_lab_test_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO lab_tests (name, price) VALUES
  ('Full blood count', 500), ('Malaria rapid test', 250), ('Blood sugar', 150), ('Urinalysis', 200),
  ('Stool analysis', 250), ('Pregnancy test', 150), ('HIV rapid test', 200), ('Typhoid test', 400),
  ('Liver function tests', 1200), ('Kidney function tests', 1200), ('Chest X-ray', 1500);

CREATE TABLE IF NOT EXISTS medicines (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'tablet',
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_medicine_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO medicines (name, unit, unit_price) VALUES
  ('Paracetamol 500 mg', 'tablet', 3), ('Artemether-lumefantrine', 'tablet', 15), ('Amoxicillin 500 mg', 'capsule', 15),
  ('Ibuprofen 400 mg', 'tablet', 8), ('Oral rehydration salts', 'sachet', 30), ('Iron tablets', 'tablet', 5),
  ('Lubricating eye drops', 'bottle', 350), ('Cough syrup', 'bottle', 250), ('Salbutamol inhaler', 'inhaler', 600),
  ('Metformin 500 mg', 'tablet', 6), ('Amlodipine 5 mg', 'tablet', 8), ('Ciprofloxacin 500 mg', 'tablet', 20),
  ('Azithromycin 500 mg', 'tablet', 60), ('Cetirizine 10 mg', 'tablet', 10), ('Omeprazole 20 mg', 'capsule', 15);

ALTER TABLE lab_orders
  ADD COLUMN IF NOT EXISTS test_id INT NULL,
  ADD COLUMN IF NOT EXISTS clinical_info VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS price DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE prescriptions
  ADD COLUMN IF NOT EXISTS medicine_id INT NULL,
  ADD COLUMN IF NOT EXISTS quantity INT NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS unit_price DECIMAL(10,2) NOT NULL DEFAULT 0;

-- 6. Billing ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  visit_id INT NOT NULL,
  receipt_no VARCHAR(30) NULL,
  amount DECIMAL(10,2) NOT NULL,
  method ENUM('cash','mpesa','card','insurance','waived') NOT NULL,
  reference VARCHAR(100) NULL,
  tendered DECIMAL(10,2) NULL,
  stage VARCHAR(30) NULL,
  received_by INT NULL,
  paid_at DATETIME NOT NULL DEFAULT current_timestamp(),
  UNIQUE KEY uq_receipt_no (receipt_no),
  KEY idx_pay_visit (visit_id),
  KEY idx_pay_time (paid_at),
  CONSTRAINT fk_pay_visit FOREIGN KEY (visit_id) REFERENCES visits(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS charges (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  visit_id INT NOT NULL,
  kind ENUM('consultation','specialist','lab','medication') NOT NULL,
  description VARCHAR(150) NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  status ENUM('unpaid','paid','waived') NOT NULL DEFAULT 'unpaid',
  ref_table VARCHAR(20) NULL,
  ref_id INT NULL,
  payment_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  KEY idx_charge_visit (visit_id, status),
  CONSTRAINT fk_charge_visit FOREIGN KEY (visit_id) REFERENCES visits(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
