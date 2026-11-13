@extends('layouts.staf')
@section('judul', 'Nilai Lampau')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Nilai Lampau</h1>
        <p class="sub">Nilai rapor semester lama yang belum pernah diisi lewat aplikasi ini.</p>
    </div>
</div>

@if ($daftarPeriode->isEmpty())
    <div class="pesan-salah catatan" role="status"><x-ikon nama="info" :ukuran="18" /><span>Belum ada semester untuk nilai lampau. Admin menandai semester lama di Pengaturan Rapor (matikan "Rapor diisi di aplikasi ini").</span></div>
@else
<form class="bar-saring" method="GET" action="{{ route('staf.lampau.index') }}">
    <div class="bar-isi">
        <label for="f-periode">Semester</label>
        <select id="f-periode" name="periode" onchange="this.form.kelas.value='';this.form.submit()">
            @foreach ($daftarPeriode as $p)
                <option value="{{ $p->id }}" @selected($periode?->id === $p->id)>{{ $p->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="bar-isi">
        <label for="f-kelas">Kelas</label>
        <select id="f-kelas" name="kelas" onchange="this.form.submit()">
            <option value="">Pilih kelas</option>
            @foreach ($daftarKelas as $k)
                <option value="{{ $k->id }}" @selected($kelas?->id === $k->id)>{{ $k->nama }}</option>
            @endforeach
        </select>
    </div>
    <div class="bar-isi">
        <label for="f-mapel">Mata pelajaran</label>
        <select id="f-mapel" name="mapel" onchange="this.form.submit()">
            <option value="">Pilih mapel</option>
            @foreach ($daftarMapel as $m)
                <option value="{{ $m->id }}" @selected($mapel?->id === $m->id)>{{ $m->nama }}</option>
            @endforeach
        </select>
    </div>
</form>

@if ($kelas && $mapel)
<form method="POST" action="{{ route('staf.lampau.simpan') }}">
    @csrf
    <input type="hidden" name="periode" value="{{ $periode->id }}">
    <input type="hidden" name="kelas" value="{{ $kelas->id }}">
    <input type="hidden" name="mapel" value="{{ $mapel->id }}">

    <div class="pita-info">
        <div class="pita-isi">
            <div><small>Mapel</small><b>{{ $mapel->nama }}</b></div>
            <div><small>Kelas</small><b>{{ $kelas->nama }}</b></div>
            <div><small>Semester</small><b>{{ $periode->label() }}</b></div>
            <div><small>KKM</small><b><span class="pita-kkm">{{ $mapel->kkm }}</span></b></div>
            <div><small>Siswa</small><b>{{ count($baris) }}</b></div>
        </div>
    </div>

    <div class="kartu tanpa-padding">
        <div class="gulir">
            <table class="tabel tabel-nilai">
                <thead>
                    <tr>
                        <th class="no">No</th><th class="nama">Nama Siswa</th><th class="tengah grup-np">NP</th><th class="tengah grup-nk">NK</th>
                        <th class="tengah">NA</th><th class="tengah">Pred</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($baris as $i => $b)
                        <tr>
                            <td class="no">{{ $i + 1 }}</td>
                            <td class="nama"><strong>{{ $b['siswa']->nama }}</strong><small class="sub-baris">{{ $b['siswa']->nisn }}</small></td>
                            <td class="sel-nilai"><input type="text" inputmode="decimal" name="nilai[{{ $b['siswa']->id }}][np]" value="{{ $b['np'] }}" aria-label="NP {{ $b['siswa']->nama }}"></td>
                            <td class="sel-nilai"><input type="text" inputmode="decimal" name="nilai[{{ $b['siswa']->id }}][nk]" value="{{ $b['nk'] }}" aria-label="NK {{ $b['siswa']->nama }}"></td>
                            <td class="sel-hasil na">{{ $b['na'] ?? '–' }}</td>
                            <td class="sel-hasil">{{ $b['predikat'] ?? '–' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="kosong">Belum ada siswa di kelas ini pada semester tersebut.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if (count($baris) > 0)
        <button class="tombol-simpan" type="submit"><x-ikon nama="centang" :ukuran="18" />Simpan Nilai</button>
    @endif
</form>
@else
    <div class="kartu kosong-kartu">Pilih kelas dan mata pelajaran untuk mengisi nilai.</div>
@endif
@endif
@endsection
