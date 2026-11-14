/* Unggah gambar yang lebih mudah bagi guru: foto HP diperkecil otomatis di peramban (cepat dan hemat kuota),
   dan gambar dari papan klip (Ctrl+V, mis. tangkapan layar) bisa langsung ditempel ke kolom gambar yang aktif. */
(function () {
    'use strict';

    var MAKS_SISI = 1600;
    var AMBANG = 350 * 1024;

    function ganti(input, berkas) {
        try {
            var dt = new DataTransfer();
            dt.items.add(berkas);
            input.files = dt.files;
        } catch (e) { /* peramban lama: biarkan berkas asli */ }
    }

    function perkecil(input) {
        var berkas = input.files && input.files[0];
        if (!berkas || !/^image\/(jpeg|png|webp)$/.test(berkas.type)) return;
        var url = URL.createObjectURL(berkas);
        var gambar = new Image();
        gambar.onload = function () {
            URL.revokeObjectURL(url);
            var skala = Math.min(1, MAKS_SISI / Math.max(gambar.width, gambar.height));
            if (skala >= 1 && berkas.size <= AMBANG) return; // sudah kecil
            var kanvas = document.createElement('canvas');
            kanvas.width = Math.round(gambar.width * skala);
            kanvas.height = Math.round(gambar.height * skala);
            var ctx = kanvas.getContext('2d');
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, kanvas.width, kanvas.height);
            ctx.drawImage(gambar, 0, 0, kanvas.width, kanvas.height);
            kanvas.toBlob(function (blob) {
                if (blob && blob.size < berkas.size) {
                    ganti(input, new File([blob], (berkas.name || 'gambar').replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }));
                    input.setAttribute('data-diperkecil', '1');
                }
            }, 'image/jpeg', 0.86);
        };
        gambar.onerror = function () { URL.revokeObjectURL(url); };
        gambar.src = url;
    }

    document.addEventListener('change', function (e) {
        if (e.target && e.target.matches && e.target.matches('input[type=file][data-unggah]') && !e.target.hasAttribute('data-sedang-ganti')) perkecil(e.target);
    });

    // Tempel dari papan klip ke kolom gambar pada baris opsi yang sedang aktif (atau kolom gambar utama).
    document.addEventListener('paste', function (e) {
        var item = Array.prototype.find.call((e.clipboardData && e.clipboardData.items) || [], function (i) { return i.kind === 'file' && /^image\//.test(i.type); });
        if (!item) return;
        var aktif = document.activeElement;
        var baris = aktif && aktif.closest ? aktif.closest('.opsi-baris') : null;
        var target = baris ? baris.querySelector('input[type=file][data-unggah]') : document.querySelector('input[type=file][data-unggah]#gambar');
        if (!target) return;
        e.preventDefault();
        var berkas = item.getAsFile();
        ganti(target, new File([berkas], 'tempel.png', { type: berkas.type }));
        target.dispatchEvent(new Event('change', { bubbles: true }));
        var tanda = target.closest('.opsi-gambar, .bidang');
        if (tanda) { tanda.style.outline = '2px solid #3960FB'; setTimeout(function () { tanda.style.outline = ''; }, 1200); }
    });
})();

/* Zona unggah gambar (klik atau seret) dengan pratinjau dan tombol buang, seperti aplikasi lama. */
(function () {
    'use strict';

    function pasang(zona) {
        var input = zona.querySelector('input[type=file]');
        var isi = zona.querySelector('.zona-isi');
        var pratinjau = zona.querySelector('.zona-pratinjau');
        if (!input || !isi || !pratinjau) return;
        var img = pratinjau.querySelector('img');
        var nama = pratinjau.querySelector('.zona-nama');
        var hapus = pratinjau.querySelector('.zona-hapus');

        function tampil() {
            var berkas = input.files && input.files[0];
            if (!berkas) { pratinjau.hidden = true; isi.hidden = false; return; }
            var url = URL.createObjectURL(berkas);
            img.onload = function () { URL.revokeObjectURL(url); };
            img.src = url;
            nama.textContent = berkas.name + ' · ' + Math.max(1, Math.round(berkas.size / 1024)) + ' KB';
            pratinjau.hidden = false;
            isi.hidden = true;
        }

        input.addEventListener('change', function () { setTimeout(tampil, 30); /* tunggu pengecilan gambar selesai */ setTimeout(tampil, 600); });
        hapus.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            input.value = '';
            tampil();
        });
        ['dragenter', 'dragover'].forEach(function (n) { zona.addEventListener(n, function (e) { e.preventDefault(); zona.classList.add('seret'); }); });
        ['dragleave', 'drop'].forEach(function (n) { zona.addEventListener(n, function () { zona.classList.remove('seret'); }); });
        zona.addEventListener('drop', function (e) {
            e.preventDefault();
            var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
            if (!f || !/^image\//.test(f.type)) return;
            try {
                var dt = new DataTransfer();
                dt.items.add(f);
                input.files = dt.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (err) { /* peramban lama: gunakan klik */ }
        });
    }

    document.querySelectorAll('.zona-unggah').forEach(pasang);
})();
