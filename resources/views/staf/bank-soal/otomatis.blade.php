@extends('layouts.staf')
@section('judul', 'Susun Soal Otomatis')

@section('konten')
<h1 class="judul-halaman">Susun soal otomatis</h1>
<p class="sub">{{ $bank->nama }} · {{ $bank->mapel->nama }} · Kelas {{ $bank->tingkat }}</p>

@if (! $penyedia)
    <div class="kartu">
        <div class="pesan-salah" style="margin:0"><x-ikon nama="peringatan" :ukuran="18" />
            <span>Fitur susun soal otomatis belum tersedia di instalasi ini. Hubungi pengelola aplikasi.</span></div>
    </div>
@else
    <form class="kartu formulir" method="POST" action="{{ route('staf.bank-soal.otomatis.proses', $bank) }}" id="formOtomatis">
        @csrf

        <div class="bidang @error('topik') salah @enderror">
            <label for="topik">Topik <span class="wajib">*</span></label>
            <input id="topik" name="topik" type="text" maxlength="300" placeholder="Contoh: Persamaan linear satu variabel" value="{{ old('topik') }}" required autofocus>
            @error('topik') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>

        <div class="bidang @error('materi') salah @enderror">
            <label for="materi">Materi acuan (opsional)</label>
            <textarea id="materi" name="materi" rows="5" maxlength="4000">{{ old('materi') }}</textarea>
            @error('materi') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>

        <div class="bidang @error('tipe') salah @enderror">
            <label for="tipe">Jenis soal</label>
            <select id="tipe" name="tipe">
                <option value="pg" @selected(old('tipe', 'pg') === 'pg')>Pilihan ganda</option>
                <option value="esai" @selected(old('tipe') === 'esai')>Esai</option>
                <option value="campuran" @selected(old('tipe') === 'campuran')>Campuran (separuh pilihan ganda, separuh esai)</option>
            </select>
        </div>

        <div class="bidang @error('jumlah') salah @enderror">
            <label for="jumlah">Jumlah soal (1–{{ $maks }})</label>
            <input id="jumlah" name="jumlah" type="number" min="1" max="{{ $maks }}" value="{{ old('jumlah', 5) }}" required>
            @error('jumlah') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>

        <div class="bidang">
            <label for="level">Level kognitif</label>
            <select id="level" name="level">
                <option value="campuran" @selected(old('level', 'campuran') === 'campuran')>Campuran (L1, L2, L3 tersebar)</option>
                <option value="L1" @selected(old('level') === 'L1')>L1 — Pengetahuan/pemahaman</option>
                <option value="L2" @selected(old('level') === 'L2')>L2 — Penerapan</option>
                <option value="L3" @selected(old('level') === 'L3')>L3 — Penalaran (HOTS)</option>
            </select>
        </div>

        <div class="bidang">
            <label for="soal_per_bacaan">Soal berbasis bacaan</label>
            <select id="soal_per_bacaan" name="soal_per_bacaan">
                <option value="0" @selected((int) old('soal_per_bacaan', 0) === 0)>Tanpa bacaan</option>
                @foreach ([2, 3, 4, 5] as $n)
                    <option value="{{ $n }}" @selected((int) old('soal_per_bacaan', 0) === $n)>Satu bacaan untuk {{ $n }} soal</option>
                @endforeach
            </select>
        </div>

        <div class="bidang">
            <label for="jumlah_opsi">Jumlah opsi pilihan ganda</label>
            <select id="jumlah_opsi" name="jumlah_opsi">
                <option value="4" @selected((int) old('jumlah_opsi', $bank->jumlah_opsi_bawaan) === 4)>4 opsi (A–D)</option>
                <option value="5" @selected((int) old('jumlah_opsi', $bank->jumlah_opsi_bawaan) === 5)>5 opsi (A–E)</option>
            </select>
        </div>

        <div class="bidang">
            <label for="kesulitan">Tingkat kesulitan</label>
            <select id="kesulitan" name="kesulitan">
                @foreach (['mudah' => 'Mudah', 'sedang' => 'Sedang', 'sulit' => 'Sulit'] as $k => $l)
                    <option value="{{ $k }}" @selected(old('kesulitan', 'sedang') === $k)>{{ $l }}</option>
                @endforeach
            </select>
        </div>

        <div class="aksi-form">
            <button class="tombol kecil" type="submit" id="tombolSusun" @disabled($sisa <= 0) @if ($sisa <= 0) title="Batas permintaan harian tercapai" @endif><x-ikon nama="otomatis" :ukuran="16" /><span>Susun soal</span></button>
            <a class="tombol kecil garis" href="{{ route('staf.bank-soal.show', $bank) }}">Batal</a>
        </div>
        <small class="bantuan" id="infoTunggu" hidden>Sedang menyusun soal. Jangan tutup halaman ini.</small>
    </form>

    @isset($rancangan)
        @include('staf.bank-soal.partials.rancangan')
    @endisset

    @push('skrip')
    <script>
        document.getElementById('formOtomatis').addEventListener('submit', function () {
            var t = document.getElementById('tombolSusun');
            t.disabled = true; t.querySelector('span').textContent = 'Menyusun…';
            document.getElementById('infoTunggu').hidden = false;
        });
    </script>
    @endpush
@endif
@endsection
