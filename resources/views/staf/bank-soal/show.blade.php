@extends('layouts.staf')
@section('judul', $bank->nama)

@push('skrip')
    @include('staf.partials.skrip-rumus')
    <script src="{{ asset('js/editor-soal.js') }}?v={{ filemtime(public_path('js/editor-soal.js')) }}" defer></script>
@endpush

@php
    $soalPg = $soal->where('tipe', 'pg')->values();
    $soalEsai = $soal->where('tipe', 'esai')->values();
    $tabAwal = request('tipe') === 'esai' ? 'esai' : (request('tab') === 'bacaan' ? 'bacaan' : 'pg');
    $otomatis = \App\Models\PenyediaOtomatis::tersedia();
@endphp

@section('konten')
<div class="remah">
    <a href="{{ route('staf.bank-soal.index') }}">Bank Soal</a><span>›</span><span>{{ $bank->mapel->nama }} — Kelas {{ $bank->tingkat }}</span>
</div>

<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman judul-kecil">Editor Soal: {{ $bank->nama }} <span class="judul-redup">(Kelas {{ $bank->tingkat }})</span></h1>
        <a class="tautan-kembali" href="{{ route('staf.bank-soal.index') }}">← Kembali ke Bank Soal</a>
    </div>
</div>

<div class="strip-bank">
    <span><strong>Mapel:</strong> {{ $bank->mapel->nama }}</span>
    <span><strong>Dibuat oleh:</strong> {{ $bank->pembuat->nama }}</span>
    <span class="strip-aksi">
        <a class="tombol kecil garis" href="{{ route('staf.bank-soal.edit', $bank) }}"><x-ikon nama="ubah" :ukuran="14" />Ubah</a>
        <form method="POST" action="{{ route('staf.bank-soal.salin', $bank) }}">@csrf<button class="tombol kecil garis" type="submit"><x-ikon nama="salin" :ukuran="14" />Salin bank</button></form>
        <form method="POST" action="{{ route('staf.bank-soal.destroy', $bank) }}" onsubmit="return confirm('Hapus bank soal ini beserta seluruh soalnya?')">@csrf @method('DELETE')<button class="tombol kecil bahaya" type="submit"><x-ikon nama="hapus" :ukuran="14" />Hapus</button></form>
    </span>
</div>

<div class="stat-editor">
    <div class="stat-kotak"><span class="stat-ikon pg">PG</span><div><b>{{ $ringkasan['pg'] }}</b><small>Soal PG</small></div></div>
    <div class="stat-kotak"><span class="stat-ikon es">Es</span><div><b>{{ $ringkasan['esai'] }}</b><small>Soal Esai</small></div></div>
    <div class="stat-kotak"><span class="stat-ikon tot">∑</span><div><b>{{ $ringkasan['pg'] + $ringkasan['esai'] }}</b><small>Total Soal</small></div></div>
    @if ($ringkasan['pg'] + $ringkasan['esai'] > 0)
        <a class="stat-kotak stat-tautan" href="{{ route('staf.bank-soal.kisi', $bank) }}" data-cetak><x-ikon nama="cetak" :ukuran="18" />Cetak Kisi-Kisi</a>
    @endif
    @if ($ringkasan['draf'] > 0)
        <form class="stat-kotak stat-draf" method="POST" action="{{ route('staf.bank-soal.siapkan-semua', $bank) }}">@csrf
            <span><b>{{ $ringkasan['draf'] }}</b> <small>soal masih draf</small></span>
            <button class="tombol kecil garis" type="submit">Tandai semua siap</button>
        </form>
    @endif
</div>

<div class="tab-soal" role="tablist">
    <button type="button" class="tab-soal-tombol" data-tab="pg" role="tab"><x-ikon nama="centang" :ukuran="13" />Pilihan Ganda <span class="tab-jumlah">{{ $ringkasan['pg'] }}</span></button>
    <button type="button" class="tab-soal-tombol" data-tab="esai" role="tab"><x-ikon nama="berkas" :ukuran="13" />Esai <span class="tab-jumlah">{{ $ringkasan['esai'] }}</span></button>
    <button type="button" class="tab-soal-tombol" data-tab="bacaan" role="tab"><x-ikon nama="info" :ukuran="13" />Bacaan <span class="tab-jumlah">{{ $daftarBacaan->count() }}</span></button>
</div>

<div id="editorSoal" data-awal="{{ $tabAwal }}">
    @foreach (['pg' => [$soalPg, 'pilihan ganda', 'Soal Pilihan Ganda'], 'esai' => [$soalEsai, 'esai', 'Soal Esai']] as $kode => [$daftar, $nama, $tombol])
        <section class="panel-soal" data-panel="{{ $kode }}" role="tabpanel">
            @forelse ($daftar as $i => $s)
                <article class="soal-kartu">
                    <header class="soal-kepala">
                        <span class="soal-no {{ $kode }}">{{ $i + 1 }}</span>
                        <span class="soal-jenis">{{ $kode === 'pg' ? 'PILIHAN GANDA' : 'ESAI' }}</span>
                        @if ($s->level)<span class="lencana pucat">{{ $s->level }}</span>@endif
                        @if ($s->status === 'draf')<span class="lencana kuning">Draf</span>@endif
                        @if ($s->sumber !== 'manual')<span class="lencana pucat">{{ $s->sumber === 'otomatis' ? 'Otomatis' : 'Impor' }}</span>@endif
                        <span class="soal-aksi-kepala">
                            <a class="tombol kecil garis" href="{{ route('staf.soal.edit', [$bank, $s]) }}"><x-ikon nama="ubah" :ukuran="13" />Edit</a>
                            <form method="POST" action="{{ route('staf.soal.destroy', [$bank, $s]) }}" onsubmit="return confirm('Hapus soal {{ $kode === 'pg' ? 'PG' : 'esai' }} nomor {{ $i + 1 }}?')">@csrf @method('DELETE')<button class="tombol kecil bahaya" type="submit"><x-ikon nama="hapus" :ukuran="13" />Hapus</button></form>
                        </span>
                    </header>

                    @if ($s->bacaan)
                        <details class="soal-bacaan">
                            <summary>Bacaan: {{ $s->bacaan->label() }}</summary>
                            <div class="render-rumus">{!! nl2br(e($s->bacaan->teks)) !!}</div>
                            @if ($s->bacaan->gambar)<img class="bacaan-gambar" src="{{ route('staf.bacaan.gambar', $s->bacaan) }}" alt="Gambar bacaan" loading="lazy">@endif
                        </details>
                    @endif

                    <div class="soal-teks render-rumus">{!! nl2br(e($s->pertanyaan)) !!}</div>
                    @if ($s->gambar)<img class="soal-gambar" src="{{ route('staf.soal.gambar', $s) }}" alt="Gambar soal" loading="lazy">@endif

                    @if ($s->tipe === 'pg')
                        <div class="opsi-grid">
                            @foreach ($s->opsi as $o)
                                <div class="opsi-kotak {{ $o->benar ? 'benar' : '' }}">
                                    <b>{{ $o->label }}.</b>
                                    <span><span class="render-rumus">{{ $o->teks }}</span>@if ($o->benar) ✓@endif
                                        @if ($o->gambar)<img class="opsi-gambar-tampil" src="{{ route('staf.soal.gambar-opsi', $o) }}" alt="Gambar opsi {{ $o->label }}" loading="lazy">@endif</span>
                                </div>
                            @endforeach
                        </div>
                    @elseif ($s->rubrik_esai)
                        <div class="rubrik"><b>Jawaban acuan:</b> <span class="render-rumus">{!! nl2br(e($s->rubrik_esai)) !!}</span></div>
                    @endif

                    @if ($s->pembahasan)
                        <div class="rubrik"><b>Pembahasan:</b> <span class="render-rumus">{!! nl2br(e($s->pembahasan)) !!}</span></div>
                    @endif

                    <footer class="soal-aksi">
                        <span class="soal-bobot">Bobot {{ rtrim(rtrim($s->bobot, '0'), '.') }}</span>
                        <form method="POST" action="{{ route('staf.soal.status', [$bank, $s]) }}">
                            @csrf
                            <input type="hidden" name="status" value="{{ $s->status === 'siap' ? 'draf' : 'siap' }}">
                            <button type="submit" class="tautan">{{ $s->status === 'siap' ? 'Kembalikan ke draf' : 'Tandai siap' }}</button>
                        </form>
                    </footer>
                </article>
            @empty
                <div class="soal-kosong">
                    <p>Belum ada soal {{ $nama }}</p>
                    <small>Klik tombol <strong>+</strong> di kanan bawah lalu pilih <strong>+ {{ $tombol }}</strong></small>
                </div>
            @endforelse
        </section>
    @endforeach

    <section class="panel-soal" data-panel="bacaan" role="tabpanel">
        @if ($daftarBacaan->isEmpty())
            <div class="soal-kosong">
                <p>Belum ada bacaan</p>
                <small>Bacaan atau stimulus dipakai bersama oleh beberapa soal. Klik <strong>+</strong> lalu pilih <strong>+ Bacaan / Stimulus</strong></small>
            </div>
        @else
            <div class="db-panel">
                <div class="gulir">
                    <table class="tabel tabel-daftar tabel-kartu">
                        <thead><tr><th class="no">No</th><th>Bacaan</th><th>Dipakai</th><th class="kanan">Aksi</th></tr></thead>
                        <tbody>
                            @foreach ($daftarBacaan as $b)
                                <tr>
                                    <td class="no">{{ $loop->iteration }}</td>
                                    <td><strong class="render-rumus">{{ $b->label() }}</strong></td>
                                    <td>{{ $b->soal_count }} soal</td>
                                    <td class="kanan aksi-baris">
                                        <a href="{{ route('staf.soal.create', [$bank, 'tipe' => 'pg', 'bacaan' => $b->id]) }}">+ Soal PG</a>
                                        <a href="{{ route('staf.soal.create', [$bank, 'tipe' => 'esai', 'bacaan' => $b->id]) }}">+ Soal Esai</a>
                                        <a href="{{ route('staf.bacaan.edit', [$bank, $b]) }}">Ubah</a>
                                        <form method="POST" action="{{ route('staf.bacaan.destroy', [$bank, $b]) }}" onsubmit="return confirm('Hapus bacaan ini? Soal yang memakainya tetap ada tanpa bacaan.')">@csrf @method('DELETE')<button type="submit" class="tautan">Hapus</button></form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </section>
</div>

<div class="fab-latar" id="fabLatar" hidden></div>
<div class="fab-grup" id="fabGrup">
    <div class="fab-daftar" id="fabDaftar" hidden>
        @if ($otomatis)
            <a class="fab-butir" href="{{ route('staf.bank-soal.otomatis', $bank) }}"><span class="fab-teks">Susun soal otomatis</span><span class="fab-ikon-butir ai"><x-ikon nama="otomatis" :ukuran="17" /></span></a>
            <a class="fab-butir" href="{{ route('staf.bank-soal.foto', $bank) }}"><span class="fab-teks">Pindai soal dari foto</span><span class="fab-ikon-butir ai"><x-ikon nama="unggah" :ukuran="17" /></span></a>
        @elseif (auth('web')->user()->role === 'admin')
            <span class="fab-butir mati" title="Fitur ini belum diaktifkan oleh pengelola aplikasi."><span class="fab-teks">Susun soal otomatis (belum aktif)</span><span class="fab-ikon-butir ai"><x-ikon nama="otomatis" :ukuran="17" /></span></span>
        @endif
        <a class="fab-butir" href="{{ route('staf.bank-soal.impor.form', $bank) }}"><span class="fab-teks">Impor Excel</span><span class="fab-ikon-butir xl"><x-ikon nama="unggah" :ukuran="17" /></span></a>
        <a class="fab-butir" href="{{ route('staf.bacaan.create', $bank) }}"><span class="fab-teks">+ Bacaan / Stimulus</span><span class="fab-ikon-butir bc"><x-ikon nama="info" :ukuran="17" /></span></a>
        <a class="fab-butir" href="{{ route('staf.soal.create', [$bank, 'tipe' => 'esai']) }}"><span class="fab-teks">+ Soal Esai</span><span class="fab-ikon-butir es"><x-ikon nama="berkas" :ukuran="17" /></span></a>
        <a class="fab-butir" href="{{ route('staf.soal.create', [$bank, 'tipe' => 'pg']) }}"><span class="fab-teks">+ Soal Pilihan Ganda</span><span class="fab-ikon-butir pg"><x-ikon nama="centang" :ukuran="17" /></span></a>
    </div>
    <button type="button" class="fab-utama" id="fabUtama" aria-label="Tambah" aria-expanded="false"><x-ikon nama="tambah" :ukuran="26" /></button>
</div>
@endsection
