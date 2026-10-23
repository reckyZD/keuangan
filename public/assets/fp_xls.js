/**
 * Konversi file Excel absensi (.xls/.xlsx) -> CSV "PIN,Nama,Waktu" supaya bisa diproses
 * oleh alur import yang sudah ada. Berjalan di browser (SheetJS). Format dikenali otomatis:
 *  1. Laporan "Data Log" dari software mesin fingerprint (jam scan per tanggal).
 *  2. Rekap per pegawai: "NAMA : ...", "PIN Finger : N", lalu tabel Tanggal / Masuk / pulang.
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
  function logKeCsv(aoa, petaPin) {
    petaPin = petaPin || {};
    var tanpaPin = {};
    var per = bacaPeriode(aoa);
    if (!per) throw new Error('Baris "Periode : 2026/10/02 ~ 10/16" tidak ditemukan di sheet Log.');

    var hari = null;          // peta kolom -> tanggal (YYYY-MM-DD) dari baris angka 2,3,4,...
    var pin = null, nama = '';
    var out = ['PIN,Nama,Waktu'], rows = [];
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

      // baris identitas: "No : 5 ... Nama : novi ... Dept : Office" (label "No" boleh tidak ada;
      // PIN lalu diambil dari sheet Laporan Kehadiran berdasarkan nama)
      var adaNama = row.some(function (v) { return /^Nama\s*:?$/i.test(v); });
      if (/^No\s*:?$/i.test(row[0]) || adaNama) {
        pin = null; nama = '';
        for (var c2 = 0; c2 < row.length; c2++) {
          if (row[c2] === '') continue;
          if (/^Nama\s*:?$/i.test(row[c2])) { nama = nextVal(row, c2); }
          else if (/^Dept\s*:?$/i.test(row[c2])) { /* abaikan */ }
          else if (c2 > 0 && pin === null && /^\d+$/.test(row[c2])) { pin = row[c2]; }
        }
        if (pin === null && nama !== '') {
          pin = petaPin[nama.toLowerCase().replace(/\s+/g, ' ')] || null;
          if (pin === null) tanpaPin[nama] = true;
        }
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
            rows.push([pin, nama, hari[c3] + ' ' + pad(+hm[0]) + ':' + hm[1] + ':00']);
            jumlah++;
            daftar[pin] = true;
            if (awal === null || hari[c3] < awal) awal = hari[c3];
            if (akhir === null || hari[c3] > akhir) akhir = hari[c3];
          }
        }
      }
    }
    return { csv: out.join('\r\n') + '\r\n', jumlah: jumlah, karyawan: Object.keys(daftar).length, dari: awal, sampai: akhir, tanpaPin: Object.keys(tanpaPin), rows: rows };
  }

  /**
   * Dari sheet "Laporan Kehadiran": baris berisi sel "Nama" + nilai, dan baris di bawahnya
   * berisi sel "No" + nilai di kolom yang sama. Mengembalikan {nama(lowercase): pin}.
   */
  function petaNamaPin(aoa, peta) {
    peta = peta || {};
    for (var r = 0; r < aoa.length - 1; r++) {
      var row = aoa[r].map(function (v) { return String(v == null ? '' : v).trim(); });
      var bawah = aoa[r + 1].map(function (v) { return String(v == null ? '' : v).trim(); });
      for (var c = 0; c < row.length; c++) {
        if (!/^Nama\s*:?$/i.test(row[c]) || /^No\s*:?$/i.test(row[c])) continue;
        if (!/^No\s*:?$/i.test(bawah[c] || '')) continue;
        var nm = nextVal(row, c), no = nextVal(bawah, c);
        if (nm !== '' && /^\d+$/.test(no)) peta[nm.toLowerCase().replace(/\s+/g, ' ')] = no;
      }
    }
    return peta;
  }


  var BULAN = {
    januari: 1, jan: 1, februari: 2, pebruari: 2, feb: 2, maret: 3, mar: 3, april: 4, apr: 4, mei: 5, may: 5,
    juni: 6, jun: 6, juli: 7, jul: 7, agustus: 8, agu: 8, agt: 8, aug: 8, september: 9, sep: 9, sept: 9,
    oktober: 10, okt: 10, oct: 10, november: 11, nov: 11, nop: 11, desember: 12, des: 12, dec: 12
  };

  /** "22 September 2026", "2026-09-22", "22/09/2026" -> "2026-09-22" (atau null). */
  function parseTanggal(txt) {
    var s = String(txt == null ? '' : txt).trim(), m, y, mo, d;
    if ((m = /(\d{1,2})\s+([A-Za-z]+)\.?,?\s+(\d{2,4})/.exec(s)) && BULAN[m[2].toLowerCase()]) {
      d = +m[1]; mo = BULAN[m[2].toLowerCase()]; y = +m[3];
    } else if ((m = /(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/.exec(s))) {
      y = +m[1]; mo = +m[2]; d = +m[3];
    } else if ((m = /(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/.exec(s))) {
      d = +m[1]; mo = +m[2]; y = +m[3];
      if (mo > 12 && d <= 12) { var t = d; d = mo; mo = t; }   // terbaca m/d/y
    } else return null;
    if (y < 100) y += 2000;
    if (mo < 1 || mo > 12 || d < 1 || d > 31) return null;
    return y + '-' + pad(mo) + '-' + pad(d);
  }

  function parseJam(txt) {
    var m = /(\d{1,2})[:.](\d{2})/.exec(String(txt == null ? '' : txt));
    return m && +m[1] < 24 && +m[2] < 60 ? pad(+m[1]) + ':' + m[2] + ':00' : null;
  }

  function sesudah(row, idx) {                       // nilai non-kosong setelah sel idx, tanpa awalan ":"
    for (var j = idx + 1; j < row.length; j++) {
      var v = row[j].replace(/^[:\s]+/, '');
      if (v !== '') return v;
    }
    return '';
  }

  /**
   * Format rekap per pegawai:
   *   NAMA : DARMAN ...... PIN Finger : 2
   *   No. | Tanggal | HARI | Masuk | pulang | TOTAL | Lembur | ...
   *   1   | 22 September 2026 | Sabtu | 07:35 | 18:20 | ...
   * Yang dipakai hanya Tanggal, Masuk, pulang (lembur dihitung ulang oleh sistem).
   * Tanpa jam pulang -> hanya scan masuk (nanti diisi default 9 jam oleh import).
   */
  function rekapKeCsv(aoa) {
    var rows = [], pin = null, nama = '', kol = null, tanpaPin = {};
    for (var i = 0; i < aoa.length; i++) {
      var row = aoa[i].map(function (v) { return String(v == null ? '' : v).trim(); });
      if (!row.some(function (v) { return v !== ''; })) continue;

      var identitas = false;
      for (var c = 0; c < row.length; c++) {
        var mN = /^NAMA(?:\s+(?:KARYAWAN|PEGAWAI))?\s*:?\s*(.*)$/i.exec(row[c]);
        if (mN) {
          var v = mN[1].replace(/^[:\s]+/, '') || sesudah(row, c);
          if (v !== '') { nama = v; pin = null; kol = null; identitas = true; }
          continue;
        }
        var mP = /^(?:PIN|ID)(?:\s*Finger)?\s*:?\s*(.*)$/i.exec(row[c]);
        if (mP) {
          var p = mP[1].replace(/^[:\s]+/, '') || sesudah(row, c);
          if (/^\d+$/.test(p)) { pin = p; identitas = true; }
        }
      }
      if (identitas) continue;

      var iT = -1, iM = -1, iP = -1;
      row.forEach(function (v, j) {
        if (/^tanggal$/i.test(v)) iT = j;
        else if (/^(masuk|msuk)$/i.test(v)) iM = j;
        else if (/^(pulang|keluar|kluar)$/i.test(v)) iP = j;
      });
      if (iT >= 0 && iM >= 0 && iP >= 0) { kol = { t: iT, m: iM, p: iP }; continue; }

      if (kol && nama !== '') {
        var tgl = parseTanggal(row[kol.t]);
        if (!tgl) continue;
        var jm = parseJam(row[kol.m]), jp = parseJam(row[kol.p]);
        if (!jm && !jp) continue;
        if (pin === null) { tanpaPin[nama] = true; continue; }
        if (jm) rows.push([pin, nama, tgl + ' ' + jm]);
        if (jp) rows.push([pin, nama, tgl + ' ' + jp]);
      }
    }
    return bungkus(rows, Object.keys(tanpaPin));
  }

  /** rows [[pin,nama,'YYYY-MM-DD HH:MM:SS'],...] -> hasil lengkap (csv + ringkasan). */
  function bungkus(rows, tanpaPin) {
    var out = ['PIN,Nama,Waktu'], daftar = {}, awal = null, akhir = null;
    rows.forEach(function (r) {
      out.push(r[0] + ',' + csvSel(r[1]) + ',' + r[2]);
      daftar[r[0]] = true;
      var t = r[2].substr(0, 10);
      if (awal === null || t < awal) awal = t;
      if (akhir === null || t > akhir) akhir = t;
    });
    return { csv: out.join('\r\n') + '\r\n', jumlah: rows.length, karyawan: Object.keys(daftar).length,
             dari: awal, sampai: akhir, tanpaPin: tanpaPin || [], rows: rows };
  }

  /**
   * Deteksi otomatis. sheets = [{nama: 'Sheet1', aoa: [[...]]}, ...] dari seluruh isi workbook.
   * Mencoba laporan Log (sheet bernama Log / A1 "Data Log") dulu, lalu rekap per pegawai,
   * lalu laporan Log pada sheet lain. Hasil semua sheet yang cocok digabung.
   */
  function otomatis(sheets) {
    var peta = {};
    sheets.forEach(function (s) { petaNamaPin(s.aoa, peta); });
    var kandidatLog = sheets.filter(function (s) {
      return /log/i.test(s.nama) || /data\s*log/i.test(String((s.aoa[0] || [])[0] || ''));
    });
    var lainnya = sheets.filter(function (s) { return kandidatLog.indexOf(s) < 0; });
    var rows = [], tanpaPin = {}, format = '';

    function tambah(r, f) {
      if (!r || !r.jumlah) return false;
      rows = rows.concat(r.rows);
      r.tanpaPin.forEach(function (n) { tanpaPin[n] = true; });
      if (format.indexOf(f) < 0) format += (format ? ' + ' : '') + f;
      return true;
    }
    function cobaLog(list) {
      var ok = false;
      list.forEach(function (s) { try { ok = tambah(logKeCsv(s.aoa, peta), 'laporan Log mesin') || ok; } catch (e) { /* bukan format ini */ } });
      return ok;
    }
    function cobaRekap(list) {
      var ok = false;
      list.forEach(function (s) { try { ok = tambah(rekapKeCsv(s.aoa), 'rekap per pegawai') || ok; } catch (e) { } });
      return ok;
    }
    if (!cobaLog(kandidatLog)) { if (!cobaRekap(sheets)) cobaLog(lainnya); }

    var hasil = bungkus(rows, Object.keys(tanpaPin));
    hasil.format = format;
    return hasil;
  }

  function nextVal(row, idx) {
    for (var j = idx + 1; j < row.length; j++) if (row[j] !== '') return row[j];
    return '';
  }

  root.FpXls = { logKeCsv: logKeCsv, rekapKeCsv: rekapKeCsv, petaNamaPin: petaNamaPin, otomatis: otomatis };
  if (typeof module !== 'undefined') module.exports = root.FpXls;
})(typeof window !== 'undefined' ? window : globalThis);
