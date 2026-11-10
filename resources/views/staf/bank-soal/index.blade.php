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

<form class="bar-saring" method="GET" action="{{ route('staf.bank-soal.index') }}">
    <div class="bar-isi">
        <label for="f-mapel">Mata pelajaran</label>
        <select id="f-mapel" name="mapel" onchange="this.form.submit()">
            <option value="">Semua mapel</option>
            @foreach ($daftarMapel as $id => $nama)
                <option value="{{ $id }}" @selected((string) request('mapel') === (string) $id)>{{ $nama }}</option>
            @endforeach
        </select>
    </div>
    <div class="bar-isi">
        <label for="f-tingkat">Tingkat</label>
        <select id="f-tingkat" name="tingkat" onchange="this.form.submit()">
            <option value="">Semua tingkat</option>
            @foreach (['X', 'XI', 'XII'] as $t)
                <option value="{{ $t }}" @selected(request('tingkat') === $t)>Kelas {{ $t }}</option>
            @endforeach
        </select>
    </div>
    <div class="bar-isi bar-cari">
        <label for="f-q">Cari</label>
        <input id="f-q" type="search" name="q" value="{{ request('q') }}" placeholder="Ketik nama bank…" autocomplete="off">
    </div>
    <button class="tombol kecil" type="submit">Cari</button>
    @if (collect(request()->query())->except('page')->filter()->isNotEmpty())
        <a class="tautan" href="{{ route('staf.bank-soal.index') }}">Atur ulang</a>
    @endif
    <span class="bar-total">Total: <b>{{ number_format($banks->total(), 0, ',', '.') }}</b> bank</span>
</form>

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel tabel-daftar">
            <thead>
                <tr>
                    <th class="no">No</th><th>Nama bank</th><th>Mata pelajaran</th><th>Tingkat</th><th>PG</th><th>Esai</th><th>Draf</th>
                    @if (auth('web')->user()->role === 'admin') <th>Pembuat</th> @endif
                    <th class="kanan">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($banks as $b)
                    <tr>
                        <td class="no">{{ $banks->firstItem() + $loop->index }}</td>
                        <td><a href="{{ route('staf.bank-soal.show', $b) }}"><strong>{{ $b->nama }}</strong></a></td>
                        <td>{{ $b->mapel->nama }}</td>
                        <td><span class="lencana-kelas">{{ $b->tingkat }}</span></td>
                        <td>{{ $b->jumlah_pg }}</td>
                        <td>{{ $b->jumlah_esai }}</td>
                        <td>@if ($b->jumlah_draf) <span class="lencana kuning">{{ $b->jumlah_draf }} draf</span> @else — @endif</td>
                        @if (auth('web')->user()->role === 'admin') <td>{{ $b->pembuat->nama }}</td> @endif
                        <td class="kanan aksi-baris"><a href="{{ route('staf.bank-soal.show', $b) }}">Buka</a></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ auth('web')->user()->role === 'admin' ? 9 : 8 }}" class="kosong">Belum ada bank soal. Klik "Buat bank soal" untuk memulai.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{ $banks->links('pagination.ringkas') }}
@endsection
