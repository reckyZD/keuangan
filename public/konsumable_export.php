<?php
/**
 * Unduh rekap konsumable (Excel .xlsx).
 *   konsumable_export.php?dari=YYYY-MM-DD&sampai=YYYY-MM-DD   rekap stok periode + rincian
 *   konsumable_export.php?tahun=YYYY                          rekap 1 tahun + per bulan + rincian
 */
require __DIR__ . '/../app/bootstrap.php';
require_login();
require APP_PATH . '/xlsx.php';
require APP_PATH . '/konsumable.php';
pastikan_tabel_konsumable();

function kon_tgl_valid(string $d): bool {
    $o = DateTime::createFromFormat('Y-m-d', $d);
    return $o && $o->format('Y-m-d') === $d;
}

$tahun = (int)($_GET['tahun'] ?? 0);
if ($tahun >= 2000 && $tahun <= 2100) {
    $dari = "$tahun-01-01"; $sampai = "$tahun-12-31";
} else {
    $tahun = 0;
    $dari   = (string)($_GET['dari'] ?? '');
    $sampai = (string)($_GET['sampai'] ?? '');
    if (!kon_tgl_valid($dari) || !kon_tgl_valid($sampai)) {
        flash('Tanggal rekap tidak valid. Isi "Dari" dan "Sampai" dengan benar.', 'danger');
        redirect('konsumable.php');
    }
    if ($dari > $sampai) [$dari, $sampai] = [$sampai, $dari];
}

$co      = company();
$periode = $tahun ? "Rekap tahun $tahun" : 'Periode: ' . tgl_panjang($dari) . ' s/d ' . tgl_panjang($sampai);
$head    = fn(array $h) => array_map(fn($x) => [$x, XS_HEAD], $h);
$lbl     = [null, XS_TOTLBL];
$judul   = fn(string $j) => [[[$co['nama'], XS_TITLE]], [[$j, XS_BOLD]], [$periode], ['Dicetak: ' . date('d/m/Y H:i')], []];
const BLN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

$s = $pdo->prepare('SELECT * FROM konsumable WHERE tanggal BETWEEN ? AND ? ORDER BY tanggal, id');
$s->execute([$dari, $sampai]);
$data = $s->fetchAll();

/* ---------- Sheet: Rekap Stok (IN / OUT / Balance) ---------- */
[$rekap, $tot] = kon_rekap($dari, $sampai);
$rows = $judul($tahun ? "Rekap Stok Konsumable Tahun $tahun" : 'Rekap Stok Konsumable');
$rows[] = $head(['No', 'Nama Barang', 'Satuan', 'Stok Awal', 'Barang Masuk (IN)', 'Barang Keluar (OUT)', 'Stok Akhir (Balance)',
                 'Harga Rata-rata (Rp)', 'Nilai Barang Masuk (Rp)', 'Nilai Stok Akhir (Rp)', 'Keterangan']);
$n = 0;
foreach ($rekap as $r) {
    if ($r['awal'] == 0 && $r['in'] == 0 && $r['out'] == 0 && $r['akhir'] == 0) continue;
    $rows[] = [[++$n, XS_TEXT], [$r['nama'], XS_TEXT], [$r['satuan'], XS_TEXT], [$r['awal'], XS_QTY], [$r['in'], XS_QTY], [$r['out'], XS_QTY],
               [$r['akhir'], XS_QTY], [round($r['avg']), XS_NUM], [round($r['nilai_in']), XS_NUM], [round($r['nilai_akhir']), XS_NUM], [$r['ket'], XS_TEXT]];
}
$rows[] = [$lbl, ['TOTAL', XS_TOTLBL], $lbl, $lbl, $lbl, $lbl, $lbl, $lbl, [round($tot['nilai_in']), XS_TOTNUM], [round($tot['nilai_akhir']), XS_TOTNUM], $lbl];
$rows[] = [];
$rows[] = [['Catatan: Stok Akhir = Stok Awal + Masuk - Keluar. Harga rata-rata = total harga semua pembelian s/d akhir periode / total qty dibeli.', XS_BOLD]];
$sheets = [['name' => 'Rekap Stok', 'widths' => [6, 34, 10, 12, 16, 16, 18, 18, 22, 22, 22], 'freeze' => 6, 'rows' => $rows]];

/* ---------- Rekap tahunan: per bulan ---------- */
if ($tahun) {
    $in = []; $out = []; $rp = array_fill(1, 12, 0.0); $ntr = array_fill(1, 12, ['in' => 0, 'out' => 0]);
    foreach ($data as $r) {
        $m = (int)substr($r['tanggal'], 5, 2); $k = kon_key($r['nama_barang']);
        if ($r['jenis'] === 'keluar') {
            $out[$k] ??= ['nama' => $r['nama_barang'], 'satuan' => '', 'm' => array_fill(1, 12, 0.0)];
            $out[$k]['m'][$m] += (float)$r['qty']; $ntr[$m]['out']++;
            if (trim((string)$r['satuan']) !== '') $out[$k]['satuan'] = $r['satuan'];
        } else {
            $in[$k] ??= ['nama' => $r['nama_barang'], 'satuan' => '', 'm' => array_fill(1, 12, 0.0)];
            $in[$k]['m'][$m] += (float)$r['qty']; $rp[$m] += (float)$r['total']; $ntr[$m]['in']++;
            if (trim((string)$r['satuan']) !== '') $in[$k]['satuan'] = $r['satuan'];
        }
    }
    $matriks = function (array $b, string $judul_sheet) use ($judul, $head, $lbl): array {
        uasort($b, fn($x, $y) => strcasecmp($x['nama'], $y['nama']));
        $r = $judul($judul_sheet);
        $r[] = $head(array_merge(['No', 'Nama Barang', 'Satuan'], BLN, ['Total']));
        $n = 0; $kol = array_fill(1, 12, 0.0); $gt = 0.0;
        foreach ($b as $x) {
            $tt = array_sum($x['m']); $gt += $tt;
            $baris = [[++$n, XS_TEXT], [$x['nama'], XS_TEXT], [$x['satuan'], XS_TEXT]];
            for ($m = 1; $m <= 12; $m++) { $kol[$m] += $x['m'][$m]; $baris[] = [$x['m'][$m] ?: null, XS_QTY]; }
            $baris[] = [$tt, XS_TOTQTY];
            $r[] = $baris;
        }
        $baris = [$lbl, ['TOTAL QTY', XS_TOTLBL], $lbl];
        for ($m = 1; $m <= 12; $m++) $baris[] = [$kol[$m], XS_TOTQTY];
        $baris[] = [$gt, XS_TOTQTY];
        $r[] = $baris;
        return $r;
    };
    $w = array_merge([6, 34, 10], array_fill(0, 12, 9), [12]);
    $sheets[] = ['name' => 'Masuk per Bulan',  'widths' => $w, 'freeze' => 6, 'rows' => $matriks($in, "Barang Masuk (IN) per Bulan - Tahun $tahun")];
    $sheets[] = ['name' => 'Keluar per Bulan', 'widths' => $w, 'freeze' => 6, 'rows' => $matriks($out, "Barang Keluar (OUT) per Bulan - Tahun $tahun")];

    $r3 = $judul("Pembelian Konsumable per Bulan - Tahun $tahun");
    $r3[] = $head(['Bulan', 'Catatan Masuk', 'Catatan Keluar', 'Total Pembelian (Rp)']);
    $a = 0; $b = 0; $c = 0.0;
    for ($m = 1; $m <= 12; $m++) {
        $r3[] = [[NAMA_BULAN[$m], XS_TEXT], [$ntr[$m]['in'], XS_NUM], [$ntr[$m]['out'], XS_NUM], [$rp[$m], XS_NUM]];
        $a += $ntr[$m]['in']; $b += $ntr[$m]['out']; $c += $rp[$m];
    }
    $r3[] = [['TOTAL TAHUN ' . $tahun, XS_TOTLBL], [$a, XS_TOTNUM], [$b, XS_TOTNUM], [$c, XS_TOTNUM]];
    $sheets[] = ['name' => 'Biaya per Bulan', 'widths' => [24, 16, 16, 24], 'freeze' => 6, 'rows' => $r3];
}

/* ---------- Sheet: rincian transaksi ---------- */
$rr = $judul('Rincian Barang Masuk & Keluar');
$rr[] = $head(['No', 'Tanggal', 'Jenis', 'Nama Barang', 'Qty', 'Satuan', 'Harga Satuan (Rp)', 'Total Harga (Rp)', 'Dipakai Oleh / Bagian', 'Keterangan', 'Dicatat Oleh']);
$gm = 0.0;
foreach ($data as $i => $r) {
    $masuk = $r['jenis'] !== 'keluar';
    $rr[] = [[$i + 1, XS_TEXT], [xl_date($r['tanggal']), XS_DATE], [$masuk ? 'Masuk (IN)' : 'Keluar (OUT)', XS_TEXT], [$r['nama_barang'], XS_TEXT],
             [(float)$r['qty'], XS_QTY], [(string)$r['satuan'], XS_TEXT], [$masuk ? (float)$r['harga_satuan'] : null, XS_NUM], [$masuk ? (float)$r['total'] : null, XS_NUM],
             [(string)$r['bagian'], XS_TEXT], [(string)$r['keterangan'], XS_TEXT], [(string)$r['dicatat_oleh'], XS_TEXT]];
    if ($masuk) $gm += (float)$r['total'];
}
$rr[] = [$lbl, $lbl, $lbl, ['TOTAL PEMBELIAN (' . count($data) . ' catatan)', XS_TOTLBL], $lbl, $lbl, $lbl, [$gm, XS_TOTNUM], $lbl, $lbl, $lbl];
$sheets[] = ['name' => 'Rincian', 'widths' => [6, 12, 14, 34, 10, 10, 18, 18, 22, 30, 16], 'freeze' => 6, 'rows' => $rr];

$tmp = @tempnam(sys_get_temp_dir(), 'xl') ?: @tempnam(__DIR__ . '/uploads', 'xl');
if (!$tmp || !xlsx_save($sheets, $tmp)) {
    if ($tmp && is_file($tmp)) @unlink($tmp);
    flash('Gagal membuat file Excel. Pastikan ekstensi zip PHP aktif (php.ini: extension=zip).', 'danger');
    redirect('konsumable.php');
}
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . ($tahun ? "rekap_konsumable_tahun_$tahun" : "rekap_konsumable_{$dari}_sd_{$sampai}") . '.xlsx"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($tmp);
@unlink($tmp);
exit;
