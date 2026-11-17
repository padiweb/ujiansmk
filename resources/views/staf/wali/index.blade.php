@extends('layouts.staf')
@section('judul', 'Wali Kelas')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Wali Kelas</h1>
        <p class="sub">Absensi, catatan, keputusan, dan finalisasi rapor. Semester: {{ $periode?->label() ?? '–' }}</p>
    </div>
    <form class="saring" style="margin:0" method="GET" action="{{ route('staf.wali.index') }}">
        <select name="periode" aria-label="Semester" onchange="this.form.submit()">
            @foreach ($daftarPeriode as $p)
                <option value="{{ $p->id }}" @selected($periode?->id === $p->id)>{{ $p->label() }}@if ($p->aktif) (aktif)@endif</option>
            @endforeach
        </select>
    </form>
</div>

@if ($periode && ! $periode->rapor_di_sini)
    <div class="pesan-salah catatan" role="status"><x-ikon nama="info" :ukuran="18" /><span>Rapor semester ini tidak diisi di aplikasi ini.</span></div>
@endif

<div class="db-panel">
    <header><h3>{{ $admin ? 'Daftar Kelas' : 'Kelas Perwalian Anda' }}</h3><small>{{ count($kelas) }} kelas</small></header>
    <div class="gulir">
        <table class="tabel tabel-daftar tabel-kartu">
            <thead><tr><th class="no">No</th><th>Kelas</th>@if ($admin)<th>Wali Kelas</th>@endif<th>Jumlah Siswa</th><th>Nilai Mapel Lengkap</th><th>Status</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($kelas as $k)
                    <tr>
                        <td class="no">{{ $loop->iteration }}</td>
                        <td><span class="lencana-kelas">{{ $k['kelas']->nama }}</span></td>
                        @if ($admin)<td>{{ $k['kelas']->waliKelas?->nama ?? '–' }}</td>@endif
                        <td>{{ $k['siswa'] }} Siswa</td>
                        <td>{{ $k['lengkap'] }} dari {{ count($k['mapel']) }} mapel</td>
                        <td>
                            @if ($k['final']) <span class="lencana pucat">Final</span>
                            @elseif (count($k['mapel']) > 0 && $k['lengkap'] >= count($k['mapel'])) <span class="lencana">Siap difinalkan</span>
                            @else <span class="lencana kuning">Belum lengkap</span> @endif
                        </td>
                        <td class="kanan"><a class="tombol kecil" href="{{ route('staf.wali.show', [$k['kelas'], 'periode' => $periode?->id]) }}">Buka Kelas <x-ikon nama="panah-kanan" :ukuran="14" /></a></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $admin ? 7 : 6 }}" class="kosong">{{ $admin ? 'Belum ada kelas pada semester ini.' : 'Anda belum ditetapkan sebagai wali kelas pada semester ini.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
