<?php
/**
 * Unduh rekap absensi + lembur (Excel .xlsx):
 * absensi_export.php?dari=YYYY-MM-DD&sampai=YYYY-MM-DD[&karyawan_id=ID]
 */
require __DIR__ . '/../app/bootstrap.php';
require_login();
require APP_PATH . '/xlsx.php';

function abs_tgl_valid(string $d): bool {
    $o = DateTime::createFromFormat('Y-m-d', $d);
    return $o && $o->format('Y-m-d') === $d;
}
/** 'HH:MM[:SS]' -> pecahan hari Excel (08:00 = 0.3333). */
function abs_waktu(string $t): float {
    $p = array_map('intval', explode(':', $t) + [0, 0, 0]);
    return ($p[0] * 3600 + $p[1] * 60 + $p[2]) / 86400;
}

$dari   = (string)($_GET['dari'] ?? '');
$sampai = (string)($_GET['sampai'] ?? '');
$kid    = (int)($_GET['karyawan_id'] ?? 0);
if (!abs_tgl_valid($dari) || !abs_tgl_valid($sampai)) {
    flash('Tanggal rekap tidak valid. Isi "Dari" dan "Sampai" dengan benar.', 'danger');
    redirect('absensi.php');
}
if ($dari > $sampai) [$dari, $sampai] = [$sampai, $dari];

$nama_filter = 'Semua karyawan';
if ($kid > 0) {
    $s = $pdo->prepare('SELECT nama FROM karyawan WHERE id=?');
    $s->execute([$kid]);
    $nm = $s->fetchColumn();
    if ($nm === false) { flash('Karyawan tidak ditemukan.', 'danger'); redirect('absensi.php'); }
    $nama_filter = (string)$nm;
}
$fk = $kid > 0 ? ' AND a.karyawan_id = ' . $kid : '';

$s = $pdo->prepare("SELECT a.tanggal, a.jam_masuk, a.jam_keluar, a.tanggal_merah, a.karyawan_id, k.nama, k.nip,
                           l.jam AS jam_lembur, l.total AS upah_lembur
                    FROM absensi a JOIN karyawan k ON k.id = a.karyawan_id
                    LEFT JOIN lembur l ON l.id = a.lembur_id
                    WHERE a.tanggal BETWEEN ? AND ?$fk ORDER BY a.tanggal, k.nama, a.id");
$s->execute([$dari, $sampai]);
$data = $s->fetchAll();

// Lembur yang diinput manual (tidak berasal dari absensi)
$fkl = $kid > 0 ? ' AND l.karyawan_id = ' . $kid : '';
$s = $pdo->prepare("SELECT l.tanggal, l.karyawan_id, k.nama, l.jam, l.tipe, l.upah_per_jam, l.total, l.keterangan
                    FROM lembur l JOIN karyawan k ON k.id = l.karyawan_id
                    LEFT JOIN absensi a ON a.lembur_id = l.id
                    WHERE a.id IS NULL AND l.tanggal BETWEEN ? AND ?$fkl ORDER BY l.tanggal, k.nama, l.id");
$s->execute([$dari, $sampai]);
$manual = $s->fetchAll();

$hari_nama = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
$co      = company();
$periode = 'Periode: ' . tgl_panjang($dari) . ' s/d ' . tgl_panjang($sampai) . '   |   ' . $nama_filter;
$head    = fn(array $h) => array_map(fn($x) => [$x, XS_HEAD], $h);

/* ---------- Sheet 1: detail absensi + lembur per hari ---------- */
$rows = [
    [[$co['nama'], XS_TITLE]],
    [['Rekap Absensi & Lembur', XS_BOLD]],
    [$periode],
    ['Dicetak: ' . date('d/m/Y H:i')],
    [],
    $head(['No', 'Tanggal', 'Hari', 'Karyawan', 'Jam Masuk', 'Jam Keluar', 'Durasi Kerja (jam)', 'Jenis Hari', 'Lembur / Kurang (jam)', 'Upah Lembur / Potongan (Rp)']),
];
$per = [];            // rekap per karyawan
$tot_dur = 0.0; $tot_lj = 0.0; $tot_lu = 0.0;
foreach ($data as $i => $r) {
    $m = strtotime($r['tanggal'] . ' ' . $r['jam_masuk']);
    $k = strtotime($r['tanggal'] . ' ' . $r['jam_keluar']);
    if ($k <= $m) $k += 86400;
    $dur = round(($k - $m) / 3600, 1);
    $N = (int)date('N', strtotime($r['tanggal']));
    $libur = (int)$r['tanggal_merah'] === 1 || in_array($N, HARI_LIBUR, true);
    $jenis = (int)$r['tanggal_merah'] === 1 ? 'Tanggal merah' : ($libur ? 'Libur' : 'Hari kerja');
    $lj = (float)$r['jam_lembur']; $lu = (float)$r['upah_lembur'];

    $rows[] = [
        [$i + 1, XS_TEXT], [xl_date($r['tanggal']), XS_DATE], [$hari_nama[$N], XS_TEXT], [$r['nama'], XS_TEXT],
        [abs_waktu($r['jam_masuk']), XS_TIME], [abs_waktu($r['jam_keluar']), XS_TIME], [$dur, XS_DEC], [$jenis, XS_TEXT],
        [$lj != 0 ? $lj : null, XS_DEC], [$lu != 0 ? $lu : null, XS_NUM],
    ];
    $kk = (int)$r['karyawan_id'];
    $per[$kk] ??= ['nama' => $r['nama'], 'hadir' => 0, 'libur' => 0, 'dur' => 0.0, 'lj' => 0.0, 'lu' => 0.0, 'mj' => 0.0, 'mu' => 0.0];
    $per[$kk]['hadir']++; if ($libur) $per[$kk]['libur']++;
    $per[$kk]['dur'] += $dur; $per[$kk]['lj'] += $lj; $per[$kk]['lu'] += $lu;
    $tot_dur += $dur; $tot_lj += $lj; $tot_lu += $lu;
}
$lbl = [null, XS_TOTLBL];
$rows[] = [$lbl, $lbl, $lbl, ['TOTAL (' . count($data) . ' hari hadir)', XS_TOTLBL], $lbl, $lbl,
           [$tot_dur, XS_TOTDEC], $lbl, [$tot_lj, XS_TOTDEC], [$tot_lu, XS_TOTNUM]];

/* ---------- Sheet 2: ringkasan per karyawan ---------- */
foreach ($manual as $r) {
    $id = (int)$r['karyawan_id'];
    $per[$id] ??= ['nama' => $r['nama'], 'hadir' => 0, 'libur' => 0, 'dur' => 0.0, 'lj' => 0.0, 'lu' => 0.0, 'mj' => 0.0, 'mu' => 0.0];
    $per[$id]['mj'] += (float)$r['jam']; $per[$id]['mu'] += (float)$r['total'];
}
uasort($per, fn($a, $b) => strcasecmp($a['nama'], $b['nama']));
$rows2 = [
    [[$co['nama'], XS_TITLE]],
    [['Ringkasan Absensi & Lembur per Karyawan', XS_BOLD]],
    [$periode],
    [],
    $head(['No', 'Karyawan', 'Hari Hadir', 'Hadir di Hari Libur', 'Total Jam Kerja', 'Lembur dari Absensi (jam)', 'Lembur Manual (jam)', 'Total Lembur (jam)', 'Total Upah Lembur (Rp)']),
];
$n = 0; $t = ['hadir' => 0, 'libur' => 0, 'dur' => 0.0, 'lj' => 0.0, 'mj' => 0.0, 'u' => 0.0];
foreach ($per as $p) {
    $u = $p['lu'] + $p['mu'];
    $rows2[] = [[++$n, XS_TEXT], [$p['nama'], XS_TEXT], [$p['hadir'], XS_NUM], [$p['libur'], XS_NUM], [$p['dur'], XS_DEC],
                [$p['lj'], XS_DEC], [$p['mj'], XS_DEC], [$p['lj'] + $p['mj'], XS_DEC], [$u, XS_NUM]];
    $t['hadir'] += $p['hadir']; $t['libur'] += $p['libur']; $t['dur'] += $p['dur']; $t['lj'] += $p['lj']; $t['mj'] += $p['mj']; $t['u'] += $u;
}
$rows2[] = [$lbl, ['TOTAL', XS_TOTLBL], [$t['hadir'], XS_TOTNUM], [$t['libur'], XS_TOTNUM], [$t['dur'], XS_TOTDEC],
            [$t['lj'], XS_TOTDEC], [$t['mj'], XS_TOTDEC], [$t['lj'] + $t['mj'], XS_TOTDEC], [$t['u'], XS_TOTNUM]];

/* ---------- Sheet 3: lembur manual ---------- */
$rows3 = [
    [[$co['nama'], XS_TITLE]],
    [['Lembur Input Manual (tanpa absensi)', XS_BOLD]],
    [$periode],
    [],
    $head(['No', 'Tanggal', 'Karyawan', 'Jenis Hari', 'Lembur (jam)', 'Tarif/jam (Rp)', 'Total (Rp)', 'Keterangan']),
];
$tj = 0.0; $tu = 0.0;
foreach ($manual as $i => $r) {
    $rows3[] = [[$i + 1, XS_TEXT], [xl_date($r['tanggal']), XS_DATE], [$r['nama'], XS_TEXT], [$r['tipe'] === 'libur' ? 'Libur' : 'Hari kerja', XS_TEXT],
                [(float)$r['jam'], XS_DEC], [(float)$r['upah_per_jam'], XS_NUM], [(float)$r['total'], XS_NUM], [(string)$r['keterangan'], XS_TEXT]];
    $tj += (float)$r['jam']; $tu += (float)$r['total'];
}
if (!$manual) $rows3[] = [[null, XS_TEXT], ['Tidak ada lembur manual pada periode ini.', XS_TEXT]];
$rows3[] = [$lbl, $lbl, $lbl, ['TOTAL', XS_TOTLBL], [$tj, XS_TOTDEC], $lbl, [$tu, XS_TOTNUM], $lbl];

$sheets = [
    ['name' => 'Detail Absensi',   'widths' => [6, 12, 10, 26, 11, 11, 14, 14, 12, 18], 'freeze' => 6, 'rows' => $rows],
    ['name' => 'Per Karyawan',     'widths' => [6, 28, 11, 14, 14, 16, 14, 14, 20],     'freeze' => 5, 'rows' => $rows2],
    ['name' => 'Lembur Manual',    'widths' => [6, 12, 26, 14, 12, 14, 16, 36],         'freeze' => 5, 'rows' => $rows3],
];

$tmp = @tempnam(sys_get_temp_dir(), 'xl') ?: @tempnam(__DIR__ . '/uploads', 'xl');
if (!$tmp || !xlsx_save($sheets, $tmp)) {
    if ($tmp && is_file($tmp)) @unlink($tmp);
    flash('Gagal membuat file Excel. Pastikan ekstensi zip PHP aktif (php.ini: extension=zip).', 'danger');
    redirect('absensi.php');
}

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="rekap_absensi_lembur_' . $dari . '_sd_' . $sampai . '.xlsx"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($tmp);
@unlink($tmp);
exit;
