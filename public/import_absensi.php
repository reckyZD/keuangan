<?php
require __DIR__ . '/../app/bootstrap.php';
require_login();
pastikan_kolom_lembur();
$title = 'Import absensi fingerprint';
$subtitle = 'Unggah laporan fingerprint (.xls / .xlsx / .csv / .dat); lembur dihitung otomatis.';

/* ---------- helper ---------- */
function baca_csv(string $path, int $limit = 0): array {
    $raw = (string)file_get_contents($path);
    // Excel "Unicode Text" = UTF-16; buang BOM
    if (substr($raw, 0, 2) === "\xFF\xFE" || substr($raw, 0, 2) === "\xFE\xFF") {
        $raw = function_exists('mb_convert_encoding') ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16') : (string)iconv('UTF-16', 'UTF-8//IGNORE', $raw);
    } elseif (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    }
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $raw));

    $first = '';
    foreach ($lines as $l) { if (trim($l) !== '') { $first = $l; break; } }
    $c = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($c);
    $del = array_key_first($c);
    $spasi = $c[$del] === 0;            // tidak ada pemisah sama sekali -> pisahkan dengan spasi

    $rows = [];
    foreach ($lines as $l) {
        if (trim($l) === '') continue;
        $r = $spasi ? preg_split('/\s+/', trim($l)) : str_getcsv($l, $del, '"', '');
        $rows[] = array_map('trim', $r);
        if ($limit && count($rows) >= $limit) break;
    }
    return $rows;
}

/** Ringkasan per karyawan untuk CSV hasil konversi XLS (kolom: PIN, Nama, Waktu). */
function ringkas_log(string $path, array $map): ?array {
    $rows = baca_csv($path);
    if (strtolower($rows[0][0] ?? '') !== 'pin' || strtolower($rows[0][2] ?? '') !== 'waktu') return null;
    array_shift($rows);
    $per = []; $dari = $sampai = null;
    foreach ($rows as $r) {
        $pin = norm_pin($r[0] ?? '');
        $w = $r[2] ?? '';
        if ($pin === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $w)) continue;
        $tgl = substr($w, 0, 10);
        if (!isset($per[$pin])) $per[$pin] = ['nama' => $r[1] ?? '', 'hari' => [], 'scan' => 0];
        $per[$pin]['hari'][$tgl] = true;
        $per[$pin]['scan']++;
        if ($dari === null || $tgl < $dari) $dari = $tgl;
        if ($sampai === null || $tgl > $sampai) $sampai = $tgl;
    }
    if (!$per) return null;
    ksort($per, SORT_NATURAL);
    foreach ($per as $pin => $p) {
        $per[$pin]['hari'] = count($p['hari']);
        $per[$pin]['k'] = $map[$pin] ?? null;
    }
    return ['per' => $per, 'dari' => $dari, 'sampai' => $sampai, 'scan' => array_sum(array_column($per, 'scan'))];
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
$ringkas = null;
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
        if (($_POST['sumber'] ?? '') === 'xls') {          // hasil konversi laporan XLS: tampilkan ringkasan, lewati pencocokan kolom
            $peta = [];
            foreach ($pdo->query('SELECT * FROM karyawan WHERE aktif=1 AND pin_fingerprint IS NOT NULL')->fetchAll() as $k) {
                $peta[norm_pin($k['pin_fingerprint'])] = $k;
            }
            $ringkas = ringkas_log($tmp, $peta);
        }
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
                  'sudah_ada' => 0, 'terkunci' => 0, 'tunggal' => [], 'default9' => [], 'tak_dikenal' => []];
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
            $cekAbs = $pdo->prepare('SELECT id, lembur_id FROM absensi WHERE karyawan_id=? AND tanggal=?');

            foreach ($touch as $pin => $tgls) {
                if (!isset($map[$pin])) { $hasil['tak_dikenal'][$pin] = true; continue; }
                $k = $map[$pin];
                foreach (array_keys($tgls) as $tgl) {
                    $q->execute([$pin, "$tgl 00:00:00", "$tgl 23:59:59"]);
                    [$min, $max] = $q->fetch(PDO::FETCH_NUM);
                    if (!$min) continue;
                    $default9 = false;
                    if (strtotime($max) - strtotime($min) < MIN_SELANG_SCAN_MENIT * 60) {
                        // Tidak ada scan pulang: jika scan pagi (sebelum DEFAULT_MASUK_MAKS) anggap jam masuk, jam pulang diisi JAM_PULANG (17:00), tanpa lembur.
                        if (substr($min, 11, 5) < DEFAULT_MASUK_MAKS) {
                            $default9 = true;
                        } else {
                            $hasil['tunggal'][] = $k['nama'] . ' (' . $tgl . ')';
                            continue;
                        }
                    }
                    if (gaji_terkunci((int)$k['id'], $tgl)) { $hasil['terkunci']++; continue; }   // gaji periode itu sudah diproses

                    $cekAbs->execute([$k['id'], $tgl]);
                    if ($ada = $cekAbs->fetch()) {
                        if (!$timpa) { $hasil['sudah_ada']++; continue; }
                        $pdo->prepare('DELETE FROM absensi WHERE id=?')->execute([$ada['id']]);
                        if ($ada['lembur_id']) $pdo->prepare('DELETE FROM lembur WHERE id=?')->execute([$ada['lembur_id']]);
                    }

                    $masuk = substr($min, 11, 8);
                    $keluar = $default9 ? JAM_PULANG . ':00' : substr($max, 11, 8);
                    if ($default9) $hasil['default9'][] = $k['nama'] . ' (' . $tgl . ' masuk ' . substr($masuk, 0, 5) . ')';
                    $merah = in_array($tgl, $merah_list, true);
                    [$jam, $tipe] = hitung_jam_lembur($tgl, $masuk, $keluar, $merah);
                    if ($default9 && $jam > 0) $jam = 0.0;     // pulang hasil tebakan: tidak boleh menambah lembur (kurang jam tetap mengurangi)

                    $lembur_id = null;
                    if ($jam != 0) {
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
    <?php if ($hasil['default9']): ?>
      <div class="alert alert-info py-2">Tidak ada scan pulang, jam pulang diisi otomatis 17:00 (tanpa tambahan lembur; kalau kurang dari 9 jam, mengurangi lembur) untuk <?= count($hasil['default9']) ?> hari:
        <?= e(implode('; ', array_slice($hasil['default9'], 0, 20))) ?><?= count($hasil['default9']) > 20 ? ' ...' : '' ?>
        <br><span class="small">Jika scan pulangnya ternyata ada, import ulang filenya dengan centang "timpa".</span></div>
    <?php endif; ?>
    <?php if ($hasil['tunggal']): ?>
      <div class="alert alert-secondary py-2">Hanya 1 scan sore (setelah <?= DEFAULT_MASUK_MAKS ?>, tidak jelas masuk/pulang), tidak dibuatkan absensi:
        <?= e(implode('; ', array_slice($hasil['tunggal'], 0, 20))) ?><?= count($hasil['tunggal']) > 20 ? ' ...' : '' ?></div>
    <?php endif; ?>
    <a href="absensi.php" class="btn btn-primary btn-sm">Lihat Absensi</a>
    <a href="lembur.php" class="btn btn-outline-primary btn-sm">Lihat Lembur</a>
  </div></div>

<?php elseif ($ringkas): ?>
  <?php $tak = array_filter($ringkas['per'], fn($p) => !$p['k']); $cocok = count($ringkas['per']) - count($tak); ?>
  <div class="card mb-3"><div class="card-header">Langkah 2: Periksa &amp; Proses</div><div class="card-body">
    <p class="mb-3">Periode <b><?= e(tgl($ringkas['dari'])) ?></b> s/d <b><?= e(tgl($ringkas['sampai'])) ?></b>:
      <b><?= (int)$ringkas['scan'] ?></b> scan dari <b><?= count($ringkas['per']) ?></b> karyawan yang punya data.</p>
    <div class="table-responsive mb-3"><table class="table table-sm align-middle mb-0">
      <thead><tr><th>PIN</th><th>Nama di file</th><th>Karyawan di aplikasi</th><th class="text-end">Hari ada scan</th><th class="text-end">Jumlah scan</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($ringkas['per'] as $pin => $p): ?>
        <tr>
          <td><?= e($pin) ?></td>
          <td><?= e($p['nama']) ?></td>
          <td><?= $p['k'] ? e($p['k']['nama']) : '<span class="text-muted">-</span>' ?></td>
          <td class="text-end"><?= (int)$p['hari'] ?></td>
          <td class="text-end"><?= (int)$p['scan'] ?></td>
          <td><?= $p['k'] ? '<span class="badge text-bg-success">Cocok</span>' : '<span class="badge text-bg-warning">PIN belum terdaftar</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($tak): ?>
      <div class="alert alert-warning py-2"><?= count($tak) ?> PIN belum cocok dengan data karyawan, jadi dilewati. Isi kolom PIN di menu <a href="karyawan.php">Karyawan</a>
        (sama dengan nomor di laporan), lalu import ulang file yang sama (aman, tidak akan dobel).</div>
    <?php endif; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="proses">
      <input type="hidden" name="kol_pin" value="0"><input type="hidden" name="kol_waktu" value="2"><input type="hidden" name="kol_jam" value="">
      <input type="hidden" name="fmt" value="ymd"><input type="hidden" name="header" value="1">
      <div class="row g-3">
        <div class="col-md-8"><label class="form-label">Tanggal merah / libur nasional dalam periode ini (opsional)</label>
          <input name="tanggal_merah" class="form-control" placeholder="2026-12-25, 2026-12-31 (format tahun-bulan-hari, pisahkan koma)"></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="timpa" id="timpa"><label class="form-check-label" for="timpa">Timpa absensi yang sudah ada pada tanggal yang sama</label></div></div>
      </div>
      <button class="btn btn-success mt-3" <?= $cocok ? '' : 'disabled' ?>>Proses Import (<?= $cocok ?> karyawan)</button>
      <a href="import_absensi.php" class="btn btn-secondary mt-3">Batal</a>
    </form>
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
  <div class="card mb-3"><div class="card-header">Langkah 1: Unggah File Fingerprint</div><div class="card-body">
    <form method="post" enctype="multipart/form-data" id="form-upload"><?= csrf_field() ?><input type="hidden" name="aksi" value="upload"><input type="hidden" name="sumber" id="fp-sumber" value="">
      <div class="mb-3"><input type="file" name="csv" accept=".xls,.xlsx,.csv,.txt,.dat" class="form-control" required>
        <div class="hint mt-1" id="fp-info">Bisa langsung file laporan dari software mesin (contoh: <code>001_2026_10_MON.XLS</code>), tanpa disimpan ulang.</div></div>
      <button class="btn btn-primary" id="fp-btn">Unggah &amp; Lihat Preview</button>
    </form>
  </div></div>
  <div class="alert alert-info small">
    <b>Syarat &amp; cara kerja</b>
    <ul class="mb-0">
      <li>Isi kolom <b>PIN / ID Fingerprint</b> tiap karyawan di menu <a href="karyawan.php">Karyawan</a> (sama dengan ID di mesin).</li>
      <li>Format yang didukung: <b>.xls / .xlsx</b> (format dikenali otomatis, dibaca langsung di browser): laporan <b>Data Log</b> dari software mesin, atau rekap per pegawai (<code>NAMA</code>, <code>PIN Finger</code>, lalu kolom <code>Tanggal / Masuk / pulang</code>), <b>.csv</b> (koma, titik koma, atau tab dikenali otomatis), serta <b>.dat</b> dari mesin (ZKTeco, unduhan flashdisk).</li>
      <li>Untuk file .xls/.xlsx, PIN diambil dari kolom <b>No</b> laporan mesin (atau dari <b>PIN Finger</b> pada rekap per pegawai), jadi harus sama dengan PIN di menu Karyawan. Jika sheet Log tidak memuat PIN, PIN dicari lewat nama di sheet Laporan Kehadiran.</li>
      <li>Yang dibutuhkan hanya kolom PIN dan tanggal-jam scan. Kolom lain (nama, status masuk/pulang, verifikasi) diabaikan.</li>
      <li>Scan <b>pertama</b> di satu tanggal = jam masuk, scan <b>terakhir</b> = jam keluar, lalu lembur dihitung: (jam keluar - jam masuk) - 9 jam, tanpa batas minimal; hasil minus (kurang dari 9 jam) mengurangi total lembur. Tarif per jam diatur di Pengaturan. Jika tidak ada scan pulang (hanya 1 scan pagi, sebelum jam 13:00), jam pulang diisi 17:00; kalau masuknya lewat jam 08:00 berarti kurang dari 9 jam dan kekurangannya mengurangi lembur.</li>
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
<?php if (!$hasil && !$preview): ?>
<?php $sheetjs = is_file(__DIR__ . '/assets/xlsx.full.min.js') ? 'assets/xlsx.full.min.js' : 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js'; ?>
<script src="<?= e($sheetjs) ?>"></script>
<script src="assets/fp_xls.js"></script>
<script>
(function () {
  var form = document.getElementById('form-upload');
  if (!form) return;
  var input = form.querySelector('input[type=file]'), info = document.getElementById('fp-info'), btn = document.getElementById('fp-btn');
  form.addEventListener('submit', function (ev) {
    var f = input.files[0];
    if (!f || !/\.xlsx?$/i.test(f.name)) return;          // csv/txt/dat: kirim apa adanya
    ev.preventDefault();
    btn.disabled = true; info.textContent = 'Membaca file Excel...';
    var gagal = function (m) { btn.disabled = false; info.textContent = m; info.className = 'hint mt-1 text-danger'; };
    if (!window.XLSX || !window.FpXls) { gagal('Pembaca Excel gagal dimuat. Pastikan terhubung ke internet, atau simpan file sebagai .csv dulu.'); return; }
    f.arrayBuffer().then(function (buf) {
      var wb = XLSX.read(buf, { type: 'array' });
      var sheets = wb.SheetNames.map(function (n) {
        return { nama: n, aoa: XLSX.utils.sheet_to_json(wb.Sheets[n], { header: 1, raw: false, defval: '' }) };
      });
      var r = FpXls.otomatis(sheets);                   // format dikenali otomatis (laporan Log mesin / rekap per pegawai)
      if (!r.jumlah) throw new Error('Tidak ada data scan yang dikenali di file ini.' + (r.tanpaPin.length ? ' PIN tidak ditemukan untuk: ' + r.tanpaPin.slice(0, 5).join(', ') + '.' : '') + ' Format yang didukung: laporan Data Log dari mesin, atau rekap per pegawai (NAMA, PIN Finger, kolom Tanggal / Masuk / pulang).');
      var dt = new DataTransfer();
      dt.items.add(new File([r.csv], 'log_fingerprint.csv', { type: 'text/csv' }));
      input.files = dt.files;
      document.getElementById('fp-sumber').value = 'xls';
      info.textContent = 'Format: ' + r.format + '. ' + r.jumlah + ' scan dari ' + r.karyawan + ' karyawan (' + r.dari + ' s/d ' + r.sampai + ') terbaca' + (r.tanpaPin.length ? ' (' + r.tanpaPin.length + ' nama tanpa PIN dilewati: ' + r.tanpaPin.slice(0, 5).join(', ') + ')' : '') + '. Mengunggah...';
      form.submit();
    }).catch(function (e) { gagal(e.message || 'File tidak bisa dibaca.'); });
  });
})();
</script>
<?php endif; ?>
<?php require APP_PATH . '/views/footer.php'; ?>
