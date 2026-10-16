<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
$title = 'Import absensi fingerprint';
$subtitle = 'Unggah file CSV / ATTLOG dari mesin fingerprint; lembur dihitung otomatis.';

/* ---------- helper ---------- */
function baca_csv(string $path, int $limit = 0): array {
    $fh = fopen($path, 'r');
    $first = (string)fgets($fh);
    rewind($fh);
    if (substr($first, 0, 3) === "\xEF\xBB\xBF") fseek($fh, 3);   // buang BOM
    $c = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($c);
    $del = array_key_first($c);
    $rows = [];
    while (($r = fgetcsv($fh, 0, $del, '"', '')) !== false) {
        if ($r === [null]) continue;                                // baris kosong
        $rows[] = array_map('trim', $r);
        if ($limit && count($rows) >= $limit) break;
    }
    fclose($fh);
    return $rows;
}

function parse_waktu(string $t, string $fmt): ?string {
    // jam ditulis dengan titik (17.05 / 17.05.30) -> titik dua
    $t = preg_replace_callback('/\s(\d{1,2})\.(\d{2})(?:\.(\d{2}))?$/', function ($m) {
        return ' ' . $m[1] . ':' . $m[2] . ((isset($m[3]) && $m[3] !== '') ? ':' . $m[3] : '');
    }, $t);
    $daftar = [
        'ymd' => ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y/m/d H:i:s', 'Y/m/d H:i', 'Y-m-d\TH:i:s'],
        'dmy' => ['d/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd.m.Y H:i:s', 'd.m.Y H:i'],
        'mdy' => ['m/d/Y H:i:s', 'm/d/Y H:i', 'm/d/Y g:i:s A', 'm/d/Y g:i A'],
    ][$fmt] ?? [];
    foreach ($daftar as $f) {
        $d = DateTime::createFromFormat('!' . $f, $t);
        $err = DateTime::getLastErrors();
        if ($d && (!$err || ($err['warning_count'] === 0 && $err['error_count'] === 0))) {
            return $d->format('Y-m-d H:i:s');
        }
    }
    return null;
}

function hapus_temp(): void {
    $f = $_SESSION['import_file'] ?? null;
    if ($f && is_file($f) && strpos(basename($f), 'absen_') === 0) @unlink($f);
    unset($_SESSION['import_file']);
}

/** Tebak judul/kolom/format dari beberapa baris pertama file. */
function tebak_pengaturan(array $preview): array {
    $re_tgl = '/\d{1,4}[-\/.]\d{1,2}[-\/.]\d{1,4}/';
    $baris1 = $preview[0];

    // judul kolom ada jika baris pertama tidak mengandung tanggal
    $punya_header = true;
    foreach ($baris1 as $v) { if (preg_match($re_tgl, $v)) { $punya_header = false; break; } }
    $mulai = $punya_header ? 1 : 0;
    $sample = $preview[$mulai] ?? $preview[0];

    // kolom tanggal/waktu
    $kw = 0;
    foreach ($sample as $i => $v) { if (preg_match($re_tgl, $v)) { $kw = $i; break; } }

    // jam di kolom terpisah?
    $kj = null;
    if (!preg_match('/\s\d{1,2}[:.]\d{2}/', $sample[$kw] ?? '')) {
        foreach ($sample as $i => $v) {
            if ($i !== $kw && preg_match('/^\d{1,2}[:.]\d{2}([:.]\d{2})?$/', $v)) { $kj = $i; break; }
        }
    }

    // kolom PIN: dari nama judul, lalu dari kolom angka
    $kp = null;
    if ($punya_header) {
        foreach ($baris1 as $i => $v) {
            if ($i !== $kw && $i !== $kj && preg_match('/^(pin|id|no\.?\s*id|no\.?\s*pin|userid|user\s*id|badge|id\s*karyawan|nip)$/i', trim($v))) { $kp = $i; break; }
        }
    }
    if ($kp === null) {
        foreach ($sample as $i => $v) {
            if ($i !== $kw && $i !== $kj && preg_match('/^\d+$/', $v)) { $kp = $i; break; }
        }
    }
    if ($kp === null) $kp = ($kw === 0) ? 1 : 0;

    // format tanggal
    $ymd = false; $d_gt12 = false; $m_gt12 = false;
    for ($r = $mulai; $r < count($preview); $r++) {
        $v = $preview[$r][$kw] ?? '';
        if (preg_match('/^\d{4}[-\/.]/', $v)) { $ymd = true; break; }
        if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-]\d{4}/', $v, $m)) {
            if ((int)$m[1] > 12) $d_gt12 = true;
            if ((int)$m[2] > 12) $m_gt12 = true;
        }
    }
    $fmt = $ymd ? 'ymd' : (($m_gt12 && !$d_gt12) ? 'mdy' : 'dmy');

    return [$punya_header, $kp, $kw, $kj, $fmt];
}

/* ---------- proses ---------- */
$preview = null;
$hasil = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'upload') {
        $f = $_FILES['csv'] ?? null;
        $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !in_array($ext, ['csv', 'txt', 'dat'], true) || $f['size'] > 5 * 1024 * 1024) {
            flash('Unggah gagal. Gunakan file .csv, .txt, atau .dat (maks 5 MB).', 'danger');
            redirect('import_absensi.php');
        }
        hapus_temp();
        $tmp = sys_get_temp_dir() . '/absen_' . bin2hex(random_bytes(8)) . '.csv';
        move_uploaded_file($f['tmp_name'], $tmp);
        $_SESSION['import_file'] = $tmp;
        $preview = baca_csv($tmp, 8);
        if (!$preview) { hapus_temp(); flash('File kosong.', 'danger'); redirect('import_absensi.php'); }
    }

    if ($aksi === 'proses') {
        $tmp = $_SESSION['import_file'] ?? '';
        if (!$tmp || !is_file($tmp)) { flash('File tidak ditemukan, unggah ulang.', 'danger'); redirect('import_absensi.php'); }

        $kp = (int)$_POST['kol_pin'];
        $kw = (int)$_POST['kol_waktu'];
        $kj = ($_POST['kol_jam'] ?? '') === '' ? null : (int)$_POST['kol_jam'];
        $fmt = in_array($_POST['fmt'] ?? '', ['ymd', 'dmy', 'mdy'], true) ? $_POST['fmt'] : 'ymd';
        $timpa = isset($_POST['timpa']);
        $merah_list = array_filter(array_map('trim', explode(',', $_POST['tanggal_merah'] ?? '')));

        $rows = baca_csv($tmp);
        if (isset($_POST['header'])) array_shift($rows);

        // peta PIN -> karyawan
        $map = [];
        foreach ($pdo->query('SELECT * FROM karyawan WHERE aktif=1 AND pin_fingerprint IS NOT NULL')->fetchAll() as $k) {
            $map[norm_pin($k['pin_fingerprint'])] = $k;
        }

        $hasil = ['baris' => count($rows), 'scan_baru' => 0, 'format_salah' => 0, 'dibuat' => 0, 'jam_lembur' => 0.0,
                  'sudah_ada' => 0, 'terkunci' => 0, 'tunggal' => [], 'tak_dikenal' => []];
        $touch = [];

        $pdo->beginTransaction();
        try {
            // 1) simpan log scan mentah (duplikat diabaikan)
            $ins = $pdo->prepare('INSERT IGNORE INTO log_scan (pin, waktu) VALUES (?, ?)');
            foreach ($rows as $r) {
                $pin = norm_pin($r[$kp] ?? '');
                $t = trim($r[$kw] ?? '');
                if ($kj !== null) $t .= ' ' . trim($r[$kj] ?? '');
                if ($pin === '' || trim($t) === '') continue;
                $w = parse_waktu(trim($t), $fmt);
                if (!$w) { $hasil['format_salah']++; continue; }
                $ins->execute([$pin, $w]);
                $hasil['scan_baru'] += $ins->rowCount();
                $touch[$pin][substr($w, 0, 10)] = true;
            }

            // 2) bentuk absensi + lembur dari scan pertama & terakhir tiap hari
            $q = $pdo->prepare('SELECT MIN(waktu), MAX(waktu) FROM log_scan WHERE pin=? AND waktu BETWEEN ? AND ?');
            $cekGaji = $pdo->prepare('SELECT 1 FROM gaji WHERE karyawan_id=? AND periode IN (?, ?)');
            $cekAbs = $pdo->prepare('SELECT id, lembur_id FROM absensi WHERE karyawan_id=? AND tanggal=?');

            foreach ($touch as $pin => $tgls) {
                if (!isset($map[$pin])) { $hasil['tak_dikenal'][$pin] = true; continue; }
                $k = $map[$pin];
                foreach (array_keys($tgls) as $tgl) {
                    $q->execute([$pin, "$tgl 00:00:00", "$tgl 23:59:59"]);
                    [$min, $max] = $q->fetch(PDO::FETCH_NUM);
                    if (!$min) continue;
                    if (strtotime($max) - strtotime($min) < MIN_SELANG_SCAN_MENIT * 60) {
                        $hasil['tunggal'][] = $k['nama'] . ' (' . $tgl . ')';
                        continue;
                    }
                    $cekGaji->execute([$k['id'], ...periode_kandidat($tgl)]);
                    if ($cekGaji->fetch()) { $hasil['terkunci']++; continue; }   // gaji periode itu sudah diproses

                    $cekAbs->execute([$k['id'], $tgl]);
                    if ($ada = $cekAbs->fetch()) {
                        if (!$timpa) { $hasil['sudah_ada']++; continue; }
                        $pdo->prepare('DELETE FROM absensi WHERE id=?')->execute([$ada['id']]);
                        if ($ada['lembur_id']) $pdo->prepare('DELETE FROM lembur WHERE id=?')->execute([$ada['lembur_id']]);
                    }

                    $masuk = substr($min, 11, 8);
                    $keluar = substr($max, 11, 8);
                    $merah = in_array($tgl, $merah_list, true);
                    [$jam, $tipe] = hitung_jam_lembur($tgl, $masuk, $keluar, $merah);

                    $lembur_id = null;
                    if ($jam > 0) {
                        [$upah_jam, $total] = hitung_lembur($jam);
                        $pdo->prepare('INSERT INTO lembur (karyawan_id,tanggal,jam,tipe,upah_per_jam,total,keterangan) VALUES (?,?,?,?,?,?,?)')
                            ->execute([$k['id'], $tgl, $jam, $tipe, $upah_jam, $total, 'Otomatis dari fingerprint ' . substr($masuk, 0, 5) . '-' . substr($keluar, 0, 5)]);
                        $lembur_id = (int)$pdo->lastInsertId();
                        $hasil['jam_lembur'] += $jam;
                    }
                    $pdo->prepare('INSERT INTO absensi (karyawan_id,tanggal,jam_masuk,jam_keluar,tanggal_merah,lembur_id) VALUES (?,?,?,?,?,?)')
                        ->execute([$k['id'], $tgl, $masuk, $keluar, $merah ? 1 : 0, $lembur_id]);
                    $hasil['dibuat']++;
                }
            }
            $pdo->commit();
            hapus_temp();
        } catch (Throwable $t) {
            $pdo->rollBack();
            flash('Import gagal, tidak ada data yang tersimpan.', 'danger');
            redirect('import_absensi.php');
        }
    }
}

require APP_PATH . '/views/header.php';
?>

<?php if ($hasil): ?>
  <div class="card mb-3"><div class="card-header">Hasil Import</div><div class="card-body">
    <ul class="mb-3">
      <li>Baris dibaca: <b><?= (int)$hasil['baris'] ?></b> | scan baru tersimpan: <b><?= (int)$hasil['scan_baru'] ?></b></li>
      <li>Absensi dibuat: <b><?= (int)$hasil['dibuat'] ?></b> | total lembur: <b><?= e($hasil['jam_lembur']) ?> jam</b></li>
      <?php if ($hasil['sudah_ada']): ?><li>Dilewati karena absensinya sudah ada: <?= (int)$hasil['sudah_ada'] ?> (centang "timpa" untuk menggantinya)</li><?php endif; ?>
      <?php if ($hasil['terkunci']): ?><li class="text-danger">Dilewati karena gaji periodenya sudah diproses: <?= (int)$hasil['terkunci'] ?></li><?php endif; ?>
      <?php if ($hasil['format_salah']): ?><li class="text-danger">Baris dengan format tanggal/jam tidak terbaca: <?= (int)$hasil['format_salah'] ?> (cek pilihan format tanggal)</li><?php endif; ?>
    </ul>
    <?php if ($hasil['tak_dikenal']): ?>
      <div class="alert alert-warning py-2">PIN belum terdaftar di data karyawan: <b><?= e(implode(', ', array_keys($hasil['tak_dikenal']))) ?></b>.
        Isi kolom PIN di menu <a href="karyawan.php">Karyawan</a>, lalu import ulang file yang sama (aman, tidak akan dobel).</div>
    <?php endif; ?>
    <?php if ($hasil['tunggal']): ?>
      <div class="alert alert-secondary py-2">Hanya 1 scan (atau selang scan &lt; <?= MIN_SELANG_SCAN_MENIT ?> menit), tidak dibuatkan absensi:
        <?= e(implode('; ', array_slice($hasil['tunggal'], 0, 20))) ?><?= count($hasil['tunggal']) > 20 ? ' ...' : '' ?></div>
    <?php endif; ?>
    <a href="absensi.php" class="btn btn-primary btn-sm">Lihat Absensi</a>
    <a href="lembur.php" class="btn btn-outline-primary btn-sm">Lihat Lembur</a>
  </div></div>

<?php elseif ($preview): ?>
  <?php
    [$punya_header, $guess_p, $guess_w, $guess_j, $guess_fmt] = tebak_pengaturan($preview);
    $labels = [];
    foreach ($preview[0] as $i => $v) $labels[$i] = $punya_header ? $v : 'contoh: ' . $v;
    $opt = function (int $sel, bool $kosong = false) use ($labels) {
        $h = $kosong ? '<option value="">- tidak ada -</option>' : '';
        foreach ($labels as $i => $v) {
            $h .= '<option value="' . $i . '"' . ($i === $sel ? ' selected' : '') . '>Kolom ' . ($i + 1) . ' — ' . e($v) . '</option>';
        }
        return $h;
    };
  ?>
  <div class="card mb-3"><div class="card-header">Langkah 2: Cocokkan Kolom <span class="text-muted small">(sudah ditebak otomatis, periksa sebelum memproses)</span></div><div class="card-body">
    <div class="table-responsive mb-3"><table class="table table-sm table-bordered mb-0">
      <?php foreach ($preview as $r): ?><tr><?php foreach ($r as $c): ?><td><?= e($c) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </table></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="proses">
      <div class="row g-3">
        <div class="col-md-4"><label class="form-label">Kolom PIN / ID karyawan</label><select name="kol_pin" class="form-select"><?= $opt($guess_p) ?></select></div>
        <div class="col-md-4"><label class="form-label">Kolom tanggal &amp; waktu scan</label><select name="kol_waktu" class="form-select"><?= $opt($guess_w) ?></select></div>
        <div class="col-md-4"><label class="form-label">Kolom jam (hanya jika terpisah dari tanggal)</label><select name="kol_jam" class="form-select"><?= $opt($guess_j ?? -1, true) ?></select></div>
        <div class="col-md-4"><label class="form-label">Format tanggal</label>
          <select name="fmt" class="form-select">
            <option value="ymd" <?= $guess_fmt === 'ymd' ? 'selected' : '' ?>>2026-10-09 17:05:00 (tahun-bulan-hari)</option>
            <option value="dmy" <?= $guess_fmt === 'dmy' ? 'selected' : '' ?>>09/10/2026 17:05 (hari/bulan/tahun)</option>
            <option value="mdy" <?= $guess_fmt === 'mdy' ? 'selected' : '' ?>>10/09/2026 17:05 (bulan/hari/tahun)</option>
          </select></div>
        <div class="col-md-8"><label class="form-label">Tanggal merah / libur nasional dalam file ini (opsional)</label>
          <input name="tanggal_merah" class="form-control" placeholder="2026-12-25, 2026-12-31 (format tahun-bulan-hari, pisahkan koma)"></div>
        <div class="col-12">
          <div class="form-check"><input class="form-check-input" type="checkbox" name="header" id="header" <?= $punya_header ? 'checked' : '' ?>><label class="form-check-label" for="header">Baris pertama adalah judul kolom</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="timpa" id="timpa"><label class="form-check-label" for="timpa">Timpa absensi yang sudah ada pada tanggal yang sama</label></div>
        </div>
      </div>
      <button class="btn btn-success mt-3">Proses Import</button>
      <a href="import_absensi.php" class="btn btn-secondary mt-3">Batal</a>
    </form>
  </div></div>

<?php else: ?>
  <div class="card mb-3"><div class="card-header">Langkah 1: Unggah File CSV</div><div class="card-body">
    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="aksi" value="upload">
      <div class="mb-3"><input type="file" name="csv" accept=".csv,.txt,.dat" class="form-control" required></div>
      <button class="btn btn-primary">Unggah &amp; Lihat Preview</button>
    </form>
  </div></div>
  <div class="alert alert-info small">
    <b>Syarat &amp; cara kerja</b>
    <ul class="mb-0">
      <li>Isi kolom <b>PIN / ID Fingerprint</b> tiap karyawan di menu <a href="karyawan.php">Karyawan</a> (sama dengan ID di mesin).</li>
      <li>Format yang didukung: <b>.csv</b> (koma, titik koma, atau tab dikenali otomatis), serta <b>.dat</b> dari mesin (ZKTeco, unduhan flashdisk). Jika berkas dari Excel (.xls/.xlsx), buka lalu <i>Simpan Sebagai</i> CSV dulu.</li>
      <li>Yang dibutuhkan hanya kolom PIN dan tanggal-jam scan. Kolom lain (nama, status masuk/pulang, verifikasi) diabaikan.</li>
      <li>Scan <b>pertama</b> di satu tanggal = jam masuk, scan <b>terakhir</b> = jam keluar, lalu lembur dihitung dengan aturan di <code>config.php</code>.</li>
      <li>Import file yang sama berulang kali aman: scan duplikat diabaikan dan absensi yang sudah ada tidak dobel.</li>
      <li>Tanggal yang gajinya sudah diproses akan dilewati supaya data gaji tidak berubah.</li>
      <li>Shift yang pulang lewat tengah malam belum didukung di import (dipisah per tanggal kalender).</li>
    </ul>
    <div class="mt-2">Contoh file (PIN 1-4, data uji ada di <code>database/sample_data.sql</code>):
      <a href="samples/1_zkteco_attlog.dat" download>1. ZKTeco .dat</a> |
      <a href="samples/2_laporan_excel_titik_koma.csv" download>2. CSV Excel (titik koma)</a> |
      <a href="samples/3_tanggal_jam_terpisah.csv" download>3. Tanggal &amp; jam terpisah</a></div>
  </div>
<?php endif; ?>
<?php require APP_PATH . '/views/footer.php'; ?>
