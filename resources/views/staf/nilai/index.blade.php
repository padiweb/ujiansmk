@extends('layouts.staf')
@section('judul', 'Input Nilai')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Input Nilai</h1>
        <p class="sub">Nilai rapor per mata pelajaran dan kelas.</p>
    </div>
</div>

<form class="saring" method="GET" action="{{ route('staf.nilai.index') }}">
    <select name="periode" aria-label="Semester" onchange="this.form.submit()">
        @foreach ($daftarPeriode as $p)
            <option value="{{ $p->id }}" @selected($periode?->id === $p->id)>{{ $p->label() }}@if ($p->aktif) (aktif)@endif</option>
        @endforeach
    </select>
</form>

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel">
            <thead><tr><th>Mata pelajaran</th><th>Kelas</th>@if ($admin)<th>Guru</th>@endif<th>Siswa</th><th>Status input</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($pasangan as $p)
                    <tr>
                        <td><strong>{{ $p['mapel']->nama }}</strong></td>
                        <td>{{ $p['kelas']->nama }}</td>
                        @if ($admin)<td>{{ $p['guru'] ?? '—' }}</td>@endif
                        <td>{{ $p['siswa'] }}</td>
                        <td>
                            @if ($p['final']) <span class="lencana pucat">Final</span>
                            @elseif ($p['siswa'] > 0 && $p['terisi'] >= $p['siswa']) <span class="lencana">Lengkap</span>
                            @elseif ($p['terisi'] > 0) <span class="lencana kuning">{{ $p['terisi'] }} dari {{ $p['siswa'] }}</span>
                            @else <span class="sub">Belum diisi</span> @endif
                        </td>
                        <td class="kanan aksi-baris"><a href="{{ route('staf.nilai.tabel', [$p['mapel'], $p['kelas'], 'periode' => $periode?->id]) }}">Input</a></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $admin ? 6 : 5 }}" class="kosong">Belum ada mata pelajaran dan kelas yang diampu pada semester ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
