@extends('layouts.dasar')
@section('judul', 'Token Ujian')

@section('isi')
<div class="login-latar">
    <div class="login-kartu">
        <img class="login-logo" src="{{ asset('images/logo_smk.png') }}" alt="Logo {{ config('ujian.sekolah') }}" style="width:72px;height:72px">
        <span class="lencana" style="margin-bottom:10px">{{ $jadwal->mapel->nama }}</span>
        <h1 style="font-size:1.25rem">{{ $jadwal->nama }}</h1>
        <p class="sekolah" style="margin-bottom:18px">{{ $jadwal->durasi_menit }} menit</p>

        @if (session('gagal'))
            <div class="pesan-salah" role="alert"><x-ikon nama="peringatan" :ukuran="18" /><span>{{ session('gagal') }}</span></div>
        @endif

        @if ($jendela === 'belum_dibuka')
            <div class="pesan-salah"><x-ikon nama="jam" :ukuran="18" /><span>Ujian ini belum dibuka. Dibuka pada {{ $jadwal->mulai_pada->format('d/m/Y H:i') }}.</span></div>
        @elseif ($jendela === 'ditutup')
            <div class="pesan-salah"><x-ikon nama="jam" :ukuran="18" /><span>Waktu untuk memulai ujian ini sudah ditutup.</span></div>
        @else
            <form method="POST" action="{{ route('siswa.ujian.token.kirim', $jadwal) }}" autocomplete="off">
                @csrf
                @error('token')
                    <div class="pesan-salah" role="alert"><x-ikon nama="peringatan" :ukuran="18" /><span>{{ $message }}</span></div>
                @enderror
                <div class="bidang @error('token') salah @enderror">
                    <label for="token">Token ujian</label>
                    <input id="token" name="token" type="text" class="input-token" maxlength="12" required autofocus
                           autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="•••••">
                    <small class="bantuan">Minta token kepada pengawas. Jangan dibagikan ke siapa pun.</small>
                </div>
                <button class="tombol" type="submit">Lanjut</button>
            </form>
        @endif

        <div class="login-tautan"><a href="{{ route('siswa.beranda') }}">← Kembali ke beranda</a></div>
    </div>
</div>
@endsection
