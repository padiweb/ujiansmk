@extends('layouts.staf')
@section('judul', 'Nilai Lampau')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Nilai Lampau</h1>
        <p class="sub">Nilai rapor semester lama untuk siswa yang sekarang duduk di kelas yang dipilih.</p>
    </div>
    @if ($admin)
        <div class="aksi-halaman"><a class="tombol kecil garis" href="{{ route('staf.lampau.impor.form') }}"><x-ikon nama="unggah" :ukuran="16" />Impor Excel</a></div>
    @endif
</div>

@if ($daftarPeriode->isEmpty())
    <div class="pesan-salah catatan" role="status"><x-ikon nama="info" :ukuran="18" /><span>Belum ada semester untuk nilai lampau. Admin perlu membuka Pengaturan Rapor, bagian Semester, lalu mematikan centang "Rapor di sini" pada semester lama dan menyimpannya.</span></div>
@else
<form class="bar-saring" method="GET" action="{{ route('staf.lampau.index') }}">
    <div class="bar-isi">
        <label for="f-periode">Semester</label>
        <select id="f-periode" name="periode" onchange="this.form.submit()">
            @foreach ($daftarPeriode as $p)
                <option value="{{ $p->id }}" @selected($periode?->id === $p->id)>{{ $p->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="bar-isi">
        <label for="f-kelas">Kelas saat ini</label>
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

    <div class="bar-saring">
        <div class="bar-isi bar-cari">
            <label for="f-kelas-lama">Nama kelas pada semester itu</label>
            <input id="f-kelas-lama" name="kelas_lama" type="text" maxlength="50" value="{{ old('kelas_lama', $kelasLama) }}" placeholder="Contoh: X TKJ 1" autocomplete="off">
        </div>
    </div>

    <div class="pita-info">
        <div class="pita-isi">
            <div><small>Mapel</small><b>{{ $mapel->nama }}</b></div>
            <div><small>Kelas saat ini</small><b>{{ $kelas->nama }}</b></div>
            <div><small>Semester</small><b>{{ $periode->label() }}</b></div>
            <div><small>KKM</small><b><span class="pita-kkm">{{ $mapel->kkm }}</span></b></div>
            <div><small>Siswa</small><b>{{ count($baris) }}</b></div>
        </div>
    </div>

    <div class="kartu tanpa-padding">
        <div class="gulir">
            <table class="tabel tabel-nilai tabel-kartu">
                <thead>
                    <tr>
                        <th class="no">No</th><th class="nama">Nama Siswa</th><th class="tengah grup-np">NP</th><th class="tengah grup-nk">NK</th>
                        <th class="tengah">NA</th><th class="tengah">Pred</th><th>Status</th>
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
                            <td class="sel-status"><span class="status-nilai {{ $b['status'] }}">{{ ['kosong' => 'Belum diisi', 'draf' => 'Draf lama', 'final' => 'Sudah final'][$b['status']] }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="kosong">Belum ada siswa di kelas ini pada semester tersebut.</td></tr>
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
