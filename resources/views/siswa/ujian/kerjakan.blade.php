@extends('layouts.dasar')
@section('judul', $jadwal->mapel->nama)
@section('viewport', 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no')

@section('isi')
<div id="ujian"
     data-sesi="{{ $sesi->id }}"
     data-perangkat="{{ $perangkat }}"
     data-paket="{{ $alamat['paket'] }}"
     data-sinkron="{{ $alamat['sinkron'] }}"
     data-pelanggaran="{{ $alamat['pelanggaran'] }}"
     data-kirim="{{ $alamat['kirim'] }}"
     data-hasil="{{ $alamat['hasil'] }}">

    <header class="ujian-atas">
        <div class="ujian-judul">
            <img src="{{ asset('images/logo_smk.png') }}" alt="">
            <div><strong>{{ $jadwal->mapel->nama }}</strong><small>{{ $siswa->nama }}</small></div>
        </div>
        <div class="ujian-status">
            <span id="statusSimpan" class="simpan" title="Status penyimpanan jawaban">Tersimpan</span>
            <span id="pelanggaran" class="lencana kuning" hidden></span>
            <span id="timer" class="timer" aria-live="off">--:--</span>
        </div>
    </header>

    <div class="ujian-tubuh">
        <aside class="ujian-nav" aria-label="Nomor soal">
            <div id="navSoal" class="nav-grid"></div>
            <p class="nav-kunci"><span class="nav-contoh terjawab"></span> terjawab <span class="nav-contoh"></span> belum</p>
        </aside>

        <main class="ujian-isi">
            <div id="muat" class="kartu kosong-kartu">Memuat soal…</div>
            <form id="formSoal" onsubmit="return false" hidden></form>
            <div id="areaKirim" class="kartu" hidden>
                <button type="button" id="tombolKirim" class="tombol"><x-ikon nama="centang" :ukuran="18" />Kirim jawaban</button>
                <small id="infoTunggu" class="bantuan" style="text-align:center" hidden></small>
                <small class="bantuan" style="text-align:center">Pastikan semua soal sudah dijawab. Jawaban tidak bisa diubah setelah dikirim.</small>
            </div>
        </main>
    </div>

    {{-- Lapisan layar penuh: konfirmasi kirim, terkunci (blokir), galat. --}}
    <div id="lapisan" class="lapisan" hidden role="dialog" aria-modal="true">
        <div class="lapisan-kotak">
            <h2 id="lapisanJudul"></h2>
            <p id="lapisanIsi"></p>
            <div class="aksi-form" id="lapisanAksi" style="justify-content:center"></div>
        </div>
    </div>

    {{-- Gerbang layar penuh: tampil sejak awal; modul pengaman (ujian-pengaman.js) membukanya bila tidak diperlukan. --}}
    <div id="gerbang" class="lapisan lapisan-gerbang" role="dialog" aria-modal="true" aria-labelledby="gerbangJudul">
        <div class="lapisan-kotak">
            <x-ikon nama="peringatan" :ukuran="40" class="ikon-lapisan" />
            <h2 id="gerbangJudul">Mode layar penuh diperlukan</h2>
            <p id="gerbangAlasan">Ujian ini wajib dikerjakan dalam layar penuh. Selama ujian, jangan membuka aplikasi atau tab lain.</p>
            <div class="aksi-form" style="justify-content:center">
                <button type="button" id="gerbangMulai" class="tombol kecil" disabled>Memuat pengaman…</button>
            </div>
            <noscript><p class="galat-bidang">JavaScript harus aktif untuk mengerjakan ujian ini.</p></noscript>
        </div>
    </div>

    {{-- Perisai: tampil saat aplikasi atau jendela lain terdeteksi menutupi ujian. --}}
    <div id="perisai" class="lapisan lapisan-perisai" role="alert" hidden>
        <div class="lapisan-kotak">
            <x-ikon nama="peringatan" :ukuran="40" class="ikon-lapisan" />
            <h2>Aplikasi lain terdeteksi</h2>
            <p>Kalkulator, asisten, atau aplikasi mengambang terdeteksi. Tutup semua aplikasi lain untuk melanjutkan ujian. Kejadian ini dicatat.</p>
            <div id="perisaiDurasi" class="durasi-perisai">0 dtk</div>
        </div>
    </div>

    <div id="peringatan" class="peringatan" role="alert" hidden>
        <x-ikon nama="peringatan" :ukuran="22" />
        <div><strong id="peringatanJudul"></strong><p id="peringatanIsi"></p></div>
        <button type="button" id="peringatanTutup" class="tombol kecil garis">Mengerti</button>
    </div>
</div>
@endsection

@push('skrip')
<link rel="stylesheet" href="{{ asset('vendor/katex/katex.min.css') }}">
<script src="{{ asset('vendor/katex/katex.min.js') }}" defer></script>
<script src="{{ asset('vendor/katex/contrib/auto-render.min.js') }}" defer></script>
<script src="{{ asset('js/rumus.js') }}?v={{ filemtime(public_path('js/rumus.js')) }}" defer></script>
<script src="{{ asset('js/ujian.js') }}?v={{ filemtime(public_path('js/ujian.js')) }}" defer></script>
<script src="{{ asset('js/ujian-pengaman.js') }}?v={{ filemtime(public_path('js/ujian-pengaman.js')) }}" defer></script>
@endpush
