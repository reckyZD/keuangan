/**
 * Konversi file laporan fingerprint (.xls/.xlsx, sheet "Log") -> CSV "PIN,Nama,Waktu"
 * supaya bisa diproses oleh alur import yang sudah ada. Berjalan di browser (SheetJS).
 */
(function (root) {
  'use strict';

  var pad = function (n) { return (n < 10 ? '0' : '') + n; };

  /** Dari teks "2026/10/02 ~ 10/16" -> {y, m, d} tanggal awal. */
  function bacaPeriode(aoa) {
    for (var i = 0; i < Math.min(aoa.length, 10); i++) {
      for (var j = 0; j < aoa[i].length; j++) {
        var m = /(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})\s*~/.exec(String(aoa[i][j]));
        if (m) return { y: +m[1], m: +m[2], d: +m[3] };
      }
    }
    return null;
  }

  function csvSel(v) { return '"' + String(v).replace(/"/g, '""') + '"'; }

  /**
   * @param {Array<Array<string>>} aoa  isi sheet "Log" (array of arrays)
   * @return {{csv:string, jumlah:number, karyawan:number, dari:string, sampai:string}}
   */
  function logKeCsv(aoa) {
    var per = bacaPeriode(aoa);
    if (!per) throw new Error('Baris "Periode : 2026/10/02 ~ 10/16" tidak ditemukan di sheet Log.');

    var hari = null;          // peta kolom -> tanggal (YYYY-MM-DD) dari baris angka 2,3,4,...
    var pin = null, nama = '';
    var out = ['PIN,Nama,Waktu'];
    var jumlah = 0, daftar = {}, awal = null, akhir = null;

    for (var i = 0; i < aoa.length; i++) {
      var row = aoa[i].map(function (v) { return String(v == null ? '' : v).trim(); });
      var tak = row.filter(function (v) { return v !== ''; });
      if (!tak.length) continue;

      // baris judul tanggal: semua sel berisi angka 1-31
      if (tak.every(function (v) { return /^\d{1,2}$/.test(v) && +v >= 1 && +v <= 31; })) {
        hari = {};
        var y = per.y, mo = per.m, prev = 0;
        for (var c = 0; c < row.length; c++) {
          if (row[c] === '') continue;
          var d = +row[c];
          if (prev && d < prev) { mo++; if (mo > 12) { mo = 1; y++; } }   // lewat pergantian bulan
          prev = d;
          hari[c] = y + '-' + pad(mo) + '-' + pad(d);
        }
        continue;
      }

      // baris identitas: "No : 5 ... Nama : novi ... Dept : Office"
      if (/^No\s*:?$/i.test(row[0])) {
        pin = null; nama = '';
        for (var c2 = 1; c2 < row.length; c2++) {
          if (row[c2] === '') continue;
          if (/^Nama\s*:?$/i.test(row[c2])) { nama = nextVal(row, c2); }
          else if (/^Dept\s*:?$/i.test(row[c2])) { /* abaikan */ }
          else if (pin === null && /^\d+$/.test(row[c2])) { pin = row[c2]; }
        }
        // nama bisa sama dengan nilai sel setelah label; pastikan bukan label berikutnya
        continue;
      }

      // baris jam scan: sel-sel berisi "07:41\n17:46"
      if (pin !== null && hari) {
        for (var c3 = 0; c3 < row.length; c3++) {
          if (row[c3] === '' || !hari[c3]) continue;
          var jam = row[c3].match(/\d{1,2}:\d{2}/g);
          if (!jam) continue;
          for (var k = 0; k < jam.length; k++) {
            var hm = jam[k].split(':');
            out.push(pin + ',' + csvSel(nama) + ',' + hari[c3] + ' ' + pad(+hm[0]) + ':' + hm[1] + ':00');
            jumlah++;
            daftar[pin] = true;
            if (awal === null || hari[c3] < awal) awal = hari[c3];
            if (akhir === null || hari[c3] > akhir) akhir = hari[c3];
          }
        }
      }
    }
    return { csv: out.join('\r\n') + '\r\n', jumlah: jumlah, karyawan: Object.keys(daftar).length, dari: awal, sampai: akhir };
  }

  function nextVal(row, idx) {
    for (var j = idx + 1; j < row.length; j++) if (row[j] !== '') return row[j];
    return '';
  }

  root.FpXls = { logKeCsv: logKeCsv };
  if (typeof module !== 'undefined') module.exports = root.FpXls;
})(typeof window !== 'undefined' ? window : globalThis);
