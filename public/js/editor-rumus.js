/* Alat bantu menulis soal: tombol sisip rumus (editor visual MathLive, dimuat saat dibutuhkan), palet simbol, furigana,
   dan pratinjau langsung di bawah kolom bertanda data-rumus. Rumus disisipkan sebagai \( ... \) pada kolom yang terakhir dipilih. */
(function () {
    'use strict';

    var bar = document.getElementById('alatRumus');
    if (!bar) return;
    var terakhir = null;
    var urlMathlive = bar.getAttribute('data-mathlive');
    var urlFont = bar.getAttribute('data-fonts');
    var mathlivePromise = null;

    var kolom = Array.prototype.slice.call(document.querySelectorAll('[data-rumus]'));
    kolom.forEach(function (el) {
        el.addEventListener('focus', function () { terakhir = el; });
        // Pratinjau langsung
        var pr = document.createElement('div');
        pr.className = 'pratinjau-rumus tanpa-cetak';
        pr.hidden = true;
        el.insertAdjacentElement('afterend', pr);
        var tunda;
        function segarkan() {
            var v = el.value || '';
            var perlu = /\\\(|\\\[|\$\$|\{\{[^{}|]+\|/.test(v);
            pr.hidden = !perlu;
            if (!perlu) { pr.textContent = ''; return; }
            pr.textContent = v;
            pr.classList.add('render-rumus');
            if (window.Rumus) window.Rumus.render(pr);
        }
        el.addEventListener('input', function () { clearTimeout(tunda); tunda = setTimeout(segarkan, 300); });
        segarkan();
    });

    function sisipkan(teks, kursorDi) {
        var el = terakhir || kolom[0];
        if (!el) return;
        el.focus();
        var a = el.selectionStart != null ? el.selectionStart : el.value.length;
        var b = el.selectionEnd != null ? el.selectionEnd : a;
        el.value = el.value.slice(0, a) + teks + el.value.slice(b);
        var pos = a + (kursorDi != null ? kursorDi : teks.length);
        el.setSelectionRange(pos, pos);
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // Palet simbol (teks biasa) dan templat rumus
    bar.querySelectorAll('[data-sisip]').forEach(function (tombol) {
        tombol.addEventListener('click', function () {
            var teks = tombol.getAttribute('data-sisip');
            var k = tombol.getAttribute('data-kursor');
            sisipkan(teks, k !== null ? parseInt(k, 10) : null);
        });
    });

    // Furigana: bungkus teks terpilih menjadi {{teks|}} dan taruh kursor di bacaan
    var tombolFuri = bar.querySelector('[data-furigana]');
    if (tombolFuri) {
        tombolFuri.addEventListener('click', function () {
            var el = terakhir || kolom[0];
            if (!el) return;
            var a = el.selectionStart || 0, b = el.selectionEnd || 0;
            var dasar = el.value.slice(a, b) || '漢字';
            var teks = '{{' + dasar + '|}}';
            el.focus();
            el.value = el.value.slice(0, a) + teks + el.value.slice(b);
            var pos = a + dasar.length + 3;
            el.setSelectionRange(pos, pos);
            el.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    function muatMathlive() {
        if (mathlivePromise) return mathlivePromise;
        mathlivePromise = new Promise(function (selesai, gagal) {
            var s = document.createElement('script');
            s.src = urlMathlive;
            s.onload = function () {
                try {
                    if (window.MathfieldElement) {
                        window.MathfieldElement.fontsDirectory = urlFont;
                        window.MathfieldElement.soundsDirectory = null;
                    }
                } catch (e) { /* abaikan */ }
                selesai();
            };
            s.onerror = function () { gagal(new Error('Editor rumus tidak dapat dimuat.')); };
            document.head.appendChild(s);
        });
        return mathlivePromise;
    }

    // Dialog editor rumus visual
    var dialog = document.getElementById('dialogRumus');
    var tombolBuka = bar.querySelector('[data-buka-rumus]');
    if (dialog && tombolBuka) {
        var wadah = dialog.querySelector('[data-wadah]');
        var pesan = dialog.querySelector('[data-pesan]');
        var medan = null;
        tombolBuka.addEventListener('click', function () {
            if (dialog.showModal) dialog.showModal(); else dialog.setAttribute('open', '');
            pesan.textContent = 'Memuat editor…';
            muatMathlive().then(function () {
                pesan.textContent = '';
                if (!medan) {
                    medan = document.createElement('math-field');
                    medan.setAttribute('aria-label', 'Editor rumus');
                    medan.style.cssText = 'display:block;width:100%;font-size:1.5rem;padding:10px;border:1px solid #D1D5DB;border-radius:8px;min-height:64px';
                    wadah.appendChild(medan);
                }
                medan.value = '';
                setTimeout(function () { medan.focus(); }, 50);
            }).catch(function (e) {
                pesan.textContent = e.message + ' Anda masih bisa mengetik rumus manual, misalnya \\(\\frac{a}{b}\\).';
            });
        });
        dialog.querySelector('[data-sisipkan]').addEventListener('click', function () {
            var latex = medan ? String(medan.value || '').trim() : '';
            if (latex) sisipkan('\\(' + latex + '\\)');
            if (dialog.close) dialog.close(); else dialog.removeAttribute('open');
        });
        dialog.querySelector('[data-batal]').addEventListener('click', function () {
            if (dialog.close) dialog.close(); else dialog.removeAttribute('open');
        });
    }
})();
