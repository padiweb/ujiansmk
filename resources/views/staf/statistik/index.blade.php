@extends('layouts.staf')
@section('judul', 'Statistik')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Statistik Nilai</h1>
        <p class="sub">{{ $keterangan }}</p>
    </div>
</div>

<form class="bar-saring" method="GET" action="{{ route('staf.statistik.index') }}">
    <div class="bar-isi">
        <label for="f-jenis">Jenis</label>
        <select id="f-jenis" name="jenis" onchange="this.form.kelas.value='';this.form.submit()">
            <option value="rapor" @selected($jenis === 'rapor')>Nilai rapor semester</option>
            <option value="us" @selected($jenis === 'us')>Ujian Sekolah (kelas XII)</option>
        </select>
    </div>
    @if ($jenis === 'us')
        <div class="bar-isi">
            <label for="f-tahun">Tahun ajaran</label>
            <select id="f-tahun" name="tahun" onchange="this.form.kelas.value='';this.form.submit()">
                @foreach ($daftarTahun as $t)
                    <option value="{{ $t->id }}" @selected($tahun?->id === $t->id)>{{ $t->nama }}@if ($t->aktif) (aktif)@endif</option>
                @endforeach
            </select>
        </div>
    @else
        <div class="bar-isi">
            <label for="f-periode">Semester</label>
            <select id="f-periode" name="periode" onchange="this.form.kelas.value='';this.form.submit()">
                @foreach ($daftarPeriode as $p)
                    <option value="{{ $p->id }}" @selected($periode?->id === $p->id)>{{ $p->label() }}@if ($p->aktif) (aktif)@endif</option>
                @endforeach
            </select>
        </div>
    @endif
    <div class="bar-isi">
        <label for="f-kelas">Kelas</label>
        <select id="f-kelas" name="kelas" onchange="this.form.submit()">
            <option value="">Semua kelas</option>
            @foreach ($daftarKelas as $k)
                <option value="{{ $k->id }}" @selected($kelasId === $k->id)>{{ $k->nama }}</option>
            @endforeach
        </select>
    </div>
</form>

@if ($siswa->isEmpty())
    <div class="kartu kosong-kartu">{{ $jenis === 'us' ? 'Belum ada nilai (rapor atau Ujian Sekolah) siswa kelas XII pada tahun ajaran ini.' : 'Belum ada nilai rapor pada semester ini.' }}</div>
@else
    <div class="db-angka rata">
        <div class="db-kotak biru"><b>{{ $siswa->count() }}</b><span>Siswa bernilai</span></div>
        <div class="db-kotak"><b>{{ number_format($siswa->avg('rata'), 2, ',', '.') }}</b><span>Rata-rata</span></div>
        <div class="db-kotak"><b>{{ number_format($siswa->max('rata'), 2, ',', '.') }}</b><span>Tertinggi</span></div>
        <div class="db-kotak"><b>{{ number_format($siswa->min('rata'), 2, ',', '.') }}</b><span>Terendah</span></div>
    </div>

    @foreach ($perKelas as $id => $baris)
        <div class="db-panel" style="margin-bottom:16px">
            <header>
                <h3>Peringkat {{ $kelasNama[$id] ?? 'Kelas' }}</h3>
                <a href="{{ route('staf.statistik.cetak', ['kelas' => $id] + $cetakParam) }}" data-cetak>Cetak</a>
            </header>
            <div class="gulir">
                <table class="tabel tabel-daftar">
                    <thead><tr><th class="no">Rank</th><th>Nama Siswa</th><th>NISN</th><th>Jumlah Mapel</th><th class="kanan">Rata-rata</th></tr></thead>
                    <tbody>
                        @foreach ($baris as $r)
                            <tr><td class="no">{{ $r->peringkat }}</td><td><strong>{{ $r->nama }}</strong></td><td>{{ $r->nisn }}</td><td>{{ $r->mapel }}</td><td class="kanan"><strong>{{ number_format($r->rata, 2, ',', '.') }}</strong></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    @unless ($kelasId)
        @foreach ($perTingkat as $tingkat => $baris)
            <div class="db-panel" style="margin-bottom:16px">
                <header><h3>Juara Paralel Kelas {{ $tingkat }}</h3><small>10 teratas</small></header>
                <div class="gulir">
                    <table class="tabel tabel-daftar">
                        <thead><tr><th class="no">Rank</th><th>Nama Siswa</th><th>Kelas</th><th class="kanan">Rata-rata</th></tr></thead>
                        <tbody>
                            @foreach ($baris as $r)
                                <tr><td class="no">{{ $r->peringkat }}</td><td><strong>{{ $r->nama }}</strong></td><td><span class="lencana-kelas">{{ $r->kelas }}</span></td><td class="kanan"><strong>{{ number_format($r->rata, 2, ',', '.') }}</strong></td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    @endunless

    @if ($jenis === 'rapor')
    <div class="db-panel">
        <header><h3>Rata-rata per Mata Pelajaran</h3><small>{{ count($perMapel) }} mapel</small></header>
        <div class="gulir">
            <table class="tabel tabel-daftar tabel-baris">
                <thead><tr><th>Mata Pelajaran</th><th>KKM</th><th>Siswa</th><th>Tuntas</th><th>Tertinggi</th><th>Terendah</th><th class="kanan">Rata-rata</th></tr></thead>
                <tbody>
                    @foreach ($perMapel as $m)
                        <tr><td>{{ $m->mapel }}</td><td>{{ $m->kkm }}</td><td>{{ $m->siswa }}</td><td>{{ $m->tuntas }} ({{ round($m->tuntas / max(1, $m->siswa) * 100) }}%)</td><td>{{ $m->tertinggi }}</td><td>{{ $m->terendah }}</td><td class="kanan"><strong>{{ number_format($m->rata, 2, ',', '.') }}</strong></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
@endif
@endsection
