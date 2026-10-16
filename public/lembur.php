<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
$title = 'Lembur';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['aksi'] ?? '') === 'hapus') {
        $pdo->prepare('DELETE FROM lembur WHERE id=?')->execute([(int)$_POST['id']]);
        flash('Data lembur dihapus.');
    } else {
        $s = $pdo->prepare('SELECT gaji_pokok FROM karyawan WHERE id=? AND aktif=1');
        $s->execute([(int)$_POST['karyawan_id']]);
        $k = $s->fetch();
        $jam = (float)$_POST['jam'];
        $tipe = $_POST['tipe'] === 'libur' ? 'libur' : 'hari_kerja';
        if ($k && $jam > 0 && $jam <= 24) {
            [$upah_jam, $total] = hitung_lembur($jam);
            $pdo->prepare('INSERT INTO lembur (karyawan_id,tanggal,jam,tipe,upah_per_jam,total,keterangan) VALUES (?,?,?,?,?,?,?)')
                ->execute([(int)$_POST['karyawan_id'], $_POST['tanggal'], $jam, $tipe, $upah_jam, $total, trim($_POST['keterangan'])]);
            flash('Lembur dicatat: ' . rupiah($total));
        } else {
            flash('Data tidak valid.', 'danger');
        }
    }
    redirect('lembur.php');
}

$periode = $_GET['periode'] ?? date('Y-m');
$karyawan = $pdo->query('SELECT id, nama FROM karyawan WHERE aktif=1 ORDER BY nama')->fetchAll();
$s = $pdo->prepare("SELECT l.*, k.nama FROM lembur l JOIN karyawan k ON k.id=l.karyawan_id
                    WHERE DATE_FORMAT(l.tanggal,'%Y-%m') = ? ORDER BY l.tanggal DESC, l.id DESC");
$s->execute([$periode]);
$rows = $s->fetchAll();
$grand = array_sum(array_column($rows, 'total'));
require APP_PATH . '/views/header.php';
?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card"><div class="card-header">Input Lembur</div><div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-2"><label class="form-label">Karyawan</label>
          <select name="karyawan_id" class="form-select" required>
            <?php foreach ($karyawan as $k): ?><option value="<?= (int)$k['id'] ?>"><?= e($k['nama']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="mb-2"><label class="form-label">Tanggal</label><input type="date" name="tanggal" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
        <div class="mb-2"><label class="form-label">Jam Lembur <span class="text-muted fw-normal">(Rp <?= number_format(UPAH_LEMBUR_PER_JAM, 0, ',', '.') ?>/jam)</span></label><input type="number" name="jam" step="0.5" min="0.5" max="24" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Jenis Hari</label>
          <select name="tipe" class="form-select"><option value="hari_kerja">Hari kerja</option><option value="libur">Hari libur (Minggu / tanggal merah)</option></select></div>
        <div class="mb-3"><label class="form-label">Keterangan</label><input name="keterangan" class="form-control"></div>
        <button class="btn btn-primary">Simpan</button>
      </form>
    </div></div>
  </div>
  <div class="col-lg-8">
    <form class="row g-2 mb-3" method="get">
      <div class="col-auto"><input type="month" name="periode" class="form-control" value="<?= e($periode) ?>"></div>
      <div class="col-auto"><button class="btn btn-outline-primary">Tampilkan</button></div>
    </form>
    <div class="card"><div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Tanggal</th><th>Karyawan</th><th>Jam</th><th>Jenis</th><th class="text-end">Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= e($r['tanggal']) ?></td><td><?= e($r['nama']) ?></td><td><?= e($r['jam']) ?></td>
            <td><?= $r['tipe'] === 'libur' ? 'Libur' : 'Hari kerja' ?></td>
            <td class="text-end"><?= rupiah($r['total']) ?></td>
            <td class="text-end"><form method="post" onsubmit="return confirm('Hapus?')"><?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger">Hapus</button></form></td></tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-3">Belum ada lembur.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr class="table-light"><th colspan="4">Total periode</th><th class="text-end"><?= rupiah($grand) ?></th><th></th></tr></tfoot>
      </table>
    </div></div>
  </div>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>
