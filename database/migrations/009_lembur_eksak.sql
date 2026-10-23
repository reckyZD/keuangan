-- Lembur dihitung eksak (mis. 1,55 jam) dan tarif per jam diatur di Pengaturan.
-- Jalankan sekali di database lama. (Halaman Absensi/Lembur/Import juga mengubah kolom otomatis.)
USE keuangan_db;
ALTER TABLE lembur MODIFY jam DECIMAL(5,2) NOT NULL;
INSERT IGNORE INTO pengaturan (kunci, nilai) VALUES ('upah_lembur_per_jam', '7000');
