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
$s = $pdo->prepare("SELECT * FROM kas WHERE DATE_FORMAT(tanggal,'%Y-%m')=? ORDER BY tanggal DESC, id DESC");
$s->execute([$periode]);
$rows = $s->fetchAll();
$masuk = $keluar = 0;
foreach ($rows as $r) { if ($r['tipe'] === 'masuk') $masuk += $r['jumlah']; else $keluar += $r['jumlah']; }
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
        <tfoot><tr class="table-light"><th colspan="3">Total (Saldo: <?= rupiah($masuk - $keluar) ?>)</th>
          <th class="text-end"><?= rupiah($masuk) ?></th><th class="text-end"><?= rupiah($keluar) ?></th><th></th></tr></tfoot>
      </table>
    </div></div>
  </div>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>
