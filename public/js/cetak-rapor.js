/* Cetak/Pratinjau rapor: dimuat di iframe tersembunyi lalu langsung membuka dialog cetak (seperti aplikasi lama), tanpa tab baru. */
(function () {
    'use strict';

    var form = document.getElementById('formCetakRapor');
    if (!form) return;
    var pesan = document.getElementById('pesanCetakRapor');

    function tampilkan(teks) {
        if (!pesan) return;
        pesan.textContent = teks;
        pesan.hidden = !teks;
    }

    form.addEventListener('submit', function (e) {
        var tombol = e.submitter;
        var format = tombol ? tombol.value : '';
        // Tombol di baris siswa membawa id siswa; tombol "Kelas" mengosongkannya (semua siswa).
        form.elements.siswa.value = (tombol && tombol.getAttribute('data-siswa')) || '';
        if (format !== 'cetak' && format !== 'pratinjau') return; // Word: unduhan biasa di tab baru
        e.preventDefault();

        var params = new URLSearchParams(new FormData(form));
        params.set('format', format);
        params.set('bingkai', '1');

        var lama = document.getElementById('bingkaiCetakRapor');
        if (lama) lama.remove();
        tampilkan('');
        if (tombol) tombol.disabled = true;

        var bingkai = document.createElement('iframe');
        bingkai.id = 'bingkaiCetakRapor';
        bingkai.setAttribute('aria-hidden', 'true');
        bingkai.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
        bingkai.onload = function () {
            if (tombol) tombol.disabled = false;
            try {
                var body = bingkai.contentDocument && bingkai.contentDocument.body;
                if (body && body.getAttribute('data-rapor') === '1') {
                    setTimeout(function () { bingkai.contentWindow.focus(); bingkai.contentWindow.print(); }, 400);
                } else {
                    tampilkan((body && body.getAttribute('data-galat')) || 'Rapor tidak dapat dibuka. Muat ulang halaman lalu coba lagi.');
                }
            } catch (err) {
                tampilkan('Rapor tidak dapat dibuka. Muat ulang halaman lalu coba lagi.');
            }
        };
        bingkai.src = form.getAttribute('action') + '?' + params.toString();
        document.body.appendChild(bingkai);
    });
})();
