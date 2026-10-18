@extends('layouts.staf')
@section('judul', $j ? 'Ubah Jadwal' : 'Buat Jadwal')

@php
    $terbatas = $j?->status === 'aktif'; // jadwal aktif: hanya sebagian isian yang boleh diubah
    $format = fn ($waktu) => $waktu?->format('Y-m-d\TH:i');
    $kelasDipilih = collect(old('kelas_ids', $j?->kelas->pluck('id')->all() ?? []))->map(fn ($v) => (int) $v)->all();
    $bankDipilih = collect(old('bank_ids', $j?->bank->pluck('bank_soal_id')->all() ?? []))->map(fn ($v) => (int) $v)->all();
    $rinciBank = $j?->bank->keyBy('bank_soal_id') ?? collect();
    $kegiatanAwal = (int) old('kegiatan_id', $j?->kegiatan_id);
    $kegiatanLama = $j?->kegiatan && ! $daftarKegiatan->contains('id', $j->kegiatan_id) ? $j->kegiatan : null; // kegiatan yang kini tidak aktif
@endphp

@section('konten')
<h1 class="judul-halaman">{{ $j ? 'Ubah' : 'Buat' }} jadwal ujian</h1>
@if ($terbatas)
    <div class="info" style="margin-top:12px"><x-ikon nama="info" :ukuran="18" /><span>Jadwal ini sudah aktif, jadi soal dan peserta sudah dibekukan. Yang masih bisa diubah: waktu tutup, batas pelanggaran, tampilan hasil, dan denah.</span></div>
@endif

<form class="kartu formulir" method="POST" action="{{ $j ? route('staf.jadwal.update', $j) : route('staf.jadwal.store') }}" id="formJadwal" style="max-width:760px">
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
            <small class="bantuan">Nama jadwal dibuat otomatis dari kegiatan dan mata pelajaran. Kegiatan baru ditambahkan di menu <a href="{{ route('staf.kegiatan.index') }}">Kegiatan Ujian</a>.</small>
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

        <div class="bidang @error('durasi_menit') salah @enderror">
            <label for="durasi_menit">Durasi pengerjaan (menit) <span class="wajib">*</span></label>
            <input id="durasi_menit" name="durasi_menit" type="number" min="5" max="480" value="{{ old('durasi_menit', $j?->durasi_menit ?? 90) }}" required>
            <small class="bantuan">Dihitung sejak masing-masing siswa menekan Mulai.</small>
            @error('durasi_menit') <small class="galat-bidang">{{ $message }}</small> @enderror
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
        <fieldset class="opsi-blok @error('kelas_ids') salah @enderror">
            <legend>Kelas peserta <span class="wajib">*</span></legend>
            <small class="bantuan" style="margin:0 0 8px">Hanya kelas dari tahun ajaran semester yang dipilih yang tampil.</small>
            @foreach ($daftarKelas as $k)
                <label class="centang" data-tahun="{{ $k->tahun_ajaran_id }}" data-kelas>
                    <input type="checkbox" name="kelas_ids[]" value="{{ $k->id }}" @checked(in_array($k->id, $kelasDipilih, true))>
                    {{ $k->nama }} <small class="bantuan" style="margin:0">Kelas {{ $k->tingkat }} · {{ $k->tahunAjaran->nama }}</small>
                </label>
            @endforeach
            @error('kelas_ids') <small class="galat-bidang">{{ $message }}</small> @enderror
        </fieldset>

        <fieldset class="opsi-blok @error('bank_ids') salah @enderror">
            <legend>Bank soal <span class="wajib">*</span></legend>
            <small class="bantuan" style="margin:0 0 8px">Hanya bank untuk mata pelajaran yang dipilih. Kosongkan jumlah untuk memakai semua soal yang siap.</small>
            @foreach ($daftarBank as $b)
                <div class="baris-bank" data-mapel="{{ $b->mapel_id }}" data-bank>
                    <label class="centang">
                        <input type="checkbox" name="bank_ids[]" value="{{ $b->id }}" @checked(in_array($b->id, $bankDipilih, true))>
                        <span><strong>{{ $b->nama }}</strong> <small class="bantuan" style="margin:0">Kelas {{ $b->tingkat }} · {{ $b->pembuat->nama }} · siap: {{ $b->pg_siap }} PG, {{ $b->esai_siap }} esai</small></span>
                    </label>
                    <div class="jumlah-bank">
                        <input type="number" min="1" max="500" name="jumlah_pg[{{ $b->id }}]" placeholder="PG" aria-label="Jumlah PG dari {{ $b->nama }}"
                               value="{{ old("jumlah_pg.{$b->id}", $rinciBank[$b->id]->jumlah_pg ?? '') }}">
                        <input type="number" min="1" max="500" name="jumlah_esai[{{ $b->id }}]" placeholder="Esai" aria-label="Jumlah esai dari {{ $b->nama }}"
                               value="{{ old("jumlah_esai.{$b->id}", $rinciBank[$b->id]->jumlah_esai ?? '') }}">
                    </div>
                </div>
            @endforeach
            @if ($daftarBank->isEmpty()) <small class="bantuan">Belum ada bank soal. Buat dulu di menu Bank Soal.</small> @endif
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

    <div class="bidang">
        <label for="denah_id">Denah ruang ujian</label>
        <select id="denah_id" name="denah_id">
            <option value="">Tanpa denah (ruang dan meja tidak ditentukan)</option>
            @foreach ($daftarDenah as $d)
                <option value="{{ $d->id }}" @selected((int) old('denah_id', $j?->denah_id) === $d->id)>{{ $d->nama }}</option>
            @endforeach
        </select>
        <small class="bantuan">Atur denah di menu Denah Ruang. Penempatan siswa dari denah dipakai untuk ruang, nomor meja, dan kartu ujian.</small>
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
        var periode = document.getElementById('kegiatan_id'), mapel = document.getElementById('mapel_id');
        function saring() {
            var tahun = periode.selectedOptions[0] ? periode.selectedOptions[0].dataset.tahun : '';
            document.querySelectorAll('[data-kelas]').forEach(function (el) {
                var cocok = !tahun || el.dataset.tahun === tahun;
                el.hidden = !cocok;
                if (!cocok) el.querySelector('input').checked = false;
            });
            document.querySelectorAll('[data-bank]').forEach(function (el) {
                var cocok = !mapel.value || el.dataset.mapel === mapel.value;
                el.hidden = !cocok;
                if (!cocok) el.querySelector('input[type=checkbox]').checked = false;
            });
        }
        periode.addEventListener('change', saring);
        mapel.addEventListener('change', saring);
        saring();
    })();
</script>
@endpush
@endunless
@endsection
