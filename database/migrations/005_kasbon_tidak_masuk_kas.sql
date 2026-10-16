-- Pelunasan kasbon dibayar langsung owner lewat potong gaji, jadi tidak dicatat sebagai kas masuk.
-- Jalankan sekali jika sebelumnya sudah memproses gaji dengan versi lama.
USE keuangan_db;
DELETE FROM kas WHERE kategori = 'Pelunasan Kasbon';
