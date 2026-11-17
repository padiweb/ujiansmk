/*
 * Tampilan HP: tabel bertanda .tabel-kartu digambar sebagai kartu (CSS). Skrip ini memberi tiap sel label dari judul kolomnya
 * dan menandai sel judul kartu, termasuk untuk baris yang digambar ulang oleh skrip lain (mis. monitor).
 */
(function () {
    'use strict';

    /* Label tiap kolom; judul bertingkat (rowspan/colspan) diurai menjadi "Kelompok · Kolom". */
    function labelKolom(tabel) {
        var baris = tabel.querySelectorAll('thead tr'), peta = [], i, j, k, lebar = 0, hasil = [];
        for (i = 0; i < baris.length; i++) {
            peta[i] = peta[i] || [];
            var kol = 0, th = baris[i].children;
            for (j = 0; j < th.length; j++) {
                while (peta[i][kol]) kol++;
                var rs = parseInt(th[j].getAttribute('rowspan'), 10) || 1;
                var cs = parseInt(th[j].getAttribute('colspan'), 10) || 1;
                var teks = (th[j].textContent || '').replace(/\s+/g, ' ').trim();
                for (var r = 0; r < rs; r++) {
                    peta[i + r] = peta[i + r] || [];
                    for (var c = 0; c < cs; c++) peta[i + r][kol + c] = teks;
                }
                kol += cs;
            }
        }
        for (i = 0; i < peta.length; i++) lebar = Math.max(lebar, (peta[i] || []).length);
        for (k = 0; k < lebar; k++) {
            var bagian = [];
            for (i = 0; i < peta.length; i++) {
                var t = peta[i] && peta[i][k];
                if (t && bagian.indexOf(t) === -1) bagian.push(t);
            }
            hasil.push(bagian.length > 1 ? bagian[0] + ' · ' + bagian[bagian.length - 1] : (bagian[0] || ''));
        }
        return hasil;
    }

    function tandai(tabel) {
        var judul = labelKolom(tabel);
        Array.prototype.forEach.call(tabel.querySelectorAll('tbody tr'), function (tr) {
            if (tr.dataset.kartu === '1') return;
            var sel = tr.children, judulDitandai = false;
            for (var i = 0; i < sel.length; i++) {
                var td = sel[i];
                if (td.tagName !== 'TD' || td.hasAttribute('colspan')) continue;
                var label = judul[i] || '';
                if (!td.hasAttribute('data-label')) td.setAttribute('data-label', label);
                if (!judulDitandai && !td.classList.contains('no') && !td.classList.contains('kanan') && label !== '' && label !== 'No') {
                    td.classList.add('sel-judul');
                    judulDitandai = true;
                }
            }
            tr.dataset.kartu = '1';
        });
    }

    function pasang() {
        Array.prototype.forEach.call(document.querySelectorAll('table.tabel-kartu'), function (tabel) {
            tandai(tabel);
            var badan = tabel.tBodies[0];
            if (badan && window.MutationObserver) {
                new MutationObserver(function () { tandai(tabel); }).observe(badan, { childList: true });
            }
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', pasang); else pasang();
})();
