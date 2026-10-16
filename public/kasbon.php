<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
$title = 'Kasbon';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $jumlah = (float)$_POST['jumlah'];
    $cicilan = (float)$_POST['cicilan'];
    if ($jumlah > 0 && $cicilan > 0 && $cicilan <= $jumlah) {
        $nm = $pdo->prepare('SELECT nama FROM karyawan WHERE id=?');
        $nm->execute([(int)$_POST['karyawan_id']]);
        $nama_k = (string)$nm->fetchColumn();
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO kasbon (karyawan_id,tanggal,jumlah,cicilan_per_bulan,sisa,keterangan) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$_POST['karyawan_id'], $_POST['tanggal'], $jumlah, $cicilan, $jumlah, trim($_POST['keterangan'])]);
        // Kasbon yang dicairkan tercatat sebagai kas keluar
        $pdo->prepare('INSERT INTO kas (tanggal,tipe,kategori,jumlah,keterangan) VALUES (?,?,?,?,?)')
            ->execute([$_POST['tanggal'], 'keluar', 'Kasbon', $jumlah, 'Pencairan kasbon - ' . $nama_k]);
        $pdo->commit();
        flash('Kasbon dicatat.');
    } else {
        flash('Jumlah/cicilan tidak valid (cicilan tidak boleh melebihi jumlah).', 'danger');
    }
    redirect('kasbon.php');
}

$karyawan = $pdo->query('SELECT id, nama FROM karyawan WHERE aktif=1 ORDER BY nama')->fetchAll();
$rows = $pdo->query('SELECT b.*, k.nama FROM kasbon b JOIN karyawan k ON k.id=b.karyawan_id ORDER BY b.status, b.tanggal DESC')->fetchAll();
require APP_PATH . '/views/header.php';
?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card"><div class="card-header">Input Kasbon</div><div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label">Karyawan</label>
          <select name="karyawan_id" class="form-select" required>
            <?php foreach ($karyawan as $k): ?><option value="<?= (int)$k['id'] ?>"><?= e($k['nama']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="mb-3"><label class="form-label">Tanggal</label><input type="date" name="tanggal" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
        <div class="mb-3"><label class="form-label" for="kb-jumlah">Jumlah kasbon</label>
          <div class="amount"><span>Rp</span><input type="text" id="kb-jumlah" inputmode="numeric" autocomplete="off" placeholder="0" required data-rupiah><input type="hidden" name="jumlah"></div></div>
        <div class="mb-3"><label class="form-label" for="kb-cicilan">Cicilan per gajian (potongan gaji)</label>
          <div class="amount"><span>Rp</span><input type="text" id="kb-cicilan" inputmode="numeric" autocomplete="off" placeholder="0" required data-rupiah><input type="hidden" name="cicilan"></div></div>
        <div class="mb-3"><label class="form-label">Keterangan</label><input name="keterangan" class="form-control"></div>
        <button class="btn btn-primary">Simpan</button>
      </form>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card"><div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Tanggal</th><th>Karyawan</th><th class="text-end">Jumlah</th><th class="text-end">Cicilan</th><th class="text-end">Sisa</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= e($r['tanggal']) ?></td><td><?= e($r['nama']) ?></td>
            <td class="text-end"><?= rupiah($r['jumlah']) ?></td><td class="text-end"><?= rupiah($r['cicilan_per_bulan']) ?></td>
            <td class="text-end"><?= rupiah($r['sisa']) ?></td>
            <td><span class="badge text-bg-<?= $r['status'] === 'lunas' ? 'success' : 'warning' ?>"><?= e(ucfirst($r['status'])) ?></span></td></tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-3">Belum ada kasbon.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div></div>
  </div>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>
