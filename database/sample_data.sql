-- Data uji untuk mencoba import file di public/samples/ (PIN 1-4 cocok dengan file contoh)
USE keuangan_db;
INSERT IGNORE INTO karyawan (nip, nama, jabatan, gaji_pokok, pin_fingerprint, aktif) VALUES
('K001', 'Budi',  'Staff',      5000000, '1', 1),
('K002', 'Siti',  'Staff',      4500000, '2', 1),
('K003', 'Andi',  'Operator',   4000000, '3', 1),
('K004', 'Dewi',  'Admin',      4800000, '4', 1);
