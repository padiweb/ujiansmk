@extends('layouts.staf')
@section('judul', $bank ? 'Ubah Bank Soal' : 'Buat Bank Soal')

@section('konten')
<h1 class="judul-halaman">{{ $bank ? 'Ubah' : 'Buat' }} Bank Soal</h1>

<form class="kartu formulir" method="POST"
      action="{{ $bank ? route('staf.bank-soal.update', $bank) : route('staf.bank-soal.store') }}">
    @csrf
    @if ($bank) @method('PUT') @endif

    <div class="bidang @error('nama') salah @enderror">
        <label for="nama">Nama bank <span class="wajib">*</span></label>
        <input id="nama" name="nama" type="text" value="{{ old('nama', $bank?->nama) }}" maxlength="150" required autofocus>
        <small class="bantuan">Contoh: Matematika Kelas X — Semester Ganjil.</small>
        @error('nama') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    @if ($bank)
        <div class="bidang">
            <label>Mata pelajaran</label>
            <input type="text" value="{{ $bank->mapel->nama }} — Kelas {{ $bank->tingkat }}" disabled>
            <small class="bantuan">Mapel dan tingkat tidak dapat diubah. Gunakan "Salin bank" untuk membuat bank baru.</small>
        </div>
    @else
        <div class="bidang @error('mapel_id') salah @enderror">
            <label for="mapel_id">Mata pelajaran <span class="wajib">*</span></label>
            <select id="mapel_id" name="mapel_id" required>
                <option value="">— Pilih —</option>
                @foreach ($daftarMapel as $id => $nama)
                    <option value="{{ $id }}" @selected((string) old('mapel_id') === (string) $id)>{{ $nama }}</option>
                @endforeach
            </select>
            @if ($daftarMapel->isEmpty())
                <small class="bantuan">Belum ada mapel yang Anda ampu pada tahun ajaran aktif. Hubungi admin.</small>
            @endif
            @error('mapel_id') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>

        <div class="bidang @error('tingkat') salah @enderror">
            <label for="tingkat">Tingkat <span class="wajib">*</span></label>
            <select id="tingkat" name="tingkat" required>
                <option value="">— Pilih —</option>
                @foreach (['X', 'XI', 'XII'] as $t)
                    <option value="{{ $t }}" @selected(old('tingkat') === $t)>Kelas {{ $t }}</option>
                @endforeach
            </select>
            @error('tingkat') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>
    @endif

    <div class="bidang @error('jumlah_opsi_bawaan') salah @enderror">
        <label for="jumlah_opsi_bawaan">Jumlah opsi pilihan ganda (bawaan)</label>
        <select id="jumlah_opsi_bawaan" name="jumlah_opsi_bawaan">
            <option value="4" @selected((int) old('jumlah_opsi_bawaan', $bank?->jumlah_opsi_bawaan ?? 4) === 4)>4 opsi (A–D)</option>
            <option value="5" @selected((int) old('jumlah_opsi_bawaan', $bank?->jumlah_opsi_bawaan ?? 4) === 5)>5 opsi (A–E)</option>
        </select>
        <small class="bantuan">Nilai awal saat menambah soal. Tetap bisa dipilih per soal.</small>
    </div>

    <div class="aksi-form">
        <button class="tombol kecil" type="submit">Simpan</button>
        <a class="tombol kecil garis" href="{{ $bank ? route('staf.bank-soal.show', $bank) : route('staf.bank-soal.index') }}">Batal</a>
    </div>
</form>
@endsection
