/* Monitor pengawas: memperbarui tabel peserta tiap 5 detik dan menjalankan tindakan pengawas.
 * Semua teks dari server dipasang lewat textContent (tidak pernah innerHTML). */
(function () {
    'use strict';

    var tabel = document.getElementById('monTabel');
    if (!tabel) return;

    var CFG = {
        selang: 5000,          // ms antar pembaruan (halaman terlihat)
        selangSembunyi: 15000, // ms antar pembaruan (tab di latar belakang)
    };
    var aksiBoleh = (tabel.getAttribute('data-boleh') || '').split(',');
    function boleh(kode) { return aksiBoleh.indexOf(kode) !== -1; }
    var totalSoal = parseInt(tabel.getAttribute('data-total'), 10) || 0; // 0 = tiap baris membawa total_soal sendiri (monitor ruang)
    var tubuh = document.getElementById('monIsi');
    var data = JSON.parse(document.getElementById('monAwal').textContent);
    var sebelumnya = null;   // peta id → baris pada pembaruan sebelumnya (tanpa bunyi pada muatan pertama)
    var gagal = 0;
    var bunyiNyala = false;
    var audio = null;
    var timer = null;

    var NAMA_STATUS = { belum: 'Belum mulai', berlangsung: 'Mengerjakan', selesai: 'Selesai', diblokir: 'Terblokir' };
    var NAMA_ALASAN = { kirim: 'dikirim siswa', waktu_habis: 'waktu habis', dipaksa: 'dipaksa pengawas' };

    function $(id) { return document.getElementById(id); }

    function el(tag, kelas, teks) {
        var e = document.createElement(tag);
        if (kelas) e.className = kelas;
        if (teks !== undefined && teks !== null) e.textContent = teks;
        return e;
    }

    function usia(d) {
        if (d === null || d === undefined) return '—';
        if (d < 60) return d + ' dtk';
        if (d < 3600) return Math.floor(d / 60) + ' mnt';
        return Math.floor(d / 3600) + ' jam';
    }

    // ── Bunyi ───────────────────────────────────────────────────────────────

    function bip(nada, durasi, kali) {
        if (!bunyiNyala || !audio) return;
        try {
            for (var i = 0; i < kali; i++) {
                var o = audio.createOscillator();
                var g = audio.createGain();
                o.type = 'sine';
                o.frequency.value = nada;
                var t = audio.currentTime + i * (durasi + 0.08);
                g.gain.setValueAtTime(0.0001, t);
                g.gain.exponentialRampToValueAtTime(0.25, t + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, t + durasi);
                o.connect(g); g.connect(audio.destination);
                o.start(t); o.stop(t + durasi + 0.02);
            }
        } catch (e) { /* bunyi tidak wajib */ }
    }

    $('monBunyi').addEventListener('click', function () {
        bunyiNyala = !bunyiNyala;
        if (bunyiNyala && !audio) {
            try { audio = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { audio = null; }
        }
        if (audio && audio.state === 'suspended') audio.resume();
        this.textContent = 'Bunyi: ' + (bunyiNyala ? 'nyala' : 'mati');
        this.setAttribute('aria-pressed', bunyiNyala ? 'true' : 'false');
        bip(660, 0.12, 1); // contoh bunyi saat dinyalakan
    });

    // ── Penyaringan dan penggambaran ─────────────────────────────────────────

    function lolos(b) {
        var s = $('monStatus').value;
        var ruang = $('monRuang') ? $('monRuang').value : '';
        var cari = $('monCari').value.trim().toLowerCase();

        if (s === 'melanggar' ? !(b.pelanggaran > 0 && b.status !== 'selesai')
            : s === 'senyap' ? !b.senyap
            : s !== '' && b.status !== s) return false;
        if (ruang !== '' && String(b.ruang_id) !== ruang) return false;
        if (cari !== '' && (b.nama + ' ' + (b.nisn || '')).toLowerCase().indexOf(cari) === -1) return false;
        return true;
    }

    function tombolAksi(b, kode, label, kelas) {
        var t = el('button', 'tombol kecil ' + (kelas || 'garis'), label);
        t.type = 'button';
        t.addEventListener('click', function () { mintaAksi(b, kode); });
        return t;
    }

    function baris(b) {
        var tr = el('tr', 'mon-baris');
        tr.setAttribute('data-id', b.id);
        if (b.status === 'diblokir') tr.classList.add('mon-blokir');
        else if (b.pelanggaran > 0 && b.status !== 'selesai') tr.classList.add('mon-langgar');
        if (b.senyap) tr.classList.add('mon-senyap');
        if (b.status === 'selesai' || b.status === 'belum') tr.classList.add('mon-redup');

        var tdNama = el('td', 'mon-nama');
        tdNama.setAttribute('data-inisial', inisial(b.nama)); // dipakai CSS untuk lingkaran inisial di tampilan HP
        tdNama.appendChild(el('strong', null, b.nama));
        tdNama.appendChild(el('small', 'bantuan', (b.nisn || '') + (b.ujian ? ' · ' + b.ujian : '') + (b.ruang ? ' · ' + b.ruang + (b.meja ? ' / meja ' + b.meja : '') : '')));
        tr.appendChild(tdNama);

        tr.appendChild(el('td', null, b.kelas || '—'));

        var tdStatus = el('td');
        tdStatus.appendChild(el('span', 'lencana' + (b.status === 'diblokir' ? ' kuning' : (b.status === 'berlangsung' ? '' : ' pucat')), NAMA_STATUS[b.status] || b.status));
        if (b.senyap) tdStatus.appendChild(el('span', 'lencana kuning', 'Tidak ada kabar'));
        if (b.status === 'selesai' && b.alasan) tdStatus.appendChild(el('small', 'bantuan', NAMA_ALASAN[b.alasan] || b.alasan));
        if (b.tambahan > 0) tdStatus.appendChild(el('small', 'bantuan', '+' + b.tambahan + ' menit · sampai ' + (b.batas || '—')));
        tr.appendChild(tdStatus);

        var tdP = el('td', b.status === 'belum' || !b.pelanggaran ? 'mon-nol' : 'mon-pel');
        if (b.status === 'belum') tdP.textContent = '—';
        else {
            tdP.appendChild(el('strong', null, String(b.pelanggaran)));
            if (b.p_jenis) tdP.appendChild(el('small', 'bantuan', b.p_jenis + ' · ' + b.p_waktu));
        }
        tr.appendChild(tdP);

        var tdJ = el('td', b.status === 'belum' ? 'mon-nol' : 'mon-jawab');
        if (b.status === 'belum') tdJ.textContent = '—';
        else {
            var total = b.total_soal !== undefined ? b.total_soal : totalSoal;
            tdJ.appendChild(el('span', null, b.dijawab + '/' + total));
            var bar = el('div', 'mon-bar');
            var isi = el('span');
            isi.style.width = (total ? Math.min(100, Math.round(b.dijawab / total * 100)) : 0) + '%';
            bar.appendChild(isi);
            tdJ.appendChild(bar);
        }
        tr.appendChild(tdJ);

        tr.appendChild(el('td', 'mon-kabar', usia(b.kabar)));

        var tdPl = el('td', 'mon-perangkat');
        if (b.sistem_operasi || b.nama_peramban) {
            tdPl.appendChild(el('span', null, [b.sistem_operasi, b.nama_peramban].filter(Boolean).join(' · ')));
            var modeNama = { aplikasi: 'Aplikasi sekolah', pwa: 'PWA terpasang', peramban: 'Peramban' }[b.mode_tampil];
            if (modeNama) tdPl.appendChild(el('small', 'bantuan', modeNama));
        } else {
            tdPl.appendChild(document.createTextNode(b.platform === 'aplikasi' ? 'Aplikasi' : (b.platform ? 'Peramban' : '—')));
        }
        if (b.perangkat_dilepas) tdPl.appendChild(el('small', 'bantuan', 'perangkat dilepas, menunggu masuk ulang'));
        tr.appendChild(tdPl);

        var tdA = el('td', 'kanan');
        var kotak = el('div', 'mon-aksi');
        if (b.status === 'diblokir' && boleh('aktifkan')) kotak.appendChild(tombolAksi(b, 'aktifkan', 'Aktifkan kembali', 'hp-utama'));
        if ((b.status === 'berlangsung' || b.status === 'diblokir') && boleh('reset')) kotak.appendChild(tombolAksi(b, 'reset', 'Reset perangkat'));
        if (b.status !== 'selesai' && boleh('waktu')) kotak.appendChild(tombolAksi(b, 'waktu', 'Tambah waktu'));
        if ((b.status === 'berlangsung' || b.status === 'diblokir') && boleh('selesai')) kotak.appendChild(tombolAksi(b, 'selesai', 'Paksa selesai', 'bahaya'));
        tdA.appendChild(kotak);
        tr.appendChild(tdA);

        // Tampilan HP: ketuk kartu membuka lembar rincian (tombol di dalam kartu tetap bekerja sendiri).
        tr.addEventListener('click', function (e) {
            if (!layarHP.matches || e.target.closest('button, a, input, select')) return;
            bukaLembar(b);
        });

        return tr;
    }

    function inisial(nama) {
        var k = String(nama || '').trim().split(/\s+/).filter(Boolean);
        if (!k.length) return '?';
        return (k[0].charAt(0) + (k.length > 1 ? k[1].charAt(0) : '')).toUpperCase();
    }

    // ── Lembar rincian siswa (khusus HP) ─────────────────────────────────────

    var layarHP = window.matchMedia('(max-width: 768px)');
    var lembar = null;       // { akar, id }

    function baris2(k, v) {
        var d = el('div', 'lembar-kv');
        d.appendChild(el('span', null, k));
        d.appendChild(el('b', null, v));
        return d;
    }

    function tutupLembar() {
        if (lembar) { lembar.akar.remove(); lembar = null; document.documentElement.classList.remove('lembar-terbuka'); }
    }

    function bukaLembar(b) {
        tutupLembar();
        var akar = el('div', 'lembar');
        akar.setAttribute('role', 'dialog'); akar.setAttribute('aria-modal', 'true'); akar.setAttribute('aria-label', 'Rincian ' + b.nama);
        var tirai = el('div', 'lembar-tirai');
        tirai.addEventListener('click', tutupLembar);
        var kotak = el('div', 'lembar-kotak');
        kotak.appendChild(el('div', 'lembar-gagang'));

        var kepala = el('div', 'lembar-kepala');
        kepala.appendChild(el('span', 'lembar-av' + (b.status === 'diblokir' ? ' kuning' : ''), inisial(b.nama)));
        var nm = el('div', 'lembar-nama');
        nm.appendChild(el('strong', null, b.nama));
        nm.appendChild(el('small', null, [b.kelas, b.nisn ? 'NISN ' + b.nisn : ''].filter(Boolean).join(' · ')));
        kepala.appendChild(nm);
        kepala.appendChild(el('span', 'lencana' + (b.status === 'diblokir' ? ' kuning' : (b.status === 'berlangsung' ? '' : ' pucat')), NAMA_STATUS[b.status] || b.status));
        kotak.appendChild(kepala);

        var total = b.total_soal !== undefined ? b.total_soal : totalSoal;
        var perangkat = [b.sistem_operasi, b.nama_peramban].filter(Boolean).join(' · ') || (b.platform === 'aplikasi' ? 'Aplikasi' : (b.platform ? 'Peramban' : '—'));
        var modeNama = { aplikasi: 'aplikasi sekolah', pwa: 'PWA terpasang', peramban: 'peramban' }[b.mode_tampil];
        kotak.appendChild(baris2('Perangkat', perangkat + (modeNama ? ' (' + modeNama + ')' : '')));
        if (b.ruang) kotak.appendChild(baris2('Ruang', b.ruang + (b.meja ? ' / meja ' + b.meja : '')));
        if (b.status !== 'belum') kotak.appendChild(baris2('Soal terjawab', b.dijawab + ' dari ' + total));
        kotak.appendChild(baris2('Kabar terakhir', usia(b.kabar) + (b.kabar !== null && b.kabar !== undefined ? ' lalu' : '')));
        if (b.tambahan > 0) kotak.appendChild(baris2('Tambahan waktu', '+' + b.tambahan + ' menit (sampai ' + (b.batas || '—') + ')'));
        if (b.status === 'selesai' && b.alasan) kotak.appendChild(baris2('Selesai karena', NAMA_ALASAN[b.alasan] || b.alasan));
        if (b.perangkat_dilepas) kotak.appendChild(baris2('Perangkat', 'dilepas, menunggu masuk ulang'));
        if (b.status !== 'belum') kotak.appendChild(baris2('Pelanggaran', b.pelanggaran + (b.p_jenis ? ' · terakhir ' + b.p_jenis + ' (' + b.p_waktu + ')' : '')));

        var aksi = el('div', 'lembar-aksi');
        function tmb(ke, kode, label, kelas) {
            var t = el('button', 'tombol ' + kelas, label); t.type = 'button';
            t.addEventListener('click', function () { tutupLembar(); mintaAksi(b, kode); });
            ke.appendChild(t);
        }
        var dua = el('div', 'lembar-dua');
        var aktif = b.status === 'berlangsung' || b.status === 'diblokir';
        if (b.status === 'diblokir' && boleh('aktifkan')) tmb(aksi, 'aktifkan', 'Aktifkan kembali', 'lembar-besar');
        if (b.status !== 'selesai' && boleh('waktu')) tmb(dua, 'waktu', 'Tambah waktu', 'garis');
        if (aktif && boleh('reset')) tmb(dua, 'reset', 'Reset perangkat', 'garis');
        if (aktif && boleh('selesai')) tmb(dua, 'selesai', 'Paksa selesai', 'garis bahaya');
        if (dua.children.length) aksi.appendChild(dua);
        var tutup = el('button', 'lembar-tutup', 'Tutup'); tutup.type = 'button';
        tutup.addEventListener('click', tutupLembar);
        aksi.appendChild(tutup);
        kotak.appendChild(aksi);

        akar.appendChild(tirai); akar.appendChild(kotak);
        document.body.appendChild(akar);
        document.documentElement.classList.add('lembar-terbuka');
        lembar = { akar: akar, id: b.id };
    }
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') tutupLembar(); });

    function gambar() {
        var tampil = data.peserta.filter(lolos);
        tubuh.textContent = '';
        if (!tampil.length) {
            var tr = el('tr'); var td = el('td', 'kosong', data.peserta.length ? 'Tidak ada peserta yang cocok dengan saringan.' : 'Belum ada peserta.');
            td.colSpan = 8; tr.appendChild(td); tubuh.appendChild(tr);
        } else {
            var f = document.createDocumentFragment();
            tampil.forEach(function (b) { f.appendChild(baris(b)); });
            tubuh.appendChild(f);
        }

        Object.keys(data.ringkasan).forEach(function (k) {
            var e = document.querySelector('[data-ringkas="' + k + '"]');
            if (e) e.textContent = data.ringkasan[k];
        });
        $('monWaktu').textContent = data.waktu;
    }

    function periksaBaru() {
        var peta = {};
        data.peserta.forEach(function (b) { peta[b.id] = b; });

        if (sebelumnya) {
            var langgar = false, blokir = false;
            data.peserta.forEach(function (b) {
                var lama = sebelumnya[b.id];
                if (!lama) return;
                if (b.status === 'diblokir' && lama.status !== 'diblokir') blokir = true;
                else if (b.pelanggaran > lama.pelanggaran) langgar = true;
            });
            if (blokir) bip(440, 0.25, 3);
            else if (langgar) bip(880, 0.15, 2);
        }
        sebelumnya = peta;
    }

    // ── Pengambilan data ────────────────────────────────────────────────────

    function jadwalkan() {
        clearTimeout(timer);
        timer = setTimeout(ambil, document.hidden ? CFG.selangSembunyi : CFG.selang);
    }

    function ambil() {
        fetch(tabel.getAttribute('data-url'), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) {
                if (r.status === 401 || r.status === 419) { location.reload(); throw new Error('masuk'); }
                if (r.status === 409) { location.href = tabel.getAttribute('data-url').replace(/\/data$/, '').replace(/\/\d+$/, '').replace(/\/ruang$/, ''); throw new Error('tidak_aktif'); }
                if (!r.ok) throw new Error('gagal');
                return r.json();
            })
            .then(function (d) {
                data = d; gagal = 0; $('monKoneksi').hidden = true;
                periksaBaru(); gambar();
            })
            .catch(function (e) {
                if (e && (e.message === 'masuk' || e.message === 'tidak_aktif')) return;
                gagal++; if (gagal >= 2) $('monKoneksi').hidden = false;
            })
            .then(jadwalkan);
    }

    document.addEventListener('visibilitychange', function () { if (!document.hidden) { clearTimeout(timer); ambil(); } });
    ['monStatus', 'monRuang'].forEach(function (id) { if ($(id)) $(id).addEventListener('change', gambar); });
    $('monCari').addEventListener('input', gambar);

    // ── Tindakan ────────────────────────────────────────────────────────────

    var dlg = { b: null, kode: null };
    var TEKS = {
        aktifkan: ['Aktifkan kembali', function (b) { return b.nama + ' akan dapat melanjutkan ujian. Hitungan pelanggaran diatur ulang ke 0, jadi siswa mendapat kesempatan penuh lagi.'; }],
        reset: ['Reset perangkat', function (b) { return 'Perangkat ' + b.nama + ' saat ini dilepas. Siswa harus memasukkan token ujian lagi di perangkat yang akan dipakai; jawaban dan sisa waktu tetap.'; }],
        waktu: ['Tambah waktu', function (b) { return 'Tambahkan waktu mengerjakan untuk ' + b.nama + '.'; }],
        selesai: ['Paksa selesai', function (b) { return 'Ujian ' + b.nama + ' diakhiri sekarang dan dinilai dari jawaban yang sudah tersimpan. Tindakan ini tidak dapat dibatalkan.'; }],
    };

    function bukaDialog(b, kode) {
        dlg.b = b; dlg.kode = kode;
        $('monDialogJudul').textContent = TEKS[kode][0];
        $('monDialogIsi').textContent = TEKS[kode][1](b);
        $('monDialogMenit').hidden = kode !== 'waktu';
        $('monDialogGalat').hidden = true;
        $('monDialog').hidden = false;
        (kode === 'waktu' ? $('monMenit') : $('monDialogOk')).focus();
    }

    function tutupDialog() { $('monDialog').hidden = true; dlg.b = null; }
    function mintaAksi(b, kode) { bukaDialog(b, kode); }

    $('monDialogBatal').addEventListener('click', tutupDialog);
    $('monDialog').addEventListener('keydown', function (e) { if (e.key === 'Escape') tutupDialog(); });

    $('monDialogOk').addEventListener('click', function () {
        if (!dlg.b) return;
        var tombol = this, b = dlg.b, kode = dlg.kode;
        var badan = new FormData();
        if (kode === 'waktu') badan.append('menit', $('monMenit').value);
        tombol.disabled = true;

        // Monitor ruang: tiap baris membawa jadwal_id; monitor jadwal: alamat dasar sudah memuat jadwalnya.
        var alamat = b.jadwal_id ? tabel.getAttribute('data-aksi') + '/' + b.jadwal_id + '/sesi/' + b.id + '/' + kode
            : tabel.getAttribute('data-aksi') + '/' + b.id + '/' + kode;
        fetch(alamat, {
            method: 'POST', body: badan, credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': tabel.getAttribute('data-token'), 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, j: j, status: r.status }; });
        }).then(function (h) {
            tombol.disabled = false;
            if (!h.ok) {
                var pesan = h.j.pesan || (h.j.errors && h.j.errors.menit && h.j.errors.menit[0]) || (h.status === 419 ? 'Sesi Anda habis. Muat ulang halaman.' : 'Tindakan gagal. Coba lagi.');
                $('monDialogGalat').textContent = pesan; $('monDialogGalat').hidden = false;
                return;
            }
            tutupDialog();
            catatRiwayat(h.j.catatan);
            clearTimeout(timer); ambil();
        }).catch(function () {
            tombol.disabled = false;
            $('monDialogGalat').textContent = 'Koneksi bermasalah. Coba lagi.'; $('monDialogGalat').hidden = false;
        });
    });

    function catatRiwayat(teks) {
        if (!teks) return;
        var ul = $('monRiwayat');
        var kosong = ul.querySelector('.kosong-riwayat');
        if (kosong) kosong.remove();
        ul.insertBefore(el('li', null, teks), ul.firstChild);
        while (ul.children.length > 8) ul.removeChild(ul.lastChild);
    }

    // Tampilan HP: saringan status berupa deretan pil (dipasang dari pilihan <select>, yang disembunyikan CSS di HP).
    (function () {
        var pilih = $('monStatus');
        if (!pilih) return;
        var baris = el('div', 'pil-baris');
        baris.setAttribute('role', 'group'); baris.setAttribute('aria-label', 'Saring status');
        Array.prototype.forEach.call(pilih.options, function (o) {
            var p = el('button', 'pil' + (o.value === pilih.value ? ' aktif' : ''), o.value === '' ? 'Semua' : o.textContent);
            p.type = 'button'; p.setAttribute('aria-pressed', o.value === pilih.value ? 'true' : 'false');
            p.addEventListener('click', function () {
                pilih.value = o.value;
                Array.prototype.forEach.call(baris.children, function (x) { x.classList.remove('aktif'); x.setAttribute('aria-pressed', 'false'); });
                p.classList.add('aktif'); p.setAttribute('aria-pressed', 'true');
                gambar();
            });
            baris.appendChild(p);
        });
        var form = pilih.closest('form');
        form.parentNode.insertBefore(baris, form);
    })();

    // Mulai: gambar data awal dari halaman, lalu perbarui berkala.
    periksaBaru();
    gambar();
    jadwalkan();
})();
