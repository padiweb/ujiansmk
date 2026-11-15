@extends('layouts.staf')
@section('judul', 'Arsip Hasil Ujian')

@php $angka = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ','); @endphp

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Arsip Hasil Ujian</h1>
        <p class="sub">Hasil ujian CBT dari aplikasi lama</p>
    </div>
</div>

<form class="bar-saring" method="GET" action="{{ route('staf.hasil-lama.index') }}">
    <div class="bar-isi">
        <label for="f-periode">Semester</label>
        <select id="f-periode" name="periode" onchange="this.form.submit()">
            <option value="">Semua semester</option>
            @foreach ($daftarPeriode as $p)
                <option value="{{ $p->id }}" @selected($periode === $p->id)>{{ $p->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="bar-isi">
        <label for="f-mapel">Mata pelajaran</label>
        <select id="f-mapel" name="mapel" onchange="this.form.submit()">
            <option value="">Semua</option>
            @foreach ($daftarMapel as $m)
                <option value="{{ $m }}" @selected($mapel === $m)>{{ $m }}</option>
            @endforeach
        </select>
    </div>
    <div class="bar-isi">
        <label for="f-kelas">Kelas</label>
        <select id="f-kelas" name="kelas" onchange="this.form.submit()">
            <option value="">Semua</option>
            @foreach ($daftarKelas as $k)
                <option value="{{ $k }}" @selected($kelas === $k)>{{ $k }}</option>
            @endforeach
        </select>
    </div>
    <div class="bar-isi bar-cari">
        <label for="f-q">Cari</label>
        <input id="f-q" type="search" name="q" value="{{ $q }}" placeholder="Nama atau NISN…" autocomplete="off">
    </div>
    <button class="tombol kecil" type="submit">Cari</button>
    @if ($periode || $mapel !== '' || $kelas !== '' || $q !== '')<a class="tautan" href="{{ route('staf.hasil-lama.index') }}">Atur ulang</a>@endif
    <span class="bar-total">Total: <b>{{ number_format($hasil->total(), 0, ',', '.') }}</b> hasil</span>
</form>

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel tabel-daftar">
            <thead>
                <tr><th class="no">No</th><th>Siswa</th><th>Kelas</th><th>Ujian</th><th>Semester</th><th class="kanan">Benar</th><th class="kanan">Salah</th><th class="kanan">Nilai PG</th><th class="kanan">Nilai Esai</th><th class="kanan">Nilai Akhir</th><th>Selesai</th></tr>
            </thead>
            <tbody>
                @forelse ($hasil as $h)
                    <tr>
                        <td class="no">{{ $hasil->firstItem() + $loop->index }}</td>
                        <td><strong>{{ $h->siswa->nama }}</strong><small class="sub-baris">{{ $h->siswa->nisn }}</small></td>
                        <td>{{ $h->kelas_nama ?? '—' }}</td>
                        <td>{{ $h->mapel_nama }}@unless ($h->sudah_dikoreksi) <span class="lencana kuning">Belum dikoreksi</span>@endunless</td>
                        <td>{{ $h->periode->label() }}</td>
                        <td class="kanan">{{ $h->benar }}</td>
                        <td class="kanan">{{ $h->salah }}</td>
                        <td class="kanan">{{ $angka($h->skor_pg) }}</td>
                        <td class="kanan">{{ $angka($h->skor_esai) }}</td>
                        <td class="kanan"><strong>{{ $angka($h->skor_akhir) }}</strong></td>
                        <td>{{ $h->selesai_pada?->format('d/m/Y H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="kosong">Belum ada hasil ujian lama.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{ $hasil->links('pagination.ringkas') }}
@endsection
