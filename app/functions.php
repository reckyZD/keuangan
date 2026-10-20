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

/* ---------------------------------------------------------------------
 *  Periode gajian. Kunci periode (disimpan di gaji.periode) menjelaskan dirinya sendiri:
 *    2026-10        sebulan penuh            (mode bulanan)
 *    2026-10-1      tgl 1-15                 (mode 2x sebulan)
 *    2026-10-2      tgl 16-akhir bulan       (mode 2x sebulan)
 *    2026-10-05     14 hari sejak tgl itu    (mode tiap 2 minggu)
 *  Karena itu data lama tetap terbaca walau mode gajian diganti.
 * ------------------------------------------------------------------- */

/** Rentang tanggal [mulai, akhir] (Y-m-d) dari kunci periode; null jika tidak valid. */
function periode_range(string $k): ?array {
    if (preg_match('/^(\d{4})-(\d{2})$/', $k, $m)) {
        if (!checkdate((int)$m[2], 1, (int)$m[1])) return null;
        return [$k . '-01', date('Y-m-t', strtotime($k . '-01'))];
    }
    if (preg_match('/^(\d{4})-(\d{2})-([12])$/', $k, $m)) {
        if (!checkdate((int)$m[2], 1, (int)$m[1])) return null;
        $ym = $m[1] . '-' . $m[2];
        return $m[3] === '1' ? [$ym . '-01', $ym . '-15'] : [$ym . '-16', date('Y-m-t', strtotime($ym . '-01'))];
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $k, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        [$fs, $fe] = gajian_pertama();
        return $k === $fs ? [$k, $fe] : [$k, date('Y-m-d', strtotime($k . ' +13 days'))];
    }
    return null;
}

/** Periode pertama untuk mode tiap 2 minggu: [dari, sampai]. Periode berikutnya tiap 14 hari, berakhir di hari yang sama. */
function gajian_pertama(): array {
    $co = company();
    $ok = function (string $d): bool {
        return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    };
    if ($ok($co['gajian_awal']) && $ok($co['gajian_akhir']) && $co['gajian_akhir'] >= $co['gajian_awal']) {
        return [$co['gajian_awal'], $co['gajian_akhir']];
    }
    $fe = date('Y-m-d', strtotime('saturday this week'));
    return [date('Y-m-d', strtotime($fe . ' -13 days')), $fe];
}

/** Kolom gaji.periode semula CHAR(7); perlu VARCHAR(12) untuk kunci 2x sebulan / 2 mingguan. Aman dipanggil berulang. */
function pastikan_kolom_periode(): void {
    global $pdo;
    if (company()['gajian_mode'] === 'bulanan') return;
    try { $pdo->exec('ALTER TABLE gaji MODIFY periode VARCHAR(12) NOT NULL'); } catch (Throwable $t) {}
}

function periode_valid(string $k): bool { return periode_range($k) !== null; }

/** "Oktober 2026", "1-15 Oktober 2026", "28 Sep - 11 Okt 2026" (sesuai bentuk kunci). */
function periode_label(string $k): string {
    $r = periode_range($k);
    if (!$r) return $k;
    if (preg_match('/^\d{4}-\d{2}$/', $k)) return NAMA_BULAN[(int)substr($k, 5, 2)] . ' ' . substr($k, 0, 4);
    $ta = strtotime($r[0]); $tb = strtotime($r[1]);
    if (date('Y-m', $ta) === date('Y-m', $tb)) {
        return (int)date('j', $ta) . ' - ' . (int)date('j', $tb) . ' ' . NAMA_BULAN[(int)date('n', $tb)] . ' ' . date('Y', $tb);
    }
    $pendek = fn(int $t): string => (int)date('j', $t) . ' ' . substr(NAMA_BULAN[(int)date('n', $t)], 0, 3);
    $thn = date('Y', $ta) === date('Y', $tb) ? '' : ' ' . date('Y', $ta);
    return $pendek($ta) . $thn . ' - ' . $pendek($tb) . ' ' . date('Y', $tb);
}

/** Kunci periode yang memuat tanggal tertentu, menurut mode gajian di Pengaturan. */
function periode_for_date(string $tgl): string {
    $co = company();
    $t  = strtotime($tgl);
    if ($co['gajian_mode'] === 'semi_bulanan') {
        return date('Y-m', $t) . ((int)date('j', $t) <= 15 ? '-1' : '-2');
    }
    if ($co['gajian_mode'] === 'dua_mingguan') {
        [$fs, $fe] = gajian_pertama();
        $d = date('Y-m-d', $t);
        if ($d >= $fs && $d <= $fe) return $fs;                       // periode pertama (mis. 19 Sep - 3 Okt)
        $awal  = strtotime($d > $fe ? $fe . ' +1 day' : $fs);         // setelahnya: blok 14 hari dari hari berikutnya
        $hari  = (int)round(($t - $awal) / 86400);
        $geser = (int)floor($hari / 14) * 14;
        return date('Y-m-d', strtotime(date('Y-m-d', $awal) . ' ' . sprintf('%+d', $geser) . ' days'));
    }
    return date('Y-m', $t);
}

/** Periode sebelumnya dari kunci yang sama bentuknya. */
function periode_prev(string $k): string {
    if (preg_match('/^\d{4}-\d{2}$/', $k)) return date('Y-m', strtotime($k . '-01 -1 month'));
    if (preg_match('/^(\d{4}-\d{2})-([12])$/', $k, $m)) {
        return $m[2] === '2' ? $m[1] . '-1' : date('Y-m', strtotime($m[1] . '-01 -1 month')) . '-2';
    }
    [$fs, $fe] = gajian_pertama();
    if ($k === date('Y-m-d', strtotime($fe . ' +1 day'))) return $fs;   // setelah periode pertama
    return date('Y-m-d', strtotime($k . ' -14 days'));
}

/** Pilihan periode untuk dropdown: periode berjalan + sebelumnya + periode yang pernah digaji. */
function periode_options(int $n = 12): array {
    global $pdo;
    $k = periode_for_date(date('Y-m-d'));
    $set = [];
    for ($i = 0; $i < $n; $i++) { $set[$k] = true; $k = periode_prev($k); }
    try {
        foreach ($pdo->query('SELECT DISTINCT periode FROM gaji')->fetchAll(PDO::FETCH_COLUMN) as $p) {
            if (periode_range((string)$p)) $set[(string)$p] = true;
        }
    } catch (Throwable $t) {}
    $keys = array_map('strval', array_keys($set));
    usort($keys, function ($a, $b) {
        $ra = periode_range($a); $rb = periode_range($b);
        return strcmp($rb[0], $ra[0]) ?: strcmp($rb[1], $ra[1]);
    });
    return $keys;
}

/**
 * Gaji pokok yang dibayar pada periode ini (pengaturan "gaji_pokok_cara"):
 *  penuh          nominal di data Karyawan dibayar utuh pada setiap gajian (bawaan, tidak dibagi)
 *  sekali_sebulan nominal dibayar utuh hanya pada gajian pertama tiap bulan (berdasarkan tanggal gajian = akhir periode)
 *  dibagi         nominal bulanan dibagi rata per gajian (2x sebulan = 1/2, tiap 2 minggu = 12/26)
 */
function gaji_pokok_periode(float $gp, string $k): float {
    $cara = company()['gaji_pokok_cara'];
    if ($cara === 'sekali_sebulan') {
        $r = periode_range($k); $p = periode_range(periode_prev($k));
        if (!$r || !$p) return $gp;
        return substr($p[1], 0, 7) === substr($r[1], 0, 7) ? 0.0 : $gp;   // gajian sebelumnya masih di bulan yang sama
    }
    if ($cara === 'dibagi') {
        if (preg_match('/^\d{4}-\d{2}$/', $k)) return $gp;
        if (preg_match('/^\d{4}-\d{2}-[12]$/', $k)) return round($gp / 2);
        return round($gp * 12 / 26);
    }
    return $gp;
}

/** Skema pekerja harian: upah per hari di data karyawan; jumlah hari & upah/hari disimpan di slip. Aman dipanggil berulang (di luar transaksi). */
function pastikan_kolom_harian(): void {
    global $pdo;
    static $selesai = false;
    if ($selesai) return;
    $selesai = true;
    $ada = static function (string $tabel, string $kolom) use ($pdo): bool {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $s->execute([$tabel, $kolom]);
        return (bool)$s->fetchColumn();
    };
    try {
        if (!$ada('karyawan', 'upah_harian')) $pdo->exec('ALTER TABLE karyawan ADD COLUMN upah_harian DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER gaji_pokok');
        if (!$ada('gaji', 'hari_kerja'))      $pdo->exec('ALTER TABLE gaji ADD COLUMN hari_kerja INT NOT NULL DEFAULT 0');
        if (!$ada('gaji', 'upah_harian'))     $pdo->exec('ALTER TABLE gaji ADD COLUMN upah_harian DECIMAL(15,2) NOT NULL DEFAULT 0');
    } catch (Throwable $t) {}
}

/**
 * Jumlah hari hadir tiap karyawan pada rentang tanggal => [karyawan_id => jumlah].
 * Hanya hari kerja biasa: hadir di hari libur (Minggu / tanggal merah) dibayar lewat lembur, bukan upah harian.
 */
function hari_hadir_periode(string $awal, string $akhir): array {
    global $pdo;
    $libur = implode(',', array_map('intval', HARI_LIBUR)) ?: '0';
    $s = $pdo->prepare("SELECT karyawan_id, COUNT(*) FROM absensi WHERE tanggal BETWEEN ? AND ? AND tanggal_merah = 0 AND (WEEKDAY(tanggal) + 1) NOT IN ($libur) GROUP BY karyawan_id");
    $s->execute([$awal, $akhir]);
    return $s->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Tabel rincian potongan kasbon per slip, dipakai tombol Reset untuk mengembalikan sisa kasbon. Aman dipanggil berulang. */
function pastikan_tabel_potongan(): void {
    global $pdo;
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS gaji_potongan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gaji_id INT NOT NULL,
            kasbon_id INT NOT NULL,
            jumlah DECIMAL(15,2) NOT NULL,
            INDEX idx_gaji (gaji_id)
        ) ENGINE=InnoDB');
    } catch (Throwable $t) {}
}

/** Apakah tanggal ini sudah masuk periode yang gajinya diproses untuk karyawan tsb? */
function gaji_terkunci(int $kid, string $tgl): bool {
    global $pdo;
    static $cache = [];
    if (!isset($cache[$kid])) {
        $s = $pdo->prepare('SELECT periode FROM gaji WHERE karyawan_id=?');
        $s->execute([$kid]);
        $cache[$kid] = $s->fetchAll(PDO::FETCH_COLUMN);
    }
    foreach ($cache[$kid] as $p) {
        $r = periode_range((string)$p);
        if ($r && $tgl >= $r[0] && $tgl <= $r[1]) return true;
    }
    return false;
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
            'gajian_mode'     => 'dua_mingguan',  // bulanan | semi_bulanan | dua_mingguan
            'gajian_awal'     => '2026-09-19',    // periode pertama (khusus dua_mingguan): dari ...
            'gajian_akhir'    => '2026-10-03',    // ... sampai (hari gajian, Sabtu). Berikutnya tiap 14 hari.
            'gaji_pokok_cara' => 'penuh'];        // penuh | sekali_sebulan | dibagi
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
        'download' => '<path d="M12 4v11M7.5 10.5 12 15l4.5-4.5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
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

/** Ikon tab browser: logo perusahaan jika sudah diunggah, jika belum lambang cincin bawaan. */
function favicon_tags(): string {
    if ($u = company_logo_url()) {
        return '<link rel="icon" href="' . e($u) . '">' . "\n  " . '<link rel="apple-touch-icon" href="' . e($u) . '">';
    }
    return '<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 32 32\'%3E%3Ccircle cx=\'16\' cy=\'16\' r=\'15\' fill=\'%231F2A44\'/%3E%3Ccircle cx=\'16\' cy=\'16\' r=\'9\' fill=\'none\' stroke=\'%23E2A93B\' stroke-width=\'2.6\'/%3E%3Ccircle cx=\'16\' cy=\'16\' r=\'3.4\' fill=\'%23E2A93B\'/%3E%3C/svg%3E">';
}
