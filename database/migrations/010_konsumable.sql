-- Role pengelola konsumable + tabel konsumable (stok IN/OUT). Jalankan sekali di database lama.
-- (Halaman Pengguna dan Konsumable juga membuatnya otomatis.)
USE keuangan_db;
ALTER TABLE users MODIFY role ENUM('admin','staff','konsumable') NOT NULL DEFAULT 'admin';
CREATE TABLE IF NOT EXISTS konsumable (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tanggal DATE NOT NULL,
  jenis ENUM('masuk','keluar') NOT NULL DEFAULT 'masuk',
  nama_barang VARCHAR(150) NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  satuan VARCHAR(20) DEFAULT NULL,
  harga_satuan DECIMAL(15,2) NOT NULL DEFAULT 0,
  total DECIMAL(15,2) NOT NULL DEFAULT 0,
  bagian VARCHAR(100) DEFAULT NULL,
  keterangan VARCHAR(255) DEFAULT NULL,
  dicatat_oleh VARCHAR(100) DEFAULT NULL,
  INDEX idx_tgl (tanggal)
) ENGINE=InnoDB;
-- Jika tabel konsumable sudah ada dari versi sebelumnya (hanya pembelian), jalankan dua baris ini:
-- ALTER TABLE konsumable ADD COLUMN jenis ENUM('masuk','keluar') NOT NULL DEFAULT 'masuk' AFTER tanggal;
-- ALTER TABLE konsumable ADD COLUMN bagian VARCHAR(100) DEFAULT NULL AFTER total;
