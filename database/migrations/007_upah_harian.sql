-- Pekerja harian: upah per hari per karyawan (tanpa gaji pokok bulanan).
-- Jalankan sekali di database lama. (Halaman Karyawan & Gaji juga menambahkannya otomatis.)
USE keuangan_db;
ALTER TABLE karyawan ADD COLUMN upah_harian DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER gaji_pokok;
ALTER TABLE gaji ADD COLUMN hari_kerja INT NOT NULL DEFAULT 0, ADD COLUMN upah_harian DECIMAL(15,2) NOT NULL DEFAULT 0;
