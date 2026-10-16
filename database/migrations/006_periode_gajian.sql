-- Periode gajian fleksibel (sebulan sekali / 2x sebulan / tiap 2 minggu) + rincian potongan kasbon untuk Reset.
-- (Aplikasi juga menjalankan ALTER/CREATE ini otomatis saat dibutuhkan.)
USE keuangan_db;
ALTER TABLE gaji MODIFY periode VARCHAR(12) NOT NULL;
CREATE TABLE IF NOT EXISTS gaji_potongan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  gaji_id INT NOT NULL,
  kasbon_id INT NOT NULL,
  jumlah DECIMAL(15,2) NOT NULL,
  INDEX idx_gaji (gaji_id)
) ENGINE=InnoDB;
INSERT IGNORE INTO pengaturan (kunci, nilai) VALUES
('gajian_mode', 'dua_mingguan'), ('gajian_awal', '2026-09-19'), ('gajian_akhir', '2026-10-03'), ('gaji_pokok_cara', 'penuh');
