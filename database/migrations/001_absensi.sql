-- Jalankan ini jika database sudah terlanjur dibuat dari versi sebelumnya
USE keuangan_db;
CREATE TABLE IF NOT EXISTS absensi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  karyawan_id INT NOT NULL,
  tanggal DATE NOT NULL,
  jam_masuk TIME NOT NULL,
  jam_keluar TIME NOT NULL,
  tanggal_merah TINYINT(1) NOT NULL DEFAULT 0,
  lembur_id INT DEFAULT NULL,
  UNIQUE KEY uq_absen (karyawan_id, tanggal),
  FOREIGN KEY (karyawan_id) REFERENCES karyawan(id),
  FOREIGN KEY (lembur_id) REFERENCES lembur(id) ON DELETE SET NULL
) ENGINE=InnoDB;
