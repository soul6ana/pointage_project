-- Run this once in phpMyAdmin on database pointage_emp

ALTER TABLE pointages
  ADD COLUMN verification_method ENUM('webauthn','otp_fallback') NOT NULL DEFAULT 'webauthn' AFTER adresse,
  ADD COLUMN otp_request_id INT NULL AFTER verification_method;

CREATE TABLE IF NOT EXISTS otp_fallback_requests (
  id INT NOT NULL AUTO_INCREMENT,
  employe_id INT NOT NULL,
  type ENUM('arrivee','depart') NOT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  requested_latitude DECIMAL(10,7) DEFAULT NULL,
  requested_longitude DECIMAL(10,7) DEFAULT NULL,
  requested_address VARCHAR(255) DEFAULT NULL,
  request_reason VARCHAR(255) DEFAULT NULL,
  status ENUM('pending','approved','rejected','used','expired') NOT NULL DEFAULT 'pending',
  approved_by_admin_id INT DEFAULT NULL,
  approved_at DATETIME DEFAULT NULL,
  rejected_by_admin_id INT DEFAULT NULL,
  rejected_at DATETIME DEFAULT NULL,
  decision_note VARCHAR(255) DEFAULT NULL,
  otp_hash VARCHAR(255) DEFAULT NULL,
  otp_expires_at DATETIME DEFAULT NULL,
  otp_used_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY employe_id (employe_id),
  KEY status (status)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
