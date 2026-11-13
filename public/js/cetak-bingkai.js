/* Cetak tanpa tab baru: tautan atau formulir bertanda data-cetak dimuat di bingkai tersembunyi, lalu dialog cetak langsung terbuka
   (seperti aplikasi lama). Dokumen cetak harus memberi tanda <body data-rapor="1">. Unduhan Word tidak melewati skrip ini. */
(function () {
    'use strict';

    function pesan(teks) {
        var el = document.getElementById('pesanCetakBingkai');
        if (!el) {
            el = document.createElement('div');
            el.id = 'pesanCetakBingkai';
            el.setAttribute('role', 'alert');
            el.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:2000;max-width:90vw;padding:12px 18px;border-radius:10px;background:#FEF3C7;color:#92400E;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.18)';
            document.body.appendChild(el);
        }
        el.textContent = teks;
        el.hidden = !teks;
        if (teks) setTimeout(function () { el.hidden = true; }, 6000);
    }

    function cetak(url, selesai) {
        var sambung = url.indexOf('?') === -1 ? '?' : '&';
        var lama = document.getElementById('bingkaiCetak');
        if (lama) lama.remove();
        pesan('');

        var bingkai = document.createElement('iframe');
        bingkai.id = 'bingkaiCetak';
        bingkai.setAttribute('aria-hidden', 'true');
        bingkai.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
        bingkai.onload = function () {
            if (selesai) selesai();
            try {
                var body = bingkai.contentDocument && bingkai.contentDocument.body;
                if (body && body.getAttribute('data-rapor') === '1') {
                    setTimeout(function () { bingkai.contentWindow.focus(); bingkai.contentWindow.print(); }, 400);
                } else {
                    pesan((body && body.getAttribute('data-galat')) || 'Dokumen tidak dapat dibuka. Muat ulang halaman lalu coba lagi.');
                }
            } catch (err) {
                pesan('Dokumen tidak dapat dibuka. Muat ulang halaman lalu coba lagi.');
            }
        };
        bingkai.src = url + sambung + 'bingkai=1';
        document.body.appendChild(bingkai);
    }

    // Tautan: <a data-cetak href="...">
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[data-cetak]') : null;
        if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
        e.preventDefault();
        cetak(a.href);
    });

    // Formulir: <form data-cetak method="GET">; hanya tombol bernilai "cetak" yang memakai bingkai, selain itu (Word) dikirim biasa.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.hasAttribute || !form.hasAttribute('data-cetak')) return;
        var tombol = e.submitter;
        if (!tombol || tombol.value !== 'cetak') return;
        e.preventDefault();

        var params = new URLSearchParams(new FormData(form));
        params.set('format', 'cetak');
        tombol.disabled = true;
        cetak(form.getAttribute('action') + '?' + params.toString(), function () { tombol.disabled = false; });
    });
})();
