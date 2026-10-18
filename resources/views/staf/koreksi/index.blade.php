@extends('layouts.staf')
@section('judul', 'Koreksi Esai')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Koreksi Esai</h1>
        <p class="sub">Pilih mata pelajaran dan kelas{{ auth('web')->user()->role === 'guru' ? ' yang Anda ampu' : '' }} untuk mengoreksi jawaban esai.</p>
    </div>
</div>

@include('staf.partials.pilih-unit', ['rute' => 'staf.koreksi.show', 'kosong' => 'Belum ada jadwal dengan soal esai untuk pilihan ini.'])
@endsection
