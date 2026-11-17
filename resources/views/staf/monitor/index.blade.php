@extends('layouts.staf')
@section('judul', 'Monitor Ujian')

@php
    $jendela = ['belum_dibuka' => 'Belum dibuka', 'dibuka' => 'Sedang dibuka', 'ditutup' => 'Sudah ditutup'];
@endphp

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Monitor Ujian</h1>
        <p class="sub">{{ $pengawas ? 'Pilih ruang yang akan Anda awasi.' : 'Jadwal yang sedang aktif.' }}</p>
    </div>
</div>

@if ($pengawas)
    <div class="db-panel" style="margin-bottom:18px">
        <header><h3>Pilih ruang</h3><small>{{ $ruang->count() }} ruang</small></header>
        <div class="gulir">
            <table class="tabel tabel-daftar tabel-kartu">
                <thead><tr><th class="no">No</th><th>Ruang</th><th>Jenis</th><th>Kapasitas</th><th>Siswa</th><th>Mengerjakan</th><th>Terblokir</th><th class="kanan">Aksi</th></tr></thead>
                <tbody>
                    @forelse ($ruang as $r)
                        @php $s = $ringkasRuang[$r->id] ?? ['siswa' => 0, 'mengerjakan' => 0, 'terblokir' => 0]; @endphp
                        <tr>
                            <td class="no">{{ $loop->iteration }}</td>
                            <td><a href="{{ route('staf.monitor.ruang', $r) }}"><strong>{{ $r->nama }}</strong></a></td>
                            <td>{{ $r->campur ? 'Campur tingkat' : 'Biasa' }}</td>
                            <td>{{ $r->kapasitas }}</td>
                            <td>{{ $s['siswa'] }}</td>
                            <td>{{ $s['mengerjakan'] }}</td>
                            <td>{{ $s['terblokir'] }}</td>
                            <td class="kanan aksi-baris"><a href="{{ route('staf.monitor.ruang', $r) }}">Pantau</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="kosong">Belum ada ruang. Tambahkan di menu Ruang Ujian.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

@if ($pengawas && $jadwal->isNotEmpty())
    <h2 class="judul-kartu" style="margin-top:6px">Per ujian</h2>
@endif

@if ($jadwal->isNotEmpty() || ! $pengawas)
<div class="db-panel">
    <header><h3>{{ $pengawas ? 'Jadwal ujian' : 'Jadwal aktif' }}</h3><small>{{ $jadwal->count() }} jadwal</small></header>
    <div class="gulir">
        <table class="tabel tabel-daftar tabel-kartu">
            <thead><tr><th class="no">No</th><th>Jadwal</th><th>Mata pelajaran</th><th>Waktu</th><th>Status</th><th>Mengerjakan</th><th>Terblokir</th><th>Selesai</th><th>Belum mulai</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($jadwal as $j)
                    @php $h = $hitung[$j->id] ?? collect(); @endphp
                    <tr>
                        <td class="no">{{ $loop->iteration }}</td>
                        <td><a href="{{ route('staf.monitor.show', $j) }}"><strong>{{ $j->nama }}</strong></a></td>
                        <td>{{ $j->mapel->nama }}</td>
                        <td>{{ $j->mulai_pada->format('d/m/Y H:i') }}–{{ $j->tutup_pada->format('H:i') }}</td>
                        <td>{{ $jendela[$j->jendela()] }}</td>
                        <td>{{ $h['berlangsung'] ?? 0 }}</td>
                        <td>{{ $h['diblokir'] ?? 0 }}</td>
                        <td>{{ $h['selesai'] ?? 0 }}</td>
                        <td>{{ $h['belum'] ?? 0 }}</td>
                        <td class="kanan aksi-baris"><a href="{{ route('staf.monitor.show', $j) }}">Pantau</a></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="kosong">Tidak ada jadwal ujian aktif.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
