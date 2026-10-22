/* Input Nilai: hitung NP/NK/NA di layar (hanya tampilan; server menghitung ulang saat simpan) dan tempel dari Excel. */
(function () {
    'use strict';

    var form = document.getElementById('formNilai');
    if (!form) return;

    var bobot = JSON.parse(form.getAttribute('data-bobot'));
    var skala = JSON.parse(form.getAttribute('data-skala')); // urut dari batas tertinggi
    var kkm = parseFloat(form.getAttribute('data-kkm')) || 0;
    var PENGETAHUAN = ['ujian', 'harian', 'tugas_akhir'];
    var KETERAMPILAN = ['praktik', 'adab'];

    function angka(v) {
        v = String(v || '').replace(',', '.').trim();
        var n = parseFloat(v);
        return isNaN(n) ? 0 : Math.max(0, Math.min(100, n));
    }

    function jumlah(baris, kunci) {
        var t = 0;
        kunci.forEach(function (k) {
            var i = baris.querySelector('input[data-k="' + k + '"]');
            t += angka(i ? i.value : 0) * (bobot[k] || 0) / 100;
        });
        return Math.round(t);
    }

    function predikat(na) {
        if (na <= 0) return '-';
        for (var i = 0; i < skala.length; i++) if (na >= skala[i].min) return skala[i].p;
        return '-';
    }

    function hitung(baris) {
        var np = jumlah(baris, PENGETAHUAN), nk = jumlah(baris, KETERAMPILAN), na;
        if (nk === 0 && np > 0) na = np;
        else if (np === 0 && nk > 0) na = nk;
        else na = Math.round((np * bobot.np + nk * bobot.nk) / 100);

        baris.querySelector('[data-hasil=np]').textContent = np;
        baris.querySelector('[data-hasil=nk]').textContent = nk;
        baris.querySelector('[data-hasil=na]').textContent = na;
        baris.querySelector('[data-hasil=predikat]').textContent = predikat(na);
        baris.classList.toggle('mon-langgar', na > 0 && na < kkm);
    }

    var barisSemua = Array.prototype.slice.call(form.querySelectorAll('tr[data-baris]'));
    barisSemua.forEach(function (b) {
        hitung(b);
        b.addEventListener('input', function (e) { if (e.target.hasAttribute('data-k')) hitung(b); });
    });

    // Tempel dari Excel: tab = kolom berikutnya, baris baru = siswa berikutnya.
    form.addEventListener('paste', function (e) {
        var sel = e.target;
        if (!sel.hasAttribute || !sel.hasAttribute('data-k')) return;
        var teks = (e.clipboardData || window.clipboardData).getData('text');
        if (teks.indexOf('\t') === -1 && teks.indexOf('\n') === -1) return; // satu sel: biarkan bawaan
        e.preventDefault();

        var baris = barisSemua.indexOf(sel.closest('tr'));
        var kolom = Array.prototype.slice.call(sel.closest('tr').querySelectorAll('input[data-k]')).indexOf(sel);
        teks.replace(/\r/g, '').replace(/\n+$/, '').split('\n').forEach(function (garis, r) {
            var tr = barisSemua[baris + r];
            if (!tr) return;
            var kotak = tr.querySelectorAll('input[data-k]');
            garis.split('\t').forEach(function (nilai, c) {
                if (kotak[kolom + c] && !kotak[kolom + c].disabled) kotak[kolom + c].value = nilai.trim();
            });
            hitung(tr);
        });
    });
})();
