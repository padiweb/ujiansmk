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

    // Bilah tab bawah (tampilan HP): empat pintasan utama sesuai peran, ditambah tombol Menu.
    $tab = [['staf.beranda', 'Beranda', 'beranda', 'staf.beranda']];
    if ($admin) {
        $tab[] = ['staf.monitor.index', 'Monitor', 'monitor', 'staf.monitor.*'];
        $tab[] = ['staf.jadwal.index', 'Jadwal', 'jam', 'staf.jadwal.*'];
        $tab[] = ['staf.bank-soal.index', 'Bank Soal', 'berkas', 'staf.bank-soal.*'];
    } elseif ($pengguna->role === 'guru') {
        $tab[] = ['staf.bank-soal.index', 'Bank Soal', 'berkas', 'staf.bank-soal.*'];
        $tab[] = ['staf.koreksi.index', 'Koreksi', 'koreksi', 'staf.koreksi.*'];
        $tab[] = $pengguna->mengawasi()
            ? ['staf.monitor.index', 'Monitor', 'monitor', 'staf.monitor.*']
            : ['staf.nilai.index', 'Nilai', 'berkas', 'staf.nilai.*'];
    } else {
        $tab[] = ['staf.monitor.index', 'Monitor', 'monitor', 'staf.monitor.*'];
    }

    // Menu samping, diurutkan menurut alur kerja: siapkan soal → atur ujian → awasi → nilai → rapor/kelulusan → data induk → sistem.
    $jenis = request()->route('jenis');
    $guruAtauAdmin = $admin || $pengguna->role === 'guru';
    $butir = fn (string $rute, string $label, string $ikon, string $pola) => [route($rute), $label, $ikon, request()->routeIs($pola)];
    $menu = [['judul' => null, 'isi' => [$butir('staf.beranda', 'Beranda', 'beranda', 'staf.beranda')]]];

    if ($guruAtauAdmin) {
        $menu[] = ['judul' => 'Siapkan soal', 'isi' => [
            [route('staf.bank-soal.index'), 'Bank Soal', 'bank-soal', request()->routeIs('staf.bank-soal.*', 'staf.soal.*')],
        ]];
    }
    if ($admin) {
        $menu[] = ['judul' => 'Atur ujian', 'isi' => [
            $butir('staf.kegiatan.index', 'Kegiatan Ujian', 'kalender', 'staf.kegiatan.*'),
            $butir('staf.jadwal.index', 'Jadwal Ujian', 'jam', 'staf.jadwal.*'),
            $butir('staf.ruang.index', 'Ruang Ujian', 'ruang', 'staf.ruang.*'),
            $butir('staf.pengawas.index', 'Pengawas', 'staf', 'staf.pengawas.*'),
        ]];
    }
    $menu[] = ['judul' => $guruAtauAdmin ? 'Awasi ujian' : 'Ujian', 'isi' => [$butir('staf.monitor.index', 'Monitor Ujian', 'monitor', 'staf.monitor.*')]];

    if ($guruAtauAdmin) {
        $nilai = [
            $butir('staf.koreksi.index', 'Koreksi Esai', 'koreksi', 'staf.koreksi.*'),
            $butir('staf.nilai.index', 'Input Nilai', 'berkas', 'staf.nilai.*'),
            $butir('staf.us.index', 'Input Nilai US', 'berkas', 'staf.us.*'),
            $butir('staf.lampau.index', 'Nilai Lampau', 'berkas', 'staf.lampau.*'),
            $butir('staf.wali.index', 'Wali Kelas', 'staf', 'staf.wali.*'),
            $butir('staf.rekap.index', 'Rekap Nilai', 'berkas', 'staf.rekap.*'),
        ];
        if ($admin) {
            $nilai[] = $butir('staf.statistik.index', 'Statistik', 'berkas', 'staf.statistik.*');
        }
        $menu[] = ['judul' => 'Nilai', 'isi' => $nilai];
    }
    if ($admin) {
        $menu[] = ['judul' => 'Kelulusan & arsip', 'isi' => [
            [route('staf.kelulusan.daftar', 'transkrip'), 'Transkrip US', 'berkas', request()->routeIs('staf.kelulusan.*') && $jenis === 'transkrip'],
            [route('staf.kelulusan.daftar', 'skl'), 'Cetak SKL', 'berkas', request()->routeIs('staf.kelulusan.*') && $jenis === 'skl'],
            [route('staf.kelulusan.daftar', 'mutasi'), 'Surat Mutasi', 'berkas', request()->routeIs('staf.kelulusan.*') && $jenis === 'mutasi'],
            $butir('staf.arsip.index', 'Arsip Siswa', 'berkas', 'staf.arsip.*'),
            $butir('staf.hasil-lama.index', 'Arsip Hasil Ujian', 'berkas', 'staf.hasil-lama.*'),
        ]];

        $induk = [];
        foreach ($master as [$slug, $label, $ikon]) {
            $induk[] = [route('staf.master.index', $slug), $label, $ikon, $slugAktif === $slug];
        }
        $menu[] = ['judul' => 'Data induk', 'isi' => $induk];

        $sistem = [];
        if (config('ujian.mode_pengembang')) {
            $sistem[] = $butir('staf.sinkron.index', 'Diagnosis Sistem', 'unduh', 'staf.sinkron.*');
        }
        $sistem[] = $butir('staf.eduweb', 'Sinkron EduWeb', 'unduh', 'staf.eduweb*');
        $sistem[] = $butir('staf.rapor.pengaturan', 'Pengaturan Rapor', 'pengaturan', 'staf.rapor.*');
        $sistem[] = $butir('staf.pengaturan', 'Pengaturan Sekolah', 'pengaturan', 'staf.pengaturan');
        $menu[] = ['judul' => 'Sistem', 'isi' => $sistem];
    }
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
            @foreach ($menu as $seksi)
                @if ($seksi['judul'])<div class="sisi-seksi"><span>{{ $seksi['judul'] }}</span></div>@endif
                @foreach ($seksi['isi'] as [$url, $label, $ikon, $aktif])
                    <a href="{{ $url }}" data-tip="{{ $label }}" class="sisi-a {{ $aktif ? 'aktif' : '' }}" @if ($aktif) aria-current="page" @endif>
                        <x-ikon :nama="$ikon" /><span class="sisi-lbl">{{ $label }}</span>
                    </a>
                @endforeach
            @endforeach
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

    <nav class="bawah" aria-label="Menu cepat">
        <svg class="bawah-latar" aria-hidden="true" focusable="false"><path d="" fill="#fff"/></svg>
        <span class="bawah-bola" aria-hidden="true"></span>
        @foreach ($tab as [$rute, $label, $ikon, $pola])
            <a href="{{ route($rute) }}" class="bawah-a {{ request()->routeIs($pola) ? 'aktif' : '' }}" @if (request()->routeIs($pola)) aria-current="page" @endif>
                <x-ikon :nama="$ikon" :ukuran="25" /><span>{{ $label }}</span>
            </a>
        @endforeach
        <button type="button" class="bawah-a" id="bawahMenu" aria-label="Buka semua menu"><x-ikon nama="menu" :ukuran="25" /><span>Menu</span></button>
    </nav>
</div>
@endsection

@push('skrip')
<script src="{{ asset('js/cetak-bingkai.js') }}?v={{ filemtime(public_path('js/cetak-bingkai.js')) }}" defer></script>
<script src="{{ asset('js/tabel-kartu.js') }}?v={{ filemtime(public_path('js/tabel-kartu.js')) }}" defer></script>
<script src="{{ asset('js/bilah-bawah.js') }}?v={{ filemtime(public_path('js/bilah-bawah.js')) }}" defer></script>
<script>
    (function () {
        var html = document.documentElement;
        function simpan(v) { try { localStorage.setItem('sisi-mini', v); } catch (e) {} }
        document.getElementById('sisiCiut').addEventListener('click', function () { html.classList.add('sisi-mini'); simpan('1'); });
        document.getElementById('sisiBuka').addEventListener('click', function () { html.classList.remove('sisi-mini'); simpan('0'); });
        function tutup() { html.classList.remove('sisi-terbuka'); }
        document.getElementById('hamburger').addEventListener('click', function () { html.classList.add('sisi-terbuka'); });
        document.getElementById('bawahMenu').addEventListener('click', function () { html.classList.add('sisi-terbuka'); });
        document.getElementById('tirai').addEventListener('click', tutup);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') tutup(); });
        document.querySelectorAll('.sisi-badan a').forEach(function (a) { a.addEventListener('click', tutup); });
    })();
</script>
@endpush
