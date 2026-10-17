<?php
/** Unduh rekap kas (Excel .xlsx) untuk rentang tanggal: kas_export.php?dari=YYYY-MM-DD&sampai=YYYY-MM-DD */
require __DIR__ . '/../app/bootstrap.php';
require_login();
require APP_PATH . '/xlsx.php';

function kas_tgl_valid(string $d): bool {
    $o = DateTime::createFromFormat('Y-m-d', $d);
    return $o && $o->format('Y-m-d') === $d;
}

$dari   = (string)($_GET['dari'] ?? '');
$sampai = (string)($_GET['sampai'] ?? '');
if (!kas_tgl_valid($dari) || !kas_tgl_valid($sampai)) {
    flash('Tanggal rekap tidak valid. Isi "Dari" dan "Sampai" dengan benar.', 'danger');
    redirect('kas.php');
}
if ($dari > $sampai) [$dari, $sampai] = [$sampai, $dari];

// Saldo awal = seluruh kas sebelum tanggal "dari"
$s = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tipe='masuk' THEN jumlah ELSE -jumlah END),0) FROM kas WHERE tanggal < ?");
$s->execute([$dari]);
$saldo_awal = (float)$s->fetchColumn();

$s = $pdo->prepare('SELECT tanggal, tipe, kategori, keterangan, jumlah FROM kas WHERE tanggal BETWEEN ? AND ? ORDER BY tanggal ASC, id ASC');
$s->execute([$dari, $sampai]);
$data = $s->fetchAll();

$co      = company();
$periode = 'Periode: ' . tgl_panjang($dari) . ' s/d ' . tgl_panjang($sampai);

/* ---------- Sheet 1: rincian transaksi ---------- */
$rows = [
    [[$co['nama'], XS_TITLE]],
    [['Rekap Kas', XS_BOLD]],
    [$periode],
    ['Dicetak: ' . date('d/m/Y H:i')],
    [],
    array_map(fn($h) => [$h, XS_HEAD], ['No', 'Tanggal', 'Kategori', 'Keterangan', 'Masuk (Rp)', 'Keluar (Rp)', 'Saldo (Rp)']),
    [[null, XS_TOTLBL], [null, XS_TOTLBL], [null, XS_TOTLBL], ['Saldo awal (sebelum ' . tgl($dari) . ')', XS_TOTLBL],
     [null, XS_TOTLBL], [null, XS_TOTLBL], [$saldo_awal, XS_TOTNUM]],
];
$saldo = $saldo_awal; $tm = 0.0; $tk = 0.0; $kat = [];
foreach ($data as $i => $r) {
    $j = (float)$r['jumlah'];
    $masuk = $r['tipe'] === 'masuk';
    if ($masuk) { $saldo += $j; $tm += $j; } else { $saldo -= $j; $tk += $j; }
    $k = trim((string)$r['kategori']) !== '' ? trim((string)$r['kategori']) : '(Tanpa kategori)';
    $kat[$k] ??= [0.0, 0.0];
    $kat[$k][$masuk ? 0 : 1] += $j;
    $rows[] = [
        [$i + 1, XS_TEXT], [xl_date($r['tanggal']), XS_DATE], [$k, XS_TEXT], [(string)$r['keterangan'], XS_TEXT],
        [$masuk ? $j : null, XS_NUM], [$masuk ? null : $j, XS_NUM], [$saldo, XS_NUM],
    ];
}
$rows[] = [[null, XS_TOTLBL], [null, XS_TOTLBL], [null, XS_TOTLBL], ['TOTAL (' . count($data) . ' transaksi)', XS_TOTLBL],
           [$tm, XS_TOTNUM], [$tk, XS_TOTNUM], [$saldo, XS_TOTNUM]];

/* ---------- Sheet 2: ringkasan per kategori ---------- */
ksort($kat, SORT_NATURAL | SORT_FLAG_CASE);
$rows2 = [
    [[$co['nama'], XS_TITLE]],
    [['Ringkasan Kas per Kategori', XS_BOLD]],
    [$periode],
    [],
    array_map(fn($h) => [$h, XS_HEAD], ['Kategori', 'Masuk (Rp)', 'Keluar (Rp)']),
];
foreach ($kat as $nama => [$m, $k]) {
    $rows2[] = [[$nama, XS_TEXT], [$m > 0 ? $m : null, XS_NUM], [$k > 0 ? $k : null, XS_NUM]];
}
$rows2[] = [['TOTAL', XS_TOTLBL], [$tm, XS_TOTNUM], [$tk, XS_TOTNUM]];

$sheets = [
    ['name' => 'Rekap Kas',    'widths' => [6, 12, 20, 48, 16, 16, 18], 'freeze' => 6, 'rows' => $rows],
    ['name' => 'Per Kategori', 'widths' => [32, 18, 18],               'freeze' => 5, 'rows' => $rows2],
];

$tmp = @tempnam(sys_get_temp_dir(), 'xl') ?: @tempnam(__DIR__ . '/uploads', 'xl');
if (!$tmp || !xlsx_save($sheets, $tmp)) {
    if ($tmp && is_file($tmp)) @unlink($tmp);
    flash('Gagal membuat file Excel. Pastikan ekstensi zip PHP aktif (php.ini: extension=zip).', 'danger');
    redirect('kas.php');
}

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="rekap_kas_' . $dari . '_sd_' . $sampai . '.xlsx"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($tmp);
@unlink($tmp);
exit;
