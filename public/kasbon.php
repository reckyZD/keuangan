<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
$title = 'Kasbon';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = $_POST['aksi'] ?? '';

    /* ---------- Edit / hapus kasbon ---------- */
    if ($aksi === 'hapus' || $aksi === 'edit') {
        $st = $pdo->prepare('SELECT b.*, k.nama FROM kasbon b JOIN karyawan k ON k.id=b.karyawan_id WHERE b.id=?');
        $st->execute([(int)($_POST['id'] ?? 0)]);
        $b = $st->fetch();
        if (!$b) { flash('Data kasbon tidak ditemukan.', 'danger'); redirect('kasbon.php'); }

        $ket_kas  = 'Pencairan kasbon - ' . $b['nama'];            // catatan kas yang dibuat saat kasbon dicairkan
        $terbayar = (float)$b['jumlah'] - (float)$b['sisa'];        // sudah dipotong lewat gaji

        if ($aksi === 'hapus') {
            pastikan_tabel_potongan();
            $n = $pdo->prepare('SELECT COUNT(*) FROM gaji_potongan WHERE kasbon_id=?');
            $n->execute([$b['id']]);
            if ($terbayar > 0 || (int)$n->fetchColumn() > 0) {
                flash('Kasbon ini sudah dipotong lewat gaji, jadi tidak bisa dihapus. Reset gaji periode terkait dulu (menu Gaji & slip), atau ubah nilainya lewat Edit.', 'danger');
                redirect('kasbon.php');
            }
            $pdo->beginTransaction();
            try {
                $d = $pdo->prepare("DELETE FROM kas WHERE tanggal=? AND tipe='keluar' AND kategori='Kasbon' AND keterangan=? AND jumlah=? LIMIT 1");
                $d->execute([$b['tanggal'], $ket_kas, $b['jumlah']]);
                $kas_ada = $d->rowCount() > 0;
                $pdo->prepare('DELETE FROM kasbon WHERE id=?')->execute([$b['id']]);
                $pdo->commit();
                flash('Kasbon dihapus' . ($kas_ada ? ' beserta catatan pengeluaran kasnya.' : '. Catatan kas pencairannya tidak ditemukan, cek manual di menu Kas.'));
            } catch (Throwable $t) {
                $pdo->rollBack();
                flash('Gagal menghapus kasbon.', 'danger');
            }
            redirect('kasbon.php');
        }

        // edit
        $tanggal = (string)($_POST['tanggal'] ?? '');
        $dt      = DateTime::createFromFormat('Y-m-d', $tanggal);
        $jumlah  = (float)$_POST['jumlah'];
        $cicilan = (float)$_POST['cicilan'];
        if (!$dt || $dt->format('Y-m-d') !== $tanggal) {
            flash('Tanggal tidak valid.', 'danger');
        } elseif ($jumlah <= 0 || $cicilan <= 0 || $cicilan > $jumlah) {
            flash('Jumlah/cicilan tidak valid (cicilan tidak boleh melebihi jumlah).', 'danger');
        } elseif ($jumlah < $terbayar) {
            flash('Jumlah kasbon tidak boleh lebih kecil dari yang sudah dipotong lewat gaji (' . rupiah($terbayar) . ').', 'danger');
        } else {
            $sisa = $jumlah - $terbayar;
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE kasbon SET tanggal=?, jumlah=?, cicilan_per_bulan=?, sisa=?, status=?, keterangan=? WHERE id=?')
                    ->execute([$tanggal, $jumlah, $cicilan, $sisa, $sisa <= 0 ? 'lunas' : 'aktif', trim($_POST['keterangan'] ?? ''), $b['id']]);
                // samakan catatan kas pencairannya
                $u = $pdo->prepare("UPDATE kas SET tanggal=?, jumlah=? WHERE tanggal=? AND tipe='keluar' AND kategori='Kasbon' AND keterangan=? AND jumlah=? LIMIT 1");
                $u->execute([$tanggal, $jumlah, $b['tanggal'], $ket_kas, $b['jumlah']]);
                $kas_ada = $u->rowCount() > 0 || ($tanggal === $b['tanggal'] && $jumlah == (float)$b['jumlah']);
                $pdo->commit();
                flash('Kasbon diperbarui' . ($kas_ada ? '.' : '. Catatan kas pencairannya tidak ditemukan, cek manual di menu Kas.'));
            } catch (Throwable $t) {
                $pdo->rollBack();
                flash('Gagal memperbarui kasbon.', 'danger');
            }
        }
        redirect('kasbon.php');
    }

    /* ---------- Tambah kasbon ---------- */
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

$edit = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT b.*, k.nama FROM kasbon b JOIN karyawan k ON k.id=b.karyawan_id WHERE b.id=?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
$karyawan = $pdo->query('SELECT id, nama FROM karyawan WHERE aktif=1 ORDER BY nama')->fetchAll();
[$hal, $per] = pg_params();
$total = (int)$pdo->query('SELECT COUNT(*) FROM kasbon')->fetchColumn();
$rows = $pdo->query('SELECT b.*, k.nama FROM kasbon b JOIN karyawan k ON k.id=b.karyawan_id ORDER BY b.status, b.tanggal DESC, b.id DESC' . pg_limit($total, $hal, $per))->fetchAll();
require APP_PATH . '/views/header.php';
?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card"><div class="card-header"><?= $edit ? 'Edit' : 'Input' ?> Kasbon</div><div class="card-body">
      <form method="post">
        <?= csrf_field() ?>
        <?php if ($edit): ?><input type="hidden" name="aksi" value="edit"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
        <div class="mb-3"><label class="form-label">Karyawan</label>
          <?php if ($edit): ?>
            <input class="form-control" value="<?= e($edit['nama']) ?>" disabled>
          <?php else: ?>
          <select name="karyawan_id" class="form-select" required>
            <?php foreach ($karyawan as $k): ?><option value="<?= (int)$k['id'] ?>"><?= e($k['nama']) ?></option><?php endforeach; ?>
          </select>
          <?php endif; ?></div>
        <div class="mb-3"><label class="form-label">Tanggal</label><input type="date" name="tanggal" class="form-control" required value="<?= e($edit['tanggal'] ?? date('Y-m-d')) ?>"></div>
        <div class="mb-3"><label class="form-label" for="kb-jumlah">Jumlah kasbon</label>
          <div class="amount"><span>Rp</span><input type="text" id="kb-jumlah" inputmode="numeric" autocomplete="off" placeholder="0" required data-rupiah value="<?= $edit ? (int)round($edit['jumlah']) : '' ?>"><input type="hidden" name="jumlah"></div>
          <?php if ($edit && $edit['jumlah'] > $edit['sisa']): ?><div class="hint mt-1">Sudah dipotong lewat gaji: <?= rupiah($edit['jumlah'] - $edit['sisa']) ?> (jumlah tidak boleh lebih kecil dari ini).</div><?php endif; ?></div>
        <div class="mb-3"><label class="form-label" for="kb-cicilan">Cicilan per gajian (potongan gaji)</label>
          <div class="amount"><span>Rp</span><input type="text" id="kb-cicilan" inputmode="numeric" autocomplete="off" placeholder="0" required data-rupiah value="<?= $edit ? (int)round($edit['cicilan_per_bulan']) : '' ?>"><input type="hidden" name="cicilan"></div></div>
        <div class="mb-3"><label class="form-label">Keterangan</label><input name="keterangan" class="form-control" value="<?= e($edit['keterangan'] ?? '') ?>"></div>
        <button class="btn btn-primary">Simpan</button>
        <?php if ($edit): ?><a href="kasbon.php" class="btn btn-secondary">Batal</a><?php endif; ?>
      </form>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card"><div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Tanggal</th><th>Karyawan</th><th class="text-end">Jumlah</th><th class="text-end">Cicilan</th><th class="text-end">Sisa</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= e(tgl($r['tanggal'])) ?></td><td><?= e($r['nama']) ?></td>
            <td class="text-end"><?= rupiah($r['jumlah']) ?></td><td class="text-end"><?= rupiah($r['cicilan_per_bulan']) ?></td>
            <td class="text-end"><?= rupiah($r['sisa']) ?></td>
            <td><span class="badge text-bg-<?= $r['status'] === 'lunas' ? 'success' : 'warning' ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            <td class="text-end text-nowrap">
              <a href="?<?= e(http_build_query(array_merge($_GET, ['edit' => (int)$r['id']]))) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus kasbon ini? Catatan pengeluaran kas pencairannya juga ikut dihapus.')">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger">Hapus</button>
              </form>
            </td></tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-3">Belum ada kasbon.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?= pg_footer($total, $hal, $per) ?></div>
  </div>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>
