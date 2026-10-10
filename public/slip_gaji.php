<?php
/**
 * Cetak slip gaji, ukuran 1/3 A4 (210 x 99 mm).
 *   slip_gaji.php?id=12            -> satu pegawai
 *   slip_gaji.php?periode=2026-10  -> semua pegawai periode itu, 3 slip per lembar A4
 */
require __DIR__ . '/../app/bootstrap.php';
require_login();

$id      = (int)($_GET['id'] ?? 0);
$periode = (string)($_GET['periode'] ?? '');

$sql = 'SELECT g.*, k.nama, k.nip, k.jabatan FROM gaji g JOIN karyawan k ON k.id = g.karyawan_id';
if ($id > 0) {
    $s = $pdo->prepare("$sql WHERE g.id = ?");
    $s->execute([$id]);
} elseif (preg_match('/^\d{4}-\d{2}$/', $periode)) {
    $s = $pdo->prepare("$sql WHERE g.periode = ? ORDER BY k.nama");
    $s->execute([$periode]);
} else {
    redirect('gaji.php');
}
$rows = $s->fetchAll();
if (!$rows) {
    flash('Belum ada slip untuk dicetak. Proses gaji periode ini terlebih dahulu.', 'danger');
    redirect('gaji.php' . ($periode ? '?periode=' . urlencode($periode) : ''));
}

// Total jam lembur per pegawai pada periodenya
$q = $pdo->prepare("SELECT COALESCE(SUM(jam),0) FROM lembur WHERE karyawan_id=? AND DATE_FORMAT(tanggal,'%Y-%m')=?");
foreach ($rows as &$r) { $q->execute([$r['karyawan_id'], $r['periode']]); $r['jam_lembur'] = (float)$q->fetchColumn(); }
unset($r);

$co      = company();
$halaman = array_chunk($rows, 3);
$kembali = 'gaji.php?periode=' . urlencode($rows[0]['periode']);
$tgl_cetak = ($co['kota'] !== '' ? $co['kota'] . ', ' : '') . tgl_panjang(date('Y-m-d'));
$jam_fmt = fn(float $j) => rtrim(rtrim(number_format($j, 1, ',', '.'), '0'), ',');
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Slip gaji <?= e(count($rows) === 1 ? $rows[0]['nama'] . ' - ' . periode_label($rows[0]['periode']) : periode_label($rows[0]['periode'])) ?></title>
  <link rel="stylesheet" href="<?= asset('slip.css') ?>">
</head>
<body>
<div class="toolbar">
  <a class="tb-back" href="<?= e($kembali) ?>">&larr; Kembali</a>
  <span class="tb-info"><?= count($rows) ?> slip, ukuran 1/3 A4 (<?= count($halaman) ?> lembar)</span>
  <button type="button" onclick="window.print()">Cetak</button>
</div>

<div class="sheets">
<?php foreach ($halaman as $hal): ?>
  <div class="page">
  <?php foreach ($hal as $g):
      $pendapatan = (float)$g['gaji_pokok'] + (float)$g['total_lembur'];
      $potongan   = (float)$g['potongan_kasbon'];
  ?>
    <section class="slip">
      <header class="s-head">
        <div class="s-brand">
          <?= brand_mark('s-logo') ?>
          <div>
            <b><?= e($co['nama']) ?></b>
            <?php if ($co['alamat'] !== ''): ?><span><?= e($co['alamat']) ?></span><?php endif; ?>
            <?php if ($co['telp'] !== '' || $co['email'] !== ''): ?><span><?= e(trim($co['telp'] . ($co['telp'] !== '' && $co['email'] !== '' ? '  |  ' : '') . $co['email'])) ?></span><?php endif; ?>
          </div>
        </div>
        <div class="s-title"><b>SLIP GAJI</b><span>Periode <?= e(periode_label($g['periode'])) ?></span></div>
      </header>

      <dl class="s-id">
        <div><dt>Nama</dt><dd><?= e($g['nama']) ?></dd></div>
        <div><dt>NIP</dt><dd><?= e($g['nip']) ?></dd></div>
        <div><dt>Jabatan</dt><dd><?= e($g['jabatan'] ?: '-') ?></dd></div>
      </dl>

      <div class="s-cols">
        <table>
          <thead><tr><th colspan="2">Pendapatan</th></tr></thead>
          <tbody>
            <tr><td>Gaji pokok</td><td class="n"><?= rupiah($g['gaji_pokok']) ?></td></tr>
            <tr><td>Upah lembur<?= $g['jam_lembur'] > 0 ? ' (' . e($jam_fmt($g['jam_lembur'])) . ' jam)' : '' ?></td><td class="n"><?= rupiah($g['total_lembur']) ?></td></tr>
          </tbody>
          <tfoot><tr><td>Total pendapatan</td><td class="n"><?= rupiah($pendapatan) ?></td></tr></tfoot>
        </table>
        <table>
          <thead><tr><th colspan="2">Potongan</th></tr></thead>
          <tbody>
            <tr><td>Cicilan kasbon</td><td class="n"><?= $potongan > 0 ? rupiah($potongan) : '-' ?></td></tr>
            <tr class="pad"><td>&nbsp;</td><td></td></tr>
          </tbody>
          <tfoot><tr><td>Total potongan</td><td class="n"><?= rupiah($potongan) ?></td></tr></tfoot>
        </table>
      </div>

      <div class="s-net"><span>Gaji diterima</span><b><?= rupiah($g['gaji_bersih']) ?></b></div>
      <p class="s-say">Terbilang: <i><?= e(terbilang_rp($g['gaji_bersih'])) ?></i></p>

      <footer class="s-sign">
        <div><span>Penerima,</span><em></em><u><?= e($g['nama']) ?></u></div>
        <div><span><?= e($tgl_cetak) ?><br><?= e($co['ttd_jabatan'] ?: 'Keuangan') ?>,</span><em></em><u><?= e($co['ttd_nama'] ?: '') ?></u></div>
      </footer>
    </section>
  <?php endforeach; ?>
  </div>
<?php endforeach; ?>
</div>
</body>
</html>
