@extends('layouts.staf')
@section('judul', $def->judul())

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">{{ $def->judul() }}</h1>
        <p class="sub">{{ $def->tabelSendiri() ? 'Manajemen data '.\Illuminate\Support\Str::lower($def->judul()).'.' : number_format($baris->total(), 0, ',', '.').' data' }}</p>
    </div>

    @unless ($terkunci)
        <div class="aksi-halaman">
            @if ($pengimpor)
                <a class="tombol kecil garis" href="{{ route('staf.impor.form', $def->slug()) }}"><x-ikon nama="unggah" :ukuran="16" />Impor Excel</a>
            @endif
            <a class="tombol kecil" href="{{ route('staf.master.create', $def->slug()) }}"><x-ikon nama="tambah" :ukuran="16" />Tambah</a>
        </div>
    @endunless
</div>

@if ($terkunci)
    <div class="pesan-salah">Data ini dikelola dari EduWeb dan hanya bisa dibaca di sini. Ubah di EduWeb, lalu sinkronkan.</div>
@endif

<form class="bar-saring" method="GET" action="{{ route('staf.master.index', $def->slug()) }}">
    @foreach ($def->filter() as $f)
        <div class="bar-isi">
            <label for="f-{{ $f['name'] }}">{{ \Illuminate\Support\Str::ucfirst(\Illuminate\Support\Str::after($f['label'], 'Semua ')) }}</label>
            <select id="f-{{ $f['name'] }}" name="{{ $f['name'] }}" onchange="this.form.submit()">
                <option value="">{{ $f['label'] }}</option>
                @foreach ($f['options'] as $nilai => $label)
                    <option value="{{ $nilai }}" @selected((string) request($f['name']) === (string) $nilai)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    @endforeach
    @if ($def->cari())
        <div class="bar-isi bar-cari">
            <label for="f-q">Cari</label>
            <input id="f-q" type="search" name="q" value="{{ request('q') }}" placeholder="Ketik {{ implode(', ', $def->cari()) }}…" autocomplete="off">
        </div>
    @endif
    <button class="tombol kecil" type="submit">Cari</button>
    @if (collect(request()->query())->except('page')->filter()->isNotEmpty())
        <a class="tautan" href="{{ route('staf.master.index', $def->slug()) }}">Atur ulang</a>
    @endif
    <span class="bar-total">Total: <b>{{ number_format($baris->total(), 0, ',', '.') }}</b> {{ \Illuminate\Support\Str::lower($def->judul()) }}</span>
</form>

@if ($def->tabelSendiri())
    <div class="kartu tanpa-padding">
        <div class="gulir">@include($def->tabelSendiri())</div>
    </div>
@else
<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel tabel-daftar tabel-kartu">
            <thead>
                <tr>
                    <th class="no">No</th>
                    @foreach ($def->kolom() as $k)
                        <th>{{ $k[1] }}</th>
                    @endforeach
                    @unless ($terkunci)
                        <th class="kanan">Aksi</th>
                    @endunless
                </tr>
            </thead>
            <tbody>
                @forelse ($baris as $b)
                    <tr>
                        <td class="no">{{ $baris->firstItem() + $loop->index }}</td>
                        @foreach ($def->kolom() as $k)
                            <td>{{ isset($k[2]) ? $k[2]($b) : ($b->{$k[0]} ?? '—') }}</td>
                        @endforeach
                        @unless ($terkunci)
                            <td class="kanan aksi-baris">
                                <a href="{{ route('staf.master.edit', [$def->slug(), $b->id]) }}">Ubah</a>
                                <form method="POST" action="{{ route('staf.master.destroy', [$def->slug(), $b->id]) }}"
                                      onsubmit="return confirm('Hapus data ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="tautan">Hapus</button>
                                </form>
                            </td>
                        @endunless
                    </tr>
                @empty
                    <tr><td colspan="{{ count($def->kolom()) + 2 }}" class="kosong">Belum ada data.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif

{{ $baris->links('pagination.ringkas') }}
@endsection
