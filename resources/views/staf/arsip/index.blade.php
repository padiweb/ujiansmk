@extends('layouts.staf')
@section('judul', 'Arsip Siswa')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Arsip Siswa</h1>
        <p class="sub">Siswa yang sudah lulus atau keluar</p>
    </div>
</div>

<form class="bar-saring" method="GET" action="{{ route('staf.arsip.index') }}">
    <div class="bar-isi">
        <label for="f-status">Status</label>
        <select id="f-status" name="status" onchange="this.form.submit()">
            <option value="lulus" @selected($status === 'lulus')>Lulus</option>
            <option value="keluar" @selected($status === 'keluar')>Keluar</option>
        </select>
    </div>
    <div class="bar-isi">
        <label for="f-kelas">Kelas</label>
        <select id="f-kelas" name="kelas" onchange="this.form.submit()">
            <option value="">Semua kelas</option>
            @foreach ($daftarKelas as $k)
                <option value="{{ $k->id }}" @selected($kelasId === $k->id)>{{ $k->nama }} · {{ $k->tahunAjaran?->nama }}</option>
            @endforeach
        </select>
    </div>
    <div class="bar-isi bar-cari">
        <label for="f-q">Cari</label>
        <input id="f-q" type="search" name="q" value="{{ $q }}" placeholder="Ketik nama, NISN, atau NIS…" autocomplete="off">
    </div>
    <button class="tombol kecil" type="submit">Cari</button>
    @if ($q !== '' || $kelasId > 0)<a class="tautan" href="{{ route('staf.arsip.index', ['status' => $status]) }}">Atur ulang</a>@endif
    <span class="bar-total">Total: <b>{{ number_format($siswa->total(), 0, ',', '.') }}</b> siswa</span>
</form>

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel tabel-daftar tabel-kartu">
            <thead>
                <tr><th class="no">No</th><th>Nama Siswa</th><th>NISN</th><th>Kelas Terakhir</th><th>Rapor Final</th><th class="kanan">Dokumen</th></tr>
            </thead>
            <tbody>
                @forelse ($siswa as $s)
                    @php
                        $terakhir = $s->kelas->sortByDesc(fn ($k) => $k->tahun_ajaran_id.'-'.$k->id)->first();
                        $xii = $terakhir && $terakhir->tingkat === 'XII';
                    @endphp
                    <tr>
                        <td class="no">{{ $siswa->firstItem() + $loop->index }}</td>
                        <td><strong>{{ $s->nama }}</strong></td>
                        <td>{{ $s->nisn }}</td>
                        <td>@if ($terakhir)<span class="lencana-kelas">{{ $terakhir->nama }}</span> <small class="sub-baris">{{ $terakhir->tahunAjaran?->nama }}</small>@else – @endif</td>
                        <td>
                            @forelse (($rapor[$s->id] ?? collect())->sortBy(fn ($r) => $r->periode->tahunAjaran->nama.$r->periode->semester) as $r)
                                <a href="{{ route('staf.wali.cetak', ['kelas' => $r->kelas_id, 'periode' => $r->periode_id, 'siswa' => $s->id, 'format' => 'cetak']) }}" data-cetak>{{ ucfirst($r->periode->semester) }} {{ $r->periode->tahunAjaran->nama }}</a>@if (! $loop->last)<br>@endif
                            @empty –
                            @endforelse
                        </td>
                        <td class="kanan aksi-baris">
                            @if ($xii && $status === 'lulus')
                                <a href="{{ route('staf.kelulusan.cetak', ['jenis' => 'transkrip', 'kelas' => $terakhir->id, 'siswa' => $s->id]) }}" data-cetak>Transkrip</a>
                                <a href="{{ route('staf.kelulusan.cetak', ['jenis' => 'skl', 'kelas' => $terakhir->id, 'siswa' => $s->id]) }}" data-cetak>SKL</a>
                                <a href="{{ route('staf.kelulusan.cetak', ['jenis' => 'mutasi', 'kelas' => $terakhir->id, 'siswa' => $s->id]) }}" data-cetak>Mutasi</a>
                            @else –
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="kosong">Belum ada siswa {{ $status }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{ $siswa->links('pagination.ringkas') }}
@endsection
