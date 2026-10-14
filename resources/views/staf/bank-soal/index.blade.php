@extends('layouts.staf')
@section('judul', 'Bank Soal')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Bank Soal</h1>
        <p class="sub">{{ number_format($banks->total(), 0, ',', '.') }} bank</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil" href="{{ route('staf.bank-soal.create') }}"><x-ikon nama="tambah" :ukuran="16" />Buat bank soal</a>
    </div>
</div>

<form class="saring" method="GET" action="{{ route('staf.bank-soal.index') }}">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Cari nama bank…" aria-label="Cari">
    <select name="mapel" aria-label="Mata pelajaran">
        <option value="">Semua mapel</option>
        @foreach ($daftarMapel as $id => $nama)
            <option value="{{ $id }}" @selected((string) request('mapel') === (string) $id)>{{ $nama }}</option>
        @endforeach
    </select>
    <select name="tingkat" aria-label="Tingkat">
        <option value="">Semua tingkat</option>
        @foreach (['X', 'XI', 'XII'] as $t)
            <option value="{{ $t }}" @selected(request('tingkat') === $t)>Kelas {{ $t }}</option>
        @endforeach
    </select>
    <button class="tombol kecil garis" type="submit">Terapkan</button>
</form>

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel">
            <thead>
                <tr>
                    <th>Nama bank</th><th>Mapel</th><th>Tingkat</th><th>PG</th><th>Esai</th><th>Draf</th>
                    @if (auth('web')->user()->role === 'admin') <th>Pembuat</th> @endif
                    <th class="kanan">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($banks as $b)
                    <tr>
                        <td><a href="{{ route('staf.bank-soal.show', $b) }}"><strong>{{ $b->nama }}</strong></a></td>
                        <td>{{ $b->mapel->nama }}</td>
                        <td>{{ $b->tingkat }}</td>
                        <td>{{ $b->jumlah_pg }}</td>
                        <td>{{ $b->jumlah_esai }}</td>
                        <td>@if ($b->jumlah_draf) <span class="lencana kuning">{{ $b->jumlah_draf }}</span> @else — @endif</td>
                        @if (auth('web')->user()->role === 'admin') <td>{{ $b->pembuat->nama }}</td> @endif
                        <td class="kanan aksi-baris"><a href="{{ route('staf.bank-soal.show', $b) }}">Buka</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="kosong">Belum ada bank soal. Klik "Buat bank soal" untuk memulai.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{ $banks->links('pagination.ringkas') }}
@endsection
