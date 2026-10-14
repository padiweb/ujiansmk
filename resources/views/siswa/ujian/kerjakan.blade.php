@extends('layouts.dasar')
@section('judul', $jadwal->mapel->nama)

@section('isi')
<div id="ujian"
     data-sesi="{{ $sesi->id }}"
     data-perangkat="{{ $perangkat }}"
     data-paket="{{ $alamat['paket'] }}"
     data-sinkron="{{ $alamat['sinkron'] }}"
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
</div>
@endsection

@push('skrip')
<script src="{{ asset('js/ujian.js') }}?v={{ filemtime(public_path('js/ujian.js')) }}" defer></script>
@endpush
