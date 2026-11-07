(function () {
  'use strict';

  /* ---- Kotak konfirmasi sendiri (pengganti confirm() bawaan browser yang menampilkan nama situs) ----
     Dipakai otomatis oleh setiap <form onsubmit="return confirm('...')"> atau <form data-konfirmasi="...">.
     Dari JS: konfirmasi('Teks', {judul, ya, bahaya}).then(function (ok) { ... }) */
  var kfModal = null, kfEl = null, kfHasil = false, kfSelesai = null;
  function bangunKonfirmasi() {
    kfEl = document.createElement('div');
    kfEl.className = 'modal fade';
    kfEl.tabIndex = -1;
    kfEl.setAttribute('aria-hidden', 'true');
    kfEl.setAttribute('aria-labelledby', 'kfJudul');
    kfEl.innerHTML =
      '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
      '<div class="modal-header"><h2 class="modal-title" id="kfJudul"></h2>' +
      '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>' +
      '<div class="modal-body" id="kfIsi" style="white-space:pre-line"></div>' +
      '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>' +
      '<button type="button" class="btn" id="kfYa"></button></div></div></div>';
    document.body.appendChild(kfEl);
    kfModal = new bootstrap.Modal(kfEl);
    kfEl.querySelector('#kfYa').addEventListener('click', function () { kfHasil = true; kfModal.hide(); });
    kfEl.addEventListener('shown.bs.modal', function () { kfEl.querySelector('#kfYa').focus(); });
    kfEl.addEventListener('hidden.bs.modal', function () {
      var f = kfSelesai; kfSelesai = null;
      if (f) f(kfHasil);
    });
  }
  window.konfirmasi = function (teks, opsi) {
    opsi = opsi || {};
    if (!window.bootstrap || !bootstrap.Modal) return Promise.resolve(window.confirm(teks));   // cadangan jika Bootstrap gagal dimuat
    if (!kfEl) bangunKonfirmasi();
    var t = String(teks || '');
    var hapus = /^\s*hapus/i.test(t), proses = /^\s*proses/i.test(t), reset = /^\s*reset/i.test(t);
    var judul = opsi.judul || (hapus ? 'Hapus data?' : proses ? 'Proses gaji?' : reset ? 'Reset data?' : 'Konfirmasi');
    var ya = opsi.ya || (hapus ? 'Ya, hapus' : proses ? 'Ya, proses' : reset ? 'Ya, reset' : 'Ya, lanjutkan');
    var bahaya = opsi.bahaya !== undefined ? opsi.bahaya : (hapus || reset);
    if (/^\s*hapus\s*\??\s*$/i.test(t)) t = 'Data ini akan dihapus dan tidak bisa dibatalkan.';
    kfEl.querySelector('#kfJudul').textContent = judul;
    kfEl.querySelector('#kfIsi').textContent = t;
    var tombol = kfEl.querySelector('#kfYa');
    tombol.textContent = ya;
    tombol.className = 'btn ' + (bahaya ? 'btn-danger' : 'btn-primary');
    kfHasil = false;
    return new Promise(function (selesai) { kfSelesai = selesai; kfModal.show(); });
  };
  function kirimForm(form, pengirim) {
    form.dataset.sudahKonfirmasi = '1';
    if (form.requestSubmit) { try { form.requestSubmit(pengirim || undefined); return; } catch (err) {} }
    form.submit();
  }
  window.kirimSetelahKonfirmasi = kirimForm;

  // form lama: onsubmit="return confirm('...')" -> ubah jadi data-konfirmasi (pesan yang sama)
  document.querySelectorAll('form[onsubmit*="confirm("]').forEach(function (f) {
    var m = /confirm\(\s*(['"])((?:\\[\s\S]|(?!\1)[^\\])*)\1\s*\)/.exec(f.getAttribute('onsubmit') || '');
    if (!m) return;
    f.removeAttribute('onsubmit');
    f.setAttribute('data-konfirmasi', m[2].replace(/\\n/g, '\n').replace(/\\(['"\\])/g, '$1'));
  });
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || !f.hasAttribute || !f.hasAttribute('data-konfirmasi')) return;
    if (f.dataset.sudahKonfirmasi === '1') { delete f.dataset.sudahKonfirmasi; return; }
    e.preventDefault();
    var pengirim = e.submitter || null;
    konfirmasi(f.getAttribute('data-konfirmasi')).then(function (ok) { if (ok) kirimForm(f, pengirim); });
  }, true);

  /* ---- Sidebar (HP) ---- */
  var sb = document.getElementById('sidebar'),
      bd = document.getElementById('sbBackdrop'),
      tg = document.getElementById('sbToggle');
  function setSidebar(open) {
    if (!sb) return;
    sb.classList.toggle('open', open);
    bd.classList.toggle('show', open);
    document.body.classList.toggle('sb-open', open);
    if (tg) { tg.setAttribute('aria-expanded', open ? 'true' : 'false'); tg.setAttribute('aria-label', open ? 'Tutup menu' : 'Buka menu'); }
  }
  if (tg) tg.addEventListener('click', function () { setSidebar(!sb.classList.contains('open')); });
  if (bd) bd.addEventListener('click', function () { setSidebar(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setSidebar(false); });
  if (sb) sb.querySelectorAll('a.sb-link, a.btn-kas').forEach(function (a) { a.addEventListener('click', function () { setSidebar(false); }); });

  /* ---- Kolom nominal rupiah: titik ribuan langsung saat mengetik ----
     Markup: <div class="amount"><span>Rp</span><input data-rupiah ...><input type="hidden" name="..."></div>
     Nilai bersih (angka saja) dikirim lewat input hidden. */
  function formatRupiah(v) { return v.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
  document.querySelectorAll('[data-rupiah]').forEach(function (inp) {
    var box = inp.closest('.amount'), hid = box && box.querySelector('input[type=hidden]');
    if (!hid) return;
    function sync() {
      var d = inp.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
      hid.value = d;
      inp.value = formatRupiah(d);
    }
    inp.addEventListener('input', sync);
    sync();                                            // nilai awal (mis. setelah gagal simpan)
    if (inp.form) inp.form.addEventListener('submit', function (e) {
      if (!hid.value || +hid.value < 1) { e.preventDefault(); inp.focus(); }
    });
  });

  /* ---- Form kas cepat ---- */
  document.querySelectorAll('[data-kas-form]').forEach(function (f) {
    var kat = {};
    try { kat = JSON.parse(f.getAttribute('data-kat') || '{}'); } catch (e) {}
    var chips = f.querySelector('[data-chips]'),
        kInput = f.querySelector('input[name=kategori]'),
        btn = f.querySelector('[data-submit-label]'),
        amt = f.querySelector('[data-rupiah]');

    function tipe() { return f.querySelector('input[name=tipe]:checked').value; }
    function mark() {
      chips.querySelectorAll('.chip').forEach(function (c) { c.classList.toggle('on', c.textContent === kInput.value); });
    }
    function render(clearIfOther) {
      var t = tipe(), other = kat[t === 'masuk' ? 'keluar' : 'masuk'] || [], mine = kat[t] || [];
      f.setAttribute('data-tipe', t);
      btn.textContent = t === 'masuk' ? 'Simpan kas masuk' : 'Simpan kas keluar';
      if (clearIfOther && other.indexOf(kInput.value) > -1 && mine.indexOf(kInput.value) < 0) kInput.value = '';
      chips.innerHTML = '';
      mine.forEach(function (n) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'chip'; b.textContent = n;
        b.addEventListener('click', function () {
          kInput.value = n; mark();
          var k = f.querySelector('[name=keterangan]'); if (k) k.focus();
        });
        chips.appendChild(b);
      });
      mark();
    }
    f.querySelectorAll('input[name=tipe]').forEach(function (r) { r.addEventListener('change', function () { render(true); }); });
    kInput.addEventListener('input', mark);

    render(false);
  });

  /* ---- Tombol "Catat kas" di halaman yang sudah punya form (dashboard, kas) ---- */
  var inline = document.getElementById('kas-cepat');
  document.querySelectorAll('a[href="#kas-cepat"]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      if (!inline) return;
      e.preventDefault();
      inline.scrollIntoView({ behavior: 'smooth', block: 'center' });
      var i = inline.querySelector('[data-rupiah]');
      if (i) setTimeout(function () { i.focus({ preventScroll: true }); }, 250);
    });
  });
  var modal = document.getElementById('kasModal');
  if (modal) modal.addEventListener('shown.bs.modal', function () {
    var i = modal.querySelector('[data-rupiah]'); if (i) i.focus();
  });

  /* ---- Lihat/sembunyikan password ---- */
  document.querySelectorAll('[data-toggle-pw]').forEach(function (b) {
    b.addEventListener('click', function () {
      var i = document.getElementById(b.getAttribute('data-toggle-pw'));
      var show = i.type === 'password';
      i.type = show ? 'text' : 'password';
      b.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
      b.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
  });
})();

/* ---------- Hapus banyak: centang baris di tabel (absensi, lembur) ---------- */
(function () {
  var semua = document.getElementById('cek-semua'), form = document.getElementById('form-hapus-banyak');
  if (!semua || !form) return;
  var baris = Array.prototype.slice.call(document.querySelectorAll('.cek-baris'));
  var bar = document.getElementById('bulk-bar'), info = document.getElementById('jml-dipilih');
  var tombolSemua = document.getElementById('pilih-semua-periode'), flag = document.getElementById('semua-periode');
  var total = parseInt(form.getAttribute('data-total'), 10) || baris.length;

  function dipilih() { return baris.filter(function (c) { return c.checked; }).length; }
  function segarkan() {
    var n = dipilih(), penuh = n > 0 && n === baris.length, mode = flag.value === '1';
    semua.checked = penuh;
    semua.indeterminate = n > 0 && !penuh;
    bar.classList.toggle('d-none', n === 0);
    info.textContent = mode ? 'Semua ' + total + ' data periode ini dipilih' : n + ' dipilih';
    tombolSemua.classList.toggle('d-none', !(mode || (penuh && total > baris.length)));
    tombolSemua.textContent = mode ? 'Batalkan pilih semua' : 'Pilih semua ' + total + ' data periode ini';
  }

  semua.addEventListener('change', function () {
    flag.value = '';
    baris.forEach(function (c) { c.checked = semua.checked; });
    segarkan();
  });
  baris.forEach(function (c) { c.addEventListener('change', function () { flag.value = ''; segarkan(); }); });
  tombolSemua.addEventListener('click', function () {
    if (flag.value === '1') { flag.value = ''; baris.forEach(function (c) { c.checked = false; }); }
    else { flag.value = '1'; }
    segarkan();
  });
  form.addEventListener('submit', function (e) {
    if (form.dataset.sudahKonfirmasi === '1') { delete form.dataset.sudahKonfirmasi; return; }
    e.preventDefault();
    var mode = flag.value === '1', n = mode ? total : dipilih();
    if (!n) return;
    var teks = 'Hapus ' + n + ' data' + (mode ? ' (SEMUA data pada periode ini)' : '') + '? ' + (form.getAttribute('data-catatan') || '');
    var pengirim = e.submitter || null;
    konfirmasi(teks, { judul: 'Hapus ' + n + ' data?' }).then(function (ok) { if (ok) kirimForm(form, pengirim); });
  });
  segarkan();
})();
