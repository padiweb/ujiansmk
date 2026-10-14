@extends('layouts.dasar')
@section('judul', 'Beranda Siswa')

@section('isi')
<header class="siswa-atas">
    <h1><img src="{{ asset('images/logo_smk.png') }}" alt="">{{ config('ujian.merek') }}</h1>
    <form method="POST" action="{{ route('siswa.keluar') }}" style="margin:0">
        @csrf
        <button class="tombol kecil garis" type="submit"><x-ikon nama="keluar" :ukuran="16" />Keluar</button>
    </form>
</header>

<main class="siswa-isi">
    <div class="kartu">
        <h2 class="judul-kartu" style="font-size:1.2rem">Halo, {{ $siswa->nama }}</h2>
        <p class="sub" style="color:var(--teks-3)">NISN {{ $siswa->nisn }}</p>
    </div>
    <div class="kartu kosong-kartu">Belum ada ujian yang dijadwalkan untuk Anda.</div>
</main>
@endsection
