@extends('layouts.dasar')
@section('judul', 'Masuk Siswa')

@section('isi')
<div class="login-latar">
    <form class="login-kartu" method="POST" action="{{ route('siswa.masuk.kirim') }}">
        @csrf
        <img class="login-logo" src="{{ asset('images/logo_smk.png') }}" alt="Logo {{ config('ujian.sekolah') }}">
        <h1>Sistem Ujian</h1>
        <p class="sekolah">{{ config('ujian.sekolah') }}</p>

        @foreach (['username', 'password'] as $bidang)
            @error($bidang)
                <div class="pesan-salah" role="alert"><x-ikon nama="peringatan" :ukuran="18" /><span>{{ $message }}</span></div>
            @enderror
        @endforeach

        <div class="bidang @error('username') salah @enderror">
            <label for="username">NISN</label>
            <input id="username" name="username" type="text" inputmode="numeric" autocomplete="username"
                   value="{{ old('username') }}" maxlength="30" required autofocus>
        </div>

        <div class="bidang">
            <label for="password">Kata sandi</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>

        <button class="tombol" type="submit">Masuk Ujian</button>

        <div class="login-tautan">
            <a href="{{ route('staf.masuk') }}">Login Admin, Guru, Pengawas</a>
        </div>
    </form>
</div>
@endsection
