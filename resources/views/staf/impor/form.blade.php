@extends('layouts.staf')
@section('judul', 'Impor '.$p->judul())

@section('konten')
<h1 class="judul-halaman">Impor {{ $p->judul() }}</h1>
<p class="sub">Unggah berkas Excel (.xlsx). Data diperiksa dulu; baru disimpan setelah Anda mengonfirmasi.</p>

<div class="kartu">
    <h2 class="judul-kartu">1. Unduh templat</h2>
    <p>Isi templat ini mulai baris kedua. Kolom bertanda <strong>*</strong> wajib diisi.</p>
    <p style="margin-top:12px">
        <a class="tombol kecil garis" href="{{ $rute['templat'] }}">Unduh templat .xlsx</a>
    </p>
    @if ($p->petunjuk())
        <ul class="petunjuk">
            @foreach ($p->petunjuk() as $baris)
                <li>{{ $baris }}</li>
            @endforeach
        </ul>
    @endif
</div>

<form class="kartu formulir" method="POST" action="{{ $rute['pratinjau'] }}" enctype="multipart/form-data">
    @csrf
    <h2 class="judul-kartu">2. Unggah dan periksa</h2>
    <div class="bidang @error('berkas') salah @enderror">
        <label for="berkas">Berkas Excel</label>
        <input id="berkas" name="berkas" type="file" accept=".xlsx" required>
        @error('berkas') <small class="galat-bidang">{{ $message }}</small> @enderror
        <small class="bantuan">Maksimal 2 MB dan {{ number_format(\App\Impor\Pengimpor::MAKS_BARIS, 0, ',', '.') }} baris.</small>
    </div>
    <div class="aksi-form">
        <button class="tombol kecil" type="submit">Periksa berkas</button>
        <a class="tombol kecil garis" href="{{ $rute['kembali'] }}">Batal</a>
    </div>
</form>
@endsection
