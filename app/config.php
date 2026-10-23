<?php
// ====== DATABASE ======
const DB_HOST = 'localhost';
const DB_NAME = 'keuangan_db';
const DB_USER = 'root';
const DB_PASS = '';

// ====== ATURAN JAM KERJA ======
// Upah lembur per jam diatur di menu Pengaturan (bukan di sini).
// Lembur hari kerja = (jam keluar - jam masuk) - 9 jam, tanpa batas minimal dan tanpa pembulatan.
// Hasil minus (kerja < 9 jam) mengurangi total lembur; upah harian tidak dipotong.
// Hari kerja Senin-Sabtu. Hari libur = Minggu (7) dan tanggal merah.
const HARI_LIBUR       = [7];      // 1=Senin ... 7=Minggu
const JAM_MASUK        = '08:00';
const JAM_PULANG       = '17:00';  // lembur dihitung setelah jam ini
const JAM_KERJA_MENIT  = 540;      // 9 jam (08:00-17:00 termasuk istirahat). 9 jam dihitung dari jam masuk (awal maupun telat), sisanya lembur
const DEFAULT_MASUK_MAKS = '13:00'; // scan tunggal sebelum jam ini = jam masuk (tanpa scan pulang -> jam pulang diisi JAM_PULANG 17:00, tanpa lembur)
const ISTIRAHAT_MENIT  = 60;       // dipotong jika kerja di hari libur >= 5 jam
const MIN_SELANG_SCAN_MENIT = 60;  // import fingerprint: selang scan pertama & terakhir minimal ini

// ====== ATURAN KAS ======
// Gaji pokok dan upah lembur TIDAK masuk kas.
// (Ubah ke true hanya jika suatu saat lembur ingin dicatat sebagai pengeluaran kas.)
const LEMBUR_DARI_KAS = false;
