/*
 * Bilah tab bawah (HP): garis bilah melengkung di bawah tab terpilih dan ikon tab itu muncul sebagai bola biru yang
 * keluar dari lekukan. Saat tab lain diketuk, lekukan dan bola bergeser ke sana sementara halaman berpindah.
 * Hanya tampilan: tautan tetap tautan biasa, jadi tetap berfungsi bila skrip ini gagal.
 */
(function () {
    'use strict';

    var bar = document.querySelector('nav.bawah');
    if (!bar) return;
    var jalur = bar.querySelector('.bawah-putih');
    var lekuk = bar.querySelector('.bawah-lekuk');
    var bola = bar.querySelector('.bawah-bola');
    if (!jalur || !lekuk || !bola) return;

    var item = Array.prototype.slice.call(bar.querySelectorAll('.bawah-a'));
    var gerak = !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    var KEDALAMAN = 34;   // kedalaman lekukan (px)
    var LEBAR = 40;       // setengah lebar lekukan di tepi atas (px)
    var TURUN = 800;      // bilah digambar jauh ke bawah agar tidak pernah ada celah di bawahnya, berapa pun tinggi bilah
    var SIMPAN = 'bawah-idx';

    var x = null;         // pusat lekukan saat ini (px dari kiri); null = tanpa lekukan
    var animasi = 0;
    var pemuat = 0;       // pengatur waktu penanda "memuat"

    function pusat(i) {
        var r = item[i].getBoundingClientRect();
        var b = bar.getBoundingClientRect();
        return r.left - b.left + r.width / 2;
    }

    function lengkung(px) {
        return 'C' + (px - 28) + ',0 ' + (px - 30) + ',' + KEDALAMAN + ' ' + px + ',' + KEDALAMAN +
            'C' + (px + 30) + ',' + KEDALAMAN + ' ' + (px + 28) + ',0 ' + (px + LEBAR) + ',0';
    }

    function bentuk(px) {
        var W = bar.clientWidth;
        if (px === null) return 'M-20,0H' + (W + 20) + 'V' + TURUN + 'H-20Z';
        return 'M-20,0H' + (px - LEBAR) + lengkung(px) + 'H' + (W + 20) + 'V' + TURUN + 'H-20Z';
    }

    // Isi lekukan dengan warna latar halaman agar isi halaman yang bergulir di belakang tidak tembus pandang.
    function isiLekuk(px) {
        return px === null ? '' : 'M' + (px - LEBAR) + ',0' + lengkung(px) + 'Z';
    }

    function gambar(px) {
        x = px;
        jalur.setAttribute('d', bentuk(px));
        lekuk.setAttribute('d', isiLekuk(px));
        if (px === null) { bola.classList.add('tanpa'); return; }
        bola.classList.remove('tanpa');
        bola.style.transform = 'translate3d(' + px + 'px,0,0)';
    }

    function pasangIkon(i) {
        while (bola.firstChild) bola.removeChild(bola.firstChild);
        var s = item[i] && item[i].querySelector('svg');
        if (!s) return;
        var salin = s.cloneNode(true);
        salin.setAttribute('width', '24');
        salin.setAttribute('height', '24');
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

    // Gelembung pemuat di tengah halaman (bukan di bilah): lingkaran putih berisi cincin biru yang berputar.
    var muat = null;
    function tampilMuat() {
        if (!muat) {
            muat = document.createElement('div');
            muat.className = 'muat-halaman';
            muat.setAttribute('role', 'status');
            muat.setAttribute('aria-live', 'polite');
            var gelembung = document.createElement('span');
            gelembung.className = 'muat-gelembung';
            gelembung.setAttribute('aria-hidden', 'true');
            var teks = document.createElement('span');
            teks.className = 'muat-teks';
            teks.textContent = 'Memuat halaman';
            muat.appendChild(gelembung);
            muat.appendChild(teks);
            document.body.appendChild(muat);
        }
        muat.classList.add('tampil');
    }
    function sembunyiMuat() { if (muat) muat.classList.remove('tampil'); }

    function indeksAktif() {
        for (var i = 0; i < item.length; i++) if (item[i].classList.contains('aktif')) return i;
        return -1;
    }

    var asli = -1; // tab yang aktif menurut halaman ini (sebelum bergeser karena ketukan)

    function awal() {
        var a = indeksAktif();
        asli = a;
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

    // Ketuk tab lain: halaman langsung berpindah (tidak menunggu), sementara lekukan dan bola bergeser pada halaman lama
    // sebagai umpan balik seketika. Posisi baru dicatat supaya halaman tujuan langsung tampil di posisi akhir tanpa mengulang gerakan.
    item.forEach(function (el, i) {
        if (el.tagName !== 'A') return;
        el.addEventListener('click', function (e) {
            if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            try { sessionStorage.setItem(SIMPAN, String(i)); } catch (err) { /* tidak wajib */ }
            if (!gerak || el.classList.contains('aktif') || x === null) return;
            var lama = indeksAktif();
            if (lama >= 0) item[lama].classList.remove('aktif');
            el.classList.add('aktif');
            geser(x, pusat(i), i, 260, null);
            // Bila halaman tujuan belum tiba dalam sekejap, gelembung pemuat muncul di tengah halaman sampai halaman berpindah.
            clearTimeout(pemuat);
            pemuat = setTimeout(tampilMuat, 140);
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
        if (!e.persisted) return;
        clearTimeout(pemuat);
        sembunyiMuat();
        // Kembali ke halaman ini lewat tombol kembali: pulihkan tab aktif menurut halaman ini.
        item.forEach(function (el, i) { el.classList.toggle('aktif', i === asli); });
        if (asli >= 0) {
            pasangIkon(asli);
            try { sessionStorage.setItem(SIMPAN, String(asli)); } catch (err) { /* tidak wajib */ }
        }
        gambar(asli < 0 ? null : pusat(asli));
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', awal); else awal();
})();
