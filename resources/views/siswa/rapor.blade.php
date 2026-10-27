@extends('layouts.dasar')
@section('judul', 'Rapor '.$periode->label())

@php
    $keputusan = null;
    if ($l['bagian_keputusan']) {
        $keputusan = match (true) {
            $l['xii'] && $l['keputusan'] === 'lulus' => 'Lulus',
            $l['xii'] && $l['keputusan'] === 'tinggal' => 'Tidak lulus',
            $l['keputusan'] === 'naik' => 'Naik ke kelas '.$l['kelas_tujuan'],
            $l['keputusan'] === 'tinggal' => 'Tidak naik, tetap di kelas '.$l['kelas'],
            default => null,
        };
    }
@endphp

@section('isi')
<header class="siswa-atas">
    <h1><img src="{{ asset('images/logo_smk.png') }}" alt="">{{ config('ujian.merek') }}</h1>
    <a class="tombol kecil garis" href="{{ route('siswa.beranda') }}"><x-ikon nama="panah-kiri" :ukuran="16" />Beranda</a>
</header>

<main class="siswa-isi">
    <div class="kartu" style="margin-top:0">
        <h2 class="judul-kartu" style="font-size:1.2rem">Rapor {{ $l['semester'] === 'Genap' ? 'Semester Genap' : 'Semester Gasal' }} · {{ $l['tahun_ajaran'] }}</h2>
        <p class="sub" style="color:var(--teks-3)">{{ $siswa->nama }} · NISN {{ $siswa->nisn }} · Kelas {{ $l['kelas'] }}</p>
    </div>

    <h2 class="judul-bagian">Nilai mata pelajaran</h2>
    @foreach ([['Kelompok Umum', $l['umum']], ['Kelompok Kejuruan', $l['kejuruan']]] as [$nama, $daftar])
        @if (count($daftar) > 0)
            <div class="kartu tanpa-padding" style="margin-top:0;margin-bottom:12px">
                <div class="gulir">
                    <table class="tabel">
                        <thead><tr><th>{{ $nama }}</th><th class="tengah">NP</th><th class="tengah">NK</th><th class="tengah">NA</th><th class="tengah">Predikat</th></tr></thead>
                        <tbody>
                            @foreach ($daftar as $m)
                                <tr>
                                    <td>{{ $m['nama'] }}</td>
                                    <td class="tengah">{{ $m['np'] ?: '-' }}</td>
                                    <td class="tengah">{{ $m['nk'] ?: '-' }}</td>
                                    <td class="tengah"><strong>{{ $m['na'] ?? '-' }}</strong></td>
                                    <td class="tengah">{{ $m['predikat'] ?: '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endforeach
    @if (count($l['umum']) + count($l['kejuruan']) === 0)
        <div class="kartu kosong-kartu">Belum ada nilai mata pelajaran.</div>
    @endif

    <h2 class="judul-bagian">Ekstrakurikuler</h2>
    <div class="kartu tanpa-padding" style="margin-top:0">
        <div class="gulir">
            <table class="tabel">
                <thead><tr><th>Kegiatan</th><th class="tengah">Nilai</th><th class="tengah">Predikat</th><th>Keterangan</th></tr></thead>
                <tbody>
                    @forelse ($l['ekskul'] as $e)
                        <tr><td>{{ $e['nama'] }}</td><td class="tengah">{{ $e['nilai'] ?? '-' }}</td><td class="tengah">{{ $e['predikat'] ?: '-' }}</td><td>{{ $e['keterangan'] }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="kosong">Tidak ada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <h2 class="judul-bagian">Ketidakhadiran</h2>
    <div class="kartu tanpa-padding" style="margin-top:0">
        <table class="tabel">
            <tbody>
                <tr><td>Sakit</td><td class="kanan">{{ (int) $l['sakit'] }} hari</td></tr>
                <tr><td>Izin</td><td class="kanan">{{ (int) $l['izin'] }} hari</td></tr>
                <tr><td>Tanpa keterangan (alpa)</td><td class="kanan">{{ (int) $l['alpa'] }} hari</td></tr>
            </tbody>
        </table>
    </div>

    @if (filled($l['catatan']))
        <h2 class="judul-bagian">Catatan wali kelas</h2>
        <div class="kartu" style="margin-top:0">{!! nl2br(e($l['catatan'])) !!}</div>
    @endif

    @if ($keputusan)
        <h2 class="judul-bagian">{{ $l['xii'] ? 'Kelulusan' : 'Kenaikan kelas' }}</h2>
        <div class="kartu" style="margin-top:0"><strong>{{ $keputusan }}</strong></div>
    @endif

    <p class="bantuan" style="margin-top:16px">Rapor resmi (berkertas, bertanda tangan) diserahkan oleh wali kelas.</p>
</main>
@endsection
