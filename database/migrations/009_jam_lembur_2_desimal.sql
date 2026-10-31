-- Opsional: jam lembur disimpan 2 desimal (kolom lama hanya 1 desimal, 4,75 jam tersimpan sebagai 4,8).
-- Perlu hanya jika PEMBULATAN_MENIT di config.php diisi kurang dari 30 (mis. 15). Dengan 30 atau 60 tidak perlu.
USE keuangan_db;
ALTER TABLE lembur MODIFY jam DECIMAL(5,2) NOT NULL;
