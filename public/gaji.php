<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
$title = 'Gaji & slip';
$subtitle = 'Proses gaji per periode, lalu cetak slip per pegawai (1/3 A4).';

$periode = (string)($_REQUEST['periode'] ?? '');
if (!periode_valid($periode)) $periode = periode_for_date(date('Y-m-d'));
[$p_awal, $p_akhir] = periode_range($periode);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    pastikan_kolom_periode();      // DDL: harus di luar transaksi
    pastikan_tabel_potongan();

    /* ---------- Reset: hapus gaji & slip periode ini, kembalikan sisa kasbon ---------- */
    if (($_POST['aksi'] ?? '') === 'reset') {
        $pdo->beginTransaction();
        try {
            $s = $pdo->prepare('SELECT g.*, k.nama FROM gaji g JOIN karyawan k ON k.id=g.karyawan_id WHERE g.periode=? FOR UPDATE');
            $s->execute([$periode]);
            $hapus = $s->fetchAll();
            $det   = $pdo->prepare('SELECT kasbon_id, jumlah FROM gaji_potongan WHERE gaji_id=?');
            $balik = $pdo->prepare("UPDATE kasbon SET sisa = LEAST(jumlah, sisa + ?), status='aktif' WHERE id=?");
            $akhir = $pdo->prepare('SELECT id FROM kasbon WHERE karyawan_id=? ORDER BY id DESC LIMIT 1');
            $delD  = $pdo->prepare('DELETE FROM gaji_potongan WHERE gaji_id=?');
            $delG  = $pdo->prepare('DELETE FROM gaji WHERE id=?');
            $delK  = $pdo->prepare("DELETE FROM kas WHERE kategori='Lembur' AND keterangan IN (?, ?)");
            foreach ($hapus as $g) {
                $det->execute([$g['id']]);
                $rinci = $det->fetchAll();
                if ($rinci) {
                    foreach ($rinci as $x) $balik->execute([$x['jumlah'], $x['kasbon_id']]);
                } elseif ((float)$g['potongan_kasbon'] > 0) {          // slip lama tanpa rincian: kembalikan ke kasbon terbaru
                    $akhir->execute([$g['karyawan_id']]);
                    if ($kid = $akhir->fetchColumn()) $balik->execute([$g['potongan_kasbon'], $kid]);
                }
                $delD->execute([$g['id']]);
                $delG->execute([$g['id']]);
                $delK->execute(['Upah lembur ' . periode_label($periode) . ' - ' . $g['nama'], 'Upah lembur ' . $periode . ' - ' . $g['nama']]);
            }
            $pdo->commit();
            flash('Gaji periode ' . periode_label($periode) . ' direset: ' . count($hapus) . ' slip dihapus dan sisa kasbon dikembalikan. Silakan proses ulang.');
        } catch (Throwable $t) {
            $pdo->rollBack();
            flash('Gagal mereset gaji.', 'danger');
        }
        redirect('gaji.php?periode=' . urlencode($periode));
    }

    /* ---------- Proses gaji ---------- */
    $dibuat = 0;
    $pdo->beginTransaction();
    try {
        $cek = $pdo->prepare('SELECT 1 FROM gaji WHERE karyawan_id=? AND periode=?');
        $lem = $pdo->prepare('SELECT COALESCE(SUM(total),0) FROM lembur WHERE karyawan_id=? AND tanggal BETWEEN ? AND ?');
        $kas = $pdo->prepare("SELECT * FROM kasbon WHERE karyawan_id=? AND status='aktif' FOR UPDATE");

        foreach ($pdo->query('SELECT * FROM karyawan WHERE aktif=1')->fetchAll() as $k) {
            $cek->execute([$k['id'], $periode]);
            if ($cek->fetch()) continue;               // sudah digaji periode ini

            $lem->execute([$k['id'], $p_awal, $p_akhir]);
            $total_lembur = (float)$lem->fetchColumn();

            $kas->execute([$k['id']]);
            $potongan = 0.0;
            $rinci = [];
            foreach ($kas->fetchAll() as $b) {
                $p = min((float)$b['cicilan_per_bulan'], (float)$b['sisa']);
                if ($p <= 0) continue;
                $sisa_baru = (float)$b['sisa'] - $p;
                $pdo->prepare('UPDATE kasbon SET sisa=?, status=? WHERE id=?')
                    ->execute([$sisa_baru, $sisa_baru <= 0 ? 'lunas' : 'aktif', $b['id']]);
                $potongan += $p;
                $rinci[] = [$b['id'], $p];
            }
            $gp = gaji_pokok_periode((float)$k['gaji_pokok'], $periode);
            $bersih = $gp + $total_lembur - $potongan;
            $pdo->prepare('INSERT INTO gaji (karyawan_id,periode,gaji_pokok,total_lembur,potongan_kasbon,gaji_bersih) VALUES (?,?,?,?,?,?)')
                ->execute([$k['id'], $periode, $gp, $total_lembur, $potongan, $bersih]);
            $gid = (int)$pdo->lastInsertId();
            foreach ($rinci as [$kb_id, $kb_jml]) {
                $pdo->prepare('INSERT INTO gaji_potongan (gaji_id,kasbon_id,jumlah) VALUES (?,?,?)')->execute([$gid, $kb_id, $kb_jml]);
            }
            // Gaji pokok, lembur, dan potongan kasbon semuanya di luar kas: potongan kasbon
            // dibayar langsung oleh owner lewat pemotongan gaji. Lembur bisa dicatat sebagai
            // pengeluaran kas lewat LEMBUR_DARI_KAS di app/config.php jika kebijakan berubah.
            if (LEMBUR_DARI_KAS && $total_lembur > 0) {
                $pdo->prepare('INSERT INTO kas (tanggal,tipe,kategori,jumlah,keterangan) VALUES (?,?,?,?,?)')
                    ->execute([date('Y-m-d'), 'keluar', 'Lembur', $total_lembur, 'Upah lembur ' . periode_label($periode) . ' - ' . $k['nama']]);
            }
            $dibuat++;
        }
        $pdo->commit();
        flash('Penggajian periode ' . periode_label($periode) . " selesai: $dibuat karyawan diproses.");
    } catch (Throwable $t) {
        $pdo->rollBack();
        flash('Gagal memproses gaji.', 'danger');
    }
    redirect('gaji.php?periode=' . urlencode($periode));
}

[$hal, $per] = pg_params();
// jumlah data & total dihitung dari seluruh slip periode (bukan hanya halaman yang tampil)
$c = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(gaji_bersih),0) AS bersih FROM gaji WHERE periode=?');
$c->execute([$periode]);
$sum = $c->fetch();
$total = (int)$sum['n']; $total_bersih = (float)$sum['bersih'];
$s = $pdo->prepare('SELECT g.*, k.nama, k.nip FROM gaji g JOIN karyawan k ON k.id=g.karyawan_id WHERE g.periode=? ORDER BY k.nama, g.id' . pg_limit($total, $hal, $per));
$s->execute([$periode]);
$rows = $s->fetchAll();
require APP_PATH . '/views/header.php';
?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <form method="get" class="d-flex gap-2">
    <select name="periode" class="form-select" aria-label="Periode gaji" onchange="this.form.submit()">
      <?php foreach (periode_options() as $po): ?>
        <option value="<?= e($po) ?>" <?= $po === $periode ? 'selected' : '' ?>><?= e(periode_label($po)) ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="btn btn-outline-primary">Tampilkan</button></noscript>
  </form>
  <form method="post" onsubmit="return confirm('Proses gaji periode <?= e(periode_label($periode)) ?>? Cicilan kasbon akan dipotong.')">
    <?= csrf_field() ?><input type="hidden" name="periode" value="<?= e($periode) ?>">
    <button class="btn btn-success">Proses gaji periode ini</button>
  </form>
  <?php if ($total): ?>
    <div class="ms-lg-auto d-flex flex-wrap gap-2">
      <a class="btn btn-outline-primary" href="slip_gaji.php?periode=<?= e($periode) ?>" target="_blank" rel="noopener"><?= icon('printer', 18) ?> Cetak semua slip</a>
      <form method="post" onsubmit="return confirm('Reset gaji periode <?= e(periode_label($periode)) ?>?\n\nSemua slip periode ini dihapus dan sisa kasbon dikembalikan. Setelah itu Anda bisa memproses ulang.')">
        <?= csrf_field() ?><input type="hidden" name="periode" value="<?= e($periode) ?>"><input type="hidden" name="aksi" value="reset">
        <button class="btn btn-outline-danger">Reset periode ini</button>
      </form>
    </div>
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
    <tfoot><tr class="table-light"><th colspan="5">Total</th><th class="text-end"><?= rupiah($total_bersih) ?></th><th></th></tr></tfoot>
  </table>
</div>
<?= pg_footer($total, $hal, $per) ?></div>
<?php require APP_PATH . '/views/footer.php'; ?>
