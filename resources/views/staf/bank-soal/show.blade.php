@extends('layouts.staf')
@section('judul', $bank->nama)

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">{{ $bank->nama }}</h1>
        <p class="sub">{{ $bank->mapel->nama }} · Kelas {{ $bank->tingkat }} · dibuat oleh {{ $bank->pembuat->nama }}</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil garis" href="{{ route('staf.bank-soal.edit', $bank) }}"><x-ikon nama="ubah" :ukuran="16" />Ubah</a>
        <form method="POST" action="{{ route('staf.bank-soal.salin', $bank) }}">
            @csrf
            <button class="tombol kecil garis" type="submit"><x-ikon nama="salin" :ukuran="16" />Salin bank</button>
        </form>
        <form method="POST" action="{{ route('staf.bank-soal.destroy', $bank) }}" onsubmit="return confirm('Hapus bank soal ini beserta seluruh soalnya?')">
            @csrf
            @method('DELETE')
            <button class="tombol kecil bahaya" type="submit"><x-ikon nama="hapus" :ukuran="16" />Hapus</button>
        </form>
    </div>
</div>

<div class="kartu ringkasan-bank">
    <div class="ringkasan">
        <div><strong>{{ $ringkasan['pg'] }}</strong><span>pilihan ganda</span></div>
        <div><strong>{{ $ringkasan['esai'] }}</strong><span>esai</span></div>
        <div><strong>{{ $ringkasan['draf'] }}</strong><span>masih draf</span></div>
    </div>
    <div class="aksi-form">
        <a class="tombol kecil" href="{{ route('staf.soal.create', [$bank, 'tipe' => 'pg']) }}"><x-ikon nama="tambah" :ukuran="16" />Pilihan ganda</a>
        <a class="tombol kecil" href="{{ route('staf.soal.create', [$bank, 'tipe' => 'esai']) }}"><x-ikon nama="tambah" :ukuran="16" />Esai</a>
        <a class="tombol kecil" href="{{ route('staf.bank-soal.otomatis', $bank) }}"><x-ikon nama="otomatis" :ukuran="16" />Susun otomatis</a>
        <a class="tombol kecil garis" href="{{ route('staf.bank-soal.impor.form', $bank) }}"><x-ikon nama="unggah" :ukuran="16" />Impor Excel</a>
        @if ($ringkasan['draf'] > 0)
            <form method="POST" action="{{ route('staf.bank-soal.siapkan-semua', $bank) }}">
                @csrf
                <button class="tombol kecil garis" type="submit">Tandai {{ $ringkasan['draf'] }} draf sebagai siap</button>
            </form>
        @endif
    </div>
</div>

<form class="saring" method="GET" action="{{ route('staf.bank-soal.show', $bank) }}" style="margin-top:18px">
    <select name="tipe" aria-label="Jenis soal">
        <option value="">Semua jenis</option>
        <option value="pg" @selected(request('tipe') === 'pg')>Pilihan ganda</option>
        <option value="esai" @selected(request('tipe') === 'esai')>Esai</option>
    </select>
    <select name="status" aria-label="Status">
        <option value="">Semua status</option>
        <option value="siap" @selected(request('status') === 'siap')>Siap</option>
        <option value="draf" @selected(request('status') === 'draf')>Draf</option>
    </select>
    <button class="tombol kecil garis" type="submit">Terapkan</button>
</form>

@forelse ($soal as $i => $s)
    <article class="soal-kartu">
        <header class="soal-kepala">
            <span class="soal-no">{{ ($soal->currentPage() - 1) * $soal->perPage() + $i + 1 }}</span>
            <span class="lencana">{{ $s->tipe === 'pg' ? 'Pilihan ganda' : 'Esai' }}</span>
            @if ($s->status === 'draf') <span class="lencana kuning">Draf</span> @endif
            @if ($s->sumber !== 'manual') <span class="lencana pucat">{{ $s->sumber === 'otomatis' ? 'Otomatis' : 'Impor' }}</span> @endif
            <span class="soal-bobot">Bobot {{ rtrim(rtrim($s->bobot, '0'), '.') }}</span>
        </header>

        <div class="soal-teks">{!! nl2br(e($s->pertanyaan)) !!}</div>

        @if ($s->gambar)
            <img class="soal-gambar" src="{{ route('staf.soal.gambar', $s) }}" alt="Gambar soal" loading="lazy">
        @endif

        @if ($s->tipe === 'pg')
            <ul class="opsi-daftar">
                @foreach ($s->opsi as $o)
                    <li class="{{ $o->benar ? 'benar' : '' }}"><b>{{ $o->label }}.</b> {{ $o->teks }}@if ($o->benar) <span class="tanda-kunci">kunci</span>@endif</li>
                @endforeach
            </ul>
        @elseif ($s->rubrik_esai)
            <div class="rubrik"><b>Jawaban acuan:</b> {!! nl2br(e($s->rubrik_esai)) !!}</div>
        @endif

        <footer class="soal-aksi">
            <a href="{{ route('staf.soal.edit', [$bank, $s]) }}">Ubah</a>
            <form method="POST" action="{{ route('staf.soal.status', [$bank, $s]) }}">
                @csrf
                <input type="hidden" name="status" value="{{ $s->status === 'siap' ? 'draf' : 'siap' }}">
                <button type="submit" class="tautan">{{ $s->status === 'siap' ? 'Kembalikan ke draf' : 'Tandai siap' }}</button>
            </form>
            <form method="POST" action="{{ route('staf.soal.destroy', [$bank, $s]) }}" onsubmit="return confirm('Hapus soal ini?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="tautan">Hapus</button>
            </form>
        </footer>
    </article>
@empty
    <div class="kartu kosong-kartu">Belum ada soal. Tambahkan lewat tombol di atas atau impor dari Excel.</div>
@endforelse

{{ $soal->links('pagination.ringkas') }}
@endsection
