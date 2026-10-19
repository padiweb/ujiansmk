@extends('layouts.staf')
@section('judul', ($ruang ? 'Ubah' : 'Tambah').' Ruang Ujian')

@section('konten')
<h1 class="judul-halaman">{{ $ruang ? 'Ubah' : 'Tambah' }} Ruang Ujian</h1>

<form class="kartu formulir" method="POST" action="{{ $ruang ? route('staf.ruang.update', $ruang) : route('staf.ruang.store') }}">
    @csrf
    @if ($ruang) @method('PUT') @endif

    <div class="bidang @error('nama') salah @enderror">
        <label for="f-nama">Nama ruang <span class="wajib">*</span></label>
        <input id="f-nama" name="nama" type="text" maxlength="50" value="{{ old('nama', $ruang?->nama) }}" required>
        <small class="bantuan">Contoh: Lab Komputer 1</small>
        @error('nama') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    <div class="bidang @error('kapasitas') salah @enderror">
        <label for="f-kapasitas">Kapasitas <span class="wajib">*</span></label>
        <input id="f-kapasitas" name="kapasitas" type="number" min="1" max="500" value="{{ old('kapasitas', $ruang?->kapasitas ?? 36) }}" required>
        <small class="bantuan">Jumlah siswa maksimal.</small>
        @error('kapasitas') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    <div class="bidang">
        <label for="f-campur">Campur tingkat</label>
        <select id="f-campur" name="campur">
            <option value="0" @selected(! old('campur', $ruang?->campur))>Tidak</option>
            <option value="1" @selected(old('campur', $ruang?->campur))>Ya</option>
        </select>
        <small class="bantuan">Bila ya, satu tingkat maksimal 50% dari kapasitas.</small>
    </div>

    <div class="aksi-form">
        <button class="tombol kecil" type="submit">Simpan</button>
        <a class="tombol kecil garis" href="{{ route('staf.ruang.index') }}">Batal</a>
    </div>
</form>
@endsection
