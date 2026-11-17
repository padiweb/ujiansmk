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
        if (nilai === kartu.getAttribute('data-tersimpan')) { status(kartu, ''); return; }

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
            semua('.kor-alasan, header .lencana', kartu).forEach(function (x) { x.remove(); }); // nilai kini dari guru, bukan otomatis
            if (h.j.kunci_dibuka) {
                pesan('Kunci nilai kelas ini terbuka karena nilai diubah. Kunci lagi di Rekap Nilai setelah selesai.');
                var kk = $('korKunci'); if (kk) kk.hidden = true;
            }
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

    // Koreksi otomatis: panggil server berulang (10 jawaban per putaran) sampai semua jawaban bernilai.
    var tombolOto = $('korOtomatis');
    if (tombolOto) tombolOto.addEventListener('click', function () {
        var urlOto = kotak.getAttribute('data-otomatis');
        var kelas = kotak.getAttribute('data-kelas') || '';
        if (!confirm('Nilai semua jawaban yang belum bernilai pada soal ini secara otomatis? Nilai yang sudah Anda isi tidak diubah, dan semua nilai otomatis tetap dapat Anda ubah.')) return;

        var teksAwal = tombolOto.textContent, selesai = 0, menunggu = 0;
        tombolOto.disabled = true; pesan('');
        if ($('korSaran')) $('korSaran').disabled = true;

        function berhenti(teks) {
            tombolOto.disabled = false; tombolOto.textContent = teksAwal;
            if ($('korSaran')) $('korSaran').disabled = false;
            pesan(teks);
        }

        function putaran() {
            tombolOto.textContent = 'Mengoreksi… ' + selesai + ' dinilai';
            var b = new FormData();
            if (kelas) b.append('kelas', kelas);
            kirim(urlOto, b).then(function (h) {
                if (h.status === 429 && h.j.kode === 'tunggu' && menunggu < 6) {
                    menunggu++; tombolOto.textContent = 'Menunggu giliran… (' + selesai + ' dinilai)';
                    setTimeout(putaran, 15000); return;
                }
                if (!h.ok) { berhenti((selesai ? selesai + ' jawaban sudah dinilai. ' : '') + (h.j.pesan || 'Koreksi otomatis gagal. Coba lagi.')); return; }
                menunggu = 0;
                selesai += h.j.diproses || 0;
                if (h.j.sisa > 0 && h.j.diproses > 0) { putaran(); return; }
                if (h.j.sisa > 0) { berhenti(selesai + ' jawaban dinilai; ' + h.j.sisa + ' jawaban belum bisa dinilai otomatis. Nilai manual atau coba lagi.'); if (selesai) setTimeout(function () { location.reload(); }, 2500); return; }
                berhenti(selesai + ' jawaban dinilai otomatis. Memuat ulang…');
                setTimeout(function () { location.reload(); }, 800);
            }).catch(function () { berhenti((selesai ? selesai + ' jawaban sudah dinilai. ' : '') + 'Koneksi bermasalah. Coba lagi.'); });
        }
        putaran();
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
