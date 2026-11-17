@extends('layouts.staf')
@section('judul', 'Dashboard')

@php
    $admin = $user->role === 'admin';
    $lengkap = collect($langkah)->every(fn ($l) => $l['jumlah'] > 0);
    $pintas = $admin
        ? [['staf.jadwal.index', 'Kelola Jadwal Ujian', 'jam'], ['staf.master.index', 'Manajemen Siswa', 'siswa', 'siswa'], ['staf.bank-soal.index', 'Bank Soal', 'bank-soal'], ['staf.nilai.index', 'Input Nilai', 'berkas']]
        : [['staf.bank-soal.index', 'Bank Soal', 'bank-soal'], ['staf.koreksi.index', 'Koreksi Esai', 'koreksi'], ['staf.nilai.index', 'Input Nilai', 'berkas'], ['staf.monitor.index', 'Monitor Ujian', 'monitor']];
@endphp

@section('konten')
<div class="db-krumb"><span>Dashboard</span><span>›</span><span>Monitor Langsung</span></div>

{{-- Tampilan HP: kartu ujian yang sedang berlangsung dan pintasan (disembunyikan CSS di layar lebar) --}}
@php $sekarang = $jadwal->first(); @endphp
<section class="hp-saja hp-hero" aria-label="Ujian berlangsung">
    @if ($sekarang)
        <small>Sedang berlangsung</small>
        <strong>{{ $sekarang->mapel->nama }}</strong>
        <span>{{ $sekarang->nama }} · {{ $sekarang->mulai_pada->format('H.i') }}–{{ $sekarang->tutup_pada->format('H.i') }}@if ($jadwal->count() > 1) · +{{ $jadwal->count() - 1 }} ujian lain @endif</span>
        <a class="hp-hero-tombol" href="{{ route('staf.monitor.show', $sekarang) }}">Buka monitor</a>
    @else
        <small>Ujian</small>
        <strong>Tidak ada ujian berlangsung</strong>
        <span>Jadwal yang aktif akan muncul di sini.</span>
    @endif
</section>
<div class="hp-saja hp-pintas">
    @foreach ($pintas as $p)
        <a href="{{ isset($p[3]) ? route($p[0], $p[3]) : route($p[0]) }}"><i><x-ikon :nama="$p[2]" :ukuran="20" /></i><span>{{ $p[1] }}</span></a>
    @endforeach
</div>

<div class="db-atas">
    <div>
        <h2>{{ $sapaan }}, {{ $user->nama }}</h2>
        <p>Pantauan ujian yang sedang berlangsung. Angka diperbarui tiap 5 detik.</p>
    </div>
    <div class="db-atas-kanan">
        <span class="db-langsung" id="dbStatus"><i></i><span id="dbStatusTeks">Memuat…</span></span>
        @if ($ruang->isNotEmpty())
            <label class="db-saring">Ruang
                <select id="dbRuang">
                    <option value="0">Semua ruang</option>
                    @foreach ($ruang as $r)
                        <option value="{{ $r->id }}">{{ $r->nama }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </div>
</div>

@if ($dataEduweb)
    <div class="{{ $dataEduweb['masalah'] ? 'pesan-salah' : 'info' }}" role="status"><x-ikon :nama="$dataEduweb['masalah'] ? 'peringatan' : 'info'" :ukuran="18" /><span>{{ $dataEduweb['teks'] }}</span></div>
@endif

<div class="db-angka" id="dbAngka" data-url="{{ route('staf.beranda.data') }}" data-token="{{ csrf_token() }}" data-aksi="{{ url('staf/monitor') }}">
    <div class="db-kotak"><b id="n-belum">0</b><span>Belum mulai</span></div>
    <div class="db-kotak biru"><b id="n-mengerjakan">0</b><span>Mengerjakan</span></div>
    <div class="db-kotak gelap"><b id="n-selesai">0</b><span>Selesai</span></div>
    <div class="db-kotak kuning"><b id="n-melanggar">0</b><span>Melanggar</span></div>
    <div class="db-kotak kuning-tua"><b id="n-terblokir">0</b><span>Terblokir</span></div>
</div>

<div class="db-grid">
    <div class="db-kiri">
        <section class="db-panel">
            <header><h3>Log Peringatan &amp; Blokir</h3><small id="dbSinkron"></small></header>
            <div class="db-gulir">
                <table class="tabel">
                    <thead><tr><th>Waktu</th><th>Nama Siswa</th><th>Kelas / Ruang</th><th>Status</th><th class="kanan">Aksi</th></tr></thead>
                    <tbody id="dbMasalah"><tr><td colspan="5" class="kosong">Memuat data…</td></tr></tbody>
                </table>
            </div>
        </section>

        @if ($jumlah)
            <div class="db-tiga">
                <div class="db-stat"><b>{{ $jumlah['siswa'] }}</b><span>Siswa aktif</span></div>
                <div class="db-stat"><b>{{ $jumlah['guru'] }}</b><span>Guru</span></div>
                <div class="db-stat"><b>{{ $jumlah['soal'] }}</b><span>Soal di bank</span></div>
            </div>
        @endif

        @if ($admin && ! $lengkap)
            <section class="db-panel">
                <header><h3>Persiapan data</h3></header>
                <div class="db-isi">
                    <p class="sub" style="margin:0 0 4px">Isi berurutan dari atas. Setiap langkah bisa lewat impor Excel.</p>
                    <ol class="langkah">
                        @foreach ($langkah as $l)
                            <li class="{{ $l['jumlah'] > 0 ? 'selesai' : '' }}">
                                <a href="{{ route('staf.master.index', $l['slug']) }}">{{ $l['label'] }}</a>
                                <span class="hitung">{{ $l['jumlah'] }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </section>
        @endif
    </div>

    <div class="db-kanan">
        <section class="db-panel">
            <header><h3>Jadwal Aktif</h3>@if ($admin)<a href="{{ route('staf.jadwal.index') }}">Atur →</a>@endif</header>
            @forelse ($jadwal as $j)
                <a class="db-jadwal" href="{{ route('staf.monitor.show', $j) }}">
                    <strong>{{ $j->mapel->nama }}</strong>
                    <small>{{ $j->nama }} · {{ $j->mulai_pada->format('d/m H:i') }}–{{ $j->tutup_pada->format('H:i') }}</small>
                    @if ($admin || $user->mengawasi())<span class="db-token">Token <code>{{ $j->token }}</code></span>@endif
                </a>
            @empty
                <p class="db-kosong">Tidak ada ujian aktif.</p>
            @endforelse
        </section>

        <section class="db-panel">
            <header><h3>Akses Cepat</h3></header>
            <div class="db-pintas">
                @foreach ($pintas as $p)
                    <a href="{{ isset($p[3]) ? route($p[0], $p[3]) : route($p[0]) }}"><x-ikon :nama="$p[2]" :ukuran="16" />{{ $p[1] }}<x-ikon nama="panah-kanan" :ukuran="14" class="panah" /></a>
                @endforeach
            </div>
        </section>
    </div>
</div>
@endsection

@push('skrip')
<script src="{{ asset('js/beranda.js') }}?v={{ filemtime(public_path('js/beranda.js')) }}" defer></script>
@endpush
