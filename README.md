# Aplikasi Keuangan (PHP + Bootstrap)

## Struktur
```
keuangan/
├── index.php            -> mengarahkan ke public/
├── app/                 -> kode inti (tidak bisa diakses langsung dari browser)
│   ├── config.php       -> pengaturan DB, jam kerja, aturan kas
│   ├── bootstrap.php    -> session, koneksi DB, memuat fungsi
│   ├── functions.php
│   └── views/           -> head.php, header.php (sidebar), footer.php, kas_form.php
├── database/
│   ├── schema.sql       -> instalasi baru (sudah lengkap)
│   └── migrations/      -> hanya untuk database lama (001 - 005)
└── public/              -> halaman aplikasi (samples/ = contoh file fingerprint)
    ├── login.php, logout.php, setup.php
    ├── index.php (dashboard)
    ├── karyawan.php, absensi.php, import_absensi.php
    ├── lembur.php, kasbon.php, gaji.php, kas.php
    ├── slip_gaji.php (cetak slip 1/3 A4), pengaturan.php (data perusahaan & logo)
    ├── assets/ (app.css, app.js, slip.css)
    ├── uploads/ (logo perusahaan; pastikan bisa ditulis)
    └── samples/ (1_zkteco_attlog.dat, 2_laporan_excel_titik_koma.csv, 3_tanggal_jam_terpisah.csv)
```

## Instalasi
1. Import `database/schema.sql`.
2. Sesuaikan `app/config.php`.
3. Letakkan folder di htdocs/www, buka `http://localhost/keuangan/`.
4. Buka `public/setup.php` SEKALI (membuat admin / admin123), lalu HAPUS file itu dan ganti password.

## Upgrade dari versi lama
Jalankan file di `database/migrations/` yang belum pernah dijalankan, berurutan:
001_absensi.sql, 002_fingerprint.sql, 003_kas_tanpa_gaji.sql, 004_pengaturan.sql, 005_kasbon_tidak_masuk_kas.sql, 006_siklus_gaji.sql
(004 juga dibuat otomatis saat halaman Pengaturan dibuka pertama kali.)

## Tampilan & fitur baru
- Login, dashboard, dan semua halaman memakai sidebar (otomatis jadi menu geser di HP).
- **Catat kas** ada di dashboard (form cepat + 7 hari terakhir + transaksi terakhir) dan
  tombol "Catat kas" di sidebar/topbar membuka form yang sama di halaman mana pun.
- **Pengaturan > data perusahaan**: nama (bawaan PT ADBIRA SELONGSONG PAPER), alamat, telepon,
  email, logo, kota & nama penandatangan.
- **Slip gaji** (menu Gaji & slip): "Cetak slip" per pegawai atau "Cetak semua slip".
  Ukuran 1/3 A4 (210 x 99 mm); cetak semua = 3 slip per lembar A4.
  Di dialog cetak browser pilih: kertas A4, skala 100% (Default), margin None/Tidak ada.

## Siklus gaji & reset (uji coba)
- **Pengaturan > Siklus gaji**: sebulan sekali, atau 2x sebulan (per 2 minggu: tahap 1 tgl 1-15, tahap 2 tgl 16-akhir).
  Mode 2x sebulan membagi gaji pokok dan cicilan kasbon dua per tahap; lembur dihitung sesuai tanggal tahap.
- **Gaji & slip > Uji coba: reset gaji**: "Reset periode ini" atau "Reset semua gaji" (ketik RESET). Sisa kasbon dikembalikan.
  Tombol hanya tampil jika `MODE_TRIAL = true` di `app/config.php` (ubah ke false saat dipakai sungguhan).

## Aturan kas
- Kasbon dicairkan = pengeluaran kas.
- Potongan kasbon saat gaji diproses TIDAK masuk kas (dibayar langsung owner lewat potong gaji).
- Gaji pokok dan upah lembur tidak dicatat di kas.
- Hari kerja Senin-Sabtu (Minggu & tanggal merah = lembur penuh). Upah lembur tetap Rp 7.000/jam;
  ubah di `app/config.php` (`UPAH_LEMBUR_PER_JAM`, `HARI_LIBUR`).
- (Opsional) `LEMBUR_DARI_KAS = true` di `app/config.php` jika lembur ingin dicatat sebagai pengeluaran kas.

Data uji: jalankan database/sample_data.sql lalu import file di public/samples/.

Butuh PHP 8.1+ dengan ekstensi PDO MySQL dan mbstring.
