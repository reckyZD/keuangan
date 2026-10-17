<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
$title = 'Kas';
$subtitle = 'Catat pemasukan dan pengeluaran harian.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['aksi'] ?? '') === 'hapus') {
        $pdo->prepare('DELETE FROM kas WHERE id=?')->execute([(int)$_POST['id']]);
        flash('Transaksi dihapus.');
        redirect('kas.php');
    } elseif ((float)$_POST['jumlah'] > 0) {
        $pdo->prepare('INSERT INTO kas (tanggal,tipe,kategori,jumlah,keterangan) VALUES (?,?,?,?,?)')
            ->execute([$_POST['tanggal'], $_POST['tipe'] === 'masuk' ? 'masuk' : 'keluar', trim($_POST['kategori']), (float)$_POST['jumlah'], trim($_POST['keterangan'])]);
        flash('Transaksi dicatat.');
    } else {
        flash('Jumlah harus lebih dari nol.', 'danger');
    }
    redirect(safe_back($_POST['kembali'] ?? ''));   // kembali ke halaman asal (dashboard / modal)
}

$periode = $_GET['periode'] ?? date('Y-m');
[$hal, $per] = pg_params();
// total & saldo dihitung dari seluruh periode (bukan hanya halaman yang tampil)
$t = $pdo->prepare("SELECT COUNT(*) AS n,
                           COALESCE(SUM(CASE WHEN tipe='masuk'  THEN jumlah END),0) AS masuk,
                           COALESCE(SUM(CASE WHEN tipe='keluar' THEN jumlah END),0) AS keluar
                    FROM kas WHERE DATE_FORMAT(tanggal,'%Y-%m')=?");
$t->execute([$periode]);
$sum = $t->fetch();
$total = (int)$sum['n']; $masuk = (float)$sum['masuk']; $keluar = (float)$sum['keluar'];
$s = $pdo->prepare("SELECT * FROM kas WHERE DATE_FORMAT(tanggal,'%Y-%m')=? ORDER BY tanggal DESC, id DESC" . pg_limit($total, $hal, $per));
$s->execute([$periode]);
$rows = $s->fetchAll();
require APP_PATH . '/views/header.php';
?>
<div class="row g-4">
  <div class="col-lg-4">
    <section class="panel quick" id="kas-cepat" aria-labelledby="kc-h">
      <div class="panel-head"><h2 id="kc-h">Catat kas</h2></div>
      <?php $kf_id = 'kfk'; require APP_PATH . '/views/kas_form.php'; ?>
    </section>
  </div>
  <div class="col-lg-8">
    <?php
    $pb = preg_match('/^\d{4}-\d{2}$/', $periode) ? $periode : date('Y-m');
    $ex_dari = $pb . '-01'; $ex_sampai = date('Y-m-t', strtotime($ex_dari));
    ?>
    <div class="card mb-3"><div class="card-body py-3">
      <form class="row g-2 align-items-end" method="get" action="kas_export.php">
        <div class="col-12 fw-semibold">Unduh rekap kas</div>
        <div class="col-sm-auto"><label class="form-label" for="ex-dari">Dari tanggal</label><input type="date" id="ex-dari" name="dari" class="form-control" required value="<?= e($ex_dari) ?>"></div>
        <div class="col-sm-auto"><label class="form-label" for="ex-sampai">Sampai tanggal</label><input type="date" id="ex-sampai" name="sampai" class="form-control" required value="<?= e($ex_sampai) ?>"></div>
        <div class="col-sm-auto"><button class="btn btn-success"><?= icon('download', 18) ?> Unduh</button></div>
      </form>
    </div></div>
    <form class="row g-2 mb-3" method="get">
      <div class="col-auto"><input type="month" name="periode" class="form-control" value="<?= e($periode) ?>"></div>
      <div class="col-auto"><button class="btn btn-outline-primary">Tampilkan</button></div>
    </form>
    <div class="card"><div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Tanggal</th><th>Kategori</th><th>Keterangan</th><th class="text-end">Masuk</th><th class="text-end">Keluar</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= e(tgl($r['tanggal'])) ?></td><td><?= e($r['kategori']) ?></td><td><?= e($r['keterangan']) ?></td>
            <td class="text-end text-success"><?= $r['tipe'] === 'masuk' ? rupiah($r['jumlah']) : '' ?></td>
            <td class="text-end text-danger"><?= $r['tipe'] === 'keluar' ? rupiah($r['jumlah']) : '' ?></td>
            <td class="text-end"><form method="post" onsubmit="return confirm('Hapus?')"><?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger">Hapus</button></form></td></tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-3">Belum ada transaksi.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr class="table-light"><th colspan="3">Total periode (Saldo: <?= rupiah($masuk - $keluar) ?>)</th>
          <th class="text-end"><?= rupiah($masuk) ?></th><th class="text-end"><?= rupiah($keluar) ?></th><th></th></tr></tfoot>
      </table>
    </div>
    <?= pg_footer($total, $hal, $per) ?></div>
  </div>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>
