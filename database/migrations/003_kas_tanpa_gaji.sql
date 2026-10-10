-- Hapus catatan "Gaji" dan "Lembur" otomatis dari kas (keduanya di luar kas).
-- Jalankan sekali jika Anda sudah pernah memproses gaji dengan versi sebelumnya.
USE keuangan_db;
DELETE FROM kas WHERE kategori IN ('Gaji', 'Lembur');
