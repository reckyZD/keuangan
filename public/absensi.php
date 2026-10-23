<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
pastikan_kolom_lembur();
$title = 'Absensi';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['aksi'] ?? '') === 'hapus_banyak') {
        $p_hapus = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['periode'] ?? '')) ? $_POST['periode'] : date('Y-m');
        $pdo->beginTransaction();
        try {
            $lids = [];
            if (!empty($_POST['semua_periode'])) {               // seluruh data pada periode yang sedang ditampilkan
                $s = $pdo->prepare("SELECT lembur_id FROM absensi WHERE DATE_FORMAT(tanggal,'%Y-%m')=? AND lembur_id IS NOT NULL");
                $s->execute([$p_hapus]);
                $lids = $s->fetchAll(PDO::FETCH_COLUMN);
                $d = $pdo->prepare("DELETE FROM absensi WHERE DATE_FORMAT(tanggal,'%Y-%m')=?");
                $d->execute([$p_hapus]);
                $n = $d->rowCount();
            } else {                                              // baris yang dicentang
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
                $n = 0;
                foreach (array_chunk($ids, 500) as $grup) {
                    $in = implode(',', array_fill(0, count($grup), '?'));
                    $s = $pdo->prepare("SELECT lembur_id FROM absensi WHERE id IN ($in) AND lembur_id IS NOT NULL");
                    $s->execute($grup);
                    $lids = array_merge($lids, $s->fetchAll(PDO::FETCH_COLUMN));
                    $d = $pdo->prepare("DELETE FROM absensi WHERE id IN ($in)");
                    $d->execute($grup);
                    $n += $d->rowCount();
                }
            }
            foreach (array_chunk($lids, 500) as $grup) {
                $in = implode(',', array_fill(0, count($grup), '?'));
                $pdo->prepare("DELETE FROM lembur WHERE id IN ($in)")->execute($grup);
            }
            $pdo->commit();
            flash($n ? "$n absensi (dan lembur terkait) dihapus." : 'Tidak ada data yang dipilih.', $n ? 'success' : 'danger');
        } catch (Throwable $t) {
            $pdo->rollBack();
            flash('Gagal menghapus data terpilih.', 'danger');
        }
        redirect('absensi.php?periode=' . $p_hapus);
    }

    if (($_POST['aksi'] ?? '') === 'hapus') {
        $pdo->beginTransaction();
        $s = $pdo->prepare('SELECT lembur_id FROM absensi WHERE id=?');
        $s->execute([(int)$_POST['id']]);
        $lid = $s->fetchColumn();
        $pdo->prepare('DELETE FROM absensi WHERE id=?')->execute([(int)$_POST['id']]);
        if ($lid) $pdo->prepare('DELETE FROM lembur WHERE id=?')->execute([$lid]);
        $pdo->commit();
        flash('Absensi (dan lembur terkait) dihapus.');
        redirect('absensi.php');
    }

    $kid     = (int)$_POST['karyawan_id'];
    $tanggal = $_POST['tanggal'];
    $masuk   = $_POST['jam_masuk'];
    $keluar  = $_POST['jam_keluar'];
    $merah   = isset($_POST['tanggal_merah']);

    $s = $pdo->prepare('SELECT gaji_pokok FROM karyawan WHERE id=? AND aktif=1');
    $s->execute([$kid]);
    $k = $s->fetch();
    if (!$k) { flash('Karyawan tidak valid.', 'danger'); redirect('absensi.php'); }

    $cek = $pdo->prepare('SELECT 1 FROM absensi WHERE karyawan_id=? AND tanggal=?');
    $cek->execute([$kid, $tanggal]);
    if ($cek->fetch()) {
        flash('Absensi karyawan ini di tanggal tersebut sudah ada. Hapus dulu jika ingin input ulang.', 'danger');
        redirect('absensi.php');
    }

    [$jam, $tipe] = hitung_jam_lembur($tanggal, $masuk, $keluar, $merah);

    $pdo->beginTransaction();
    $lembur_id = null;
    $total = 0;
    if ($jam != 0) {
        [$upah_jam, $total] = hitung_lembur($jam);
        $pdo->prepare('INSERT INTO lembur (karyawan_id,tanggal,jam,tipe,upah_per_jam,total,keterangan) VALUES (?,?,?,?,?,?,?)')
            ->execute([$kid, $tanggal, $jam, $tipe, $upah_jam, $total, "Otomatis dari absensi $masuk-$keluar"]);
        $lembur_id = (int)$pdo->lastInsertId();
    }
    $pdo->prepare('INSERT INTO absensi (karyawan_id,tanggal,jam_masuk,jam_keluar,tanggal_merah,lembur_id) VALUES (?,?,?,?,?,?)')
        ->execute([$kid, $tanggal, $masuk, $keluar, $merah ? 1 : 0, $lembur_id]);
    $pdo->commit();

    flash($jam > 0 ? "Absensi tersimpan. Lembur $jam jam = " . rupiah($total) : 'Absensi tersimpan. Tidak ada lembur.');
    redirect('absensi.php');
}

$periode = $_GET['periode'] ?? date('Y-m');
$karyawan = $pdo->query('SELECT id, nama FROM karyawan WHERE aktif=1 ORDER BY nama')->fetchAll();
[$hal, $per] = pg_params();
$c = $pdo->prepare("SELECT COUNT(*) FROM absensi WHERE DATE_FORMAT(tanggal,'%Y-%m')=?");
$c->execute([$periode]);
$total = (int)$c->fetchColumn();
$s = $pdo->prepare("SELECT a.*, k.nama, l.jam AS jam_lembur, l.total
                    FROM absensi a JOIN karyawan k ON k.id=a.karyawan_id
                    LEFT JOIN lembur l ON l.id=a.lembur_id
                    WHERE DATE_FORMAT(a.tanggal,'%Y-%m')=? ORDER BY a.tanggal DESC, k.nama, a.id" . pg_limit($total, $hal, $per));
$s->execute([$periode]);
$rows = $s->fetchAll();
require APP_PATH . '/views/header.php';
?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card"><div class="card-header">Input Absensi</div><div class="card-body">
      <div class="alert alert-info py-2 small">
        Hari kerja <b>Senin-Sabtu</b>, jam <b><?= JAM_MASUK ?> - <?= JAM_PULANG ?></b>. Minggu &amp; tanggal merah = lembur penuh.<br>Upah lembur <b>Rp <?= number_format(upah_lembur_jam(), 0, ',', '.') ?>/jam</b> (atur di Pengaturan).<br>
        Lembur hari kerja = (jam keluar - jam masuk) - 9 jam, eksak tanpa pembulatan. Kalau hasilnya minus (kerja kurang dari 9 jam), jam itu mengurangi total lembur.
      </div>
      <form method="post"><?= csrf_field() ?>
        <div class="mb-2"><label class="form-label">Karyawan</label>
          <select name="karyawan_id" class="form-select" required>
            <?php foreach ($karyawan as $k): ?><option value="<?= (int)$k['id'] ?>"><?= e($k['nama']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="mb-2"><label class="form-label">Tanggal</label><input type="date" name="tanggal" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
        <div class="row g-2 mb-2">
          <div class="col"><label class="form-label">Jam Masuk</label><input type="time" name="jam_masuk" class="form-control" required value="<?= JAM_MASUK ?>"></div>
          <div class="col"><label class="form-label">Jam Keluar</label><input type="time" name="jam_keluar" class="form-control" required value="<?= JAM_PULANG ?>"></div>
        </div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="tanggal_merah" id="merah">
          <label class="form-check-label" for="merah">Tanggal merah / hari libur nasional</label></div>
        <button class="btn btn-primary">Simpan</button>
      </form>
    </div></div>
  </div>
  <div class="col-lg-8">
    <?php
    $pb = preg_match('/^\d{4}-\d{2}$/', $periode) ? $periode : date('Y-m');
    $ex_dari = $pb . '-01'; $ex_sampai = date('Y-m-t', strtotime($ex_dari));
    ?>
    <div class="card mb-3"><div class="card-body py-3">
      <form class="row g-2 align-items-end" method="get" action="absensi_export.php">
        <div class="col-12 fw-semibold">Unduh rekap absensi &amp; lembur (Excel)</div>
        <div class="col-sm-auto"><label class="form-label" for="ex-dari">Dari tanggal</label><input type="date" id="ex-dari" name="dari" class="form-control" required value="<?= e($ex_dari) ?>"></div>
        <div class="col-sm-auto"><label class="form-label" for="ex-sampai">Sampai tanggal</label><input type="date" id="ex-sampai" name="sampai" class="form-control" required value="<?= e($ex_sampai) ?>"></div>
        <div class="col-sm-auto"><label class="form-label" for="ex-kid">Karyawan</label>
          <select id="ex-kid" name="karyawan_id" class="form-select"><option value="0">Semua karyawan</option>
            <?php foreach ($karyawan as $k): ?><option value="<?= (int)$k['id'] ?>"><?= e($k['nama']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-sm-auto"><button class="btn btn-success"><?= icon('download', 18) ?> Unduh Excel</button></div>
      </form>
    </div></div>
    <form class="row g-2 mb-3" method="get">
      <div class="col-auto"><input type="month" name="periode" class="form-control" value="<?= e($periode) ?>"></div>
      <div class="col-auto"><button class="btn btn-outline-primary">Tampilkan</button></div>
    </form>
    <form id="form-hapus-banyak" method="post" class="d-none" data-total="<?= (int)$total ?>" data-catatan="Lembur terkait ikut terhapus. Tidak bisa dibatalkan."><?= csrf_field() ?>
      <input type="hidden" name="aksi" value="hapus_banyak"><input type="hidden" name="periode" value="<?= e($periode) ?>"><input type="hidden" name="semua_periode" id="semua-periode" value="">
    </form>
    <div class="card">
      <div class="card-body py-2 border-bottom bg-light d-flex flex-wrap align-items-center gap-2 d-none" id="bulk-bar">
        <span class="fw-semibold" id="jml-dipilih">0 dipilih</span>
        <button class="btn btn-sm btn-danger" form="form-hapus-banyak">Hapus terpilih</button>
        <button type="button" class="btn btn-sm btn-link d-none" id="pilih-semua-periode"></button>
      </div>
      <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead><tr><th style="width:36px"><input type="checkbox" class="form-check-input" id="cek-semua" aria-label="Pilih semua di halaman ini"></th><th>Tanggal</th><th>Karyawan</th><th>Masuk</th><th>Keluar</th><th>Lembur</th><th class="text-end">Upah Lembur</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><input type="checkbox" class="form-check-input cek-baris" name="ids[]" value="<?= (int)$r['id'] ?>" form="form-hapus-banyak" aria-label="Pilih baris"></td>
            <td><?= e(tgl($r['tanggal'])) ?></td><td><?= e($r['nama']) ?></td>
            <td><?= e(substr($r['jam_masuk'], 0, 5)) ?></td><td><?= e(substr($r['jam_keluar'], 0, 5)) ?></td>
            <td><?= $r['jam_lembur'] ? e($r['jam_lembur']) . ' jam' : '-' ?></td>
            <td class="text-end"><?= $r['total'] ? rupiah($r['total']) : '-' ?></td>
            <td class="text-end"><form method="post" onsubmit="return confirm('Hapus absensi ini beserta lemburnya?')"><?= csrf_field() ?>
              <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger">Hapus</button></form></td></tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-3">Belum ada absensi.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?= pg_footer($total, $hal, $per) ?></div>
  </div>
</div>
<?php require APP_PATH . '/views/footer.php'; ?>
