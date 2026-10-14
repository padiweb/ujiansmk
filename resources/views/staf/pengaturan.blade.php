@extends('layouts.staf')
@section('judul', 'Pengaturan Sekolah')

@section('konten')
<h1 class="judul-halaman">Pengaturan Sekolah</h1>

<form class="kartu formulir" method="POST" action="{{ route('staf.pengaturan.simpan') }}">
    @csrf
    @method('PUT')

    @php
        $isian = [
            ['nama', 'Nama sekolah', true], ['alamat', 'Alamat', false], ['kota', 'Kota/Kabupaten', false],
            ['provinsi', 'Provinsi', false], ['npsn', 'NPSN', false], ['kepala_sekolah', 'Kepala sekolah', false],
            ['nip_kepala_sekolah', 'NIP kepala sekolah', false], ['kurikulum', 'Kurikulum', true],
        ];
    @endphp

    @foreach ($isian as [$nama, $label, $wajib])
        <div class="bidang @error($nama) salah @enderror">
            <label for="f-{{ $nama }}">{{ $label }}@if ($wajib) <span class="wajib">*</span>@endif</label>
            <input id="f-{{ $nama }}" name="{{ $nama }}" type="text" value="{{ old($nama, $p->{$nama}) }}" @required($wajib)>
            @error($nama) <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>
    @endforeach

    <div class="bidang">
        <label for="f-mode">Sinkron dengan EduWeb</label>
        <select id="f-mode" name="mode_sinkron_eduweb">
            <option value="0" @selected(! old('mode_sinkron_eduweb', $p->mode_sinkron_eduweb))>Mati — data master boleh diisi di sini (impor Excel)</option>
            <option value="1" @selected(old('mode_sinkron_eduweb', $p->mode_sinkron_eduweb))>Aktif — data master hanya dibaca, datang dari EduWeb</option>
        </select>
        <small class="bantuan">Saat aktif, siswa, guru, kelas, mapel, jurusan, tahun ajaran, semester, dan pengampu tidak bisa diubah atau diimpor di sini.</small>
    </div>

    <div class="aksi-form">
        <button class="tombol kecil" type="submit">Simpan</button>
    </div>
</form>
@endsection
