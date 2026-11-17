/*
 * Tampilan HP: tabel bertanda .tabel-kartu digambar sebagai kartu (CSS). Skrip ini memberi tiap sel label dari judul kolomnya
 * dan menandai sel judul kartu, termasuk untuk baris yang digambar ulang oleh skrip lain (mis. monitor).
 */
(function () {
    'use strict';

    function tandai(tabel) {
        var judul = Array.prototype.map.call(tabel.querySelectorAll('thead th'), function (th) { return (th.textContent || '').trim(); });
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
