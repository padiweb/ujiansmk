@extends('layouts.dasar')
@section('judul', 'Beranda Siswa')

@section('isi')
<header class="bilah">
    <div class="bilah-isi">
        <div class="merek"><strong>SMK Alhikmah Tanon</strong></div>
        <div class="pengguna">
            <span>{{ $siswa->nama }}</span>
            <form method="POST" action="{{ route('siswa.keluar') }}">
                @csrf
                <button class="tombol kecil garis" type="submit">Keluar</button>
            </form>
        </div>
    </div>
</header>

<main class="halaman">
    <h1>Halo, {{ $siswa->nama }}</h1>
    <p class="sub">NISN {{ $siswa->nisn }}</p>
    <div class="kartu">Belum ada ujian yang dijadwalkan untuk Anda.</div>
</main>
@endsection
