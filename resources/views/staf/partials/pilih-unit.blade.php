{{-- Pilihan semester, mata pelajaran, dan kelas sebelum membuka koreksi / rekap. Kebutuhan: $rute, $kosong + data dari LayananKoreksi::dataPilihan --}}
<form class="saring" method="GET" action="{{ url()->current() }}">
    <select name="periode" aria-label="Semester" onchange="this.form.submit()">
        @foreach ($daftarPeriode as $p)
            <option value="{{ $p->id }}" @selected($periodeId === $p->id)>{{ $p->label() }}@if ($p->aktif) (aktif)@endif</option>
        @endforeach
        <option value="semua" @selected($periodeId === null)>Semua semester</option>
    </select>
    <select name="mapel" aria-label="Mata pelajaran" onchange="this.form.submit()">
        <option value="">Semua mata pelajaran</option>
        @foreach ($opsiMapel as $m)
            <option value="{{ $m->id }}" @selected($mapelId === $m->id)>{{ $m->nama }}</option>
        @endforeach
    </select>
    <select name="kelas" aria-label="Kelas" onchange="this.form.submit()">
        <option value="">Semua kelas</option>
        @foreach ($opsiKelas as $k)
            <option value="{{ $k->id }}" @selected($kelasId === $k->id)>{{ $k->nama }}</option>
        @endforeach
    </select>
    <noscript><button class="tombol kecil garis" type="submit">Terapkan</button></noscript>
</form>

@forelse ($unit as $u)
    @php($j = $u['jadwal'])
    <a class="kartu kartu-monitor" href="{{ route($rute, [$j, 'kelas' => $u['kelas']->id]) }}">
        <div>
            <strong>{{ $j->mapel->nama }} · {{ $u['kelas']->nama }}</strong>
            <small class="bantuan" style="margin:2px 0 0">{{ $j->nama }} · {{ $j->mulai_pada->format('d/m/Y H:i') }}</small>
        </div>
        <div>
            @isset($kunciPerKelas)
                @if ($j->nilaiDikunci([$u['kelas']->id])) <span class="lencana">Nilai dikunci</span> @endif
            @endisset
            <span class="lencana {{ $j->status === 'aktif' ? '' : 'pucat' }}">{{ $j->status === 'aktif' ? 'Aktif' : 'Selesai' }}</span>
        </div>
    </a>
@empty
    <div class="kartu kosong-kartu">{{ $kosong }}</div>
@endforelse
