/* Susun indikator kisi-kisi otomatis: memanggil server berulang (10 soal per putaran) sampai semua soal punya indikator. */
(function () {
    'use strict';

    var tombol = document.getElementById('kisiSusun');
    if (!tombol) return;

    var label = tombol.querySelector('span');
    var teksAwal = label.textContent;

    tombol.addEventListener('click', function () {
        if (!confirm('Susun materi dan indikator kisi-kisi otomatis untuk soal yang belum memilikinya? Anda dapat mengubahnya per soal di editor.')) return;

        var selesai = 0, menunggu = 0;
        tombol.disabled = true;

        function berhenti(teks, muatUlang) {
            tombol.disabled = false;
            label.textContent = teks;
            if (muatUlang) setTimeout(function () { location.reload(); }, 1800);
        }

        function putaran() {
            label.textContent = 'Menyusun… ' + selesai + ' soal selesai';
            fetch(tombol.getAttribute('data-url'), {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': tombol.getAttribute('data-token'), 'X-Requested-With': 'XMLHttpRequest' },
            }).then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, j: j }; });
            }).then(function (h) {
                if (h.status === 429 && h.j.kode === 'tunggu' && menunggu < 6) {
                    menunggu++; label.textContent = 'Menunggu giliran… (' + selesai + ' soal selesai)';
                    setTimeout(putaran, 15000); return;
                }
                if (!h.ok) { berhenti((h.j.pesan || 'Gagal menyusun kisi-kisi.') + (selesai ? ' (' + selesai + ' soal sudah tersusun)' : ''), selesai > 0); return; }
                menunggu = 0;
                selesai += h.j.diproses || 0;
                if (h.j.sisa > 0 && h.j.diproses > 0) { putaran(); return; }
                if (h.j.sisa > 0) { berhenti(selesai + ' soal tersusun; ' + h.j.sisa + ' soal belum bisa disusun otomatis. Isi manual di editor soal.', true); return; }
                berhenti(selesai + ' soal tersusun. Memuat ulang…', true);
            }).catch(function () { berhenti('Koneksi bermasalah. Coba lagi.', selesai > 0); });
        }
        putaran();
    });

    label.textContent = teksAwal;
})();
