/* Menampilkan rumus matematika dan furigana pada teks soal.
   - Rumus: \( ... \) satu baris, \[ ... \] atau $$ ... $$ blok (KaTeX; tanpa tanda $ tunggal agar "Rp5$" aman).
   - Furigana: {{漢字|かんじ}} menjadi tulisan Jepang dengan bacaan di atasnya (<ruby>).
   Teks selalu dibaca sebagai teks biasa lalu dibangun lewat DOM; tidak ada HTML dari pengguna yang dijalankan. */
(function () {
    'use strict';

    var POLA_FURIGANA = /\{\{([^{}|]+)\|([^{}|]*)\}\}/g;

    function lewati(node) {
        for (var p = node.parentNode; p && p.nodeType === 1; p = p.parentNode) {
            var t = p.tagName;
            if (t === 'SCRIPT' || t === 'STYLE' || t === 'TEXTAREA' || t === 'RUBY') return true;
            if (p.classList && (p.classList.contains('katex') || p.classList.contains('katex-display') || p.classList.contains('tanpa-rumus'))) return true;
        }
        return false;
    }

    function furigana(akar) {
        var penjelajah = document.createTreeWalker(akar, NodeFilter.SHOW_TEXT, null);
        var daftar = [], n;
        while ((n = penjelajah.nextNode())) {
            if (n.nodeValue.indexOf('{{') !== -1 && !lewati(n)) daftar.push(n);
        }
        daftar.forEach(function (node) {
            var teks = node.nodeValue, terakhir = 0, m, pecahan = document.createDocumentFragment(), ada = false;
            POLA_FURIGANA.lastIndex = 0;
            while ((m = POLA_FURIGANA.exec(teks))) {
                ada = true;
                if (m.index > terakhir) pecahan.appendChild(document.createTextNode(teks.slice(terakhir, m.index)));
                var ruby = document.createElement('ruby');
                ruby.appendChild(document.createTextNode(m[1]));
                var rt = document.createElement('rt');
                rt.textContent = m[2];
                ruby.appendChild(rt);
                pecahan.appendChild(ruby);
                terakhir = m.index + m[0].length;
            }
            if (!ada) return;
            if (terakhir < teks.length) pecahan.appendChild(document.createTextNode(teks.slice(terakhir)));
            node.parentNode.replaceChild(pecahan, node);
        });
    }

    function rumus(akar) {
        if (typeof window.renderMathInElement !== 'function') return;
        try {
            window.renderMathInElement(akar, {
                delimiters: [
                    { left: '$$', right: '$$', display: true },
                    { left: '\\[', right: '\\]', display: true },
                    { left: '\\(', right: '\\)', display: false }
                ],
                throwOnError: false, trust: false, strict: 'ignore', maxSize: 30, maxExpand: 1000,
                ignoredTags: ['script', 'style', 'textarea', 'pre', 'code']
            });
        } catch (e) { /* rumus yang rusak dibiarkan sebagai teks */ }
    }

    function render(akar) {
        if (!akar) return;
        rumus(akar);   // rumus dulu agar isi KaTeX (yang memuat { | }) tidak disentuh furigana
        furigana(akar);
    }

    window.Rumus = { render: render };

    function otomatis() {
        document.querySelectorAll('.render-rumus').forEach(render);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', otomatis);
    else otomatis();
})();
