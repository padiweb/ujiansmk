/* Absensi dari EduWeb terisi otomatis tanpa klik: dipanggil saat halaman dibuka lalu berkala.
   Kotak yang sedang Anda ketik atau sudah Anda ubah (belum disimpan) tidak ditimpa. */
(function () {
    'use strict';

    var penanda = document.getElementById('statusEduweb');
    if (!penanda) return;
    var url = penanda.getAttribute('data-url');
    var jeda = 20000;      // detik antar pemeriksaan
    var jedaGalat = 60000; // lebih jarang bila EduWeb tidak terjangkau
    var timer = null;

    function tulis(teks, galat) {
        penanda.textContent = teks;
        penanda.classList.toggle('galat', !!galat);
    }

    function terapkan(data) {
        var berubah = 0;
        Object.keys(data).forEach(function (id) {
            ['sakit', 'izin', 'alpa'].forEach(function (k) {
                var kotak = document.querySelector('input[name="rapor[' + id + '][' + k + ']"]');
                if (!kotak || kotak.disabled) return;
                var baru = String(data[id][k]);
                if (kotak === document.activeElement) return;                // sedang diketik
                if (kotak.value !== kotak.defaultValue) return;               // sudah diubah wali, belum disimpan
                if (kotak.value === baru) return;
                kotak.value = baru;
                kotak.defaultValue = baru;
                kotak.classList.add('baru-eduweb');
                setTimeout(function () { kotak.classList.remove('baru-eduweb'); }, 2500);
                berubah++;
            });
        });
        return berubah;
    }

    function periksa() {
        clearTimeout(timer);
        var lanjut = jeda;
        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('http ' + r.status);
                return r.json();
            })
            .then(function (j) {
                if (j.status === 'final') { tulis('Rapor final', false); lanjut = 0; return; }
                if (j.status === 'galat') {
                    tulis('EduWeb tidak terjangkau: ' + (j.pesan || ''), true);
                    lanjut = jedaGalat;
                    return;
                }
                var n = terapkan(j.data || {});
                tulis('Tersambung ke EduWeb · diperbarui ' + (j.waktu || '') + (n ? ' · ' + n + ' isian berubah' : ''), false);
            })
            .catch(function () {
                tulis('Tidak bisa memeriksa EduWeb', true);
                lanjut = jedaGalat;
            })
            .then(function () {
                if (lanjut > 0 && !document.hidden) timer = setTimeout(periksa, lanjut);
            });
    }

    // Tab yang tersembunyi tidak memeriksa; saat kembali dibuka, langsung periksa.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) periksa();
        else clearTimeout(timer);
    });

    tulis('Memeriksa EduWeb…', false);
    periksa();
})();
