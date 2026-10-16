CREATE DATABASE IF NOT EXISTS keuangan_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE keuangan_db;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','staff') NOT NULL DEFAULT 'admin',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE karyawan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nip VARCHAR(30) NOT NULL UNIQUE,
  nama VARCHAR(100) NOT NULL,
  jabatan VARCHAR(100) DEFAULT NULL,
  gaji_pokok DECIMAL(15,2) NOT NULL DEFAULT 0,
  pin_fingerprint VARCHAR(30) DEFAULT NULL UNIQUE,
  aktif TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE lembur (
  id INT AUTO_INCREMENT PRIMARY KEY,
  karyawan_id INT NOT NULL,
  tanggal DATE NOT NULL,
  jam DECIMAL(4,1) NOT NULL,
  tipe ENUM('hari_kerja','libur') NOT NULL DEFAULT 'hari_kerja',
  upah_per_jam DECIMAL(15,2) NOT NULL,
  total DECIMAL(15,2) NOT NULL,
  keterangan VARCHAR(255) DEFAULT NULL,
  FOREIGN KEY (karyawan_id) REFERENCES karyawan(id)
) ENGINE=InnoDB;

CREATE TABLE kasbon (
  id INT AUTO_INCREMENT PRIMARY KEY,
  karyawan_id INT NOT NULL,
  tanggal DATE NOT NULL,
  jumlah DECIMAL(15,2) NOT NULL,
  cicilan_per_bulan DECIMAL(15,2) NOT NULL,
  sisa DECIMAL(15,2) NOT NULL,
  status ENUM('aktif','lunas') NOT NULL DEFAULT 'aktif',
  keterangan VARCHAR(255) DEFAULT NULL,
  FOREIGN KEY (karyawan_id) REFERENCES karyawan(id)
) ENGINE=InnoDB;

CREATE TABLE gaji (
  id INT AUTO_INCREMENT PRIMARY KEY,
  karyawan_id INT NOT NULL,
  periode VARCHAR(12) NOT NULL,         -- YYYY-MM | YYYY-MM-1/2 (2x sebulan) | YYYY-MM-DD (tiap 2 minggu)
  gaji_pokok DECIMAL(15,2) NOT NULL,
  total_lembur DECIMAL(15,2) NOT NULL DEFAULT 0,
  potongan_kasbon DECIMAL(15,2) NOT NULL DEFAULT 0,
  gaji_bersih DECIMAL(15,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_karyawan_periode (karyawan_id, periode),
  FOREIGN KEY (karyawan_id) REFERENCES karyawan(id)
) ENGINE=InnoDB;

CREATE TABLE kas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tanggal DATE NOT NULL,
  tipe ENUM('masuk','keluar') NOT NULL,
  kategori VARCHAR(50) NOT NULL,
  jumlah DECIMAL(15,2) NOT NULL,
  keterangan VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB;

CREATE TABLE absensi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  karyawan_id INT NOT NULL,
  tanggal DATE NOT NULL,
  jam_masuk TIME NOT NULL,
  jam_keluar TIME NOT NULL,
  tanggal_merah TINYINT(1) NOT NULL DEFAULT 0,
  lembur_id INT DEFAULT NULL,
  UNIQUE KEY uq_absen (karyawan_id, tanggal),
  FOREIGN KEY (karyawan_id) REFERENCES karyawan(id),
  FOREIGN KEY (lembur_id) REFERENCES lembur(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE log_scan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pin VARCHAR(30) NOT NULL,
  waktu DATETIME NOT NULL,
  UNIQUE KEY uq_scan (pin, waktu)
) ENGINE=InnoDB;

CREATE TABLE pengaturan (
  kunci VARCHAR(50) NOT NULL PRIMARY KEY,
  nilai TEXT NULL
) ENGINE=InnoDB;

INSERT INTO pengaturan (kunci, nilai) VALUES
('nama', 'PT ADBIRA SELONGSONG PAPER'),
('alamat', ''), ('telp', ''), ('email', ''), ('kota', ''), ('logo', ''),
('ttd_nama', ''), ('ttd_jabatan', 'Keuangan'),
('gajian_mode', 'dua_mingguan'), ('gajian_awal', '2026-09-19'), ('gajian_akhir', '2026-10-03'), ('gaji_pokok_cara', 'penuh');

CREATE TABLE gaji_potongan (          -- rincian potongan kasbon per slip (untuk tombol Reset)
  id INT AUTO_INCREMENT PRIMARY KEY,
  gaji_id INT NOT NULL,
  kasbon_id INT NOT NULL,
  jumlah DECIMAL(15,2) NOT NULL,
  INDEX idx_gaji (gaji_id)
) ENGINE=InnoDB;
