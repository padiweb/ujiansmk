@extends('layouts.staf')
@section('judul', 'Wali Kelas')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Wali Kelas</h1>
        <p class="sub">Absensi, catatan, keputusan, dan finalisasi rapor kelas.</p>
    </div>
</div>

<form class="saring" method="GET" action="{{ route('staf.wali.index') }}">
    <select name="periode" aria-label="Semester" onchange="this.form.submit()">
        @foreach ($daftarPeriode as $p)
            <option value="{{ $p->id }}" @selected($periode?->id === $p->id)>{{ $p->label() }}@if ($p->aktif) (aktif)@endif</option>
        @endforeach
    </select>
</form>

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel">
            <thead><tr><th>Kelas</th>@if ($admin)<th>Wali kelas</th>@endif<th>Siswa</th><th>Nilai mapel lengkap</th><th>Status</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($kelas as $k)
                    <tr>
                        <td><strong>{{ $k['kelas']->nama }}</strong></td>
                        @if ($admin)<td>{{ $k['kelas']->waliKelas?->nama ?? '—' }}</td>@endif
                        <td>{{ $k['siswa'] }}</td>
                        <td>{{ $k['lengkap'] }} dari {{ count($k['mapel']) }}</td>
                        <td>
                            @if ($k['final']) <span class="lencana pucat">Final</span>
                            @elseif (count($k['mapel']) > 0 && $k['lengkap'] >= count($k['mapel'])) <span class="lencana">Siap difinalkan</span>
                            @else <span class="lencana kuning">Belum lengkap</span> @endif
                        </td>
                        <td class="kanan aksi-baris"><a href="{{ route('staf.wali.show', [$k['kelas'], 'periode' => $periode?->id]) }}">Buka</a></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $admin ? 6 : 5 }}" class="kosong">{{ $admin ? 'Belum ada kelas pada semester ini.' : 'Anda belum ditetapkan sebagai wali kelas pada semester ini.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
