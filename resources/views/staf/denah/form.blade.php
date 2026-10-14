@extends('layouts.staf')
@section('judul', 'Buat Denah')

@section('konten')
<h1 class="judul-halaman">Buat denah ruang ujian</h1>
<form method="POST" action="{{ route('staf.denah.store') }}" class="kartu form-lebar" novalidate>
    @csrf
    <div class="bidang @error('nama') salah @enderror">
        <label for="nama">Nama denah <span class="wajib">*</span></label>
        <input id="nama" name="nama" value="{{ old('nama') }}" maxlength="100" placeholder="mis. UTS Ganjil 2025/2026" required>
        @error('nama') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>
    <div class="bidang @error('periode_id') salah @enderror">
        <label for="periode_id">Semester <span class="wajib">*</span></label>
        <select id="periode_id" name="periode_id" required>
            @foreach ($daftarPeriode as $p)
                <option value="{{ $p->id }}" @selected((int) old('periode_id', $periodeAktif) === $p->id)>{{ ucfirst($p->semester) }} {{ $p->tahunAjaran->nama }}</option>
            @endforeach
        </select>
        @error('periode_id') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>
    <div class="aksi-form">
        <button class="tombol kecil" type="submit">Buat denah</button>
        <a class="tombol kecil garis" href="{{ route('staf.denah.index') }}">Batal</a>
    </div>
</form>
@endsection
