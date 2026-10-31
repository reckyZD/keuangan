<?php
/**
 * Konsumable = barang pakai habis. Tabel `konsumable` menyimpan dua jenis transaksi:
 *   masuk  (IN)  : pembelian/penambahan stok, ada harga satuan & total
 *   keluar (OUT) : pemakaian, dicatat qty + dipakai oleh (bagian); harga tidak diisi
 * Stok akhir = stok awal + IN - OUT. Harga rata-rata = total harga semua IN s/d akhir periode / total qty IN.
 */

/** Angka dari form: terima "1.500" / "1500" / "2,5" / "1.500,50". -1 jika tidak valid. */
function kon_angka($v): float {
    $v = trim((string)$v);
    if (substr_count($v, ',') === 1) $v = str_replace(',', '.', str_replace('.', '', $v));
    elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) $v = str_replace('.', '', $v);
    return is_numeric($v) ? (float)$v : -1.0;
}

/** "2.5" bukan "2.50"; bilangan bulat tanpa desimal. */
function kon_num($v): string {
    return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
}

function kon_key(string $nama): string { return mb_strtolower(trim(preg_replace('/\s+/', ' ', $nama))); }

/** Stok saat ini per barang => [key => ['nama'=>, 'satuan'=>, 'stok'=>]]. $kecuali_id = abaikan satu transaksi (saat edit). */
function kon_stok_sekarang(int $kecuali_id = 0): array {
    global $pdo;
    $out = [];
    $s = $pdo->prepare('SELECT id, nama_barang, satuan, qty, jenis FROM konsumable WHERE id <> ? ORDER BY tanggal, id');
    $s->execute([$kecuali_id]);
    foreach ($s->fetchAll() as $r) {
        $k = kon_key($r['nama_barang']);
        $out[$k] ??= ['nama' => $r['nama_barang'], 'satuan' => '', 'stok' => 0.0];
        $out[$k]['stok'] += $r['jenis'] === 'keluar' ? -(float)$r['qty'] : (float)$r['qty'];
        if (trim((string)$r['satuan']) !== '') $out[$k]['satuan'] = $r['satuan'];
        $out[$k]['nama'] = $r['nama_barang'];
    }
    return $out;
}

/**
 * Rekap stok per barang untuk rentang [$dari, $sampai].
 * Mengembalikan [rows, total], rows = daftar barang urut nama:
 *  nama, satuan, awal, in, out, akhir, avg, nilai_in, nilai_out, nilai_akhir, ket
 */
function kon_rekap(string $dari, string $sampai): array {
    global $pdo;
    $s = $pdo->prepare('SELECT tanggal, nama_barang, satuan, qty, total, jenis FROM konsumable WHERE tanggal <= ? ORDER BY tanggal, id');
    $s->execute([$sampai]);
    $b = [];
    foreach ($s->fetchAll() as $r) {
        $k = kon_key($r['nama_barang']);
        $b[$k] ??= ['nama' => $r['nama_barang'], 'satuan' => '', 'awal' => 0.0, 'in' => 0.0, 'out' => 0.0, 'nilai_in' => 0.0, 'cum_qty' => 0.0, 'cum_rp' => 0.0];
        $q = (float)$r['qty'];
        $sebelum = $r['tanggal'] < $dari;
        if ($r['jenis'] === 'keluar') {
            if ($sebelum) $b[$k]['awal'] -= $q; else $b[$k]['out'] += $q;
        } else {
            if ($sebelum) $b[$k]['awal'] += $q; else { $b[$k]['in'] += $q; $b[$k]['nilai_in'] += (float)$r['total']; }
            $b[$k]['cum_qty'] += $q; $b[$k]['cum_rp'] += (float)$r['total'];
        }
        if (trim((string)$r['satuan']) !== '') $b[$k]['satuan'] = $r['satuan'];
        $b[$k]['nama'] = $r['nama_barang'];
    }
    uasort($b, fn($x, $y) => strcasecmp($x['nama'], $y['nama']));
    $tot = ['nilai_in' => 0.0, 'nilai_out' => 0.0, 'nilai_akhir' => 0.0];
    foreach ($b as &$x) {
        $x['akhir'] = $x['awal'] + $x['in'] - $x['out'];
        $x['avg'] = $x['cum_qty'] > 0 ? $x['cum_rp'] / $x['cum_qty'] : 0.0;
        $x['nilai_out'] = round($x['out'] * $x['avg']);
        $x['nilai_akhir'] = round($x['akhir'] * $x['avg']);
        $x['ket'] = $x['akhir'] < -0.0001 ? 'Minus - cek data' : (abs($x['akhir']) < 0.0001 ? 'Stok habis' : '');
        $tot['nilai_in'] += $x['nilai_in']; $tot['nilai_out'] += $x['nilai_out']; $tot['nilai_akhir'] += $x['nilai_akhir'];
    }
    unset($x);
    return [array_values($b), $tot];
}
