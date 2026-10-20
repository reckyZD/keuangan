<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
$title = 'Pengaturan perusahaan';
$subtitle = 'Data ini tampil sebagai kop pada slip gaji dan di sidebar.';

// Pemakai database lama: buat tabel otomatis bila belum ada (sama dengan migrations/004_pengaturan.sql).
$pdo->exec("CREATE TABLE IF NOT EXISTS pengaturan (
  kunci VARCHAR(50) NOT NULL PRIMARY KEY,
  nilai TEXT NULL
) ENGINE=InnoDB");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $co = company();
    $in = [];
    foreach (['nama' => 120, 'alamat' => 255, 'telp' => 40, 'email' => 100, 'kota' => 60, 'ttd_nama' => 100, 'ttd_jabatan' => 60] as $k => $maks) {
        $in[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $maks);
    }
    if ($in['nama'] === '') {
        flash('Nama perusahaan wajib diisi.', 'danger');
        redirect('pengaturan.php');
    }

    // ----- Periode gajian -----
    $mode = in_array($_POST['gajian_mode'] ?? '', ['bulanan', 'semi_bulanan', 'dua_mingguan'], true) ? $_POST['gajian_mode'] : 'bulanan';
    $tgl_ok = function (string $d): bool {
        return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    };
    $awal  = trim((string)($_POST['gajian_awal'] ?? ''));
    $akhir = trim((string)($_POST['gajian_akhir'] ?? ''));
    $rentang_ok = $tgl_ok($awal) && $tgl_ok($akhir) && $akhir >= $awal && (strtotime($akhir) - strtotime($awal)) <= 20 * 86400;
    if ($mode === 'dua_mingguan' && !$rentang_ok) {
        flash('Gajian tiap 2 minggu: isi periode pertama (dari - sampai), mis. 19 Sep 2026 sampai 3 Okt 2026.', 'danger');
        redirect('pengaturan.php');
    }
    $in['gajian_mode']  = $mode;
    $in['gajian_awal']  = $rentang_ok ? $awal : '';
    $in['gajian_akhir'] = $rentang_ok ? $akhir : '';
    $in['gaji_pokok_cara'] = in_array($_POST['gaji_pokok_cara'] ?? '', ['penuh', 'sekali_sebulan', 'dibagi'], true) ? $_POST['gaji_pokok_cara'] : 'penuh';
    if ($mode !== 'bulanan') {
        // kunci periode 2x sebulan / 2 mingguan lebih panjang dari "YYYY-MM"
        try { $pdo->exec('ALTER TABLE gaji MODIFY periode VARCHAR(12) NOT NULL'); } catch (Throwable $t) {}
    }

    // ----- Logo -----
    $logo = $co['logo'];
    $dir  = __DIR__ . '/uploads';
    $hapus_logo = static function (string $f) use ($dir): void {
        $f = basename($f);
        if ($f !== '' && is_file("$dir/$f")) @unlink("$dir/$f");
    };
    if (!empty($_POST['hapus_logo'])) { $hapus_logo($logo); $logo = ''; }

    $up = $_FILES['logo'] ?? null;
    if ($up && $up['error'] !== UPLOAD_ERR_NO_FILE) {
        $tipe = ($up['error'] === UPLOAD_ERR_OK && is_uploaded_file($up['tmp_name'])) ? @getimagesize($up['tmp_name']) : false;
        $ext  = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$tipe[2] ?? 0] ?? null;
        if (!$ext || $up['size'] > 1024 * 1024) {
            flash('Logo harus berupa PNG, JPG, atau WebP dengan ukuran maksimal 1 MB.', 'danger');
            redirect('pengaturan.php');
        }
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $baru = 'logo_' . time() . '.' . $ext;
        if (!move_uploaded_file($up['tmp_name'], "$dir/$baru")) {
            flash('Logo gagal disimpan. Pastikan folder public/uploads bisa ditulis.', 'danger');
            redirect('pengaturan.php');
        }
        if ($logo !== '' && $logo !== $baru) $hapus_logo($logo);
        $logo = $baru;
    }
    $in['logo'] = $logo;

    $st = $pdo->prepare('INSERT INTO pengaturan (kunci, nilai) VALUES (?, ?) ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)');
    foreach ($in as $k => $v) $st->execute([$k, $v]);
    flash('Pengaturan perusahaan disimpan.');
    redirect('pengaturan.php');
}

$co = company();
require APP_PATH . '/views/header.php';
?>
<form method="post" enctype="multipart/form-data" class="row g-4">
  <?= csrf_field() ?>
  <div class="col-lg-7">
    <div class="card mb-4">
      <div class="card-header">Identitas perusahaan</div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label" for="nama">Nama perusahaan</label>
          <input class="form-control" id="nama" name="nama" required maxlength="120" value="<?= e($co['nama']) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label" for="alamat">Alamat</label>
          <input class="form-control" id="alamat" name="alamat" maxlength="255" value="<?= e($co['alamat']) ?>" placeholder="Jalan, nomor, kecamatan, kota">
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="telp">Telepon</label>
            <input class="form-control" id="telp" name="telp" maxlength="40" inputmode="tel" value="<?= e($co['telp']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="email">Email</label>
            <input class="form-control" id="email" type="email" name="email" maxlength="100" value="<?= e($co['email']) ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header">Periode gajian</div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label" for="gajian_mode">Gajian dilakukan</label>
          <select class="form-select" id="gajian_mode" name="gajian_mode">
            <option value="bulanan"      <?= $co['gajian_mode'] === 'bulanan' ? 'selected' : '' ?>>Sebulan sekali</option>
            <option value="semi_bulanan" <?= $co['gajian_mode'] === 'semi_bulanan' ? 'selected' : '' ?>>2 kali sebulan (tanggal 1-15 dan 16-akhir bulan)</option>
            <option value="dua_mingguan" <?= $co['gajian_mode'] === 'dua_mingguan' ? 'selected' : '' ?>>Tiap 2 minggu (14 hari)</option>
          </select>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label" for="gajian_awal">Periode pertama: dari</label>
            <input class="form-control" type="date" id="gajian_awal" name="gajian_awal" value="<?= e($co['gajian_awal']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="gajian_akhir">sampai (hari gajian)</label>
            <input class="form-control" type="date" id="gajian_akhir" name="gajian_akhir" value="<?= e($co['gajian_akhir']) ?>">
          </div>
          <div class="col-12"><p class="hint mb-0">Khusus tiap 2 minggu. Periode berikutnya otomatis tiap 14 hari dan selalu berakhir di hari yang sama dengan tanggal "sampai" (mis. 19 Sep - 3 Okt, lalu 4 - 17 Okt, 18 - 31 Okt, dst.).</p></div>
        </div>
        <p class="hint mt-3 mb-0">Mengganti pengaturan ini tidak mengubah gaji dan slip yang sudah diproses. Cicilan kasbon dipotong sebesar nilai cicilan pada setiap gajian.</p>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Penandatangan slip gaji</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label" for="kota">Kota cetak</label>
            <input class="form-control" id="kota" name="kota" maxlength="60" value="<?= e($co['kota']) ?>" placeholder="mis. Bogor">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="ttd_nama">Penandatangan</label>
            <input class="form-control" id="ttd_nama" name="ttd_nama" maxlength="100" value="<?= e($co['ttd_nama']) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="ttd_jabatan">Jabatan</label>
            <input class="form-control" id="ttd_jabatan" name="ttd_jabatan" maxlength="60" value="<?= e($co['ttd_jabatan']) ?>">
          </div>
        </div>
        <p class="hint mt-3 mb-0">Kota tampil di samping tanggal cetak pada slip. Jika penandatangan dikosongkan, slip menyediakan garis tanda tangan kosong.</p>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card mb-4">
      <div class="card-header">Logo</div>
      <div class="card-body">
        <div class="kop-preview mb-3">
          <?= brand_mark('kp-logo') ?>
          <div>
            <b><?= e($co['nama']) ?></b>
            <span><?= e($co['alamat'] ?: 'Alamat belum diisi') ?></span>
            <?php if ($co['telp'] || $co['email']): ?><span><?= e(trim($co['telp'] . ($co['telp'] && $co['email'] ? '  |  ' : '') . $co['email'])) ?></span><?php endif; ?>
          </div>
        </div>
        <label class="form-label" for="logo">Unggah logo baru</label>
        <input class="form-control" type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
        <p class="hint mt-2 mb-2">PNG, JPG, atau WebP, maksimal 1 MB. Logo persegi tampil paling rapi.</p>
        <?php if (company_logo_url()): ?>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="hapus_logo" id="hapus_logo" value="1"><label class="form-check-label" for="hapus_logo">Hapus logo (pakai lambang bawaan)</label></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="alert alert-info mb-4">
      <b>Ukuran slip: 1/3 A4</b> (210 x 99 mm). Cetak satu pegawai akan memakai sepertiga atas lembar. Cetak semua pegawai menyusun tiga slip per lembar A4.
    </div>
    <button class="btn btn-primary w-100 py-2">Simpan pengaturan</button>
  </div>
</form>
<?php require APP_PATH . '/views/footer.php'; ?>
