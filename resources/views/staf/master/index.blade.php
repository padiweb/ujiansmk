@extends('layouts.staf')
@section('judul', $def->judul())

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">{{ $def->judul() }}</h1>
        <p class="sub">{{ number_format($baris->total(), 0, ',', '.') }} data</p>
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

<form class="saring" method="GET" action="{{ route('staf.master.index', $def->slug()) }}">
    @if ($def->cari())
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Cari…" aria-label="Cari">
    @endif
    @foreach ($def->filter() as $f)
        <select name="{{ $f['name'] }}" aria-label="{{ $f['label'] }}">
            <option value="">{{ $f['label'] }}</option>
            @foreach ($f['options'] as $nilai => $label)
                <option value="{{ $nilai }}" @selected((string) request($f['name']) === (string) $nilai)>{{ $label }}</option>
            @endforeach
        </select>
    @endforeach
    <button class="tombol kecil garis" type="submit">Terapkan</button>
</form>

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel">
            <thead>
                <tr>
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
                    <tr><td colspan="{{ count($def->kolom()) + 1 }}" class="kosong">Belum ada data.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{ $baris->links('pagination.ringkas') }}
@endsection
