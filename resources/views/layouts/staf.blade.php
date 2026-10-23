@extends('layouts.dasar')

@php
    $pengguna = auth('web')->user();
    $admin = $pengguna->role === 'admin';
    $slugAktif = request()->route('entitas');
    $master = [
        ['tahun-ajaran', 'Tahun Ajaran', 'kalender'],
        ['periode', 'Semester', 'jam'],
        ['jurusan', 'Jurusan', 'jurusan'],
        ['kelas', 'Kelas', 'kelas'],
        ['mapel', 'Mata Pelajaran', 'mapel'],
        ['siswa', 'Siswa', 'siswa'],
        ['staf', 'Guru & Staf', 'staf'],
        ['pengampu', 'Guru Pengampu', 'pengampu'],
    ];
@endphp

@push('kepala')
<script>
    // Pulihkan keadaan sidebar sebelum halaman digambar agar tidak berkedip.
    try { if (matchMedia('(min-width:993px)').matches && localStorage.getItem('sisi-mini') === '1') document.documentElement.classList.add('sisi-mini'); } catch (e) {}
</script>
@endpush

@section('isi')
<div class="rangka">
    <aside class="sisi" id="sisi" aria-label="Menu utama">
        <div class="sisi-kepala">
            <img class="sisi-logo" src="{{ asset('images/logo_smk.png') }}" alt="">
            <div class="sisi-merek">
                <h2>{{ config('ujian.merek') }}</h2>
                <p>{{ $pengguna->role }} · {{ config('ujian.sekolah_singkat') }}</p>
            </div>
            <button type="button" class="sisi-ciut" id="sisiCiut" title="Ciutkan menu" aria-label="Ciutkan menu">
                <x-ikon nama="panah-kiri" :ukuran="14" />
            </button>
        </div>

        <nav class="sisi-badan">
            <a href="{{ route('staf.beranda') }}" class="sisi-a {{ request()->routeIs('staf.beranda') ? 'aktif' : '' }}" data-tip="Beranda">
                <x-ikon nama="beranda" /><span class="sisi-lbl">Beranda</span>
            </a>

            <div class="sisi-seksi"><span>Ujian</span></div>
            <a href="{{ route('staf.monitor.index') }}" data-tip="Monitor Ujian"
               class="sisi-a {{ request()->routeIs('staf.monitor.*') ? 'aktif' : '' }}">
                <x-ikon nama="monitor" /><span class="sisi-lbl">Monitor Ujian</span>
            </a>

            @if ($admin || $pengguna->role === 'guru')
                @if ($admin)
                    <a href="{{ route('staf.jadwal.index') }}" data-tip="Jadwal Ujian"
                       class="sisi-a {{ request()->routeIs('staf.jadwal.*') ? 'aktif' : '' }}">
                        <x-ikon nama="jam" /><span class="sisi-lbl">Jadwal Ujian</span>
                    </a>
                    <a href="{{ route('staf.kegiatan.index') }}" data-tip="Kegiatan Ujian"
                       class="sisi-a {{ request()->routeIs('staf.kegiatan.*') ? 'aktif' : '' }}">
                        <x-ikon nama="kalender" /><span class="sisi-lbl">Kegiatan Ujian</span>
                    </a>
                    <a href="{{ route('staf.pengawas.index') }}" data-tip="Pengawas"
                       class="sisi-a {{ request()->routeIs('staf.pengawas.*') ? 'aktif' : '' }}">
                        <x-ikon nama="staf" /><span class="sisi-lbl">Pengawas</span>
                    </a>
                    <a href="{{ route('staf.ruang.index') }}" data-tip="Ruang Ujian"
                       class="sisi-a {{ request()->routeIs('staf.ruang.*') ? 'aktif' : '' }}">
                        <x-ikon nama="ruang" /><span class="sisi-lbl">Ruang Ujian</span>
                    </a>
                @endif
                <a href="{{ route('staf.koreksi.index') }}" data-tip="Koreksi Esai"
                   class="sisi-a {{ request()->routeIs('staf.koreksi.*') ? 'aktif' : '' }}">
                    <x-ikon nama="koreksi" /><span class="sisi-lbl">Koreksi Esai</span>
                </a>
                <a href="{{ route('staf.nilai.index') }}" data-tip="Input Nilai"
                   class="sisi-a {{ request()->routeIs('staf.nilai.*') ? 'aktif' : '' }}">
                    <x-ikon nama="berkas" /><span class="sisi-lbl">Input Nilai</span>
                </a>
                <a href="{{ route('staf.wali.index') }}" data-tip="Wali Kelas"
                   class="sisi-a {{ request()->routeIs('staf.wali.*') ? 'aktif' : '' }}">
                    <x-ikon nama="staf" /><span class="sisi-lbl">Wali Kelas</span>
                </a>
                <a href="{{ route('staf.rekap.index') }}" data-tip="Rekap Nilai"
                   class="sisi-a {{ request()->routeIs('staf.rekap.*') ? 'aktif' : '' }}">
                    <x-ikon nama="berkas" /><span class="sisi-lbl">Rekap Nilai</span>
                </a>
                <a href="{{ route('staf.bank-soal.index') }}" data-tip="Bank Soal"
                   class="sisi-a {{ request()->routeIs('staf.bank-soal.*', 'staf.soal.*') ? 'aktif' : '' }}">
                    <x-ikon nama="bank-soal" /><span class="sisi-lbl">Bank Soal</span>
                </a>
            @endif

            @if ($admin)
                <div class="sisi-seksi"><span>Administrator</span></div>
                @foreach ($master as [$slug, $label, $ikon])
                    <a href="{{ route('staf.master.index', $slug) }}" data-tip="{{ $label }}"
                       class="sisi-a {{ $slugAktif === $slug ? 'aktif' : '' }}">
                        <x-ikon :nama="$ikon" /><span class="sisi-lbl">{{ $label }}</span>
                    </a>
                @endforeach

                <div class="sisi-seksi"><span>Sistem</span></div>
                @if (config('ujian.mode_pengembang'))
                    <a href="{{ route('staf.sinkron.index') }}" data-tip="Diagnosis Sistem"
                       class="sisi-a {{ request()->routeIs('staf.sinkron.*') ? 'aktif' : '' }}">
                        <x-ikon nama="unduh" /><span class="sisi-lbl">Diagnosis Sistem</span>
                    </a>
                @endif
                <a href="{{ route('staf.rapor.pengaturan') }}" data-tip="Pengaturan Rapor"
                   class="sisi-a {{ request()->routeIs('staf.rapor.*') ? 'aktif' : '' }}">
                    <x-ikon nama="pengaturan" /><span class="sisi-lbl">Pengaturan Rapor</span>
                </a>
                <a href="{{ route('staf.pengaturan') }}" data-tip="Pengaturan Sekolah"
                   class="sisi-a {{ request()->routeIs('staf.pengaturan') ? 'aktif' : '' }}">
                    <x-ikon nama="pengaturan" /><span class="sisi-lbl">Pengaturan Sekolah</span>
                </a>
            @endif
        </nav>

        <div class="sisi-kaki">
            <form method="POST" action="{{ route('staf.keluar') }}" onsubmit="return confirm('Yakin ingin keluar?')">
                @csrf
                <button type="submit" class="sisi-a" data-tip="Keluar"><x-ikon nama="keluar" /><span class="sisi-lbl">Keluar</span></button>
            </form>
            <img class="sisi-padiweb" src="{{ asset('images/logo_padiweb.png') }}" alt="{{ config('ujian.pengembang') }}">
        </div>
    </aside>

    <button type="button" class="sisi-buka" id="sisiBuka" title="Tampilkan menu" aria-label="Tampilkan menu"><x-ikon nama="menu" :ukuran="16" /></button>
    <div class="tirai" id="tirai"></div>

    <header class="atas">
        <div class="atas-kiri">
            <button type="button" class="hamburger" id="hamburger" aria-label="Buka menu"><x-ikon nama="menu" /></button>
            <h1>@yield('judul')</h1>
        </div>
        <div class="lencana-pengguna" title="{{ $pengguna->nama }}">
            <span class="avatar">{{ mb_strtoupper(mb_substr($pengguna->nama, 0, 1)) }}</span>
            <span class="nama">{{ $pengguna->nama }}</span>
            <small>{{ $pengguna->role }}</small>
        </div>
    </header>

    <main class="utama">
        <div class="utama-isi">
            @if (session('berhasil'))
                <div class="info" role="status"><x-ikon nama="centang" :ukuran="18" /><span>{{ session('berhasil') }}</span></div>
            @endif
            @if (session('catatan'))
                <div class="pesan-salah catatan" role="status"><x-ikon nama="info" :ukuran="18" /><span>{{ session('catatan') }}</span></div>
            @endif
            @if (session('gagal'))
                <div class="pesan-salah" role="alert"><x-ikon nama="peringatan" :ukuran="18" /><span>{{ session('gagal') }}</span></div>
            @endif

            @yield('konten')
        </div>
    </main>
</div>
@endsection

@push('skrip')
<script>
    (function () {
        var html = document.documentElement;
        function simpan(v) { try { localStorage.setItem('sisi-mini', v); } catch (e) {} }
        document.getElementById('sisiCiut').addEventListener('click', function () { html.classList.add('sisi-mini'); simpan('1'); });
        document.getElementById('sisiBuka').addEventListener('click', function () { html.classList.remove('sisi-mini'); simpan('0'); });
        function tutup() { html.classList.remove('sisi-terbuka'); }
        document.getElementById('hamburger').addEventListener('click', function () { html.classList.add('sisi-terbuka'); });
        document.getElementById('tirai').addEventListener('click', tutup);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') tutup(); });
        document.querySelectorAll('.sisi-badan a').forEach(function (a) { a.addEventListener('click', tutup); });
    })();
</script>
@endpush
