<?php
/**
 * Cetak slip gaji, ukuran 1/2 A4 (210 x 148,5 mm), dengan rincian absensi per tanggal.
 *   slip_gaji.php?id=12            -> satu pegawai
 *   slip_gaji.php?periode=2026-10  -> semua pegawai periode itu, 2 slip per lembar A4
 */
require __DIR__ . '/../app/bootstrap.php';
require_login();

$id      = (int)($_GET['id'] ?? 0);
$periode = (string)($_GET['periode'] ?? '');

$sql = 'SELECT g.*, k.nama, k.nip, k.jabatan FROM gaji g JOIN karyawan k ON k.id = g.karyawan_id';
if ($id > 0) {
    $s = $pdo->prepare("$sql WHERE g.id = ?");
    $s->execute([$id]);
} elseif (periode_valid($periode)) {
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

// Rekap & rincian absensi per pegawai pada periodenya
$q  = $pdo->prepare('SELECT COALESCE(SUM(jam),0) FROM lembur WHERE karyawan_id=? AND tanggal BETWEEN ? AND ?');
$qa = $pdo->prepare('SELECT tanggal, jam_masuk, jam_keluar, tanggal_merah FROM absensi WHERE karyawan_id=? AND tanggal BETWEEN ? AND ?');
$ql = $pdo->prepare('SELECT tanggal, SUM(jam) FROM lembur WHERE karyawan_id=? AND tanggal BETWEEN ? AND ? GROUP BY tanggal');
foreach ($rows as &$r) {
    $rg = periode_range($r['periode']);
    $r['ada_rekap'] = $rg !== null;
    $rg = $rg ?? ['1000-01-01', '1000-01-01'];
    $r['p_awal'] = $rg[0]; $r['p_akhir'] = $rg[1];
    $q->execute([$r['karyawan_id'], $rg[0], $rg[1]]);
    $r['jam_lembur'] = (float)$q->fetchColumn();

    $abs = [];
    $qa->execute([$r['karyawan_id'], $rg[0], $rg[1]]);
    foreach ($qa->fetchAll() as $a) $abs[$a['tanggal']] = $a;
    $lem = [];
    $ql->execute([$r['karyawan_id'], $rg[0], $rg[1]]);
    foreach ($ql->fetchAll(PDO::FETCH_NUM) as $l) $lem[$l[0]] = (float)$l[1];

    // satu baris per tanggal di periode: jam masuk/pulang, jam kerja reguler (maks 9 jam, hari kerja biasa), jam lembur
    $detail = []; $kerja_total = 0.0;
    $n_hari = (int)round((strtotime($rg[1]) - strtotime($rg[0])) / 86400) + 1;
    if ($r['ada_rekap'] && $n_hari >= 1 && $n_hari <= 33) {
        for ($i = 0; $i < $n_hari; $i++) {
            $t = date('Y-m-d', strtotime($rg[0] . " +$i day"));
            $libur = in_array((int)date('N', strtotime($t)), HARI_LIBUR, true);
            $a = $abs[$t] ?? null;
            $kerja = 0.0; $m = ''; $p = '';
            if ($a) {
                $libur = $libur || (int)$a['tanggal_merah'] === 1;
                $menit = (strtotime($t . ' ' . $a['jam_keluar']) - strtotime($t . ' ' . $a['jam_masuk'])) / 60;
                if ($menit < 0) $menit += 1440;                      // pulang lewat tengah malam
                if (!$libur) $kerja = min($menit, JAM_KERJA_MENIT) / 60;
                $m = substr($a['jam_masuk'], 0, 5); $p = substr($a['jam_keluar'], 0, 5);
            }
            $kerja_total += $kerja;
            $detail[] = ['t' => $t, 'hari' => substr(NAMA_HARI[(int)date('w', strtotime($t))], 0, 3), 'masuk' => $m, 'pulang' => $p,
                         'kerja' => $kerja, 'lembur' => $lem[$t] ?? 0.0, 'libur' => $libur];
        }
    }
    $r['detail']     = $detail;
    $r['hari_hadir'] = count($abs);
    // jam kerja reguler: dari rincian harian; jika rincian tidak ada, hari setara x 9 jam
    $r['jam_kerja']  = $detail ? $kerja_total : (float)($r['hari_kerja'] ?? 0) * (JAM_KERJA_MENIT / 60);
}
unset($r);

$co      = company();
$halaman = array_chunk($rows, 2);
$kembali = 'gaji.php?periode=' . urlencode($rows[0]['periode']);
$tgl_cetak = ($co['kota'] !== '' ? $co['kota'] . ', ' : '') . tgl_panjang(date('Y-m-d'));
<<<<<<< HEAD
$jam_fmt = fn(float $j) => rtrim(rtrim(number_format($j, 2, ',', '.'), '0'), ',');
=======
$jam_fmt = fn(float $j) => jam_menit($j);                    // 4,75 jam -> "4 jam 45 menit"
>>>>>>> 01cb343 (update slip gaji)
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Slip gaji <?= e(count($rows) === 1 ? $rows[0]['nama'] . ' - ' . periode_label($rows[0]['periode']) : periode_label($rows[0]['periode'])) ?></title>
  <?= favicon_tags() ?>
  <link rel="stylesheet" href="<?= asset('slip.css') ?>">
</head>
<body>
<div class="toolbar">
  <a class="tb-back" href="<?= e($kembali) ?>">&larr; Kembali</a>
  <span class="tb-info"><?= count($rows) ?> slip, ukuran 1/2 A4 (<?= count($halaman) ?> lembar)</span>
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

      <?php if ($g['ada_rekap']): ?>
      <div class="s-rekap">
        <div class="rk-per"><span>Absensi</span><b><?= e(tgl($g['p_awal'])) ?> s/d <?= e(tgl($g['p_akhir'])) ?></b></div>
        <div><span>Hadir</span><b><?= (int)$g['hari_hadir'] ?> hari</b></div>
        <div><span>Jam kerja</span><b><?= e($jam_fmt($g['jam_kerja'])) ?></b></div>
        <div><span>Jam lembur</span><b><?= e($jam_fmt($g['jam_lembur'])) ?></b></div>
      </div>
      <?php endif; ?>

      <?php if ($g['detail']):
          $kol = max(2, (int)ceil(count($g['detail']) / 11));        // 2 kolom (2 mingguan / setengah bulan), 3 kolom (sebulan)
          $per = (int)ceil(count($g['detail']) / $kol);
      ?>
      <div class="s-abs" style="grid-template-columns: repeat(<?= (int)$kol ?>, 1fr)">
        <?php foreach (array_chunk($g['detail'], $per) as $blok): ?>
        <table>
          <thead><tr><th>Tanggal</th><th>Masuk</th><th>Pulang</th><th>Kerja</th><th>Lembur</th></tr></thead>
          <tbody>
          <?php foreach ($blok as $d): ?>
            <tr class="<?= $d['libur'] ? 'lb' : '' ?><?= $d['masuk'] === '' ? ' off' : '' ?>">
              <td><?= e($d['hari'] . ' ' . substr(tgl($d['t']), 0, 5)) ?></td>
              <td class="c"><?= $d['masuk'] !== '' ? e($d['masuk']) : '-' ?></td>
              <td class="c"><?= $d['pulang'] !== '' ? e($d['pulang']) : '-' ?></td>
              <td class="c"><?= $d['kerja'] > 0 ? e(jam_menit($d['kerja'], true)) : '-' ?></td>
              <td class="c"><?= $d['lembur'] > 0 ? e(jam_menit($d['lembur'], true)) : '-' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="s-cols">
        <table>
          <thead><tr><th colspan="2">Pendapatan</th></tr></thead>
          <tbody>
<<<<<<< HEAD
            <tr><td><?= (float)($g['hari_kerja'] ?? 0) > 0 ? e('Upah harian (' . hari_fmt($g['hari_kerja']) . ' hari x ' . rupiah($g['upah_harian']) . ')') : 'Gaji pokok' ?></td><td class="n"><?= rupiah($g['gaji_pokok']) ?></td></tr>
            <tr><td>Upah lembur<?= $g['jam_lembur'] != 0 ? ' (' . e($jam_fmt($g['jam_lembur'])) . ' jam)' : '' ?></td><td class="n"><?= rupiah($g['total_lembur']) ?></td></tr>
=======
            <tr><td><?= (float)($g['hari_kerja'] ?? 0) > 0 ? e('Gaji ' . hari_fmt($g['hari_kerja']) . ' hari') : 'Gaji pokok' ?></td><td class="n"><?= rupiah($g['gaji_pokok']) ?></td></tr>
            <tr><td>Lembur<?= $g['jam_lembur'] > 0 ? ' ' . e($jam_fmt($g['jam_lembur'])) : '' ?></td><td class="n"><?= rupiah($g['total_lembur']) ?></td></tr>
>>>>>>> 01cb343 (update slip gaji)
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
