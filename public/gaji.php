<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
$title = 'Gaji & slip';
$subtitle = 'Proses gaji per periode, lalu cetak slip per pegawai (1/3 A4).';

$periode = $_REQUEST['periode'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $periode)) $periode = date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $dibuat = 0;
    $pdo->beginTransaction();
    try {
        $cek = $pdo->prepare('SELECT 1 FROM gaji WHERE karyawan_id=? AND periode=?');
        $lem = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM lembur WHERE karyawan_id=? AND DATE_FORMAT(tanggal,'%Y-%m')=?");
        $kas = $pdo->prepare("SELECT * FROM kasbon WHERE karyawan_id=? AND status='aktif' FOR UPDATE");

        foreach ($pdo->query('SELECT * FROM karyawan WHERE aktif=1')->fetchAll() as $k) {
            $cek->execute([$k['id'], $periode]);
            if ($cek->fetch()) continue;               // sudah digaji periode ini

            $lem->execute([$k['id'], $periode]);
            $total_lembur = (float)$lem->fetchColumn();

            $kas->execute([$k['id']]);
            $potongan = 0.0;
            foreach ($kas->fetchAll() as $b) {
                $p = min((float)$b['cicilan_per_bulan'], (float)$b['sisa']);
                $sisa_baru = (float)$b['sisa'] - $p;
                $pdo->prepare('UPDATE kasbon SET sisa=?, status=? WHERE id=?')
                    ->execute([$sisa_baru, $sisa_baru <= 0 ? 'lunas' : 'aktif', $b['id']]);
                $potongan += $p;
            }
            $bersih = (float)$k['gaji_pokok'] + $total_lembur - $potongan;
            $pdo->prepare('INSERT INTO gaji (karyawan_id,periode,gaji_pokok,total_lembur,potongan_kasbon,gaji_bersih) VALUES (?,?,?,?,?,?)')
                ->execute([$k['id'], $periode, $k['gaji_pokok'], $total_lembur, $potongan, $bersih]);
            // Gaji pokok dan lembur di luar kas. Yang tercatat di kas hanya potongan kasbon
            // (masuk) = pengembalian uang kas yang dulu dipinjamkan. Lembur bisa diaktifkan
            // lewat LEMBUR_DARI_KAS di app/config.php jika kebijakan berubah.
            if (LEMBUR_DARI_KAS && $total_lembur > 0) {
                $pdo->prepare('INSERT INTO kas (tanggal,tipe,kategori,jumlah,keterangan) VALUES (?,?,?,?,?)')
                    ->execute([date('Y-m-d'), 'keluar', 'Lembur', $total_lembur, 'Upah lembur ' . $periode . ' - ' . $k['nama']]);
            }
            if ($potongan > 0) {
                $pdo->prepare('INSERT INTO kas (tanggal,tipe,kategori,jumlah,keterangan) VALUES (?,?,?,?,?)')
                    ->execute([date('Y-m-d'), 'masuk', 'Pelunasan Kasbon', $potongan, 'Potong gaji ' . $periode . ' - ' . $k['nama']]);
            }
            $dibuat++;
        }
        $pdo->commit();
        flash("Penggajian periode $periode selesai: $dibuat karyawan diproses.");
    } catch (Throwable $t) {
        $pdo->rollBack();
        flash('Gagal memproses gaji.', 'danger');
    }
    redirect('gaji.php?periode=' . urlencode($periode));
}

$s = $pdo->prepare('SELECT g.*, k.nama, k.nip FROM gaji g JOIN karyawan k ON k.id=g.karyawan_id WHERE g.periode=? ORDER BY k.nama');
$s->execute([$periode]);
$rows = $s->fetchAll();
require APP_PATH . '/views/header.php';
?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <form method="get" class="d-flex gap-2">
    <input type="month" name="periode" class="form-control" value="<?= e($periode) ?>">
    <button class="btn btn-outline-primary">Tampilkan</button>
  </form>
  <form method="post" onsubmit="return confirm('Proses gaji periode <?= e($periode) ?>? Cicilan kasbon akan dipotong.')">
    <?= csrf_field() ?><input type="hidden" name="periode" value="<?= e($periode) ?>">
    <button class="btn btn-success">Proses gaji periode ini</button>
  </form>
  <?php if ($rows): ?>
    <a class="btn btn-outline-primary ms-lg-auto" href="slip_gaji.php?periode=<?= e($periode) ?>" target="_blank" rel="noopener"><?= icon('printer', 18) ?> Cetak semua slip</a>
  <?php endif; ?>
</div>
<div class="card"><div class="table-responsive">
  <table class="table table-hover mb-0 align-middle">
    <thead><tr><th>NIP</th><th>Nama</th><th class="text-end">Gaji Pokok</th><th class="text-end">Lembur</th><th class="text-end">Potongan Kasbon</th><th class="text-end">Gaji Bersih</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= e($r['nip']) ?></td><td><?= e($r['nama']) ?></td>
        <td class="text-end"><?= rupiah($r['gaji_pokok']) ?></td><td class="text-end"><?= rupiah($r['total_lembur']) ?></td>
        <td class="text-end text-danger">-<?= rupiah($r['potongan_kasbon']) ?></td><td class="text-end fw-semibold"><?= rupiah($r['gaji_bersih']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="slip_gaji.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">Cetak slip</a></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-3">Belum ada gaji diproses untuk periode ini.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr class="table-light"><th colspan="5">Total</th><th class="text-end"><?= rupiah(array_sum(array_column($rows, 'gaji_bersih'))) ?></th><th></th></tr></tfoot>
  </table>
</div></div>
<?php require APP_PATH . '/views/footer.php'; ?>
