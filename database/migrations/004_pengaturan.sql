-- Pengaturan perusahaan (kop slip gaji). Jalankan sekali di database lama.
-- (Halaman Pengaturan perusahaan juga membuat tabel ini otomatis bila belum ada.)
USE keuangan_db;
CREATE TABLE IF NOT EXISTS pengaturan (
  kunci VARCHAR(50) NOT NULL PRIMARY KEY,
  nilai TEXT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO pengaturan (kunci, nilai) VALUES
('nama', 'PT ADBIRA SELONGSONG PAPER'),
('alamat', ''), ('telp', ''), ('email', ''), ('kota', ''), ('logo', ''),
('ttd_nama', ''), ('ttd_jabatan', 'Keuangan');
