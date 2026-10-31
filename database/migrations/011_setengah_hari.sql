-- Aturan setengah hari: pulang sebelum 13:00 atau masuk 12:00-13:00 (hari kerja biasa, bukan Minggu / tanggal merah).
-- Hari kerja jadi 0,5 otomatis saat gaji diproses, tetapi LEMBUR NEGATIF lama dari absensi yang sudah terlanjur
-- diimpor harus dibereskan. Jalankan sekali. Sesuaikan 13:00 / 12:00 / '17:00:00' jika config.php diubah.
-- Untuk periode yang gajinya sudah diproses: Reset gaji periode itu dulu, jalankan file ini, lalu proses ulang.
USE keuangan_db;

-- 1) Masuk siang (12:00-13:00): lembur hanya jam setelah 17:00 (tidak negatif)
UPDATE lembur l JOIN absensi a ON a.lembur_id = l.id
SET l.jam   = IF(a.jam_keluar > '17:00:00', ROUND(TIME_TO_SEC(TIMEDIFF(a.jam_keluar, '17:00:00')) / 3600, 2), 0),
    l.total = ROUND(IF(a.jam_keluar > '17:00:00', ROUND(TIME_TO_SEC(TIMEDIFF(a.jam_keluar, '17:00:00')) / 3600, 2), 0) * l.upah_per_jam)
WHERE a.tanggal_merah = 0 AND WEEKDAY(a.tanggal) <> 6
  AND a.jam_masuk >= '12:00:00' AND a.jam_masuk <= '13:00:00'
  AND NOT (a.jam_keluar > a.jam_masuk AND a.jam_keluar < '13:00:00');

-- 2) Pulang sebelum 13:00: tidak ada lembur
UPDATE lembur l JOIN absensi a ON a.lembur_id = l.id
SET l.jam = 0, l.total = 0
WHERE a.tanggal_merah = 0 AND WEEKDAY(a.tanggal) <> 6
  AND a.jam_keluar > a.jam_masuk AND a.jam_keluar < '13:00:00';

-- 3) Buang baris lembur kosong (absensi.lembur_id otomatis jadi NULL)
DELETE l FROM lembur l JOIN absensi a ON a.lembur_id = l.id
WHERE l.jam = 0 AND l.total = 0 AND a.tanggal_merah = 0 AND WEEKDAY(a.tanggal) <> 6
  AND ((a.jam_keluar > a.jam_masuk AND a.jam_keluar < '13:00:00') OR (a.jam_masuk >= '12:00:00' AND a.jam_masuk <= '13:00:00'));
