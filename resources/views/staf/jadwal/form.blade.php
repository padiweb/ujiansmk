@extends('layouts.staf')
@section('judul', $j ? 'Ubah Jadwal' : 'Buat Jadwal')

@php
    $terbatas = $j?->status === 'aktif'; // jadwal aktif: hanya sebagian isian yang boleh diubah
    $format = fn ($waktu) => $waktu?->format('Y-m-d\TH:i');
    $kelasDipilih = collect(old('kelas_ids', $j?->kelas->pluck('id')->all() ?? []))->map(fn ($v) => (int) $v)->all();
    $bankDipilih = collect(old('bank_ids', $j?->bank->pluck('bank_soal_id')->all() ?? []))->map(fn ($v) => (int) $v)->all();
    $rinciBank = $j?->bank->keyBy('bank_soal_id') ?? collect();
    $kegiatanAwal = (int) old('kegiatan_id', $j?->kegiatan_id);
    $tingkatAwal = old('tingkat', $j?->kelas->first()?->tingkat);
    $awalBank = $daftarBank->whereIn('id', $bankDipilih)->map(fn ($b) => [
        'id' => $b->id, 'nama' => $b->nama, 'pembuat' => $b->pembuat?->nama, 'pg' => $b->pg_siap, 'esai' => $b->esai_siap,
        'jumlah_pg' => old("jumlah_pg.{$b->id}", $rinciBank[$b->id]->jumlah_pg ?? ''), 'jumlah_esai' => old("jumlah_esai.{$b->id}", $rinciBank[$b->id]->jumlah_esai ?? ''),
    ])->values();
    $kegiatanLama = $j?->kegiatan && ! $daftarKegiatan->contains('id', $j->kegiatan_id) ? $j->kegiatan : null; // kegiatan yang kini tidak aktif
@endphp

@section('konten')
<h1 class="judul-halaman">{{ $j ? 'Ubah' : 'Buat' }} jadwal ujian</h1>
@if ($terbatas)
    <div class="info" style="margin-top:12px"><x-ikon nama="info" :ukuran="18" /><span>Jadwal aktif: hanya waktu tutup, batas pelanggaran, dan tampilan hasil yang bisa diubah.</span></div>
@endif

<form class="kartu formulir" method="POST" action="{{ $j ? route('staf.jadwal.update', $j) : route('staf.jadwal.store') }}" id="formJadwal">
    @csrf
    @if ($j) @method('PUT') @endif

    @unless ($terbatas)
        <div class="bidang @error('kegiatan_id') salah @enderror">
            <label for="kegiatan_id">Kegiatan <span class="wajib">*</span></label>
            <select id="kegiatan_id" name="kegiatan_id" required>
                <option value="">— Pilih —</option>
                @foreach ($daftarKegiatan->concat($kegiatanLama ? [$kegiatanLama->loadMissing('periode.tahunAjaran')] : []) as $k)
                    <option value="{{ $k->id }}" data-tahun="{{ $k->periode->tahun_ajaran_id }}" @selected($kegiatanAwal === $k->id)>{{ $k->nama }} — {{ $k->periode->label() }}</option>
                @endforeach
            </select>
            <small class="bantuan">Kegiatan baru ditambahkan di <a href="{{ route('staf.kegiatan.index') }}">Kegiatan Ujian</a>.</small>
            @error('kegiatan_id') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>

        <div class="bidang @error('mapel_id') salah @enderror">
            <label for="mapel_id">Mata pelajaran <span class="wajib">*</span></label>
            <select id="mapel_id" name="mapel_id" required>
                <option value="">— Pilih —</option>
                @foreach ($daftarMapel as $m)
                    <option value="{{ $m->id }}" @selected((int) old('mapel_id', $j?->mapel_id) === $m->id)>{{ $m->nama }}</option>
                @endforeach
            </select>
            @error('mapel_id') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>

        <div class="bidang @error('kelas_ids') salah @enderror">
            <label for="tingkat">Kelas peserta <span class="wajib">*</span></label>
            <select id="tingkat" name="tingkat" required>
                <option value="">— Pilih —</option>
                @foreach (\App\Models\Kelas::TINGKAT as $t)
                    <option value="{{ $t }}" @selected($tingkatAwal === $t)>Kelas {{ $t }}</option>
                @endforeach
            </select>
            <small class="bantuan">Semua kelas pada tingkat ini ikut sebagai peserta.</small>
            @error('kelas_ids') <small class="galat-bidang">{{ $message }}</small> @enderror
            <details class="kelas-tertentu" @if ($kelasDipilih) open @endif>
                <summary>Hanya kelas tertentu (opsional)</summary>
                @foreach ($daftarKelas as $k)
                    <label class="centang" data-tahun="{{ $k->tahun_ajaran_id }}" data-tingkat="{{ $k->tingkat }}" data-kelas>
                        <input type="checkbox" name="kelas_ids[]" value="{{ $k->id }}" @checked(in_array($k->id, $kelasDipilih, true))>
                        {{ $k->nama }}
                    </label>
                @endforeach
            </details>
        </div>

        <div class="bidang @error('mulai_pada') salah @enderror">
            <label for="mulai_pada">Dibuka pada <span class="wajib">*</span></label>
            <input id="mulai_pada" name="mulai_pada" type="datetime-local" value="{{ old('mulai_pada', $format($j?->mulai_pada)) }}" required>
            @error('mulai_pada') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>
    @endunless

    <div class="bidang @error('tutup_pada') salah @enderror">
        <label for="tutup_pada">Ditutup pada <span class="wajib">*</span></label>
        <input id="tutup_pada" name="tutup_pada" type="datetime-local" value="{{ old('tutup_pada', $format($j?->tutup_pada)) }}" required>
        <small class="bantuan">Batas terakhir siswa boleh mulai. Siswa yang sudah mulai tetap mendapat durasi penuh.</small>
        @error('tutup_pada') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    @unless ($terbatas)
        <div class="bidang @error('durasi_menit') salah @enderror">
            <label for="durasi_menit">Durasi pengerjaan (menit) <span class="wajib">*</span></label>
            <input id="durasi_menit" name="durasi_menit" type="number" min="5" max="480" value="{{ old('durasi_menit', $j?->durasi_menit ?? 90) }}" required>
            <small class="bantuan">Dihitung sejak masing-masing siswa menekan Mulai.</small>
            @error('durasi_menit') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>

        <div class="bidang @error('min_kumpul_menit') salah @enderror">
            <label for="min_kumpul_menit">Waktu minimal sebelum bisa mengumpulkan (menit)</label>
            <input id="min_kumpul_menit" name="min_kumpul_menit" type="number" min="0" max="479" value="{{ old('min_kumpul_menit', $j?->min_kumpul_menit ?? 0) }}">
            <small class="bantuan">Siswa baru bisa menekan Kirim setelah waktu ini. 0 = langsung boleh.</small>
            @error('min_kumpul_menit') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>

        <fieldset class="opsi-blok @error('bank_ids') salah @enderror">
            <legend>Bank soal yang dipakai</legend>
            <small class="bantuan" style="margin:0 0 8px">Terisi otomatis dari bank guru pengampu mata pelajaran dan tingkat yang dipilih. Hilangkan centang bila ada bank yang tidak dipakai. Jumlah soal boleh dikosongkan (semua soal siap dipakai).</small>
            <div id="bankDaftar" aria-live="polite"></div>
            @error('bank_ids') <small class="galat-bidang">{{ $message }}</small> @enderror
        </fieldset>

        <div class="bidang">
            <label class="centang"><input type="checkbox" name="acak_soal" value="1" @checked(old('acak_soal', $j?->acak_soal ?? true))> Acak urutan soal untuk setiap siswa</label>
            <label class="centang"><input type="checkbox" name="acak_opsi" value="1" @checked(old('acak_opsi', $j?->acak_opsi ?? true))> Acak urutan opsi jawaban untuk setiap siswa</label>
        </div>

        <div class="bidang @error('bobot_pg') salah @enderror">
            <label for="bobot_pg">Bobot pilihan ganda (%)</label>
            <input id="bobot_pg" name="bobot_pg" type="number" min="0" max="100" value="{{ old('bobot_pg', $j?->bobot_pg ?? 100) }}" required>
            <small class="bantuan">Sisanya menjadi bobot esai. Jadwal tanpa esai harus 100.</small>
            @error('bobot_pg') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>
    @endunless

    <div class="bidang @error('batas_pelanggaran') salah @enderror">
        <label for="batas_pelanggaran">Batas pelanggaran sebelum diblokir</label>
        <input id="batas_pelanggaran" name="batas_pelanggaran" type="number" min="1" max="10" value="{{ old('batas_pelanggaran', $j?->batas_pelanggaran ?? 3) }}" required>
        <small class="bantuan">3 berarti 2 peringatan, lalu pelanggaran ke-3 memblokir siswa.</small>
        @error('batas_pelanggaran') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    <div class="bidang">
        <label for="tampilkan_hasil">Tampilan nilai ke siswa</label>
        <select id="tampilkan_hasil" name="tampilkan_hasil">
            <option value="langsung" @selected(old('tampilkan_hasil', $j?->tampilkan_hasil ?? 'langsung') === 'langsung')>Langsung setelah dikirim (nilai PG)</option>
            <option value="ditahan" @selected(old('tampilkan_hasil', $j?->tampilkan_hasil) === 'ditahan')>Ditahan (tidak ditampilkan)</option>
        </select>
    </div>


    <div class="aksi-form">
        <button class="tombol kecil" type="submit">Simpan</button>
        <a class="tombol kecil garis" href="{{ $j ? route('staf.jadwal.show', $j) : route('staf.jadwal.index') }}">Batal</a>
    </div>
</form>

@unless ($terbatas)
@push('skrip')
<script>
    (function () {
        var kegiatan = document.getElementById('kegiatan_id'), mapel = document.getElementById('mapel_id'), tingkat = document.getElementById('tingkat');
        var daftar = document.getElementById('bankDaftar'), awal = @json($awalBank), urut = 0;
        var alamat = @json(route('staf.jadwal.bank-otomatis'));

        function pesan(teks) {
            daftar.textContent = '';
            var p = document.createElement('small');
            p.className = 'bantuan';
            p.textContent = teks;
            daftar.appendChild(p);
        }

        function gambar(isi) {
            daftar.textContent = '';
            if (!isi.length) { pesan('Belum ada bank soal yang siap untuk mata pelajaran dan tingkat ini. Minta guru pengampu membuatnya di menu Bank Soal.'); return; }
            isi.forEach(function (b) {
                var baris = document.createElement('div'); baris.className = 'baris-bank';
                var label = document.createElement('label'); label.className = 'centang';
                var cek = document.createElement('input'); cek.type = 'checkbox'; cek.name = 'bank_ids[]'; cek.value = b.id; cek.checked = true;
                var teks = document.createElement('span');
                var judul = document.createElement('strong'); judul.textContent = b.nama;
                var rinci = document.createElement('small'); rinci.className = 'bantuan'; rinci.style.margin = '0';
                rinci.textContent = ' ' + (b.pembuat || '') + ' · siap: ' + b.pg + ' PG, ' + b.esai + ' esai';
                teks.appendChild(judul); teks.appendChild(rinci); label.appendChild(cek); label.appendChild(teks);
                var jumlah = document.createElement('div'); jumlah.className = 'jumlah-bank';
                [['jumlah_pg', 'PG'], ['jumlah_esai', 'Esai']].forEach(function (f) {
                    var i = document.createElement('input'); i.type = 'number'; i.min = 1; i.max = 500; i.name = f[0] + '[' + b.id + ']'; i.placeholder = f[1];
                    i.setAttribute('aria-label', 'Jumlah ' + f[1] + ' dari ' + b.nama);
                    if (b[f[0]] !== undefined && b[f[0]] !== null) i.value = b[f[0]];
                    jumlah.appendChild(i);
                });
                baris.appendChild(label); baris.appendChild(jumlah); daftar.appendChild(baris);
            });
        }

        function kelasDipilih() {
            return Array.prototype.filter.call(document.querySelectorAll('[data-kelas] input:checked'), function (c) { return !c.closest('[data-kelas]').hidden; }).map(function (c) { return c.value; });
        }

        function muat() {
            if (!kegiatan.value || !mapel.value || !tingkat.value) { pesan('Pilih kegiatan, mata pelajaran, dan kelas peserta dulu. Bank soal akan terisi otomatis.'); return; }
            var q = new URLSearchParams({ kegiatan_id: kegiatan.value, mapel_id: mapel.value, tingkat: tingkat.value });
            kelasDipilih().forEach(function (k) { q.append('kelas_ids[]', k); });
            var saya = ++urut;
            pesan('Mencari bank soal…');
            fetch(alamat + '?' + q.toString(), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
                .then(function (isi) { if (saya === urut) gambar(isi); })
                .catch(function () { if (saya === urut) pesan('Bank soal tidak dapat dimuat. Muat ulang halaman ini.'); });
        }

        function saring() {
            var tahun = kegiatan.selectedOptions[0] ? kegiatan.selectedOptions[0].dataset.tahun : '';
            document.querySelectorAll('[data-kelas]').forEach(function (el) {
                var cocok = (!tahun || el.dataset.tahun === tahun) && (!tingkat.value || el.dataset.tingkat === tingkat.value);
                el.hidden = !cocok;
                if (!cocok) el.querySelector('input').checked = false;
            });
        }

        function berubah() { saring(); muat(); }
        kegiatan.addEventListener('change', berubah);
        mapel.addEventListener('change', berubah);
        tingkat.addEventListener('change', berubah);
        document.querySelectorAll('[data-kelas] input').forEach(function (c) { c.addEventListener('change', muat); });

        saring();
        if (awal.length) gambar(awal); else muat();
    })();
</script>
@endpush
@endunless
@endsection
