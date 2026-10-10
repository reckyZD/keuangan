<?php
// Form kas cepat. Dipakai di dashboard, halaman Kas, dan modal "Catat kas" (semua halaman lain).
// Variabel opsional: $kf_id (awalan id unik), $kf_default ('masuk'|'keluar').
$kf  = $kf_id ?? 'kf';
$def = ($kf_default ?? 'keluar') === 'masuk' ? 'masuk' : 'keluar';
?>
<form method="post" action="kas.php" class="kas-form" data-kas-form data-tipe="<?= $def ?>" data-kat="<?= e(json_encode(kas_kategori(), JSON_UNESCAPED_UNICODE)) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="kembali" value="<?= e(basename($_SERVER['PHP_SELF'])) ?>">

  <div class="seg" role="radiogroup" aria-label="Jenis transaksi">
    <input type="radio" name="tipe" value="masuk"  id="<?= $kf ?>-masuk"  <?= $def === 'masuk'  ? 'checked' : '' ?>>
    <label for="<?= $kf ?>-masuk"><?= icon('arrow-in', 18) ?>Kas masuk</label>
    <input type="radio" name="tipe" value="keluar" id="<?= $kf ?>-keluar" <?= $def === 'keluar' ? 'checked' : '' ?>>
    <label for="<?= $kf ?>-keluar"><?= icon('arrow-out', 18) ?>Kas keluar</label>
  </div>

  <div class="mb-3">
    <label class="form-label" for="<?= $kf ?>-jumlah">Jumlah</label>
    <div class="amount">
      <span>Rp</span>
      <input type="text" id="<?= $kf ?>-jumlah" inputmode="numeric" autocomplete="off" placeholder="0" required data-rupiah>
      <input type="hidden" name="jumlah" value="">
    </div>
  </div>

  <div class="mb-3">
    <label class="form-label" for="<?= $kf ?>-kategori">Kategori</label>
    <input class="form-control" id="<?= $kf ?>-kategori" name="kategori" required maxlength="50" autocomplete="off" placeholder="Pilih di bawah atau ketik sendiri">
    <div class="chips" data-chips></div>
  </div>

  <div class="row g-2 mb-3">
    <div class="col-6">
      <label class="form-label" for="<?= $kf ?>-ket">Keterangan</label>
      <input class="form-control" id="<?= $kf ?>-ket" name="keterangan" maxlength="255" autocomplete="off" placeholder="Opsional">
    </div>
    <div class="col-6">
      <label class="form-label" for="<?= $kf ?>-tgl">Tanggal</label>
      <input class="form-control" type="date" id="<?= $kf ?>-tgl" name="tanggal" required value="<?= date('Y-m-d') ?>">
    </div>
  </div>

  <button class="btn btn-submit w-100" type="submit" data-submit-label>Simpan kas keluar</button>
</form>
