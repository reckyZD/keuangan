<?php
// ====== DATABASE ======
const DB_HOST = 'localhost';
const DB_NAME = 'keuangan_db';
const DB_USER = 'root';
const DB_PASS = '';

// ====== ATURAN JAM KERJA ======
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
