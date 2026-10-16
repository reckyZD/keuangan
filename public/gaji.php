<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
$title = 'Gaji & slip';
$subtitle = 'Proses gaji per periode, lalu cetak slip per pegawai (1/3 A4).';

migrasi_gaji($pdo);   // database lama: lebarkan kolom periode & buat tabel rincian potongan

$dua_tahap = gaji_dua_tahap();
$periode = (string)($_REQUEST['periode'] ?? '');
if (!empty($_REQUEST['bulan']) && preg_match('/^\d{4}-\d{2}$/', (string)$_REQUEST['bulan'])) {
    $periode = $_REQUEST['bulan'] . ($dua_tahap ? '-' . ((int)($_REQUEST['tahap'] ?? 1) === 2 ? 2 : 1) : '');
}
$periode = periode_normal($periode);
$bulan_terpilih = substr($periode, 0, 7);
$tahap_terpilih = strlen($periode) === 9 ? (int)substr($periode, 8, 1) : 1;
[$tgl_mulai, $tgl_akhir] = periode_range($periode);

/** Batalkan gaji yang sudah diproses (untuk uji coba): kembalikan sisa kasbon, hapus gaji. */
function reset_gaji(PDO $pdo, ?string $periode): int {
    $pdo->beginTransaction();
    try {
        $where = $periode === null ? '1=1' : 'periode = ?';
        $par   = $periode === null ? [] : [$periode];
        $ids = $pdo->prepare("SELECT id FROM gaji WHERE $where");
        $ids->execute($par);
        $ids = $ids->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $pdo->exec("UPDATE kasbon k JOIN (SELECT kasbon_id, SUM(jumlah) j FROM gaji_potongan WHERE gaji_id IN ($in) GROUP BY kasbon_id) p
                        ON p.kasbon_id = k.id SET k.sisa = k.sisa + p.j, k.status = 'aktif'");
            $pdo->exec("DELETE FROM gaji_potongan WHERE gaji_id IN ($in)");
            $pdo->exec("DELETE FROM gaji WHERE id IN ($in)");
        }
        // Catatan kas lembur otomatis (hanya ada jika LEMBUR_DARI_KAS = true)
        $k = $pdo->prepare("DELETE FROM kas WHERE kategori = 'Lembur' AND keterangan LIKE ?");
        $k->execute([$periode === null ? 'Upah lembur %' : "Upah lembur $periode - %"]);
        $pdo->commit();
        return count($ids);
    } catch (Throwable $t) { $pdo->rollBack(); throw $t; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = (string)($_POST['aksi'] ?? 'proses');

    if ($aksi === 'reset' || $aksi === 'reset_semua') {
        if (!MODE_TRIAL) { flash('Fitur reset dimatikan (MODE_TRIAL = false di app/config.php).', 'danger'); redirect('gaji.php?periode=' . urlencode($periode)); }
        if ($aksi === 'reset_semua' && strtoupper(trim((string)($_POST['konfirmasi'] ?? ''))) !== 'RESET') {
            flash('Reset semua dibatalkan: ketik RESET pada kolom konfirmasi.', 'danger');
            redirect('gaji.php?periode=' . urlencode($periode));
        }
        try {
            $n = reset_gaji($pdo, $aksi === 'reset' ? $periode : null);
            flash($aksi === 'reset'
                ? "Gaji periode " . periode_label($periode) . " direset ($n data dihapus, sisa kasbon dikembalikan)."
                : "Semua data gaji direset ($n data dihapus, sisa kasbon dikembalikan).");
        } catch (Throwable $t) {
            error_log('reset_gaji: ' . $t->getMessage());
            flash('Gagal mereset gaji.', 'danger');
        }
        redirect('gaji.php?periode=' . urlencode($periode));
    }

    // Satu bulan tidak boleh campur gaji bulanan dan gaji per 2 minggu.
    $bentrok = $pdo->prepare($dua_tahap ? 'SELECT 1 FROM gaji WHERE periode = ? LIMIT 1' : "SELECT 1 FROM gaji WHERE periode LIKE ? LIMIT 1");
    $bentrok->execute([$dua_tahap ? $bulan_terpilih : $bulan_terpilih . '-%']);
    if ($bentrok->fetch()) {
        flash('Bulan ' . periode_label($bulan_terpilih) . ' sudah punya gaji dengan siklus yang berbeda. Reset gaji bulan itu dulu, atau kembalikan siklus di Pengaturan.', 'danger');
        redirect('gaji.php?periode=' . urlencode($periode));
    }

    $dibuat = 0;
    $pdo->beginTransaction();
    try {
        $cek = $pdo->prepare('SELECT 1 FROM gaji WHERE karyawan_id=? AND periode=?');
        $lem = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM lembur WHERE karyawan_id=? AND tanggal BETWEEN ? AND ?");
        $kas = $pdo->prepare("SELECT * FROM kasbon WHERE karyawan_id=? AND status='aktif' FOR UPDATE");

        foreach ($pdo->query('SELECT * FROM karyawan WHERE aktif=1')->fetchAll() as $k) {
            $cek->execute([$k['id'], $periode]);
            if ($cek->fetch()) continue;               // sudah digaji periode ini

            $lem->execute([$k['id'], $tgl_mulai, $tgl_akhir]);
            $total_lembur = (float)$lem->fetchColumn();

            $kas->execute([$k['id']]);
            $potongan = 0.0;
            $rincian  = [];
            foreach ($kas->fetchAll() as $b) {
                $p = min(bagian_periode((float)$b['cicilan_per_bulan'], $periode), (float)$b['sisa']);
                if ($p <= 0) continue;
                $sisa_baru = (float)$b['sisa'] - $p;
                $pdo->prepare('UPDATE kasbon SET sisa=?, status=? WHERE id=?')
                    ->execute([$sisa_baru, $sisa_baru <= 0 ? 'lunas' : 'aktif', $b['id']]);
                $potongan += $p;
                $rincian[] = [$b['id'], $p];
            }
            $gaji_pokok = bagian_periode((float)$k['gaji_pokok'], $periode);
            $bersih = $gaji_pokok + $total_lembur - $potongan;
            $pdo->prepare('INSERT INTO gaji (karyawan_id,periode,gaji_pokok,total_lembur,potongan_kasbon,gaji_bersih) VALUES (?,?,?,?,?,?)')
                ->execute([$k['id'], $periode, $gaji_pokok, $total_lembur, $potongan, $bersih]);
            $gaji_id = (int)$pdo->lastInsertId();
            foreach ($rincian as [$kid, $jml]) {
                $pdo->prepare('INSERT INTO gaji_potongan (gaji_id,kasbon_id,jumlah) VALUES (?,?,?)')->execute([$gaji_id, $kid, $jml]);
            }
            // Gaji pokok, lembur, dan potongan kasbon semuanya di luar kas: potongan kasbon
            // dibayar langsung oleh owner lewat pemotongan gaji. Lembur bisa dicatat sebagai
            // pengeluaran kas lewat LEMBUR_DARI_KAS di app/config.php jika kebijakan berubah.
            if (LEMBUR_DARI_KAS && $total_lembur > 0) {
                $pdo->prepare('INSERT INTO kas (tanggal,tipe,kategori,jumlah,keterangan) VALUES (?,?,?,?,?)')
                    ->execute([date('Y-m-d'), 'keluar', 'Lembur', $total_lembur, 'Upah lembur ' . $periode . ' - ' . $k['nama']]);
            }
            $dibuat++;
        }
        $pdo->commit();
        flash("Penggajian periode " . periode_label($periode) . " selesai: $dibuat karyawan diproses.");
    } catch (Throwable $t) {
        $pdo->rollBack();
        error_log('proses gaji: ' . $t->getMessage());
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
    <input type="month" name="bulan" class="form-control" value="<?= e($bulan_terpilih) ?>">
    <?php if ($dua_tahap): ?>
      <select name="tahap" class="form-select" aria-label="Tahap gaji">
        <option value="1" <?= $tahap_terpilih === 1 ? 'selected' : '' ?>>Tahap 1 (tgl 1-<?= TAHAP_BATAS_TGL ?>)</option>
        <option value="2" <?= $tahap_terpilih === 2 ? 'selected' : '' ?>>Tahap 2 (tgl <?= TAHAP_BATAS_TGL + 1 ?>-akhir)</option>
      </select>
    <?php endif; ?>
    <button class="btn btn-outline-primary">Tampilkan</button>
  </form>
  <form method="post" onsubmit="return confirm('Proses gaji periode <?= e(periode_label($periode)) ?>? Cicilan kasbon akan dipotong.')">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="proses"><input type="hidden" name="periode" value="<?= e($periode) ?>">
    <button class="btn btn-success">Proses gaji periode ini</button>
  </form>
  <?php if ($rows): ?>
    <a class="btn btn-outline-primary ms-lg-auto" href="slip_gaji.php?periode=<?= e($periode) ?>" target="_blank" rel="noopener"><?= icon('printer', 18) ?> Cetak semua slip</a>
  <?php endif; ?>
</div>
<p class="hint mb-3">
  Periode: <b><?= e(periode_label($periode)) ?></b>, lembur dihitung tgl <?= e(tgl($tgl_mulai)) ?> s/d <?= e(tgl($tgl_akhir)) ?>.
  <?php if ($dua_tahap): ?>Siklus <b>2x sebulan</b>: gaji pokok dan cicilan kasbon dibayar setengah di tiap tahap. Ubah di <a href="pengaturan.php">Pengaturan</a>.
  <?php else: ?>Siklus <b>bulanan</b>. Untuk gaji per 2 minggu, ubah di <a href="pengaturan.php">Pengaturan</a>.<?php endif; ?>
</p>
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
<?php if (MODE_TRIAL): ?>
<div class="card border-warning mt-4">
  <div class="card-header">Uji coba: reset gaji</div>
  <div class="card-body">
    <p class="hint">Menghapus data gaji yang sudah diproses dan mengembalikan sisa kasbon yang tadi terpotong, supaya proses gaji bisa dicoba lagi. Data karyawan, absensi, lembur, dan kasbon tidak dihapus.
      Matikan fitur ini dengan <code>MODE_TRIAL = false</code> di <code>app/config.php</code> saat dipakai sungguhan.</p>
    <div class="d-flex flex-wrap gap-3 align-items-start">
      <form method="post" onsubmit="return confirm('Reset gaji periode <?= e(periode_label($periode)) ?>? Data gaji periode ini dihapus.')">
        <?= csrf_field() ?><input type="hidden" name="aksi" value="reset"><input type="hidden" name="periode" value="<?= e($periode) ?>">
        <button class="btn btn-outline-warning"<?= $rows ? '' : ' disabled' ?>>Reset periode ini</button>
      </form>
      <form method="post" class="d-flex gap-2" onsubmit="return confirm('Reset SEMUA data gaji di semua periode?')">
        <?= csrf_field() ?><input type="hidden" name="aksi" value="reset_semua"><input type="hidden" name="periode" value="<?= e($periode) ?>">
        <input class="form-control" name="konfirmasi" placeholder="Ketik RESET" autocomplete="off" style="max-width:160px" required>
        <button class="btn btn-outline-danger">Reset semua gaji</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require APP_PATH . '/views/footer.php'; ?>
