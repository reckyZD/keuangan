-- Jalankan jika database sudah dibuat dari versi sebelumnya
USE keuangan_db;
ALTER TABLE karyawan ADD COLUMN pin_fingerprint VARCHAR(30) DEFAULT NULL UNIQUE AFTER gaji_pokok;
CREATE TABLE IF NOT EXISTS log_scan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pin VARCHAR(30) NOT NULL,
  waktu DATETIME NOT NULL,
  UNIQUE KEY uq_scan (pin, waktu)
) ENGINE=InnoDB;
