-- Siklus gaji bulanan / 2x sebulan + rincian potongan kasbon (untuk fitur reset gaji).
-- Jalankan sekali di database lama. (Halaman Gaji & slip juga melakukannya otomatis.)
USE keuangan_db;
ALTER TABLE gaji MODIFY periode VARCHAR(10) NOT NULL;
CREATE TABLE IF NOT EXISTS gaji_potongan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  gaji_id INT NOT NULL,
  kasbon_id INT NOT NULL,
  jumlah DECIMAL(15,2) NOT NULL,
  INDEX (gaji_id),
  FOREIGN KEY (gaji_id) REFERENCES gaji(id) ON DELETE CASCADE
) ENGINE=InnoDB;
INSERT IGNORE INTO pengaturan (kunci, nilai) VALUES ('gaji_siklus', 'bulanan');
