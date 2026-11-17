@extends('layouts.staf')
@section('judul', 'Input Nilai US')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Input Nilai Ujian Sekolah</h1>
        <p class="sub">Kelas XII{{ $periodeUs ? ' · '.$periodeUs->label() : '' }}</p>
    </div>
</div>

@unless ($periodeUs)
    <div class="pesan-salah catatan" role="status"><x-ikon nama="info" :ukuran="18" /><span>Belum ada semester bertipe Ujian Sekolah, jadi hasil ujian CBT belum bisa terisi otomatis. Nilai tetap bisa diketik manual.@if ($admin) Tambahkan di <a href="{{ route('staf.rapor.pengaturan') }}">Pengaturan Rapor</a>, kartu "Semester Ujian Sekolah".@endif</span></div>
@endunless

<div class="db-panel">
    <header><h3>{{ $admin ? 'Daftar Kelas XII' : 'Daftar Kelas XII Anda' }}</h3><small>{{ count($pasangan) }} kelas</small></header>
    <div class="gulir">
        <table class="tabel tabel-daftar">
            <thead>
                <tr><th class="no">No</th><th>Mata Pelajaran</th><th>Kelas</th>@if ($admin)<th>Guru</th>@endif<th>Jumlah Siswa</th><th>Status Input</th><th class="kanan">Aksi</th></tr>
            </thead>
            <tbody>
                @forelse ($pasangan as $p)
                    <tr>
                        <td class="no">{{ $loop->iteration }}</td>
                        <td><span class="nama-mapel">{{ $p['mapel']->nama }}</span><small class="sub-baris">KKM: {{ $p['mapel']->kkm }}</small></td>
                        <td><span class="lencana-kelas">{{ $p['kelas']->nama }}</span></td>
                        @if ($admin)<td>{{ $p['guru'] ?? '–' }}</td>@endif
                        <td>{{ $p['siswa'] }}</td>
                        <td>
                            @if ($p['siswa'] === 0) Kelas Kosong
                            @elseif ($p['terisi'] >= $p['siswa']) Lengkap
                            @elseif ($p['terisi'] > 0) Sebagian: {{ $p['terisi'] }} dari {{ $p['siswa'] }}
                            @else Belum diisi @endif
                        </td>
                        <td class="kanan aksi-baris"><a href="{{ route('staf.us.tabel', [$p['mapel'], $p['kelas']]) }}">Buka Kelas →</a></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $admin ? 7 : 6 }}" class="kosong">Belum ada mapel kelas XII yang Anda ampu.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
