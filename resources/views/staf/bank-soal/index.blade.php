@extends('layouts.staf')
@section('judul', 'Bank Soal')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman judul-kecil">Bank Soal</h1>
        <p class="sub">Kelola repositori soal ujian · {{ number_format($banks->count(), 0, ',', '.') }} bank soal tersedia</p>
    </div>
</div>

<form class="bank-cari" method="GET" action="{{ route('staf.bank-soal.index') }}">
    <div class="bank-cari-isi">
        <x-ikon nama="cari" :ukuran="16" />
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Cari mata pelajaran atau nama bank…" aria-label="Cari bank soal" autocomplete="off">
    </div>
    <button class="tombol kecil" type="submit">Cari</button>
    @if (filled(request('q')))<a class="tombol kecil garis" href="{{ route('staf.bank-soal.index') }}">Reset</a>@endif
</form>

@unless (filled(request('q')))
    <div class="bank-chip-baris">
        @foreach ($perTingkat as $tingkat => $daftar)
            <div class="bank-chip"><i class="titik titik-{{ $tingkat }}"></i>Kelas {{ $tingkat }} : <strong>{{ $daftar->count() }} bank</strong></div>
        @endforeach
    </div>
@endunless

<div style="padding-bottom:80px">
@foreach ($perTingkat as $tingkat => $daftar)
    <div class="bank-bagian">
        <div class="bank-bagian-judul"><h3>Kelas {{ $tingkat }}</h3><span class="bank-hitung">{{ $daftar->count() }} bank</span></div>
        <div class="bank-garis garis-{{ $tingkat }}"></div>
    </div>

    @if ($daftar->isEmpty())
        <div class="bank-kosong"><x-ikon nama="berkas" :ukuran="30" /><p>Belum ada bank soal untuk kelas {{ $tingkat }}.</p></div>
    @else
        <div class="bank-grid">
            @foreach ($daftar as $b)
                <article class="bank-kartu kartu-{{ $tingkat }}">
                    <div class="bank-kartu-atas"><span class="bank-lencana lencana-{{ $tingkat }}">KELAS {{ $tingkat }}</span>@if ($b->jumlah_draf)<span class="lencana kuning">{{ $b->jumlah_draf }} draf</span>@endif</div>
                    <a class="bank-judul" href="{{ route('staf.bank-soal.show', $b) }}" title="{{ $b->nama }}">{{ $b->nama }}</a>
                    <div class="bank-mapel">{{ $b->mapel->nama }}@if (auth('web')->user()->role === 'admin') · {{ $b->pembuat->nama }}@endif</div>
                    <div class="bank-hitung-soal">
                        <div><b>{{ $b->jumlah_pg }}</b><span>Pilihan Ganda</span></div>
                        <div><b>{{ $b->jumlah_esai }}</b><span>Esai</span></div>
                        <div><b>{{ $b->jumlah_pg + $b->jumlah_esai }}</b><span>Total</span></div>
                    </div>
                    <div class="bank-aksi">
                        <a class="tombol kecil" href="{{ route('staf.bank-soal.show', $b) }}"><x-ikon nama="ubah" :ukuran="15" />Buka Editor</a>
                        <a class="tombol kecil garis" href="{{ route('staf.bank-soal.edit', $b) }}">Ubah</a>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
@endforeach
</div>

<a class="fab-bank" href="{{ route('staf.bank-soal.create') }}"><span class="fab-ikon"><x-ikon nama="tambah" :ukuran="16" /></span><span class="fab-label">Bank Baru</span></a>
@endsection
