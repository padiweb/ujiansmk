@extends('layouts.dasar')
@section('judul', 'Masuk Staf')

@section('isi')
<div class="login-latar">
    <form class="login-kartu" method="POST" action="{{ route('staf.masuk.kirim') }}">
        @csrf
        <img class="login-logo" src="{{ asset('images/logo_smk.png') }}" alt="Logo {{ config('ujian.sekolah') }}">
        <h1>{{ config('ujian.merek') }}</h1>
        <p class="sekolah">Admin, Guru, dan Pengawas</p>

        @if (session('info'))
            <div class="info" role="status"><x-ikon nama="info" :ukuran="18" /><span>{{ session('info') }}</span></div>
        @endif

        @foreach (['username', 'password'] as $bidang)
            @error($bidang)
                <div class="pesan-salah" role="alert"><x-ikon nama="peringatan" :ukuran="18" /><span>{{ $message }}</span></div>
            @enderror
        @endforeach

        <div class="bidang @error('username') salah @enderror">
            <label for="username">Nama pengguna</label>
            <input id="username" name="username" type="text" autocomplete="username"
                   value="{{ old('username') }}" maxlength="50" required autofocus>
        </div>

        <div class="bidang">
            <label for="password">Kata sandi</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>

        <button class="tombol" type="submit">Masuk</button>

        <div class="login-tautan">
            Anda siswa? <a href="{{ route('siswa.masuk') }}">Masuk di sini</a>
        </div>
    </form>
</div>
@endsection
