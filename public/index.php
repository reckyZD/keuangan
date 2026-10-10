<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
$title = 'Dashboard';
$hide_title = true;
$bulan = date('Y-m');

/* ---- Angka ringkas ---- */
$jml_karyawan = (int)$pdo->query('SELECT COUNT(*) FROM karyawan WHERE aktif=1')->fetchColumn();
$kasbon_aktif = (float)$pdo->query("SELECT COALESCE(SUM(sisa),0) FROM kasbon WHERE status='aktif'")->fetchColumn();

$s = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM lembur WHERE DATE_FORMAT(tanggal,'%Y-%m') = ?");
$s->execute([$bulan]);
$lembur_bulan = (float)$s->fetchColumn();

$s = $pdo->prepare("SELECT tipe, COALESCE(SUM(jumlah),0) t FROM kas WHERE DATE_FORMAT(tanggal,'%Y-%m') = ? GROUP BY tipe");
$s->execute([$bulan]);
$kas = ['masuk' => 0, 'keluar' => 0];
foreach ($s->fetchAll() as $r) $kas[$r['tipe']] = (float)$r['t'];

$saldo = (float)$pdo->query("SELECT COALESCE(SUM(CASE tipe WHEN 'masuk' THEN jumlah ELSE -jumlah END),0) FROM kas")->fetchColumn();

/* ---- Kas 7 hari terakhir ---- */
$hari = [];
for ($i = 6; $i >= 0; $i--) $hari[date('Y-m-d', strtotime("-$i day"))] = ['masuk' => 0.0, 'keluar' => 0.0];
$s = $pdo->prepare('SELECT tanggal, tipe, SUM(jumlah) t FROM kas WHERE tanggal >= ? GROUP BY tanggal, tipe');
$s->execute([array_key_first($hari)]);
foreach ($s->fetchAll() as $r) {
    if (isset($hari[$r['tanggal']])) $hari[$r['tanggal']][$r['tipe']] = (float)$r['t'];
}
$maks = max(1, ...array_values(array_map(fn($h) => max($h['masuk'], $h['keluar']), $hari)));

$terakhir = $pdo->query('SELECT * FROM kas ORDER BY tanggal DESC, id DESC LIMIT 8')->fetchAll();

$jam = (int)date('G');
$salam = $jam < 11 ? 'Selamat pagi' : ($jam < 15 ? 'Selamat siang' : ($jam < 18 ? 'Selamat sore' : 'Selamat malam'));
$nama_depan = explode(' ', trim($_SESSION['user']['nama'] ?? ''))[0];

$kf_id = 'kfd';
require APP_PATH . '/views/header.php';
?>
<div class="greet">
  <h1><?= e($salam) ?>, <?= e($nama_depan) ?></h1>
  <p><?= e(tgl_panjang(date('Y-m-d'), true)) ?></p>
</div>

<section class="ledger" aria-label="Ringkasan kas">
  <div>
    <p class="ledger-label">Saldo kas saat ini</p>
    <p class="ledger-saldo <?= $saldo < 0 ? 'neg' : '' ?>"><?= rupiah($saldo) ?></p>
    <div class="ledger-flow">
      <div><span class="lbl"><i class="dot in"></i>Masuk bulan ini</span><b><?= rupiah($kas['masuk']) ?></b></div>
      <div><span class="lbl"><i class="dot out"></i>Keluar bulan ini</span><b><?= rupiah($kas['keluar']) ?></b></div>
    </div>
  </div>
  <div class="bars-wrap">
    <p class="bars-title"><span>7 hari terakhir</span><span>Selisih bulan ini: <?= rupiah($kas['masuk'] - $kas['keluar']) ?></span></p>
    <div class="bars" role="img" aria-label="Grafik kas masuk dan keluar 7 hari terakhir">
      <?php foreach ($hari as $d => $h): $hi = fn($v) => $v > 0 ? max(4, round($v / $maks * 100)) : 4; ?>
        <div class="bar-col <?= $d === date('Y-m-d') ? 'today' : '' ?>" title="<?= e(tgl($d)) ?>: masuk <?= e(rupiah($h['masuk'])) ?>, keluar <?= e(rupiah($h['keluar'])) ?>">
          <div class="bar-pair">
            <i class="in <?= $h['masuk'] > 0 ? '' : 'nol' ?>"  style="height:<?= $hi($h['masuk']) ?>%"></i>
            <i class="out <?= $h['keluar'] > 0 ? '' : 'nol' ?>" style="height:<?= $hi($h['keluar']) ?>%"></i>
          </div>
          <span><?= e(substr(NAMA_HARI[(int)date('w', strtotime($d))], 0, 3)) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<div class="dash-grid">
  <section class="panel quick" id="kas-cepat" aria-labelledby="kc-h">
    <div class="panel-head"><h2 id="kc-h">Catat kas</h2></div>
    <?php require APP_PATH . '/views/kas_form.php'; ?>
  </section>

  <section class="panel" aria-labelledby="tx-h">
    <div class="panel-head"><h2 id="tx-h">Transaksi terakhir</h2><a href="kas.php">Lihat semua</a></div>
    <?php if ($terakhir): ?>
      <ul class="tx">
        <?php foreach ($terakhir as $r): $in = $r['tipe'] === 'masuk'; ?>
          <li>
            <span class="tx-ico <?= $in ? 'in' : 'out' ?>"><?= icon($in ? 'arrow-in' : 'arrow-out', 20) ?></span>
            <span class="tx-main">
              <strong><?= e($r['kategori']) ?></strong>
              <?php if ($r['keterangan'] !== null && $r['keterangan'] !== ''): ?><span><?= e($r['keterangan']) ?></span><?php endif; ?>
            </span>
            <span class="tx-amt">
              <b class="<?= $in ? 'in' : 'out' ?>"><?= $in ? '+' : '-' ?><?= rupiah($r['jumlah']) ?></b>
              <span><?= e(tgl($r['tanggal'])) ?></span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="empty">Belum ada transaksi kas. Catat yang pertama lewat form di samping.</div>
    <?php endif; ?>
  </section>
</div>

<div class="tiles">
  <a class="tile kraft" href="gaji.php?periode=<?= e($bulan) ?>">
    <span class="tile-ico"><?= icon('printer', 22) ?></span>
    <span><b>Slip gaji</b><span class="sub">Periode <?= e(periode_label($bulan)) ?></span></span>
  </a>
  <a class="tile" href="karyawan.php">
    <span class="tile-ico"><?= icon('users', 22) ?></span>
    <span><small>Karyawan aktif</small><b><?= $jml_karyawan ?></b></span>
  </a>
  <a class="tile" href="lembur.php">
    <span class="tile-ico"><?= icon('clock', 22) ?></span>
    <span><small>Lembur bulan ini</small><b><?= rupiah($lembur_bulan) ?></b></span>
  </a>
  <a class="tile" href="kasbon.php">
    <span class="tile-ico"><?= icon('receipt', 22) ?></span>
    <span><small>Sisa kasbon aktif</small><b><?= rupiah($kasbon_aktif) ?></b></span>
  </a>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>
