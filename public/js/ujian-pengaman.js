/*
 * Lapis anti curang di peramban (lihat docs/RANCANGAN_UJIAN.md bagian 7).
 *
 * PENTING: kode ini hanya MELAPOR. Hitungan pelanggaran, peringatan, dan blokir diputuskan server;
 * menonaktifkan berkas ini di peramban tidak membebaskan siswa dari apa pun yang sudah tercatat dan
 * tidak menyembunyikan apa yang dilihat pengawas. Pendeteksi berbasis web dapat dilewati siswa yang
 * paham teknis; lapis yang kuat adalah pembungkus Android (tahap 4).
 *
 * Semua ambang batas ada di objek KONFIG di bawah, agar mudah disetel setelah uji di perangkat nyata.
 */
(function () {
    'use strict';

    var akar = document.getElementById('ujian');
    if (!akar || !window.Ujian) return;

    var KONFIG = {
        jedaPindahTabMs: 1200,        // hilang dari layar lebih lama dari ini = pindah aplikasi/tab
        sematanLepasMs: 8000,         // tersembunyi selama ini = sematan layar kemungkinan dilepas (hanya info)
        cekFokusMs: 200,              // polling document.hasFocus()
        cekFokusBeruntun: 2,          // 2 x 200 ms = 400 ms tanpa fokus
        tundaBlurMs: 250,
        kekebalanPapanKetikMs: 800,   // papan ketik Android membuat jendela kehilangan fokus sesaat
        rasioSplit: 0.65,             // lebar jendela / lebar layar di bawah ini = layar terbagi
        penyempitanViewport: 0.08,    // lebar area tampilan menyusut lebih dari 8%
        tundaViewportMs: 1200,        // lama menunggu sebelum mengambil lebar acuan
        jedaTertutupMs: 800,          // header tak terlihat selama ini = tertutup lapisan lain
        bekuMs: 8000,                 // selisih pengatur waktu 1 dtk > ini = halaman terhenti
        cekPipMs: 2000,
        cekDevtoolsMs: 1500,
        selisihDevtoolsPx: 200,
        jedaSejenisKlienMs: 4000,     // klien tidak mengulang laporan sejenis lebih cepat dari ini
        jedaInfoKlienMs: 60000,       // laporan informasi (mis. devtools) paling sering sekali per menit
        riwayatPalsu: 30              // jumlah entri riwayat untuk menahan tombol Kembali
    };

    var $ = function (id) { return document.getElementById(id); };
    var U = window.Ujian;
    var seluler = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);
    var aplikasi = /ESMK-CBT|UjianSMKAltan/.test(navigator.userAgent);

    var S = {
        layarPenuhDiminta: false,    // siswa pernah masuk layar penuh lewat gerbang
        terakhirLapor: {},
        perisaiAktif: false, perisaiMulai: 0, perisaiPewaktu: null,
        papanKetik: { aktif: false, keluarPada: 0 }
    };

    function berjalan() {
        var k = U.keadaan();
        return !k.selesai && !k.terkunci;
    }
    function dalamKekebalanPapanKetik() {
        return S.papanKetik.aktif || (Date.now() - S.papanKetik.keluarPada) < KONFIG.kekebalanPapanKetikMs;
    }

    /* ── Pelaporan ke server ───────────────────────────────────────────── */
    function lapor(jenis, keterangan, info, jedaKlien) {
        if (!berjalan()) return;
        var sekarang = Date.now(), jeda = jedaKlien || KONFIG.jedaSejenisKlienMs;
        if (S.terakhirLapor[jenis] && sekarang - S.terakhirLapor[jenis] < jeda) return;
        S.terakhirLapor[jenis] = sekarang;

        U.minta(U.alamat.pelanggaran, { badan: { jenis: jenis, keterangan: keterangan || null, info: info || {} }, keepalive: true })
            .then(function (r) {
                if (r.status !== 200) return;
                var d = r.data;
                U.terapkanKeadaan(d); // memunculkan layar blokir bila server memutuskan demikian
                if (d.dihitung && d.status !== 'diblokir') tampilPeringatan(d.ke, d.batas, keterangan);
            })
            .catch(function () { /* sinkron berikutnya tetap membawa status terbaru dari server */ });
    }

    /* ── Peringatan (hilang sendiri) ───────────────────────────────────── */
    var pewaktuPeringatan = null;
    function tampilPeringatan(ke, batas, alasan) {
        var sisa = Math.max(0, batas - ke);
        $('peringatanJudul').textContent = 'Peringatan ' + ke + ' dari ' + (batas - 1);
        $('peringatanIsi').textContent = (alasan || 'Pelanggaran aturan ujian') + '. ' +
            (sisa <= 1 ? 'Ini peringatan TERAKHIR: satu pelanggaran lagi akan memblokir ujian Anda.' : 'Jangan ulangi. Pelanggaran ke-' + batas + ' akan memblokir ujian Anda.');
        var el = $('peringatan');
        el.hidden = false;
        clearTimeout(pewaktuPeringatan);
        pewaktuPeringatan = setTimeout(function () { el.hidden = true; }, 10000);
    }
    $('peringatanTutup').addEventListener('click', function () { $('peringatan').hidden = true; });

    /* ── Perisai: aplikasi/jendela mengambang ──────────────────────────── */
    function mulaiPerisai(alasan) {
        if (S.perisaiAktif || !berjalan()) return;
        S.perisaiAktif = true;
        S.perisaiMulai = Date.now();
        $('perisai').hidden = false;
        $('perisaiDurasi').textContent = '0 dtk';
        clearInterval(S.perisaiPewaktu);
        S.perisaiPewaktu = setInterval(function () {
            $('perisaiDurasi').textContent = Math.floor((Date.now() - S.perisaiMulai) / 1000) + ' dtk';
        }, 500);
        lapor('aplikasi_mengambang', 'Aplikasi atau jendela lain terdeteksi', { alasan: alasan });
    }
    function hentikanPerisai() {
        if (!S.perisaiAktif) return;
        S.perisaiAktif = false;
        clearInterval(S.perisaiPewaktu);
        $('perisai').hidden = true;
    }

    /* ── Gerbang layar penuh ───────────────────────────────────────────── */
    var adaLayarPenuh = !!(document.documentElement.requestFullscreen || document.documentElement.webkitRequestFullscreen);
    function sedangLayarPenuh() { return !!(document.fullscreenElement || document.webkitFullscreenElement); }

    function tampilGerbang(alasan) {
        $('gerbangAlasan').textContent = alasan || 'Ujian ini wajib dikerjakan dalam layar penuh.';
        $('gerbang').hidden = false;
    }
    function masukLayarPenuh() {
        var el = document.documentElement;
        var minta = el.requestFullscreen || el.webkitRequestFullscreen;
        var selesai = function () { S.layarPenuhDiminta = true; $('gerbang').hidden = true; kuncikanRiwayat(); };
        if (!minta) return selesai();
        var hasil = minta.call(el);
        if (hasil && hasil.then) hasil.then(selesai).catch(selesai); else selesai();
    }
    $('gerbangMulai').addEventListener('click', masukLayarPenuh);

    ['fullscreenchange', 'webkitfullscreenchange'].forEach(function (e) {
        document.addEventListener(e, function () {
            if (!berjalan() || !S.layarPenuhDiminta) return;
            if (!sedangLayarPenuh()) {
                tampilGerbang('Anda keluar dari layar penuh. Masuk kembali untuk melanjutkan.');
                lapor('keluar_layar_penuh', 'Keluar dari layar penuh');
            } else {
                $('gerbang').hidden = true;
            }
        });
    });

    $('gerbangMulai').disabled = false;
    $('gerbangMulai').textContent = 'Masuk layar penuh';
    if (!aplikasi && adaLayarPenuh && !sedangLayarPenuh()) tampilGerbang();
    else { S.layarPenuhDiminta = true; $('gerbang').hidden = true; }

    /* ── Riwayat: tombol Kembali ───────────────────────────────────────── */
    var riwayatDikunci = false;
    function kuncikanRiwayat() {
        if (riwayatDikunci) return;
        riwayatDikunci = true;
        for (var i = 0; i < KONFIG.riwayatPalsu; i++) history.pushState({ u: 1 }, '', location.href);
    }
    window.addEventListener('popstate', function () {
        if (!riwayatDikunci) return;
        history.pushState({ u: 1 }, '', location.href);
        lapor('tombol_kembali', 'Menekan tombol kembali');
    });
    kuncikanRiwayat();

    /* ── Papan ketik Android: jangan dianggap aplikasi mengambang ─────── */
    document.addEventListener('focusin', function (e) {
        var t = ((e.target || {}).tagName || '').toUpperCase();
        if (t === 'TEXTAREA' || t === 'INPUT') { S.papanKetik.aktif = true; if (S.perisaiAktif) hentikanPerisai(); }
    }, true);
    document.addEventListener('focusout', function (e) {
        var t = ((e.target || {}).tagName || '').toUpperCase();
        if (t === 'TEXTAREA' || t === 'INPUT') { S.papanKetik.aktif = false; S.papanKetik.keluarPada = Date.now(); }
    }, true);

    /* ── Sensor 1: pindah aplikasi/tab (Page Visibility) ───────────────── */
    var tersembunyiSejak = 0, pewaktuSematan = null;
    document.addEventListener('visibilitychange', function () {
        if (!berjalan()) return;
        if (document.hidden) {
            tersembunyiSejak = Date.now();
            hentikanPerisai();
            clearTimeout(pewaktuSematan);
            pewaktuSematan = setTimeout(function () {
                if (document.hidden) lapor('sematan_dilepas', 'Halaman tersembunyi lebih dari 8 detik', {}, KONFIG.jedaInfoKlienMs);
            }, KONFIG.sematanLepasMs);
        } else {
            clearTimeout(pewaktuSematan);
            if (tersembunyiSejak) {
                var selisih = Date.now() - tersembunyiSejak;
                tersembunyiSejak = 0;
                if (selisih > KONFIG.jedaPindahTabMs) lapor('pindah_tab', 'Pindah aplikasi atau tab (' + Math.floor(selisih / 1000) + ' dtk)', { durasi: Math.round(selisih / 1000) });
            }
        }
    });

    /* ── Sensor 2 dan 3: jendela kehilangan fokus (blur dan polling) ───── */
    window.addEventListener('blur', function () {
        if (!berjalan()) return;
        setTimeout(function () {
            if (!berjalan() || document.hidden) return;
            if (!document.hasFocus() && !dalamKekebalanPapanKetik()) mulaiPerisai('blur');
        }, KONFIG.tundaBlurMs);
    });
    window.addEventListener('focus', function () { hentikanPerisai(); });

    var tanpaFokus = 0;
    setInterval(function () {
        if (!berjalan() || document.hidden || dalamKekebalanPapanKetik()) { tanpaFokus = 0; return; }
        if (!document.hasFocus()) {
            tanpaFokus++;
            if (tanpaFokus === KONFIG.cekFokusBeruntun) mulaiPerisai('polling');
        } else {
            if (tanpaFokus >= KONFIG.cekFokusBeruntun) hentikanPerisai();
            tanpaFokus = 0;
        }
    }, KONFIG.cekFokusMs);

    /* ── Sensor 4 dan 11: ukuran jendela (layar terbagi) ───────────────── */
    function periksaUkuranJendela() {
        if (!berjalan() || dalamKekebalanPapanKetik()) return;
        var lebarLayar = screen.width || 0;
        if (!lebarLayar) return;
        // Pada layar sentuh dipakai lebar tampilan; pada desktop dipakai lebar jendela luar.
        var lebar = seluler ? window.innerWidth : (window.outerWidth || window.innerWidth);
        var rasio = lebar / lebarLayar;
        if (rasio < KONFIG.rasioSplit) lapor('split_screen', 'Layar terbagi atau jendela mengecil', { lebar: lebar, layar_lebar: lebarLayar, rasio: rasio });
    }
    var pewaktuUkuran = null;
    window.addEventListener('resize', function () { clearTimeout(pewaktuUkuran); pewaktuUkuran = setTimeout(periksaUkuranJendela, 400); });
    setInterval(periksaUkuranJendela, 2000);

    /* ── Sensor 5: area tampilan (VisualViewport) menyempit ────────────── */
    if (window.visualViewport) {
        var lebarAcuan = 0;
        setTimeout(function () { lebarAcuan = visualViewport.width; }, KONFIG.tundaViewportMs);
        visualViewport.addEventListener('resize', function () {
            if (!berjalan() || !lebarAcuan || dalamKekebalanPapanKetik()) return;
            if (visualViewport.scale > 1.02) return; // zoom jari bukan jendela mengambang
            if ((lebarAcuan - visualViewport.width) > lebarAcuan * KONFIG.penyempitanViewport) {
                mulaiPerisai('viewport');
                lapor('viewport_menyempit', 'Area tampilan menyempit', { lebar: visualViewport.width, rasio: visualViewport.width / lebarAcuan });
            } else if (S.perisaiAktif && visualViewport.width >= lebarAcuan * 0.97) {
                hentikanPerisai();
            }
        });
    }

    /* ── Sensor 6: halaman terhenti (layar mati, proses dibekukan) ─────── */
    var tikTerakhir = Date.now();
    setInterval(function () {
        var sekarang = Date.now(), selisih = sekarang - tikTerakhir;
        tikTerakhir = sekarang;
        if (berjalan() && !document.hidden && selisih > KONFIG.bekuMs) {
            lapor('halaman_beku', 'Halaman terhenti ' + Math.floor(selisih / 1000) + ' detik', { durasi: Math.round(selisih / 1000) });
        }
    }, 1000);

    /* ── Sensor 7: alat pengembang (hanya informasi untuk pengawas) ────── */
    if (!seluler) {
        setInterval(function () {
            if (!berjalan() || sedangLayarPenuh()) return;
            var lebarBeda = (window.outerWidth || 0) - window.innerWidth, tinggiBeda = (window.outerHeight || 0) - window.innerHeight;
            if (lebarBeda > KONFIG.selisihDevtoolsPx || tinggiBeda > KONFIG.selisihDevtoolsPx + 60) {
                lapor('devtools', 'Ukuran jendela menunjukkan alat pengembang terbuka', { lebar: lebarBeda, tinggi: tinggiBeda }, KONFIG.jedaInfoKlienMs);
            }
        }, KONFIG.cekDevtoolsMs);
    }

    /* ── Sensor 8: header tertutup lapisan lain (mis. panel ekstensi) ──── */
    (function () {
        var header = document.querySelector('.ujian-atas');
        if (!header || !window.IntersectionObserver || !('isVisible' in IntersectionObserverEntry.prototype)) return;
        var tertutupSejak = 0, pewaktu = null;
        function lapisanKitaTerbuka() { return !$('gerbang').hidden || !$('perisai').hidden || !$('lapisan').hidden || !$('peringatan').hidden; }
        new IntersectionObserver(function (entries) {
            var e = entries[entries.length - 1];
            if (!berjalan() || lapisanKitaTerbuka() || document.hidden) { tertutupSejak = 0; clearTimeout(pewaktu); return; }
            if (e.intersectionRatio > 0.5 && e.isVisible === false) {
                if (!tertutupSejak) {
                    tertutupSejak = Date.now();
                    pewaktu = setTimeout(function () {
                        if (tertutupSejak && !lapisanKitaTerbuka()) lapor('tertutup_overlay', 'Layar tertutup lapisan lain', { durasi: KONFIG.jedaTertutupMs / 1000 });
                    }, KONFIG.jedaTertutupMs);
                }
            } else { tertutupSejak = 0; clearTimeout(pewaktu); }
        }, { threshold: [0, 0.5, 1], trackVisibility: true, delay: 200 }).observe(header);
    })();

    /* ── Sensor 9: Picture-in-Picture ──────────────────────────────────── */
    setInterval(function () {
        if (berjalan() && document.pictureInPictureElement) lapor('pip', 'Mode Picture-in-Picture aktif');
    }, KONFIG.cekPipMs);

    /* ── Sensor 10: halaman ditinggalkan (hanya informasi) ─────────────── */
    window.addEventListener('pagehide', function () {
        lapor('halaman_ditinggalkan', 'Halaman ditutup atau dimuat ulang', {}, 1000);
    });

    /* ── Tab/jendela ganda ─────────────────────────────────────────────── */
    (function () {
        if (!window.BroadcastChannel) return;
        var kanal;
        try { kanal = new BroadcastChannel('ujian_' + akar.dataset.sesi); } catch (e) { return; }
        kanal.onmessage = function (e) { if (berjalan() && e.data && e.data.t === 'ada') lapor('tab_ganda', 'Ujian terbuka di tab atau jendela lain'); };
        var umumkan = function () { try { kanal.postMessage({ t: 'ada' }); } catch (e) { /* abaikan */ } };
        window.addEventListener('focus', function () { if (berjalan()) umumkan(); });
        setInterval(function () { if (berjalan()) umumkan(); }, 10000);
        umumkan();
    })();

    /* ── Masukan: papan ketik, salin, klik kanan, seleksi ──────────────── */
    document.addEventListener('keydown', function (e) {
        var sasaran = ((e.target || {}).tagName || '').toUpperCase();
        var diTeks = sasaran === 'TEXTAREA';
        if (e.ctrlKey || e.metaKey || e.altKey) {
            // Di kolom esai tetap boleh memilih semua dan urungkan/ulangi mengetik.
            if (diTeks && (e.ctrlKey || e.metaKey) && !e.altKey && ['a', 'z', 'y'].indexOf((e.key || '').toLowerCase()) !== -1) return;
            e.preventDefault();
            return;
        }
        if (/^F\d+$/i.test(e.key) && e.key.toLowerCase() !== 'f11') e.preventDefault();
        if (e.key === 'Escape' || e.key === 'Meta' || e.key === 'ContextMenu') e.preventDefault();
    }, true);
    document.addEventListener('keyup', function (e) { if (e.key === 'PrintScreen') lapor('screenshot', 'Menekan tombol PrintScreen'); }, true);
    ['contextmenu', 'dragstart', 'copy', 'cut', 'paste'].forEach(function (nama) {
        document.addEventListener(nama, function (e) { e.preventDefault(); }, true);
    });
    document.addEventListener('selectstart', function (e) {
        var t = ((e.target && e.target.tagName) || '').toUpperCase();
        if (t !== 'TEXTAREA' && t !== 'INPUT') e.preventDefault();
    }, true);

    // Jalur sentuh di tepi kiri/kanan ditutup agar gestur geser dari tepi tidak ikut menyentuh isi soal.
    ['kiri', 'kanan'].forEach(function (sisi) {
        var el = document.createElement('div');
        el.className = 'penjaga-tepi ' + sisi;
        ['touchstart', 'touchmove'].forEach(function (n) { el.addEventListener(n, function (e) { e.preventDefault(); }, { passive: false }); });
        akar.appendChild(el);
    });

    /* ── Jembatan aplikasi Android (tahap 4) ───────────────────────────── */
    // informasi = true: hanya dicatat untuk pengawas (mis. siswa melanjutkan tanpa sematan layar), tidak dihitung sebagai pelanggaran.
    window.reportMobileViolation = function (alasan, informasi) {
        lapor(informasi === true ? 'sematan_dilepas' : 'aplikasi_android',
            String(alasan || 'Overlay atau jendela mengambang terdeteksi (aplikasi)').slice(0, 200), {}, 1500);
    };

    window.Pengaman = { lapor: lapor, KONFIG: KONFIG, periksaUkuranJendela: periksaUkuranJendela };
})();
