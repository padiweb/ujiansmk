/*
 * Pengerjaan ujian di peramban siswa.
 *
 * Prinsip (lihat docs/RANCANGAN_UJIAN.md bagian 9):
 *  - Paket soal diunduh SEKALI; seluruh pengerjaan berjalan di peramban.
 *  - Jawaban ditulis dulu ke localStorage, lalu disinkronkan berkala dalam satu permintaan.
 *    Selang ditentukan server (20-90 dtk menurut beban) dan sinkron sekaligus menjadi detak.
 *  - Waktu mengikuti jam server: sisa waktu = batas_server - (jam_klien + selisih_server).
 *  - Server yang memutuskan segalanya (waktu, status, nilai); halaman ini hanya menampilkan.
 */
(function () {
    'use strict';

    var akar = document.getElementById('ujian');
    if (!akar) return;

    var cfg = {
        sesi: akar.dataset.sesi,
        perangkat: akar.dataset.perangkat,
        paket: akar.dataset.paket,
        sinkron: akar.dataset.sinkron,
        pelanggaran: akar.dataset.pelanggaran,
        kirim: akar.dataset.kirim,
        hasil: akar.dataset.hasil
    };

    var $ = function (id) { return document.getElementById(id); };
    var kunciLokal = 'ujian:' + cfg.sesi;

    var S = {
        soal: [],            // urutan khusus siswa ini
        jawaban: {},         // soal_id -> {o, t, w}
        kotor: {},           // soal_id -> true (belum tersinkron)
        selisih: 0,          // jam_server - jam_klien (ms)
        batas: 0,            // batas selesai (ms, jam server)
        bisaKirim: 0,        // saat paling awal boleh mengumpulkan (ms, jam server); 0 = tanpa pembatasan
        selang: 20,          // detik, ditentukan server
        status: 'berlangsung',
        terkunci: false,     // waktu habis / terkirim / diblokir: masukan dikunci
        selesai: false,
        sedangKirim: false,
        gagalBeruntun: 0,
        pengatur: null
    };

    /* ── Penyimpanan lokal ─────────────────────────────────────────────── */
    function simpanLokal() {
        try { localStorage.setItem(kunciLokal, JSON.stringify({ jawaban: S.jawaban, kotor: S.kotor })); } catch (e) { /* penuh/diblokir: tidak fatal */ }
    }
    function bacaLokal() {
        try { return JSON.parse(localStorage.getItem(kunciLokal) || 'null'); } catch (e) { return null; }
    }

    /* ── Jaringan ──────────────────────────────────────────────────────── */
    function minta(url, opsi) {
        opsi = opsi || {};
        var h = { 'Accept': 'application/json', 'X-Perangkat': cfg.perangkat };
        var badan;
        if (opsi.badan) { h['Content-Type'] = 'application/json'; badan = JSON.stringify(opsi.badan); }
        return fetch(url, {
            method: opsi.metode || (badan ? 'POST' : 'GET'),
            headers: h, body: badan, credentials: 'omit', cache: 'no-store', keepalive: !!opsi.keepalive
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) { return { status: r.status, data: data }; });
        });
    }

    function terapkanKeadaan(k) {
        if (!k) return;
        if (typeof k.waktu_server === 'number') S.selisih = k.waktu_server - Date.now();
        if (typeof k.batas_selesai === 'number') S.batas = k.batas_selesai;
        S.bisaKirim = typeof k.bisa_kirim === 'number' ? k.bisa_kirim : 0;
        if (typeof k.selang === 'number') S.selang = Math.max(10, Math.min(120, k.selang));
        if (typeof k.pelanggaran === 'number') tampilPelanggaran(k.pelanggaran);
        if (k.status) S.status = k.status;

        if (k.status === 'selesai') { S.selesai = true; location.replace(cfg.hasil); }
        else if (k.status === 'diblokir') kunci('Ujian Anda diblokir', 'Anda terdeteksi melanggar aturan ujian. Jawaban Anda tetap tersimpan. Temui pengawas untuk mengaktifkan kembali.', true);
        else if (S.terkunci && S.alasanKunci === 'blokir') bukaKunci();
    }

    /* ── Sinkron berkala ───────────────────────────────────────────────── */
    function adaKotor() { for (var k in S.kotor) return true; return false; }

    function kumpulkanKotor() {
        var kirim = {}, id;
        for (id in S.kotor) { if (S.jawaban[id]) kirim[id] = S.jawaban[id]; }
        return kirim;
    }

    function sinkron(opsi) {
        opsi = opsi || {};
        if (S.selesai || S.sedangKirim) return Promise.resolve();
        var dikirim = kumpulkanKotor();
        var salinan = {};
        for (var id in dikirim) salinan[id] = dikirim[id].w;

        setSimpan(adaKotor() ? 'Menyimpan…' : null);
        return minta(cfg.sinkron, { badan: { jawaban: dikirim }, keepalive: !!opsi.keepalive }).then(function (r) {
            if (r.status === 401) return galatFatal();
            if (r.status === 429 || r.status >= 500) throw new Error('sibuk');
            S.gagalBeruntun = 0;
            // Hanya bersihkan yang tidak berubah selama pengiriman.
            for (var id in salinan) { if (S.jawaban[id] && S.jawaban[id].w === salinan[id]) delete S.kotor[id]; }
            simpanLokal();
            terapkanKeadaan(r.data);
            setSimpan(adaKotor() ? 'Menyimpan…' : null);
        }).catch(function () {
            S.gagalBeruntun++;
            setSimpan('Belum tersimpan — sambungan bermasalah', true);
        });
    }

    function jadwalkan() {
        clearTimeout(S.pengatur);
        // Jeda acak +/-15% agar ratusan siswa tidak menyinkron bersamaan; mundur bertahap bila server sibuk.
        var dasar = S.selang * 1000 * (1 + Math.min(S.gagalBeruntun, 4));
        var acak = dasar * (0.85 + Math.random() * 0.3);
        S.pengatur = setTimeout(function () { sinkron().then(jadwalkan); }, acak);
    }

    /* ── Tampilan: soal ────────────────────────────────────────────────── */
    function buatSoal(daftar) {
        var form = $('formSoal'), nav = $('navSoal');
        var nomorPg = 0, nomorEsai = 0, bagianEsai = false;

        daftar.forEach(function (s, i) {
            if (s.tipe === 'esai' && !bagianEsai) {
                bagianEsai = true;
                var judul = document.createElement('h2');
                judul.className = 'judul-bagian';
                judul.textContent = 'Soal esai';
                form.appendChild(judul);
            }
            var no = s.tipe === 'pg' ? ++nomorPg : ++nomorEsai;

            var kartu = document.createElement('section');
            kartu.className = 'soal-ujian';
            kartu.id = 'soal-' + s.id;

            var tanya = document.createElement('div');
            tanya.className = 'pertanyaan';
            var nomor = document.createElement('span');
            nomor.className = 'nomor';
            nomor.textContent = no + '.';
            var teks = document.createElement('span');
            teks.textContent = ' ' + s.pertanyaan; // textContent: tidak pernah dijalankan sebagai HTML
            tanya.appendChild(nomor); tanya.appendChild(teks);
            kartu.appendChild(tanya);

            if (s.gambar) {
                var img = document.createElement('img');
                img.className = 'soal-gambar';
                img.loading = 'lazy'; img.alt = 'Gambar soal'; img.src = s.gambar;
                img.draggable = false;
                kartu.appendChild(img);
            }

            if (s.tipe === 'pg') {
                var grup = document.createElement('div');
                grup.className = 'opsi-grup';
                s.opsi.forEach(function (o, idx) {
                    var label = document.createElement('label');
                    label.className = 'opsi-label';
                    var radio = document.createElement('input');
                    radio.type = 'radio'; radio.name = 'soal_' + s.id; radio.value = o.label;
                    radio.checked = !!(S.jawaban[s.id] && S.jawaban[s.id].o === o.label);
                    radio.addEventListener('change', function () { catat(s.id, { o: o.label }); });
                    var huruf = document.createElement('b');
                    huruf.textContent = String.fromCharCode(65 + idx) + '.'; // huruf tampil menurut posisi
                    var isi = document.createElement('span');
                    isi.textContent = o.teks;
                    label.appendChild(radio); label.appendChild(huruf); label.appendChild(isi);
                    grup.appendChild(label);
                });
                kartu.appendChild(grup);
            } else {
                var area = document.createElement('textarea');
                area.placeholder = 'Tulis jawaban Anda di sini…';
                area.maxLength = 5000;
                area.value = (S.jawaban[s.id] && S.jawaban[s.id].t) || '';
                var tunda;
                area.addEventListener('input', function () {
                    clearTimeout(tunda);
                    tunda = setTimeout(function () { catat(s.id, { t: area.value }); }, 400);
                });
                area.addEventListener('blur', function () { clearTimeout(tunda); catat(s.id, { t: area.value }); });
                kartu.appendChild(area);
            }
            form.appendChild(kartu);

            var tombol = document.createElement('button');
            tombol.type = 'button'; tombol.className = 'nav-no'; tombol.id = 'nav-' + s.id;
            tombol.textContent = i + 1;
            tombol.addEventListener('click', function () { kartu.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
            nav.appendChild(tombol);
            perbaruiNav(s.id);
        });
    }

    function terjawab(id) {
        var j = S.jawaban[id];
        return !!(j && ((j.o !== undefined && j.o !== null && j.o !== '') || (j.t && j.t.trim() !== '')));
    }
    function perbaruiNav(id) {
        var el = $('nav-' + id);
        if (el) el.classList.toggle('terjawab', terjawab(id));
    }

    function catat(id, isi) {
        if (S.terkunci || S.selesai) return;
        var j = S.jawaban[id] || {};
        if ('o' in isi) j.o = isi.o;
        if ('t' in isi) { if (j.t === isi.t) return; j.t = isi.t; }
        j.w = Date.now();
        S.jawaban[id] = j;
        S.kotor[id] = true;
        simpanLokal();
        perbaruiNav(id);
        setSimpan('Menyimpan…');
    }

    function setSimpan(teks, masalah) {
        var el = $('statusSimpan');
        el.textContent = teks || 'Tersimpan';
        el.classList.toggle('masalah', !!masalah);
        el.classList.toggle('menunggu', !!teks && !masalah);
    }

    function tampilPelanggaran(n) {
        var el = $('pelanggaran');
        el.hidden = n < 1;
        if (n >= 1) el.textContent = 'Pelanggaran ' + n;
    }

    /* ── Waktu ─────────────────────────────────────────────────────────── */
    function sisaDetik() { return Math.max(0, Math.round((S.batas - (Date.now() + S.selisih)) / 1000)); }

    function tikTok() {
        if (S.selesai || !S.batas) return;
        var l = sisaDetik();
        var j = Math.floor(l / 3600), m = Math.floor(l % 3600 / 60), d = l % 60;
        var el = $('timer');
        el.textContent = (j ? j + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (d < 10 ? '0' : '') + d;
        el.classList.toggle('peringatan', l <= 300 && l > 60);
        el.classList.toggle('bahaya', l <= 60);
        if (l <= 0 && !S.terkunci) waktuHabis();
        aturTombolKirim();
    }

    /* Tombol Kirim baru aktif setelah waktu minimal (diatur admin) agar siswa tidak terburu-buru. */
    function sisaTungguKirim() {
        return S.bisaKirim ? Math.max(0, Math.ceil((S.bisaKirim - (Date.now() + S.selisih)) / 1000)) : 0;
    }

    function teksTunggu(d) {
        var m = Math.floor(d / 60), s = d % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function aturTombolKirim() {
        var tombol = $('tombolKirim');
        if (!tombol) return;
        var tunggu = sisaTungguKirim();
        var label = tombol.querySelector('.label-kirim');
        if (!label) {
            label = document.createElement('span');
            label.className = 'label-kirim';
            label.textContent = tombol.textContent.trim();
            tombol.querySelectorAll('svg').forEach(function (v) { v.remove(); });
            tombol.textContent = '';
            tombol.appendChild(label);
        }
        tombol.disabled = tunggu > 0;
        label.textContent = tunggu > 0 ? 'Kirim jawaban (aktif dalam ' + teksTunggu(tunggu) + ')' : 'Kirim jawaban';
        var info = $('infoTunggu');
        if (info) {
            info.hidden = tunggu <= 0;
            info.textContent = tunggu > 0 ? 'Gunakan waktu ini untuk memeriksa kembali semua jawaban Anda. Tombol kirim akan aktif otomatis.' : '';
        }
    }

    function waktuHabis() {
        kunci('Waktu habis', 'Jawaban Anda sedang dikirim…', false);
        // Jeda acak 0-8 dtk meredam lonjakan saat banyak siswa selesai bersamaan (server menerima sampai 90 dtk).
        setTimeout(function () { kirim(true); }, Math.random() * 8000);
    }

    /* ── Kirim ─────────────────────────────────────────────────────────── */
    function semuaJawaban() {
        var h = {};
        for (var id in S.jawaban) h[id] = S.jawaban[id];
        return h;
    }

    function kirim(otomatis) {
        if (S.selesai || S.sedangKirim) return;
        S.sedangKirim = true;
        minta(cfg.kirim, { badan: { jawaban: semuaJawaban() } }).then(function (r) {
            if (r.status === 401) { S.sedangKirim = false; return galatFatal(); }
            if (r.status === 422 && r.data && r.data.kode === 'terlalu_cepat') {
                // Belum waktunya mengumpulkan: buka kembali isian dan tampilkan pesan.
                S.sedangKirim = false;
                terapkanKeadaan(r.data);
                bukaKunci();
                lapisan('Belum bisa dikumpulkan', r.data.pesan || 'Periksa kembali jawaban Anda.', [{ teks: 'Periksa lagi', aksi: tutupLapisan }]);
                return;
            }
            if (r.status >= 400) throw new Error('gagal');
            S.selesai = true;
            try { localStorage.removeItem(kunciLokal); } catch (e) { /* abaikan */ }
            window.onbeforeunload = null;
            location.replace(cfg.hasil);
        }).catch(function () {
            S.sedangKirim = false;
            kunci(otomatis ? 'Waktu habis' : 'Pengiriman gagal',
                'Jawaban belum terkirim karena sambungan bermasalah. Mencoba lagi…', false);
            setTimeout(function () { kirim(otomatis); }, 3000 + Math.random() * 3000);
        });
    }

    function konfirmasiKirim() {
        if (S.terkunci) return;
        if (sisaTungguKirim() > 0) {
            lapisan('Belum bisa dikumpulkan', 'Periksa kembali jawaban Anda. Tombol kirim aktif dalam ' + teksTunggu(sisaTungguKirim()) + '.', [{ teks: 'Periksa lagi', aksi: tutupLapisan }]);
            return;
        }
        var kosong = S.soal.filter(function (s) { return !terjawab(s.id); }).length;
        lapisan('Kirim jawaban?',
            kosong > 0 ? kosong + ' soal belum Anda jawab. Jawaban tidak bisa diubah setelah dikirim.' : 'Semua soal sudah dijawab. Jawaban tidak bisa diubah setelah dikirim.',
            [
                { teks: 'Periksa lagi', garis: true, aksi: tutupLapisan },
                { teks: 'Ya, kirim', aksi: function () { tutupLapisan(); kunci('Mengirim…', 'Mohon tunggu, jangan tutup halaman ini.', false); kirim(false); } }
            ]);
    }

    /* ── Lapisan (dialog layar penuh) ──────────────────────────────────── */
    function lapisan(judul, isi, tombol) {
        $('lapisanJudul').textContent = judul;
        $('lapisanIsi').textContent = isi;
        var aksi = $('lapisanAksi');
        aksi.textContent = '';
        (tombol || []).forEach(function (t) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'tombol kecil' + (t.garis ? ' garis' : '');
            b.textContent = t.teks;
            b.addEventListener('click', t.aksi);
            aksi.appendChild(b);
        });
        $('lapisan').hidden = false;
    }
    function tutupLapisan() { $('lapisan').hidden = true; }

    function kunci(judul, isi, blokir) {
        S.terkunci = true;
        S.alasanKunci = blokir ? 'blokir' : 'lain';
        akar.classList.add('terkunci');
        document.querySelectorAll('#formSoal input, #formSoal textarea').forEach(function (e) { e.disabled = true; });
        // Gerbang layar penuh tidak boleh menutupi pesan blokir / waktu habis.
        var gerbang = $('gerbang'); if (gerbang) gerbang.hidden = true;
        lapisan(judul, isi, []);
    }
    function bukaKunci() {
        S.terkunci = false;
        S.alasanKunci = null;
        akar.classList.remove('terkunci');
        document.querySelectorAll('#formSoal input, #formSoal textarea').forEach(function (e) { e.disabled = false; });
        tutupLapisan();
    }

    function galatFatal() {
        S.selesai = true; // hentikan sinkron berikutnya
        clearTimeout(S.pengatur);
        lapisan('Sesi ujian tidak dikenali', 'Perangkat ini tidak lagi terdaftar untuk ujian ini. Jawaban yang sudah tersinkron aman. Muat ulang halaman, atau panggil pengawas.',
            [{ teks: 'Muat ulang', aksi: function () { location.reload(); } }]);
    }

    /* ── Mulai ─────────────────────────────────────────────────────────── */
    function mulai() {
        minta(cfg.paket).then(function (r) {
            if (r.status === 401) return galatFatal();
            if (r.status !== 200) throw new Error('paket');

            var p = r.data;
            S.soal = p.soal;
            // Gabungkan jawaban server dengan yang belum tersinkron di penyimpanan lokal (yang lokal menang).
            var j = p.jawaban || {}, id;
            for (id in j) S.jawaban[id] = { o: j[id].o, t: j[id].t, w: 0 };
            var lokal = bacaLokal();
            if (lokal) {
                for (id in (lokal.kotor || {})) {
                    if (lokal.jawaban && lokal.jawaban[id]) { S.jawaban[id] = lokal.jawaban[id]; S.kotor[id] = true; }
                }
            }
            terapkanKeadaan(p.keadaan);

            $('muat').hidden = true;
            $('formSoal').hidden = false;
            $('areaKirim').hidden = false;
            buatSoal(S.soal);
            // Sesi yang sudah diblokir saat halaman dimuat: soal baru dirender, jadi kunci ulang masukannya.
            if (S.terkunci) document.querySelectorAll('#formSoal input, #formSoal textarea').forEach(function (e) { e.disabled = true; });
            tikTok();
            setInterval(tikTok, 1000);
            if (adaKotor()) sinkron();
            jadwalkan();
        }).catch(function () {
            $('muat').textContent = 'Soal belum bisa dimuat. Mencoba lagi…';
            setTimeout(mulai, 2500 + Math.random() * 2500);
        });
    }

    /* ── Pemicu sinkron segera ─────────────────────────────────────────── */
    document.addEventListener('visibilitychange', function () { if (document.hidden) sinkron({ keepalive: true }); });
    window.addEventListener('pagehide', function () { sinkron({ keepalive: true }); });
    window.addEventListener('online', function () { sinkron(); });
    window.onbeforeunload = function (e) { if (!S.selesai) { e.preventDefault(); e.returnValue = ''; } };
    $('tombolKirim').addEventListener('click', konfirmasiKirim);

    // Titik kait untuk modul pengaman (lapis anti curang) yang dimuat terpisah.
    window.Ujian = {
        keadaan: function () { return { terkunci: S.terkunci, selesai: S.selesai, status: S.status }; },
        sinkron: sinkron,
        minta: minta,
        alamat: cfg,
        terapkanKeadaan: terapkanKeadaan,
        kunci: kunci
    };

    mulai();
})();
