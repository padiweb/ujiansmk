@extends('layouts.dasar')
@section('judul', 'Ujian Selesai')

@section('isi')
<div class="login-latar">
    <div class="login-kartu">
        <img class="login-logo" src="{{ asset('images/logo_smk.png') }}" alt="" style="width:72px;height:72px">
        <h1 style="font-size:1.3rem">Jawaban Anda sudah terkirim</h1>
        <p class="sekolah" style="margin:6px 0 20px">{{ $jadwal->nama }} · {{ $jadwal->mapel->nama }}</p>

        @if ($tampil && $hasil && $hasil->skor_pg !== null)
            <div class="skor-besar" aria-label="Nilai pilihan ganda">{{ rtrim(rtrim(number_format((float) $hasil->skor_pg, 2, ',', '.'), '0'), ',') }}</div>
            <p class="sekolah" style="margin:0 0 6px">Nilai pilihan ganda</p>
            <div class="ringkasan" style="justify-content:center;margin:14px 0 4px">
                <div><strong>{{ $hasil->benar }}</strong><span>benar</span></div>
                <div><strong>{{ $hasil->salah }}</strong><span>salah</span></div>
                <div><strong>{{ $hasil->kosong }}</strong><span>kosong</span></div>
            </div>
            @if ($hasil->status_koreksi === 'belum_dikoreksi')
                <div class="info" style="margin-top:14px"><x-ikon nama="info" :ukuran="18" /><span>Soal esai Anda menunggu koreksi guru. Nilai akhir menyusul.</span></div>
            @endif
        @else
            <div class="info"><x-ikon nama="centang" :ukuran="18" /><span>Terima kasih. Nilai akan diumumkan oleh guru.</span></div>
        @endif

        <p class="bantuan" style="margin-top:14px">Dikirim {{ $sesi->selesai_pada?->format('d/m/Y H:i') }}</p>
        <a class="tombol" href="{{ route('siswa.beranda') }}" style="margin-top:16px">Kembali ke beranda</a>
    </div>
</div>
@endsection
