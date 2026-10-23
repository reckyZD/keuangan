-- Hari kerja boleh pecahan: hadir kurang dari 9 jam dibayar upah harian / 9 x jam hadir.
-- Jalankan sekali di database lama. (Halaman Karyawan & Gaji juga mengubahnya otomatis.)
USE keuangan_db;
ALTER TABLE gaji MODIFY hari_kerja DECIMAL(7,2) NOT NULL DEFAULT 0;
