{{-- Pilihan semester, mata pelajaran, dan kelas sebelum membuka koreksi / rekap. Kebutuhan: $rute, $kosong + data dari LayananKoreksi::dataPilihan --}}
<form class="bar-saring" method="GET" action="{{ url()->current() }}">
    <div class="bar-isi">
        <label for="f-periode">Semester</label>
        <select id="f-periode" name="periode" onchange="this.form.submit()">
            @foreach ($daftarPeriode as $p)
                <option value="{{ $p->id }}" @selected($periodeId === $p->id)>{{ $p->label() }}@if ($p->aktif) (aktif)@endif</option>
            @endforeach
            <option value="semua" @selected($periodeId === null)>Semua semester</option>
        </select>
    </div>
    <div class="bar-isi">
        <label for="f-mapel">Mata pelajaran</label>
        <select id="f-mapel" name="mapel" onchange="this.form.submit()">
            <option value="">Semua mata pelajaran</option>
            @foreach ($opsiMapel as $m)
                <option value="{{ $m->id }}" @selected($mapelId === $m->id)>{{ $m->nama }}</option>
            @endforeach
        </select>
    </div>
    <div class="bar-isi">
        <label for="f-kelas">Kelas</label>
        <select id="f-kelas" name="kelas" onchange="this.form.submit()">
            <option value="">Semua kelas</option>
            @foreach ($opsiKelas as $k)
                <option value="{{ $k->id }}" @selected($kelasId === $k->id)>{{ $k->nama }}</option>
            @endforeach
        </select>
    </div>
    <noscript><button class="tombol kecil" type="submit">Terapkan</button></noscript>
    <span class="bar-total">Total: <b>{{ count($unit) }}</b> kelas</span>
</form>

<div class="db-panel">
    <div class="gulir">
        <table class="tabel tabel-daftar">
            <thead><tr><th class="no">No</th><th>Mata Pelajaran &amp; Kelas</th><th>Jadwal Ujian</th><th>Waktu</th><th>Status</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($unit as $u)
                    @php($j = $u['jadwal'])
                    <tr>
                        <td class="no">{{ $loop->iteration }}</td>
                        <td><span class="nama-mapel">{{ $j->mapel->nama }} · {{ $u['kelas']->nama }}</span></td>
                        <td>{{ $j->nama }}</td>
                        <td>{{ $j->mulai_pada->format('d/m/Y H:i') }}</td>
                        <td>
                            @isset($kunciPerKelas)
                                @if ($j->nilaiDikunci([$u['kelas']->id])) <span class="lencana">Nilai dikunci</span> @endif
                            @endisset
                            <span class="lencana {{ $j->status === 'aktif' ? '' : 'pucat' }}">{{ $j->status === 'aktif' ? 'Aktif' : 'Selesai' }}</span>
                        </td>
                        <td class="kanan"><a class="tombol kecil" href="{{ route($rute, [$j, 'kelas' => $u['kelas']->id]) }}">Buka <x-ikon nama="panah-kanan" :ukuran="14" /></a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="kosong">{{ $kosong }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
