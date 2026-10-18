/* Halaman ruang: seret baris untuk mengatur urutan nomor meja; urutan disimpan ke server. */
(function () {
    'use strict';

    var tabel = document.getElementById('drTabel');
    var isi = document.getElementById('drIsi');
    if (!tabel || !isi) return;

    var url = tabel.getAttribute('data-url');
    var token = tabel.getAttribute('data-token');
    var per = parseInt(tabel.getAttribute('data-per'), 10) || 1;
    var pesan = document.getElementById('drPesan');
    var dragging = null;

    function baris() { return Array.prototype.slice.call(isi.querySelectorAll('tr[data-id]')); }

    function beri(teks, galat) {
        if (!pesan) return;
        pesan.textContent = teks;
        pesan.style.color = galat ? 'var(--kuning-tua)' : '';
    }

    function nomori() {
        baris().forEach(function (tr, i) {
            tr.querySelector('.dr-meja strong').textContent = Math.floor(i / per) + 1;
        });
    }

    function simpan() {
        var b = new FormData();
        baris().forEach(function (tr) { b.append('ids[]', tr.getAttribute('data-id')); });
        beri('Menyimpan…');
        fetch(url, {
            method: 'POST', credentials: 'same-origin', body: b,
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (r) {
            beri(r.ok ? 'Urutan tersimpan.' : (r.status === 419 ? 'Sesi habis. Muat ulang halaman.' : 'Gagal menyimpan urutan.'), !r.ok);
        }).catch(function () { beri('Koneksi bermasalah. Muat ulang halaman.', true); });
    }

    isi.addEventListener('dragstart', function (e) {
        var tr = e.target.closest('tr[data-id]');
        if (!tr) return;
        dragging = tr;
        tr.classList.add('dr-ambil');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', tr.getAttribute('data-id')); } catch (x) {}
    });

    isi.addEventListener('dragover', function (e) {
        if (!dragging) return;
        e.preventDefault();
        var tr = e.target.closest('tr[data-id]');
        if (!tr || tr === dragging) return;
        var kotak = tr.getBoundingClientRect();
        var sesudah = e.clientY > kotak.top + kotak.height / 2;
        isi.insertBefore(dragging, sesudah ? tr.nextSibling : tr);
    });

    isi.addEventListener('drop', function (e) { if (dragging) e.preventDefault(); });

    isi.addEventListener('dragend', function () {
        if (!dragging) return;
        dragging.classList.remove('dr-ambil');
        dragging = null;
        nomori();
        simpan();
    });
})();
