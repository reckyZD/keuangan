(function () {
  'use strict';

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
