// Angka langsung dan log peringatan di beranda staf (diambil tiap 5 detik; berhenti saat tab tersembunyi).
(function () {
    var kotak = document.getElementById('dbAngka');
    if (!kotak) return;

    var $ = function (id) { return document.getElementById(id); };
    var ruang = $('dbRuang');
    var timer = null;

    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
    function jam(d) { return d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }); }

    function tampil(h) {
        var langsung = $('dbStatus').classList;
        if (!h.aktif) {
            langsung.add('mati');
            $('dbStatusTeks').textContent = 'Tidak ada ujian aktif';
        } else {
            langsung.remove('mati');
            $('dbStatusTeks').textContent = 'Langsung';
        }
        ['belum', 'mengerjakan', 'selesai', 'melanggar', 'terblokir'].forEach(function (k) { $('n-' + k).textContent = h.ringkasan[k] || 0; });
        $('dbSinkron').textContent = h.aktif ? 'Diperbarui ' + jam(new Date()) : '';

        var isi = $('dbMasalah');
        if (!h.masalah.length) {
            isi.innerHTML = '<tr><td colspan="5" class="kosong">' + (h.aktif ? 'Tidak ada pelanggaran saat ini.' : 'Tidak ada ujian aktif.') + '</td></tr>';
            return;
        }
        isi.innerHTML = h.masalah.map(function (b) {
            var status = b.terblokir ? '<span class="lencana kuning">Terblokir</span>' : '<span class="lencana pucat">Melanggar (' + b.pelanggaran + ')</span>';
            var aksi = '';
            if (b.terblokir && b.bisa_aktifkan) aksi += '<button type="button" class="tautan" data-aktifkan="' + b.jadwal_id + '/' + b.sesi_id + '" data-nama="' + esc(b.nama) + '">Aktifkan</button>';
            aksi += '<a href="' + kotak.getAttribute('data-aksi') + '/' + b.jadwal_id + '">Buka</a>';
            return '<tr><td>' + esc(b.waktu || '–') + '</td><td><strong>' + esc(b.nama) + '</strong><small>' + esc(b.ujian) + (b.jenis ? ' · ' + esc(b.jenis) : '') + '</small></td>'
                + '<td>' + esc(b.kelas || '–') + ' / ' + esc(b.ruang || '–') + '</td><td>' + status + '</td><td class="kanan"><div class="aksi-baris">' + aksi + '</div></td></tr>';
        }).join('');
    }

    function ambil() {
        clearTimeout(timer);
        var alamat = kotak.getAttribute('data-url') + (ruang && ruang.value !== '0' ? '?ruang=' + encodeURIComponent(ruang.value) : '');
        fetch(alamat, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
            .then(tampil)
            .catch(function () { $('dbStatusTeks').textContent = 'Gagal memuat'; })
            .then(function () { if (!document.hidden) timer = setTimeout(ambil, 5000); });
    }

    if (ruang) ruang.addEventListener('change', ambil);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) ambil(); });

    // Aktifkan kembali siswa terblokir memakai tindakan pengawas yang sama dengan halaman monitor.
    $('dbMasalah').addEventListener('click', function (e) {
        var t = e.target.closest('[data-aktifkan]');
        if (!t || !confirm('Aktifkan kembali ' + t.getAttribute('data-nama') + '? Hitungan pelanggarannya diatur ulang.')) return;
        var bagian = t.getAttribute('data-aktifkan').split('/');
        t.disabled = true;
        fetch(kotak.getAttribute('data-aksi') + '/' + bagian[0] + '/sesi/' + bagian[1] + '/aktifkan', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': kotak.getAttribute('data-token'), 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (r) { if (!r.ok) alert('Tindakan gagal. Muat ulang halaman lalu coba lagi.'); ambil(); })
          .catch(function () { t.disabled = false; alert('Koneksi bermasalah. Coba lagi.'); });
    });

    ambil();
})();
