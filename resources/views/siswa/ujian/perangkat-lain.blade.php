@extends('layouts.dasar')
@section('judul', 'Perangkat Lain')

@section('isi')
<div class="login-latar">
    <div class="login-kartu">
        <img class="login-logo" src="{{ asset('images/logo_smk.png') }}" alt="" style="width:72px;height:72px">
        <h1 style="font-size:1.25rem">Ujian sedang dikerjakan di perangkat lain</h1>
        <p class="sekolah" style="margin:10px 0 20px">{{ $jadwal->nama }}</p>
        <div class="pesan-salah" role="alert"><x-ikon nama="peringatan" :ukuran="18" />
            <span>Satu ujian hanya bisa dikerjakan di satu perangkat. Bila perangkat sebelumnya rusak atau mati, panggil pengawas agar perangkat Anda diizinkan.</span></div>
        <a class="tombol garis" href="{{ route('siswa.beranda') }}">Kembali ke beranda</a>
    </div>
</div>
@endsection
