<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
require APP_PATH . '/konsumable.php';
pastikan_tabel_konsumable();
$title = 'Konsumable';
$subtitle = 'Catat barang masuk (IN) dan barang keluar (OUT). Stok akhir (balance) dan rekap bulanan/tahunan dihitung otomatis.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = $_POST['aksi'] ?? 'simpan';
    $back = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['periode'] ?? '')) ? $_POST['periode'] : date('Y-m');

    if ($aksi === 'hapus') {
        $pdo->prepare('DELETE FROM konsumable WHERE id=?')->execute([(int)$_POST['id']]);
        flash('Data konsumable dihapus.');
        redirect('konsumable.php?periode=' . $back);
    }

    $id     = (int)($_POST['id'] ?? 0);
    $jenis  = ($_POST['jenis'] ?? '') === 'keluar' ? 'keluar' : 'masuk';
    $tgl    = (string)($_POST['tanggal'] ?? '');
    $nama   = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['nama_barang'] ?? ''))), 0, 150);
    $satuan = mb_substr(trim((string)($_POST['satuan'] ?? '')), 0, 20);
    $bagian = mb_substr(trim((string)($_POST['bagian'] ?? '')), 0, 100);
    $ket    = mb_substr(trim((string)($_POST['keterangan'] ?? '')), 0, 255);
    $qty    = kon_angka($_POST['qty'] ?? '');
    $harga  = $jenis === 'masuk' ? kon_angka($_POST['harga_satuan'] ?? '') : 0.0;
    $o = DateTime::createFromFormat('Y-m-d', $tgl);
    $balik = 'konsumable.php?periode=' . $back . ($id ? '&edit=' . $id : '');
    if (!$o || $o->format('Y-m-d') !== $tgl || $nama === '' || $qty <= 0 || $harga < 0 || $qty > 9999999 || $harga > 999999999) {
        flash('Data tidak valid. Isi tanggal, nama barang, qty (lebih dari 0)' . ($jenis === 'masuk' ? ', dan harga satuan.' : '.'), 'danger');
        redirect($balik);
    }
    if ($jenis === 'keluar') {                                   // barang keluar tidak boleh melebihi stok
        $ada = kon_stok_sekarang($id)[kon_key($nama)] ?? null;
        if (!$ada) {
            flash('Barang "' . $nama . '" belum pernah dicatat sebagai barang masuk. Pilih dari daftar barang, atau catat barang masuknya dulu.', 'danger');
            redirect($balik);
        }
        $st = $ada['stok'];
        $nama = $ada['nama'];                                    // pakai penulisan nama yang sudah ada
        if ($qty > $st + 0.0001) {
            flash('Stok ' . $nama . ' tidak cukup: tersedia ' . kon_num($st) . ', yang dikeluarkan ' . kon_num($qty) . '. Catat barang masuknya dulu.', 'danger');
            redirect($balik);
        }
    }
    $total = round($qty * $harga, 2);
    if ($id) {
        $pdo->prepare('UPDATE konsumable SET tanggal=?, jenis=?, nama_barang=?, qty=?, satuan=?, harga_satuan=?, total=?, bagian=?, keterangan=? WHERE id=?')
            ->execute([$tgl, $jenis, $nama, $qty, $satuan ?: null, $harga, $total, $bagian ?: null, $ket ?: null, $id]);
        flash('Data konsumable diperbarui.');
    } else {
        $pdo->prepare('INSERT INTO konsumable (tanggal,jenis,nama_barang,qty,satuan,harga_satuan,total,bagian,keterangan,dicatat_oleh) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$tgl, $jenis, $nama, $qty, $satuan ?: null, $harga, $total, $bagian ?: null, $ket ?: null, (string)($_SESSION['user']['nama'] ?? '')]);
        flash($jenis === 'masuk' ? "Barang masuk dicatat: $nama = " . rupiah($total) : "Barang keluar dicatat: $nama ($qty)");
    }
    redirect('konsumable.php?periode=' . substr($tgl, 0, 7));
}

$periode = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['periode'] ?? '')) ? $_GET['periode'] : date('Y-m');
$edit = null;
if (!empty($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM konsumable WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch() ?: null;
}
$f = fn($k, $d = '') => e($edit[$k] ?? $d);
$jenis_form = $edit['jenis'] ?? 'masuk';

$rentang = [$periode . '-01', date('Y-m-t', strtotime($periode . '-01'))];
$c = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN jenis='masuk' THEN total ELSE 0 END),0) AS grand FROM konsumable WHERE tanggal BETWEEN ? AND ?");
$c->execute($rentang);
$sum = $c->fetch();
$total_n = (int)$sum['n']; $grand = (float)$sum['grand'];
[$hal, $per] = pg_params();
$s = $pdo->prepare('SELECT * FROM konsumable WHERE tanggal BETWEEN ? AND ? ORDER BY tanggal DESC, id DESC' . pg_limit($total_n, $hal, $per));
$s->execute($rentang);
$rows = $s->fetchAll();

[$rekap, $rekap_tot] = kon_rekap($rentang[0], $rentang[1]);
$stok_js = [];                       // untuk petunjuk stok saat mengetik nama barang
foreach (kon_stok_sekarang() as $v) $stok_js[$v['nama']] = [round($v['stok'], 2), $v['satuan']];
$tahun = (int)substr($periode, 0, 4);
$nama_bulan = NAMA_BULAN[(int)substr($periode, 5, 2)] . ' ' . substr($periode, 0, 4);
require APP_PATH . '/views/header.php';
?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card"><div class="card-header"><?= $edit ? 'Ubah Catatan' : 'Catat Konsumable' ?></div><div class="card-body">
      <form method="post" id="form-kon"><?= csrf_field() ?>
        <input type="hidden" name="periode" value="<?= e($periode) ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
        <div class="btn-group w-100 mb-3" role="group" aria-label="Jenis">
          <input type="radio" class="btn-check" name="jenis" id="j-masuk" value="masuk" <?= $jenis_form === 'masuk' ? 'checked' : '' ?>><label class="btn btn-outline-success" for="j-masuk">Barang masuk (IN)</label>
          <input type="radio" class="btn-check" name="jenis" id="j-keluar" value="keluar" <?= $jenis_form === 'keluar' ? 'checked' : '' ?>><label class="btn btn-outline-danger" for="j-keluar">Barang keluar (OUT)</label>
        </div>
        <div class="mb-2"><label class="form-label" for="k-tgl">Tanggal</label><input type="date" id="k-tgl" name="tanggal" class="form-control" required value="<?= $f('tanggal', date('Y-m-d')) ?>"></div>
        <div class="mb-2"><label class="form-label" for="k-nama">Nama barang</label>
          <input id="k-nama" name="nama_barang" class="form-control" required maxlength="150" list="k-saran" autocomplete="off" value="<?= $f('nama_barang') ?>">
          <datalist id="k-saran"><?php foreach (array_keys($stok_js) as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist>
          <select id="k-nama-out" name="nama_barang" class="form-select d-none" disabled>
            <option value="">- Pilih barang -</option>
            <?php foreach ($stok_js as $n => [$st, $sat]): if ($st <= 0.0001 && kon_key($n) !== kon_key((string)($edit['nama_barang'] ?? ''))) continue; ?>
              <option value="<?= e($n) ?>" <?= kon_key($n) === kon_key((string)($edit['nama_barang'] ?? '')) ? 'selected' : '' ?>><?= e($n) ?> (stok <?= e(kon_num($st)) ?> <?= e($sat) ?>)</option>
            <?php endforeach; ?>
          </select>
          <div class="form-text" id="k-stok" aria-live="polite"></div></div>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label" for="k-qty">Qty</label><input id="k-qty" name="qty" class="form-control" inputmode="decimal" required value="<?= $edit ? e(kon_num($edit['qty'])) : '' ?>"></div>
          <div class="col-6"><label class="form-label" for="k-sat">Satuan</label><input id="k-sat" name="satuan" class="form-control" maxlength="20" placeholder="pcs, box, kg..." value="<?= $f('satuan') ?>"></div>
        </div>
        <div id="grp-masuk">
          <div class="mb-2"><label class="form-label" for="k-harga">Harga satuan (Rp)</label><input id="k-harga" name="harga_satuan" class="form-control" inputmode="decimal" value="<?= $edit && $edit['jenis'] === 'masuk' ? e(kon_num($edit['harga_satuan'])) : '' ?>"></div>
          <div class="mb-2"><label class="form-label">Total harga</label><div class="form-control bg-light fw-semibold" id="k-total" aria-live="polite">Rp 0</div></div>
        </div>
        <div class="mb-2 d-none" id="grp-keluar"><label class="form-label" for="k-bagian">Dipakai oleh / bagian</label><input id="k-bagian" name="bagian" class="form-control" maxlength="100" placeholder="mis. Produksi, Kantor, Gudang" value="<?= $f('bagian') ?>"></div>
        <div class="mb-3"><label class="form-label" for="k-ket">Keterangan</label><input id="k-ket" name="keterangan" class="form-control" maxlength="255" value="<?= $f('keterangan') ?>"></div>
        <button class="btn btn-primary"><?= $edit ? 'Simpan perubahan' : 'Simpan' ?></button>
        <?php if ($edit): ?><a class="btn btn-outline-secondary" href="konsumable.php?periode=<?= e($periode) ?>">Batal</a><?php endif; ?>
      </form>
    </div></div>
  </div>

  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-body py-3">
      <div class="fw-semibold mb-2">Unduh rekap Excel</div>
      <form class="row g-2 align-items-end mb-3" method="get" action="konsumable_export.php">
        <div class="col-sm-auto"><label class="form-label" for="ex-dari">Dari tanggal</label><input type="date" id="ex-dari" name="dari" class="form-control" required value="<?= e($rentang[0]) ?>"></div>
        <div class="col-sm-auto"><label class="form-label" for="ex-sampai">Sampai tanggal</label><input type="date" id="ex-sampai" name="sampai" class="form-control" required value="<?= e($rentang[1]) ?>"></div>
        <div class="col-sm-auto"><button class="btn btn-success"><?= icon('download', 18) ?> Rekap periode</button></div>
      </form>
      <form class="row g-2 align-items-end" method="get" action="konsumable_export.php">
        <div class="col-sm-auto"><label class="form-label" for="ex-thn">Rekap 1 tahun</label>
          <select id="ex-thn" name="tahun" class="form-select">
            <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 5; $y--): ?><option value="<?= $y ?>" <?= $y === $tahun ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?>
          </select></div>
        <div class="col-sm-auto"><button class="btn btn-success"><?= icon('download', 18) ?> Rekap tahunan</button></div>
      </form>
    </div></div>

    <form class="row g-2 mb-3" method="get">
      <div class="col-auto"><input type="month" name="periode" class="form-control" value="<?= e($periode) ?>" aria-label="Bulan"></div>
      <div class="col-auto"><button class="btn btn-outline-primary">Tampilkan</button></div>
    </form>

    <div class="card mb-3"><div class="card-header">Rekap stok <?= e($nama_bulan) ?></div>
      <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
        <thead><tr><th>Nama Barang</th><th>Satuan</th><th class="text-end">Stok Awal</th><th class="text-end">Masuk (IN)</th><th class="text-end">Keluar (OUT)</th><th class="text-end">Stok Akhir</th><th>Keterangan</th></tr></thead>
        <tbody>
        <?php foreach ($rekap as $r): if ($r['awal'] == 0 && $r['in'] == 0 && $r['out'] == 0 && $r['akhir'] == 0) continue; ?>
          <tr><td><?= e($r['nama']) ?></td><td><?= e($r['satuan']) ?></td><td class="text-end"><?= e(kon_num($r['awal'])) ?></td>
            <td class="text-end text-success"><?= $r['in'] ? e(kon_num($r['in'])) : '-' ?></td><td class="text-end text-danger"><?= $r['out'] ? e(kon_num($r['out'])) : '-' ?></td>
            <td class="text-end fw-semibold"><?= e(kon_num($r['akhir'])) ?></td><td class="small <?= $r['akhir'] < 0 ? 'text-danger' : 'text-muted' ?>"><?= e($r['ket']) ?></td></tr>
        <?php endforeach; if (!$rekap): ?><tr><td colspan="7" class="text-center text-muted py-3">Belum ada data.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr class="table-light"><th colspan="3">Pembelian bulan ini (barang masuk)</th><th class="text-end" colspan="4"><?= rupiah($rekap_tot['nilai_in']) ?></th></tr>
          <tr class="table-light"><th colspan="3">Nilai stok akhir (harga rata-rata)</th><th class="text-end" colspan="4"><?= rupiah($rekap_tot['nilai_akhir']) ?></th></tr></tfoot>
      </table></div>
    </div>

    <div class="card"><div class="card-header">Catatan <?= e($nama_bulan) ?></div><div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Tanggal</th><th>Jenis</th><th>Nama Barang</th><th class="text-end">Qty</th><th class="text-end">Harga Satuan</th><th class="text-end">Total Harga</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $in = $r['jenis'] !== 'keluar'; ?>
          <tr><td><?= e(tgl($r['tanggal'])) ?></td>
            <td><span class="badge text-bg-<?= $in ? 'success' : 'danger' ?>"><?= $in ? 'IN' : 'OUT' ?></span></td>
            <td><?= e($r['nama_barang']) ?><?php if ($r['bagian'] || $r['keterangan']): ?><div class="small text-muted"><?= e(trim(($r['bagian'] ? $r['bagian'] . ' - ' : '') . $r['keterangan'], ' -')) ?></div><?php endif; ?></td>
            <td class="text-end"><?= e(kon_num($r['qty'])) ?> <?= e($r['satuan']) ?></td>
            <td class="text-end"><?= $in ? rupiah($r['harga_satuan']) : '-' ?></td>
            <td class="text-end fw-semibold"><?= $in ? rupiah($r['total']) : '-' ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="konsumable.php?periode=<?= e($periode) ?>&edit=<?= (int)$r['id'] ?>">Edit</a>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus data ini?')"><?= csrf_field() ?>
                <input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="periode" value="<?= e($periode) ?>">
                <button class="btn btn-sm btn-outline-danger">Hapus</button></form></td></tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-3">Belum ada catatan bulan ini.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr class="table-light"><th colspan="5">Total pembelian bulan ini (<?= $total_n ?> catatan)</th><th class="text-end"><?= rupiah($grand) ?></th><th></th></tr></tfoot>
      </table>
    </div>
    <?= pg_footer($total_n, $hal, $per) ?></div>
  </div>
</div>
<script>
(function () {
  var STOK = <?= json_encode($stok_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var f = document.getElementById('form-kon');
  var q = document.getElementById('k-qty'), h = document.getElementById('k-harga'), t = document.getElementById('k-total');
  var nm = document.getElementById('k-nama'), sel = document.getElementById('k-nama-out'), sat = document.getElementById('k-sat'), info = document.getElementById('k-stok');
  var gm = document.getElementById('grp-masuk'), gk = document.getElementById('grp-keluar');
  function angka(v) {
    v = (v || '').trim();
    if ((v.match(/,/g) || []).length === 1) v = v.replace(/\./g, '').replace(',', '.');
    else if (/^\d{1,3}(\.\d{3})+$/.test(v)) v = v.replace(/\./g, '');
    var n = parseFloat(v); return isNaN(n) ? 0 : n;
  }
  function keluar() { return document.getElementById('j-keluar').checked; }
  function tampil() {
    gm.classList.toggle('d-none', keluar()); gk.classList.toggle('d-none', !keluar());
    h.required = !keluar();
    // barang keluar: pilih dari barang yang sudah masuk; barang masuk: ketik / pilih saran
    nm.classList.toggle('d-none', keluar()); nm.disabled = keluar(); nm.required = !keluar();
    sel.classList.toggle('d-none', !keluar()); sel.disabled = !keluar(); sel.required = keluar();
    stok();
    t.textContent = 'Rp ' + Math.round(angka(q.value) * angka(h.value)).toLocaleString('id-ID');
  }
  function stok() {
    var key = (keluar() ? sel.value : nm.value).trim().toLowerCase(), hit = null;
    Object.keys(STOK).forEach(function (n) { if (n.toLowerCase() === key) hit = n; });
    info.textContent = hit ? 'Stok saat ini: ' + STOK[hit][0] + ' ' + (STOK[hit][1] || '') : (key && !keluar() ? 'Barang baru' : '');
    if (hit && (keluar() || !sat.value)) sat.value = STOK[hit][1] || '';
  }
  f.addEventListener('input', tampil); f.addEventListener('change', tampil);
  nm.addEventListener('input', stok); nm.addEventListener('change', stok); sel.addEventListener('change', stok);
  tampil(); stok();
})();
</script>
<?php require APP_PATH . '/views/footer.php'; ?>
