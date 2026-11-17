/*
 * Bilah tab bawah (HP): garis bilah melengkung di bawah tab terpilih dan ikon tab itu muncul sebagai bola biru yang
 * keluar dari lekukan. Saat tab lain diketuk, lekukan dan bola bergeser ke sana, baru halaman berpindah.
 * Hanya tampilan: tautan tetap tautan biasa, jadi tetap berfungsi bila skrip ini gagal.
 */
(function () {
    'use strict';

    var bar = document.querySelector('nav.bawah');
    if (!bar) return;
    var jalur = bar.querySelector('.bawah-latar path');
    var bola = bar.querySelector('.bawah-bola');
    if (!jalur || !bola) return;

    var item = Array.prototype.slice.call(bar.querySelectorAll('.bawah-a'));
    var gerak = !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    var KEDALAMAN = 37;   // kedalaman lekukan (px)
    var LEBAR = 44;       // setengah lebar lekukan di tepi atas (px)
    var SIMPAN = 'bawah-idx';

    var x = null;         // pusat lekukan saat ini (px dari kiri); null = tanpa lekukan
    var animasi = 0;
    var sibuk = false;

    function pusat(i) {
        var r = item[i].getBoundingClientRect();
        var b = bar.getBoundingClientRect();
        return r.left - b.left + r.width / 2;
    }

    function bentuk(px) {
        var W = bar.clientWidth, T = bar.clientHeight;
        if (px === null) return 'M-20,0H' + (W + 20) + 'V' + T + 'H-20Z';
        return 'M-20,0H' + (px - LEBAR) +
            'C' + (px - 30) + ',0 ' + (px - 33) + ',' + KEDALAMAN + ' ' + px + ',' + KEDALAMAN +
            'C' + (px + 33) + ',' + KEDALAMAN + ' ' + (px + 30) + ',0 ' + (px + LEBAR) + ',0' +
            'H' + (W + 20) + 'V' + T + 'H-20Z';
    }

    function gambar(px) {
        x = px;
        jalur.setAttribute('d', bentuk(px));
        if (px === null) { bola.classList.add('tanpa'); return; }
        bola.classList.remove('tanpa');
        bola.style.transform = 'translate3d(' + px + 'px,0,0)';
    }

    function pasangIkon(i) {
        while (bola.firstChild) bola.removeChild(bola.firstChild);
        var s = item[i] && item[i].querySelector('svg');
        if (!s) return;
        var salin = s.cloneNode(true);
        salin.setAttribute('width', '27');
        salin.setAttribute('height', '27');
        salin.setAttribute('stroke-width', '2.2');
        bola.appendChild(salin);
    }

    function halus(t) { // percepatan lalu perlambatan lembut, tanpa memantul
        return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
    }

    function geser(dari, ke, indeksTujuan, durasi, selesai) {
        cancelAnimationFrame(animasi);
        var mulai = null, ganti = false;
        function langkah(waktu) {
            if (mulai === null) mulai = waktu;
            var t = Math.min(1, (waktu - mulai) / durasi);
            gambar(dari + (ke - dari) * halus(t));
            // Ikon di dalam bola memudar, berganti di tengah perjalanan, lalu muncul lagi.
            if (!ganti && t >= 0.5) { ganti = true; pasangIkon(indeksTujuan); }
            if (bola.firstChild) bola.firstChild.style.opacity = String(t < 0.5 ? 1 - t * 2 : (t - 0.5) * 2);
            if (t < 1) { animasi = requestAnimationFrame(langkah); return; }
            gambar(ke);
            if (bola.firstChild) bola.firstChild.style.opacity = '';
            if (selesai) selesai();
        }
        animasi = requestAnimationFrame(langkah);
    }

    function indeksAktif() {
        for (var i = 0; i < item.length; i++) if (item[i].classList.contains('aktif')) return i;
        return -1;
    }

    function awal() {
        var a = indeksAktif();
        if (a < 0) { gambar(null); return; }
        pasangIkon(a);
        var tujuan = pusat(a);
        var lama = null;
        try { lama = sessionStorage.getItem(SIMPAN); sessionStorage.setItem(SIMPAN, String(a)); } catch (e) { /* tidak wajib */ }
        var dari = lama !== null && lama !== '' ? parseInt(lama, 10) : NaN;
        if (gerak && !isNaN(dari) && dari !== a && item[dari] && item[dari].tagName === 'A') {
            gambar(pusat(dari));
            geser(pusat(dari), tujuan, a, 460);
        } else {
            gambar(tujuan);
        }
    }

    // Ketuk tab lain: lekukan bergeser dulu, lalu pindah halaman.
    item.forEach(function (el, i) {
        if (el.tagName !== 'A') return;
        el.addEventListener('click', function (e) {
            if (!gerak || sibuk || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            if (el.classList.contains('aktif') || x === null) return;
            e.preventDefault();
            sibuk = true;
            var lama = indeksAktif();
            if (lama >= 0) item[lama].classList.remove('aktif');
            el.classList.add('aktif');
            try { sessionStorage.setItem(SIMPAN, String(i)); } catch (err) { /* tidak wajib */ }
            geser(x, pusat(i), i, 380, function () { window.location.href = el.href; });
            setTimeout(function () { sibuk = false; }, 2500); // jaga-jaga bila halaman tidak berpindah
        });
    });

    var tunda = 0;
    window.addEventListener('resize', function () {
        clearTimeout(tunda);
        tunda = setTimeout(function () {
            var a = indeksAktif();
            gambar(a < 0 ? null : pusat(a));
        }, 80);
    });
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) { sibuk = false; var a = indeksAktif(); gambar(a < 0 ? null : pusat(a)); }
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', awal); else awal();
})();
