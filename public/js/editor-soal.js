/* Editor soal: tab Pilihan Ganda / Esai / Bacaan tanpa memuat ulang halaman, dan tombol + mengambang. */
(function () {
    'use strict';

    var akar = document.getElementById('editorSoal');
    if (!akar) return;

    var tombol = document.querySelectorAll('.tab-soal-tombol');
    var panel = akar.querySelectorAll('.panel-soal');

    function pilih(kode) {
        tombol.forEach(function (t) { t.classList.toggle('aktif', t.getAttribute('data-tab') === kode); t.setAttribute('aria-selected', t.getAttribute('data-tab') === kode ? 'true' : 'false'); });
        panel.forEach(function (p) { p.classList.toggle('aktif', p.getAttribute('data-panel') === kode); });
        try { history.replaceState(null, '', '#' + kode); } catch (e) { /* abaikan */ }
    }

    tombol.forEach(function (t) { t.addEventListener('click', function () { pilih(t.getAttribute('data-tab')); }); });
    var awal = (location.hash || '').replace('#', '');
    pilih(['pg', 'esai', 'bacaan'].indexOf(awal) !== -1 ? awal : (akar.getAttribute('data-awal') || 'pg'));

    // Tombol + mengambang
    var utama = document.getElementById('fabUtama');
    var daftar = document.getElementById('fabDaftar');
    var latar = document.getElementById('fabLatar');
    if (!utama || !daftar) return;

    function buka(b) {
        daftar.hidden = !b;
        if (latar) latar.hidden = !b;
        utama.classList.toggle('terbuka', b);
        utama.setAttribute('aria-expanded', b ? 'true' : 'false');
    }
    utama.addEventListener('click', function () { buka(daftar.hidden); });
    if (latar) latar.addEventListener('click', function () { buka(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') buka(false); });
})();
