@extends('layouts.dasar')

@php
    $pengguna = auth('web')->user();
    $menuMaster = [
        'tahun-ajaran' => 'Tahun Ajaran',
        'periode' => 'Semester',
        'jurusan' => 'Jurusan',
        'kelas' => 'Kelas',
        'mapel' => 'Mata Pelajaran',
        'siswa' => 'Siswa',
        'staf' => 'Guru & Staf',
        'pengampu' => 'Guru Pengampu',
        'ruang' => 'Ruang Ujian',
    ];
    $slugAktif = request()->route('entitas');
@endphp

@section('isi')
<header class="bilah">
    <div class="bilah-isi lebar">
        <div class="merek"><strong>SMK Alhikmah Tanon</strong></div>
        <div class="pengguna">
            <span>{{ $pengguna->nama }}</span>
            <span class="lencana">{{ $pengguna->role }}</span>
            <form method="POST" action="{{ route('staf.keluar') }}">
                @csrf
                <button class="tombol kecil garis" type="submit">Keluar</button>
            </form>
        </div>
    </div>
</header>

<div class="kerangka">
    <nav class="menu" aria-label="Menu utama">
        <a href="{{ route('staf.beranda') }}" class="{{ request()->routeIs('staf.beranda') ? 'aktif' : '' }}">Beranda</a>

        @if ($pengguna->role === 'admin')
            <div class="menu-judul">Data master</div>
            @foreach ($menuMaster as $slug => $label)
                <a href="{{ route('staf.master.index', $slug) }}" class="{{ $slugAktif === $slug ? 'aktif' : '' }}">{{ $label }}</a>
            @endforeach

            <div class="menu-judul">Sistem</div>
            <a href="{{ route('staf.pengaturan') }}" class="{{ request()->routeIs('staf.pengaturan') ? 'aktif' : '' }}">Pengaturan sekolah</a>
        @endif
    </nav>

    <main class="isi">
        @if (session('berhasil'))
            <div class="info" role="status">{{ session('berhasil') }}</div>
        @endif
        @if (session('catatan'))
            <div class="pesan-salah catatan" role="status">{{ session('catatan') }}</div>
        @endif
        @if (session('gagal'))
            <div class="pesan-salah" role="alert">{{ session('gagal') }}</div>
        @endif

        @yield('konten')
    </main>
</div>
@endsection
