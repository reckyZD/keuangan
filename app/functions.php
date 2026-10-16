<?php
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function rupiah($n): string { return 'Rp ' . number_format((float)$n, 0, ',', '.'); }

function is_logged_in(): bool { return !empty($_SESSION['user']); }

function require_login(): void {
    if (!is_logged_in()) { header('Location: login.php'); exit; }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}
function csrf_check(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
        if (!$ok) { http_response_code(419); die('Token tidak valid. Muat ulang halaman.'); }
    }
}

function flash(?string $msg = null, string $type = 'success') {
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}

function redirect(string $url): void { header("Location: $url"); exit; }

/**
 * Upah lembur = jam lembur x tarif tetap per jam (UPAH_LEMBUR_PER_JAM di app/config.php).
 * Tarif sama untuk hari kerja maupun hari libur.
 * Mengembalikan [upah_per_jam, total].
 */
function hitung_lembur(float $jam): array {
    return [(float)UPAH_LEMBUR_PER_JAM, round($jam * UPAH_LEMBUR_PER_JAM)];
}

/**
 * Hitung jam lembur dari jam masuk/keluar.
 * - Hari kerja : lembur = jam keluar - JAM_PULANG (jika >= MIN_LEMBUR_MENIT)
 * - Minggu/tanggal merah : seluruh durasi kerja (dikurangi istirahat) = lembur (Senin-Sabtu hari kerja)
 * Mengembalikan [jam_lembur, tipe].
 */
function hitung_jam_lembur(string $tanggal, string $masuk, string $keluar, bool $tanggal_merah = false): array {
    $m = strtotime("$tanggal $masuk");
    $k = strtotime("$tanggal $keluar");
    if ($k <= $m) $k += 86400;                       // pulang melewati tengah malam
    $libur = $tanggal_merah || in_array((int)date('N', strtotime($tanggal)), HARI_LIBUR, true);

    if ($libur) {
        $menit = ($k - $m) / 60;
        if ($menit >= 5 * 60) $menit -= ISTIRAHAT_MENIT;
    } else {
        $menit = ($k - strtotime("$tanggal " . JAM_PULANG)) / 60;
    }
    $tipe = $libur ? 'libur' : 'hari_kerja';
    if ($menit < MIN_LEMBUR_MENIT) return [0.0, $tipe];
    $jam = floor($menit / PEMBULATAN_MENIT) * PEMBULATAN_MENIT / 60;
    return [(float)$jam, $tipe];
}

/** Samakan format PIN fingerprint (hilangkan nol di depan: 00123 -> 123). */
function norm_pin(string $p): string {
    $p = trim($p);
    if ($p === '') return '';
    $n = ltrim($p, '0');
    return $n === '' ? '0' : $n;
}

/** Tampilkan tanggal sebagai dd/mm/yyyy (data di database tetap yyyy-mm-dd). */
function tgl(?string $d): string {
    $t = $d ? strtotime($d) : false;
    return $t ? date('d/m/Y', $t) : '';
}

/* =====================================================================
 *  UI & pengaturan perusahaan
 * ===================================================================== */

/** Alamat file aset (public/assets) + cache-busting otomatis. */
function asset(string $file): string {
    $p = __DIR__ . '/../public/assets/' . $file;
    return 'assets/' . $file . (is_file($p) ? '?v=' . filemtime($p) : '');
}

/** Halaman yang boleh dituju setelah form kas cepat disimpan. */
function safe_back(?string $p): string {
    $ok = ['index.php', 'kas.php', 'karyawan.php', 'absensi.php', 'import_absensi.php',
           'lembur.php', 'kasbon.php', 'gaji.php', 'pengaturan.php'];
    return in_array($p, $ok, true) ? $p : 'kas.php';
}

const NAMA_BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const NAMA_HARI  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

/** 2026-10 -> "Oktober 2026"; 2026-10-1 -> "Oktober 2026 (tgl 1-15)"; 2026-10-2 -> "Oktober 2026 (tgl 16-31)" */
function periode_label(string $p): string {
    if (!preg_match('/^(\d{4})-(\d{2})(?:-([12]))?$/', $p, $m)) return $p;
    $s = (NAMA_BULAN[(int)$m[2]] ?? $m[2]) . ' ' . $m[1];
    if (!empty($m[3])) {
        [$a, $b] = periode_range($p);
        $s .= ' (tgl ' . (int)substr($a, 8) . '-' . (int)substr($b, 8) . ')';
    }
    return $s;
}

/** 2026-10-10 -> "10 Oktober 2026" (hari = true -> "Sabtu, 10 Oktober 2026") */
function tgl_panjang(string $d, bool $hari = false): string {
    $t = strtotime($d);
    if (!$t) return '';
    $s = (int)date('j', $t) . ' ' . NAMA_BULAN[(int)date('n', $t)] . ' ' . date('Y', $t);
    return $hari ? NAMA_HARI[(int)date('w', $t)] . ', ' . $s : $s;
}

/** Angka -> kata dalam bahasa Indonesia (tanpa kata "rupiah"). */
function terbilang(int $n): string {
    $s = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($n < 12)   return $s[$n];
    if ($n < 20)   return terbilang($n - 10) . ' belas';
    if ($n < 100)  return terbilang(intdiv($n, 10)) . ' puluh' . ($n % 10 ? ' ' . terbilang($n % 10) : '');
    if ($n < 200)  return 'seratus' . ($n - 100 ? ' ' . terbilang($n - 100) : '');
    if ($n < 1000) return terbilang(intdiv($n, 100)) . ' ratus' . ($n % 100 ? ' ' . terbilang($n % 100) : '');
    if ($n < 2000) return 'seribu' . ($n - 1000 ? ' ' . terbilang($n - 1000) : '');
    foreach ([[1000000000000, 'triliun'], [1000000000, 'miliar'], [1000000, 'juta'], [1000, 'ribu']] as [$b, $k]) {
        if ($n >= $b) return terbilang(intdiv($n, $b)) . " $k" . ($n % $b ? ' ' . terbilang($n % $b) : '');
    }
    return '';
}

/** 4850000 -> "Empat juta delapan ratus lima puluh ribu rupiah" */
function terbilang_rp($n): string {
    $n = (int)round((float)$n);
    if ($n === 0) return 'Nol rupiah';
    $kata = ($n < 0 ? 'minus ' : '') . terbilang(abs($n));
    return ucfirst($kata) . ' rupiah';
}

/** Data perusahaan (tabel `pengaturan`); kembali ke nilai bawaan jika tabel belum ada. */
function company_defaults(): array {
    return ['nama' => 'PT ADBIRA SELONGSONG PAPER', 'alamat' => '', 'telp' => '', 'email' => '',
            'kota' => '', 'logo' => '', 'ttd_nama' => '', 'ttd_jabatan' => 'Keuangan',
            'gaji_siklus' => 'bulanan'];
}
function company(): array {
    static $c = null;
    if ($c !== null) return $c;
    global $pdo;
    $d = company_defaults();
    $c = $d;
    try {
        foreach ($pdo->query('SELECT kunci, nilai FROM pengaturan')->fetchAll() as $r) {
            if (array_key_exists($r['kunci'], $c)) $c[$r['kunci']] = (string)$r['nilai'];
        }
    } catch (Throwable $t) { /* tabel belum dibuat -> pakai bawaan */ }
    if (trim($c['nama']) === '') $c['nama'] = $d['nama'];
    return $c;
}

/** Alamat logo perusahaan (relatif dari public/) atau null. */
function company_logo_url(): ?string {
    $f = basename(company()['logo']);
    return ($f !== '' && is_file(__DIR__ . '/../public/uploads/' . $f)) ? 'uploads/' . $f : null;
}

/** Logo perusahaan; jika belum ada, pakai lambang cincin (penampang gulungan kertas). */
function brand_mark(string $class = ''): string {
    if ($u = company_logo_url()) {
        return '<img class="' . e($class) . '" src="' . e($u) . '" alt="">';
    }
    return '<span class="' . e($class) . ' is-ring" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none" stroke="currentColor">'
         . '<circle cx="16" cy="16" r="14" stroke-width="2.4"/><circle cx="16" cy="16" r="8.5" stroke-width="2.4"/>'
         . '<circle cx="16" cy="16" r="3" fill="currentColor" stroke="none"/></svg></span>';
}

/** Kategori kas yang paling sering dipakai (untuk tombol pilihan cepat). */
function kas_kategori(): array {
    global $pdo;
    $def = [
        'masuk'  => ['Penjualan', 'Lain-lain'],
        'keluar' => ['Operasional', 'Transport', 'Pembelian', 'Kasbon'],
    ];
    $out = ['masuk' => [], 'keluar' => []];
    try {
        $q = $pdo->query('SELECT tipe, kategori, COUNT(*) c FROM kas GROUP BY tipe, kategori ORDER BY c DESC, kategori');
        foreach ($q->fetchAll() as $r) {
            if ($r['kategori'] === 'Pelunasan Kasbon') continue;   // sudah tidak dipakai
            if (isset($out[$r['tipe']]) && count($out[$r['tipe']]) < 6) $out[$r['tipe']][] = $r['kategori'];
        }
    } catch (Throwable $t) {}
    foreach ($def as $t => $list) {
        foreach ($list as $k) { if (count($out[$t]) < 6 && !in_array($k, $out[$t], true)) $out[$t][] = $k; }
    }
    return $out;
}

/** Ikon garis sederhana untuk sidebar & dashboard. */
function icon(string $n, int $s = 20): string {
    static $p = [
        'home'     => '<rect x="3" y="3" width="7" height="9" rx="1.6"/><rect x="14" y="3" width="7" height="5" rx="1.6"/><rect x="14" y="12" width="7" height="9" rx="1.6"/><rect x="3" y="16" width="7" height="5" rx="1.6"/>',
        'wallet'   => '<path d="M3 7a2 2 0 0 1 2-2h13v3"/><path d="M3 7v11a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-9a1 1 0 0 0-1-1H5a2 2 0 0 1-2-2z"/><circle cx="16.5" cy="14.5" r="1.1"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18.5 14.2a6.5 6.5 0 0 1 3 5.8"/>',
        'calendar' => '<rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18M8 3v3M16 3v3M9 15l2 2 4-4"/>',
        'upload'   => '<path d="M12 15V4M7.5 8.5 12 4l4.5 4.5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'receipt'  => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
        'banknote' => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
        'building' => '<path d="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M16 9h2a2 2 0 0 1 2 2v10M2 21h20"/><path d="M8 7h4M8 11h4M8 15h4"/>',
        'logout'   => '<path d="M9 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3M16 17l5-5-5-5M21 12H9"/>',
        'menu'     => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'printer'  => '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
        'arrow-in' => '<path d="M17 7 7 17M7 8v9h9"/>',
        'arrow-out'=> '<path d="M7 17 17 7M8 7h9v9"/>',
        'eye'      => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    ];
    return '<svg class="ico" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}


/* =====================================================================
 *  Siklus gaji: bulanan, atau 2x sebulan (per 2 minggu)
 *  Kode periode : "2026-10"   = gaji sebulan penuh
 *                 "2026-10-1" = tahap 1 (tgl 1-15)
 *                 "2026-10-2" = tahap 2 (tgl 16-akhir bulan)
 * ===================================================================== */

const TAHAP_BATAS_TGL = 15;   // tahap 1 berakhir tanggal ini

/** 'bulanan' (bawaan) atau 'dua_mingguan'. */
function gaji_siklus(): string {
    return (company()['gaji_siklus'] ?? '') === 'dua_mingguan' ? 'dua_mingguan' : 'bulanan';
}
function gaji_dua_tahap(): bool { return gaji_siklus() === 'dua_mingguan'; }

function periode_valid(string $p): bool {
    return (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])(-[12])?$/', $p);
}

/** [tanggal_mulai, tanggal_akhir] (Y-m-d) dari kode periode. */
function periode_range(string $p): array {
    $ym   = substr($p, 0, 7);
    $akhir = date('Y-m-t', strtotime("$ym-01"));
    if (strlen($p) === 9) {
        return substr($p, 8, 1) === '1' ? ["$ym-01", sprintf('%s-%02d', $ym, TAHAP_BATAS_TGL)]
                                         : [sprintf('%s-%02d', $ym, TAHAP_BATAS_TGL + 1), $akhir];
    }
    return ["$ym-01", $akhir];
}

/** Kode periode untuk sebuah tanggal menurut siklus yang aktif. */
function periode_sekarang(?string $tanggal = null): string {
    $t  = $tanggal ? strtotime($tanggal) : time();
    $ym = date('Y-m', $t);
    if (!gaji_dua_tahap()) return $ym;
    return $ym . '-' . ((int)date('j', $t) <= TAHAP_BATAS_TGL ? 1 : 2);
}

/** Sesuaikan kode periode dengan siklus aktif (tambah/hapus akhiran tahap). */
function periode_normal(string $p): string {
    if (!periode_valid($p)) return periode_sekarang();
    if (gaji_dua_tahap()) {
        return strlen($p) === 9 ? $p : substr($p, 0, 7) . '-' . ((int)date('j') <= TAHAP_BATAS_TGL ? 1 : 2);
    }
    return substr($p, 0, 7);
}

/** Kode periode gaji yang mungkin menutup tanggal ini (bulanan maupun per tahap). */
function periode_kandidat(string $tanggal): array {
    $t = strtotime($tanggal);
    $ym = date('Y-m', $t);
    return [$ym, $ym . '-' . ((int)date('j', $t) <= TAHAP_BATAS_TGL ? 1 : 2)];
}

/** Bagian nilai bulanan untuk satu periode. Bulanan: utuh. Tahap 1 = setengah dibulatkan ke bawah, tahap 2 = sisanya. */
function bagian_periode(float $bulanan, string $p): float {
    if (strlen($p) !== 9) return $bulanan;
    $t1 = floor($bulanan / 2);
    return substr($p, 8, 1) === '1' ? $t1 : $bulanan - $t1;
}

/** Siapkan kolom periode (VARCHAR(10)) dan tabel rincian potongan kasbon pada database lama. */
function migrasi_gaji(PDO $pdo): void {
    try {
        $len = $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gaji' AND COLUMN_NAME = 'periode'")->fetchColumn();
        if ($len !== false && (int)$len < 10) $pdo->exec('ALTER TABLE gaji MODIFY periode VARCHAR(10) NOT NULL');
        $pdo->exec("CREATE TABLE IF NOT EXISTS gaji_potongan (
          id INT AUTO_INCREMENT PRIMARY KEY,
          gaji_id INT NOT NULL,
          kasbon_id INT NOT NULL,
          jumlah DECIMAL(15,2) NOT NULL,
          INDEX (gaji_id),
          FOREIGN KEY (gaji_id) REFERENCES gaji(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    } catch (Throwable $t) { error_log('migrasi_gaji: ' . $t->getMessage()); }
}
