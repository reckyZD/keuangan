<?php
// ====== DATABASE ======
const DB_HOST = 'localhost';
const DB_NAME = 'keuangan_db';
const DB_USER = 'root';
const DB_PASS = '';

// ====== ATURAN JAM KERJA ======
// Hari kerja Senin-Sabtu. Hari libur = Minggu (7) dan tanggal merah.
const HARI_LIBUR       = [7];      // 1=Senin ... 7=Minggu
const UPAH_LEMBUR_PER_JAM = 7000;  // tarif tetap per jam, berlaku untuk semua hari
const JAM_MASUK        = '08:00';
const JAM_PULANG       = '17:00';  // lembur dihitung setelah jam ini
const MIN_LEMBUR_MENIT = 30;       // kurang dari ini tidak dihitung lembur
const PEMBULATAN_MENIT = 30;       // dibulatkan ke bawah per 30 menit (isi 60 untuk per jam)
const ISTIRAHAT_MENIT  = 60;       // dipotong jika kerja di hari libur >= 5 jam
const MIN_SELANG_SCAN_MENIT = 60;  // import fingerprint: selang scan pertama & terakhir minimal ini

// ====== ATURAN KAS ======
// Gaji pokok dan upah lembur TIDAK masuk kas.
// (Ubah ke true hanya jika suatu saat lembur ingin dicatat sebagai pengeluaran kas.)
const LEMBUR_DARI_KAS = false;

// ====== MODE UJI COBA ======
// true  = tombol "Reset gaji" tampil di halaman Gaji & slip (untuk mencoba proses gaji berulang kali).
// false = sembunyikan tombol reset. WAJIB diubah ke false saat aplikasi dipakai sungguhan.
const MODE_TRIAL = true;
