@extends('layouts.staf')
@section('judul', $bacaan ? 'Ubah Bacaan' : 'Tambah Bacaan')

@section('konten')
<div class="pita-info pita-form">
    <div class="pita-atas">
        <a class="tombol-pita" href="{{ route('staf.bank-soal.show', $bank) }}"><x-ikon nama="panah-kiri" :ukuran="16" />Kembali</a>
    </div>
    <h1>{{ $bacaan ? 'Ubah' : 'Tambah' }} Bacaan</h1>
    <p>{{ $bank->nama }}</p>
</div>

<form class="kartu formulir" method="POST" action="{{ $bacaan ? route('staf.bacaan.update', [$bank, $bacaan]) : route('staf.bacaan.store', $bank) }}">
    @csrf
    @if ($bacaan) @method('PUT') @endif

    <div class="bidang @error('judul') salah @enderror">
        <label for="judul">Judul (opsional)</label>
        <input id="judul" name="judul" type="text" maxlength="150" value="{{ old('judul', $bacaan?->judul) }}" placeholder="Contoh: Cerita Pak Tani">
        @error('judul') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    <div class="bidang @error('teks') salah @enderror">
        <label for="teks">Teks bacaan <span class="wajib">*</span></label>
        <textarea id="teks" name="teks" rows="12" maxlength="8000" required>{{ old('teks', $bacaan?->teks) }}</textarea>
        <small class="bantuan">Cerita, pernyataan, atau data yang dibaca siswa. Satu bacaan bisa dipakai beberapa soal.</small>
        @error('teks') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    <div class="aksi-form">
        <button class="tombol kecil" type="submit">Simpan</button>
        <a class="tombol kecil garis" href="{{ route('staf.bank-soal.show', $bank) }}">Batal</a>
    </div>
</form>
@endsection
