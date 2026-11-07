<?php
require __DIR__ . '/../app/bootstrap.php';
require_once APP_PATH . '/xlsx.php';
require_login();
pastikan_kolom_harian();

/*
 * Unduh rekap gaji (.xlsx)
 *   gaji_export.php?mode=periode&periode=2026-10-17
 *   gaji_export.php?mode=bulan&bulan=2026-10
 *   gaji_export.php?mode=tahun&tahun=2026
 */

/** Susun satu sheet rekap: judul, header, data, baris total; lebar kolom otomatis. */
function gx_sheet(string $nama, string $judul, array $kolom, array $baris, array $total): array {
    $tampil = function ($v, string $tipe): string {
        if ($v === '' || $v === null) return '';
        if ($tipe === 'rp')  return ($v < 0 ? '-' : '') . 'Rp ' . number_format(abs((float)$v), 0, ',', '.');
        if ($tipe === 'num') return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
        return (string)$v;
    };
    $sel = function ($v, string $tipe, bool $tot) {
        $kosong = ($v === '' || $v === null);
        if ($tipe === 'rp')  return [$kosong ? null : (float)$v, $tot ? XS_TOTRP : XS_RP];
        if ($tipe === 'num') return [$kosong ? null : (float)$v, $tot ? XS_TOTQTY : XS_QTY];
        return [$kosong ? null : (string)$v, $tot ? XS_TOTLBL : XS_TEXT];
    };

    $lebar = [];
    foreach ($kolom as $i => [$jdl, $tipe]) {
        $m = mb_strlen($jdl) + 2;
        foreach ($baris as $b) $m = max($m, mb_strlen($tampil($b[$i] ?? '', $tipe)));
        $m = max($m, mb_strlen($tampil($total[$i] ?? '', $tipe)) + 1);   // total dicetak tebal
        $lebar[$i] = min(60, $m + 3);
    }

    $rows = [[[$judul, XS_TITLE]], []];                                   // baris 1 judul, baris 2 kosong
    $rows[] = array_map(fn($k) => [$k[0], XS_HEAD], $kolom);              // baris 3 header
    foreach ($baris as $b) {
        $r = [];
        foreach ($kolom as $i => $k) $r[] = $sel($b[$i] ?? '', $k[1], false);
        $rows[] = $r;
    }
    $r = [];
    foreach ($kolom as $i => $k) $r[] = $sel($total[$i] ?? '', $k[1], true);
    $rows[] = $r;

    return ['name' => $nama, 'widths' => $lebar, 'freeze' => 3, 'rows' => $rows];
}

$bln  = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$mode = (string)($_GET['mode'] ?? 'periode');
if (!in_array($mode, ['periode', 'bulan', 'tahun'], true)) $mode = 'periode';

if ($mode === 'tahun') {
    $thn = (string)($_GET['tahun'] ?? '');
    if (!preg_match('/^\d{4}$/', $thn)) $thn = date('Y');
    $dari = "$thn-01-01"; $sampai = "$thn-12-31";
    $judul = "Rekap gaji tahun $thn";
    $file  = "rekap-gaji-$thn.xlsx";
} elseif ($mode === 'bulan') {
    $bulan = (string)($_GET['bulan'] ?? '');
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $bulan, $m)) {
        $bulan = date('Y-m');
        $m = [$bulan, substr($bulan, 0, 4), substr($bulan, 5, 2)];
    }
    $dari   = "$bulan-01";
    $sampai = date('Y-m-t', strtotime($dari));
    $judul  = 'Rekap gaji bulan ' . $bln[(int)$m[2]] . ' ' . $m[1];
    $file   = "rekap-gaji-$bulan.xlsx";
} else {
    $periode = (string)($_GET['periode'] ?? '');
    if (!periode_valid($periode)) $periode = periode_for_date(date('Y-m-d'));
    $dari = $sampai = $periode;
    $judul = 'Rekap gaji periode ' . periode_label($periode);
    $file  = 'rekap-gaji-' . $periode . '.xlsx';
}

/* ---------- Sheet 1: per karyawan (seluruh slip pada rentang, bukan hanya satu halaman) ---------- */
$s = $pdo->prepare('SELECT k.nip, k.nama,
        MAX(g.upah_harian) AS upah_harian,
        COALESCE(SUM(g.hari_kerja),0) AS hari,
        COALESCE(SUM(g.gaji_pokok),0) AS upah,
        COALESCE(SUM(g.total_lembur),0) AS lembur,
        COALESCE(SUM(g.potongan_kasbon),0) AS kasbon,
        COALESCE(SUM(g.gaji_bersih),0) AS bersih
    FROM gaji g JOIN karyawan k ON k.id = g.karyawan_id
    WHERE g.periode BETWEEN ? AND ?
    GROUP BY k.id, k.nip, k.nama
    ORDER BY k.nama');
$s->execute([$dari, $sampai]);
$data = $s->fetchAll();

$kolom = [['NIP', 'text'], ['Nama', 'text'], ['Hari Hadir', 'num']];
if ($mode === 'periode') $kolom[] = ['Upah Harian', 'rp'];
array_push($kolom, ['Total Upah', 'rp'], ['Lembur', 'rp'], ['Potongan Kasbon', 'rp'], ['Gaji Bersih', 'rp']);

$baris = []; $t = ['hari' => 0.0, 'upah' => 0.0, 'lembur' => 0.0, 'kasbon' => 0.0, 'bersih' => 0.0];
foreach ($data as $r) {
    $b = [$r['nip'], $r['nama'], (float)$r['hari']];
    if ($mode === 'periode') $b[] = (float)$r['upah_harian'];
    array_push($b, (float)$r['upah'], (float)$r['lembur'], (float)$r['kasbon'], (float)$r['bersih']);
    $baris[] = $b;
    $t['hari'] += (float)$r['hari'];     $t['upah'] += (float)$r['upah'];
    $t['lembur'] += (float)$r['lembur']; $t['kasbon'] += (float)$r['kasbon']; $t['bersih'] += (float)$r['bersih'];
}
$total = ['', 'TOTAL', $t['hari']];
if ($mode === 'periode') $total[] = '';
array_push($total, $t['upah'], $t['lembur'], $t['kasbon'], $t['bersih']);

$sheets = [gx_sheet('Per Karyawan', $judul, $kolom, $baris, $total)];

/* ---------- Sheet 2: rincian per periode (rekap bulanan) atau per bulan (rekap tahunan) ---------- */
if ($mode !== 'periode') {
    $grup = $mode === 'bulan' ? 'g.periode' : 'LEFT(g.periode, 7)';
    $s2 = $pdo->prepare("SELECT $grup AS grup, COUNT(*) AS n,
            COALESCE(SUM(g.hari_kerja),0) AS hari,
            COALESCE(SUM(g.gaji_pokok),0) AS upah,
            COALESCE(SUM(g.total_lembur),0) AS lembur,
            COALESCE(SUM(g.potongan_kasbon),0) AS kasbon,
            COALESCE(SUM(g.gaji_bersih),0) AS bersih
        FROM gaji g
        WHERE g.periode BETWEEN ? AND ?
        GROUP BY grup ORDER BY grup");
    $s2->execute([$dari, $sampai]);

    $baris2 = []; $t2 = [0, 0.0, 0.0, 0.0, 0.0, 0.0];
    foreach ($s2->fetchAll() as $r) {
        $g = (string)$r['grup'];
        if ($mode === 'bulan') $label = periode_valid($g) ? periode_label($g) : $g;
        else                   $label = ($bln[(int)substr($g, 5, 2)] ?? $g) . ' ' . substr($g, 0, 4);
        $baris2[] = [$label, (int)$r['n'], (float)$r['hari'], (float)$r['upah'], (float)$r['lembur'], (float)$r['kasbon'], (float)$r['bersih']];
        $t2[0] += (int)$r['n'];        $t2[1] += (float)$r['hari']; $t2[2] += (float)$r['upah'];
        $t2[3] += (float)$r['lembur']; $t2[4] += (float)$r['kasbon']; $t2[5] += (float)$r['bersih'];
    }
    $sheets[] = gx_sheet(
        $mode === 'bulan' ? 'Per Periode' : 'Per Bulan',
        $judul . ($mode === 'bulan' ? ' - per periode' : ' - per bulan'),
        [[$mode === 'bulan' ? 'Periode' : 'Bulan', 'text'], ['Jumlah Slip', 'num'], ['Hari Hadir', 'num'],
         ['Total Upah', 'rp'], ['Lembur', 'rp'], ['Potongan Kasbon', 'rp'], ['Gaji Bersih', 'rp']],
        $baris2,
        ['TOTAL', $t2[0], $t2[1], $t2[2], $t2[3], $t2[4], $t2[5]]
    );
}

/* ---------- Kirim sebagai unduhan ---------- */
$tmp = tempnam(sys_get_temp_dir(), 'gaji');
if (!xlsx_save($sheets, $tmp)) {
    @unlink($tmp);
    http_response_code(500);
    exit('Gagal membuat file xlsx (ekstensi ZipArchive tidak aktif atau folder sementara tidak bisa ditulis).');
}
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
exit;