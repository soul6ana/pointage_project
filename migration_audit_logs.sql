-- Run this once in phpMyAdmin on database pointage_emp

CREATE TABLE IF NOT EXISTS audit_logs (
  id INT NOT NULL AUTO_INCREMENT,
  admin_id INT NOT NULL,
  employe_id INT DEFAULT NULL,
  action VARCHAR(80) NOT NULL,
  details VARCHAR(255) DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY admin_id (admin_id),
  KEY employe_id (employe_id),
  KEY action (action),
  KEY created_at (created_at)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;