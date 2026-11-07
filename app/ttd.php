<?php
/**
 * Tanda tangan & stempel owner (diupload lewat pengaturan, dipakai di slip gaji / laporan).
 * File disimpan tetap di public/uploads/owner-ttd.(png|jpg|webp) dan owner-stempel.(png|jpg|webp),
 * jadi tidak perlu tabel database.
 */
const TTD_JENIS = ['ttd' => 'Tanda tangan owner', 'stempel' => 'Stempel perusahaan'];
const TTD_EKSTENSI = ['png', 'jpg', 'webp'];
const TTD_MAKS_BYTE = 2 * 1024 * 1024;

function ttd_dir(): string {
    return dirname(__DIR__) . '/public/uploads';
}

/** Path file di disk, atau null jika belum diupload. */
function ttd_file(string $jenis): ?string {
    if (!isset(TTD_JENIS[$jenis])) return null;
    foreach (TTD_EKSTENSI as $ext) {
        $p = ttd_dir() . "/owner-$jenis.$ext";
        if (is_file($p)) return $p;
    }
    return null;
}

/** URL relatif terhadap folder public (untuk <img src>), atau null jika belum ada. */
function ttd_url(string $jenis): ?string {
    $p = ttd_file($jenis);
    return $p ? 'uploads/' . basename($p) . '?v=' . filemtime($p) : null;
}

function ttd_hapus(string $jenis): void {
    if (!isset(TTD_JENIS[$jenis])) return;
    foreach (TTD_EKSTENSI as $ext) {
        $p = ttd_dir() . "/owner-$jenis.$ext";
        if (is_file($p)) @unlink($p);
    }
}

/** Simpan hasil upload ($_FILES['...']). Return pesan error, atau null jika berhasil. */
function ttd_simpan(string $jenis, array $f): ?string {
    if (!isset(TTD_JENIS[$jenis])) return 'Jenis berkas tidak dikenal.';
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return 'Pilih file gambar terlebih dahulu.';
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return 'Ukuran file terlalu besar (maksimal 2 MB).';
    if ($err !== UPLOAD_ERR_OK) return 'Upload gagal, coba lagi.';
    if (($f['size'] ?? 0) > TTD_MAKS_BYTE) return 'Ukuran file terlalu besar (maksimal 2 MB).';

    $info = @getimagesize($f['tmp_name']);
    $map  = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($map[$info[2]])) return 'Format harus PNG, JPG, atau WEBP.';

    $dir = ttd_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return 'Folder uploads tidak bisa dibuat.';
    ttd_hapus($jenis);                                   // buang versi lama (termasuk beda ekstensi)
    if (!move_uploaded_file($f['tmp_name'], "$dir/owner-$jenis." . $map[$info[2]])) return 'Gagal menyimpan file.';
    return null;
}
