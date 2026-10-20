<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
pastikan_kolom_harian();
$title = 'Karyawan';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = $_POST['aksi'] ?? '';
    try {
        if ($aksi === 'hapus') {
            $pdo->prepare('DELETE FROM karyawan WHERE id=?')->execute([(int)$_POST['id']]);
            flash('Karyawan dihapus.');
        } else {
            $pin = norm_pin($_POST['pin'] ?? '');
            $d = [trim($_POST['nip']), trim($_POST['nama']), trim($_POST['jabatan']), (float)$_POST['upah_harian'], $pin === '' ? null : $pin, isset($_POST['aktif']) ? 1 : 0];
            if ($aksi === 'edit') {
                $pdo->prepare('UPDATE karyawan SET nip=?, nama=?, jabatan=?, upah_harian=?, pin_fingerprint=?, aktif=? WHERE id=?')
                    ->execute([...$d, (int)$_POST['id']]);
                flash('Data karyawan diperbarui.');
            } else {
                $pdo->prepare('INSERT INTO karyawan (nip,nama,jabatan,upah_harian,pin_fingerprint,aktif) VALUES (?,?,?,?,?,?)')->execute($d);
                flash('Karyawan ditambahkan.');
            }
        }
    } catch (PDOException $ex) {
        flash('Gagal: NIP/PIN fingerprint sudah dipakai atau data karyawan masih memiliki riwayat transaksi (nonaktifkan saja).', 'danger');
    }
    redirect('karyawan.php');
}

$edit = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM karyawan WHERE id=?'); $s->execute([(int)$_GET['edit']]); $edit = $s->fetch();
}
[$hal, $per] = pg_params();
$total = (int)$pdo->query('SELECT COUNT(*) FROM karyawan')->fetchColumn();
$rows = $pdo->query('SELECT * FROM karyawan ORDER BY nama, id' . pg_limit($total, $hal, $per))->fetchAll();
require APP_PATH . '/views/header.php';
?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card"><div class="card-header"><?= $edit ? 'Edit' : 'Tambah' ?> Karyawan</div>
    <div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="aksi" value="<?= $edit ? 'edit' : 'tambah' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
        <div class="mb-2"><label class="form-label">NIP</label><input name="nip" class="form-control" required value="<?= e($edit['nip'] ?? '') ?>"></div>
        <div class="mb-2"><label class="form-label">Nama</label><input name="nama" class="form-control" required value="<?= e($edit['nama'] ?? '') ?>"></div>
        <div class="mb-2"><label class="form-label">Jabatan</label><input name="jabatan" class="form-control" value="<?= e($edit['jabatan'] ?? '') ?>"></div>
        <div class="mb-2"><label class="form-label">Upah per hari (Rp)</label><input type="number" min="0" step="1" name="upah_harian" class="form-control" required value="<?= e($edit['upah_harian'] ?? '') ?>"><div class="hint mt-1">Pekerja harian: upah per hari hadir, boleh berbeda tiap karyawan (sesuai masa kerja).</div></div>
        <div class="mb-2"><label class="form-label">PIN / ID Fingerprint</label><input name="pin" class="form-control" placeholder="ID karyawan di mesin fingerprint" value="<?= e($edit['pin_fingerprint'] ?? '') ?>"></div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="aktif" id="aktif" <?= ($edit['aktif'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label" for="aktif">Aktif</label></div>
        <button class="btn btn-primary">Simpan</button>
        <?php if ($edit): ?><a href="karyawan.php" class="btn btn-secondary">Batal</a><?php endif; ?>
      </form>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card"><div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>NIP</th><th>Nama</th><th>Jabatan</th><th class="text-end">Upah / Hari</th><th>PIN Finger</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['nip']) ?></td><td><?= e($r['nama']) ?></td><td><?= e($r['jabatan']) ?></td>
            <td class="text-end"><?= (float)$r['upah_harian'] > 0 ? rupiah($r['upah_harian']) : '<span class="text-danger">belum diisi</span>' ?></td>
            <td><?= e($r['pin_fingerprint']) ?></td>
            <td><span class="badge text-bg-<?= $r['aktif'] ? 'success' : 'secondary' ?>"><?= $r['aktif'] ? 'Aktif' : 'Nonaktif' ?></span></td>
            <td class="text-end text-nowrap">
              <a href="?edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus karyawan ini?')">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger">Hapus</button>
              </form>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-3">Belum ada data.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?= pg_footer($total, $hal, $per) ?></div>
  </div>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>
