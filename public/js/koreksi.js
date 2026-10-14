/* Koreksi esai: simpan nilai per jawaban tanpa memuat ulang dan minta saran nilai Otomatis.
 * Teks dari server dipasang lewat textContent. */
(function () {
    'use strict';

    var kotak = document.getElementById('korKotak');
    if (!kotak) return;

    var urlNilai = kotak.getAttribute('data-nilai');
    var urlSaran = kotak.getAttribute('data-saran');
    var token = kotak.getAttribute('data-token');

    function $(id) { return document.getElementById(id); }
    function semua(sel, akar) { return Array.prototype.slice.call((akar || document).querySelectorAll(sel)); }

    function kirim(url, badan) {
        return fetch(url, {
            method: 'POST', credentials: 'same-origin', body: badan,
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, j: j }; });
        });
    }

    function pesan(teks) {
        var e = $('korPesan');
        e.textContent = teks || '';
        e.hidden = !teks;
    }

    function status(kartu, teks, galat) {
        var e = kartu.querySelector('.kor-simpan');
        e.textContent = teks;
        e.className = 'kor-simpan' + (galat ? ' galat' : '');
    }

    function simpan(kartu) {
        var input = kartu.querySelector('.kor-input');
        var nilai = input.value.trim();
        if (nilai === kartu.getAttribute('data-tersimpan')) return;

        var b = new FormData();
        b.append('nilai', nilai);
        status(kartu, 'Menyimpan…');
        kirim(urlNilai + '/' + kartu.getAttribute('data-id') + '/nilai', b).then(function (h) {
            if (!h.ok) {
                var galat = (h.j.pesan) || (h.status === 419 ? 'Sesi habis. Muat ulang halaman.' : 'Gagal menyimpan.');
                status(kartu, galat, true);
                return;
            }
            kartu.setAttribute('data-tersimpan', nilai);
            status(kartu, nilai === '' ? 'Nilai dihapus' : 'Tersimpan');
            kartu.classList.toggle('dinilai', nilai !== '');
            if (h.j.kemajuan) $('korProgres').textContent = h.j.kemajuan.dinilai + '/' + h.j.kemajuan.total;
        }).catch(function () { status(kartu, 'Koneksi bermasalah. Coba lagi.', true); });
    }

    semua('.jawaban-esai').forEach(function (kartu) {
        var input = kartu.querySelector('.kor-input');
        kartu.setAttribute('data-tersimpan', input.value.trim());
        if (input.value.trim() !== '') kartu.classList.add('dinilai');

        input.addEventListener('blur', function () { simpan(kartu); });
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); input.blur(); } });

        semua('.kor-set', kartu).forEach(function (t) {
            t.addEventListener('click', function () { input.value = t.getAttribute('data-nilai'); simpan(kartu); });
        });

        var pakai = kartu.querySelector('.kor-pakai');
        if (pakai) pakai.addEventListener('click', function () {
            input.value = kartu.querySelector('.kor-saran').getAttribute('data-saran');
            simpan(kartu);
        });
    });

    // Nama disembunyikan sebagai bawaan agar penilaian tidak bias.
    var tombolNama = $('korNama');
    tombolNama.addEventListener('click', function () {
        var tampil = this.getAttribute('aria-pressed') !== 'true';
        this.setAttribute('aria-pressed', tampil ? 'true' : 'false');
        this.textContent = tampil ? 'Sembunyikan nama' : 'Tampilkan nama';
        semua('.kor-nama').forEach(function (e) {
            e.textContent = tampil ? '· ' + e.getAttribute('data-nama') : '';
            e.hidden = !tampil;
        });
    });

    var tombolSaran = $('korSaran');
    if (tombolSaran) tombolSaran.addEventListener('click', function () {
        var kartuTarget = semua('.jawaban-esai').filter(function (k) { return k.querySelector('.kor-input').value.trim() === ''; });
        if (!kartuTarget.length) { pesan('Semua jawaban di halaman ini sudah dinilai.'); return; }

        var b = new FormData();
        kartuTarget.forEach(function (k) { b.append('ids[]', k.getAttribute('data-id')); });
        var teksAwal = tombolSaran.textContent;
        tombolSaran.disabled = true; tombolSaran.textContent = 'Meminta saran…'; pesan('');

        kirim(urlSaran, b).then(function (h) {
            tombolSaran.disabled = false; tombolSaran.textContent = teksAwal;
            if (!h.ok) { pesan(h.j.pesan || (h.j.errors && Object.values(h.j.errors)[0][0]) || 'Gagal meminta saran.'); return; }
            kartuTarget.forEach(function (k) {
                var s = h.j.saran[k.getAttribute('data-id')];
                if (!s) return;
                var box = k.querySelector('.kor-saran');
                box.setAttribute('data-saran', String(s.nilai));
                box.querySelector('.kor-saran-nilai').textContent = String(s.nilai);
                box.querySelector('.kor-saran-catatan').textContent = s.catatan || '';
                box.hidden = false;
            });
            pesan('Saran hanya usulan. Periksa setiap jawaban sebelum memakainya.');
        }).catch(function () { tombolSaran.disabled = false; tombolSaran.textContent = teksAwal; pesan('Koneksi bermasalah. Coba lagi.'); });
    });
})();
