@extends('layouts.staf')
@section('judul', 'Input Nilai')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Input Nilai Rapor</h1>
        <p class="sub">Semester: {{ $periode?->label() ?? '–' }}</p>
    </div>
    <form class="saring" style="margin:0" method="GET" action="{{ route('staf.nilai.index') }}">
        <select name="periode" aria-label="Semester" onchange="this.form.submit()">
            @foreach ($daftarPeriode as $p)
                <option value="{{ $p->id }}" @selected($periode?->id === $p->id)>{{ $p->label() }}@if ($p->aktif) (aktif)@endif</option>
            @endforeach
        </select>
    </form>
</div>

@if (! $periode)
    <div class="pesan-salah catatan" role="status"><x-ikon nama="info" :ukuran="18" /><span><b>Tidak ada semester aktif.</b> Minta admin mengaktifkan semester di menu Pengaturan.</span></div>
@elseif (! $periode->rapor_di_sini)
    <div class="pesan-salah catatan" role="status"><x-ikon nama="info" :ukuran="18" /><span>Rapor semester ini tidak diisi di aplikasi ini.</span></div>
@endif

<div class="db-panel">
    <header><h3>{{ $admin ? 'Daftar Kelas Ajar' : 'Daftar Kelas Ajar Anda' }}</h3><small>{{ count($pasangan) }} kelas</small></header>
    <div class="gulir">
        <table class="tabel tabel-daftar">
            <thead>
                <tr>
                    <th class="no">No</th><th>Mata Pelajaran</th><th>Kelas</th>@if ($admin)<th>Guru</th>@endif<th>Jumlah Siswa</th><th>Status Input</th><th class="kanan">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($pasangan as $p)
                    <tr>
                        <td class="no">{{ $loop->iteration }}</td>
                        <td><span class="nama-mapel">{{ $p['mapel']->nama }}</span><small class="sub-baris">KKM: {{ $p['mapel']->kkm }}</small></td>
                        <td><span class="lencana-kelas">{{ $p['kelas']->nama }}</span></td>
                        @if ($admin)<td>{{ $p['guru'] ?? '–' }}</td>@endif
                        <td>{{ $p['siswa'] }} Siswa</td>
                        <td>
                            @if ($p['final']) <span class="lencana pucat">Final</span>
                            @elseif ($p['siswa'] == 0) <span class="lencana pucat">Kelas Kosong</span>
                            @elseif ($p['terisi'] >= $p['siswa']) <span class="lencana">Lengkap</span>
                            @elseif ($p['terisi'] > 0) <span class="lencana kuning">Sebagian: {{ $p['terisi'] }} dari {{ $p['siswa'] }}</span>
                            @else <span class="lencana pucat">Belum diisi</span> @endif
                        </td>
                        <td class="kanan"><a class="tombol kecil" href="{{ route('staf.nilai.tabel', [$p['mapel'], $p['kelas'], 'periode' => $periode?->id]) }}">Buka Kelas <x-ikon nama="panah-kanan" :ukuran="14" /></a></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $admin ? 7 : 6 }}" class="kosong"><b>Belum ada jadwal mengajar untuk semester ini.</b><br>Hubungi admin untuk menetapkan guru pengampu.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
