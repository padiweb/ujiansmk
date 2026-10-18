@extends('layouts.staf')
@section('judul', 'Rekap Nilai')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Rekap Nilai</h1>
        <p class="sub">Pilih mata pelajaran dan kelas.</p>
    </div>
</div>

@include('staf.partials.pilih-unit', ['rute' => 'staf.rekap.show', 'kosong' => 'Belum ada jadwal ujian yang dimulai untuk pilihan ini.'])
@endsection
