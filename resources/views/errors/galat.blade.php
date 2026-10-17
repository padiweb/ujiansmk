@extends('layouts.dasar')
@section('judul', $judul)

@section('isi')
@php
    $diStaf = request()->is('staf', 'staf/*');
    try { // halaman galat harus tetap tampil walau sesi belum siap
        $masukStaf = auth('web')->check() && $diStaf;
        $masukSiswa = auth('siswa')->check() && ! $diStaf;
    } catch (\Throwable) {
        $masukStaf = $masukSiswa = false;
    }
    $tujuan = $diStaf ? route($masukStaf ? 'staf.beranda' : 'staf.masuk') : route($masukSiswa ? 'siswa.beranda' : 'siswa.masuk');
    $label = ($masukStaf || $masukSiswa) ? 'Kembali ke beranda' : 'Ke halaman masuk';
@endphp
<div class="login-latar">
    <div class="login-kartu" role="alert">
        <img class="login-logo" src="{{ asset('images/logo_smk.png') }}" alt="Logo {{ config('ujian.sekolah') }}">
        <h1>{{ $judul }}</h1>
        <p class="sekolah">{{ $pesan }}</p>
        <a class="tombol" href="{{ $tujuan }}" style="text-align:center;display:block">{{ $label }}</a>
        @unless ($masukStaf || $masukSiswa)
            <div class="login-tautan">Bila masalah berlanjut, hubungi admin sekolah.</div>
        @endunless
    </div>
</div>
@endsection
