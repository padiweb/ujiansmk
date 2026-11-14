@extends('layouts.staf')
@section('judul', 'Pindai Soal dari Foto')

@section('konten')
<div class="pita-info pita-form">
    <div class="pita-atas">
        <a class="tombol-pita" href="{{ route('staf.bank-soal.show', $bank) }}"><x-ikon nama="panah-kiri" :ukuran="16" />Kembali</a>
    </div>
    <h1>Pindai soal dari foto</h1>
    <p>{{ $bank->nama }} · {{ $bank->mapel->nama }} · Kelas {{ $bank->tingkat }}</p>
</div>

@if (! $penyedia)
    <div class="kartu">
        <div class="pesan-salah" style="margin:0"><x-ikon nama="peringatan" :ukuran="18" />
            <span>Fitur ini belum tersedia di instalasi ini. Hubungi pengelola aplikasi.</span></div>
    </div>
@else
    <form class="kartu formulir" method="POST" enctype="multipart/form-data" action="{{ route('staf.bank-soal.foto.proses', $bank) }}" id="formFoto">
        @csrf

        <div class="bidang @error('foto') salah @enderror">
            <label for="foto">Foto soal <span class="wajib">*</span></label>
            <input id="foto" name="foto" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" required data-unggah>
            @error('foto') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>

        <div class="bidang">
            <label for="jumlah_opsi">Jumlah pilihan jawaban pada soal</label>
            <select id="jumlah_opsi" name="jumlah_opsi">
                <option value="4" @selected((int) old('jumlah_opsi', $bank->jumlah_opsi_bawaan) === 4)>4 opsi (A–D)</option>
                <option value="5" @selected((int) old('jumlah_opsi', $bank->jumlah_opsi_bawaan) === 5)>5 opsi (A–E)</option>
            </select>
        </div>

        <label class="centang"><input type="checkbox" name="lampirkan" value="1" @checked(old('lampirkan'))> Lampirkan foto asli sebagai gambar soal</label>

        <div class="aksi-form">
            <button class="tombol kecil" type="submit" id="tombolFoto" @disabled($sisa <= 0) @if ($sisa <= 0) title="Batas permintaan harian tercapai" @endif><x-ikon nama="otomatis" :ukuran="16" /><span>Pindai foto</span></button>
            <a class="tombol kecil garis" href="{{ route('staf.bank-soal.show', $bank) }}">Batal</a>
        </div>
    </form>
    @isset($rancangan)
        @include('staf.bank-soal.partials.rancangan')
    @endisset
@endif
@endsection

@push('skrip')
<script src="{{ asset('js/unggah-gambar.js') }}?v={{ filemtime(public_path('js/unggah-gambar.js')) }}" defer></script>
<script>
    document.getElementById('formFoto')?.addEventListener('submit', function () {
        var t = document.getElementById('tombolFoto');
        t.disabled = true; t.querySelector('span').textContent = 'Memindai foto…';
    });
</script>
@endpush
