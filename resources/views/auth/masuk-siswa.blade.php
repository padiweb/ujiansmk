@extends('layouts.dasar')
@section('judul', 'Masuk Siswa')

@section('isi')
<div class="masuk">
    @include('auth._panel', [
        'judulPanel' => 'Kerjakan ujian dengan <em>tenang</em>, kami jaga waktunya.',
        'tentangPanel' => 'Masuk memakai NISN dan kata sandi yang sama dengan akun EduWeb Anda.',
    ])

    <main class="masuk-form">
        <form class="kartu-masuk" method="POST" action="{{ route('siswa.masuk.kirim') }}">
            @csrf
            <h2>Masuk Siswa</h2>
            <p class="sub">Gunakan NISN sebagai nama pengguna.</p>

            @foreach (['username', 'password'] as $bidang)
                @error($bidang)
                    <div class="pesan-salah" role="alert">{{ $message }}</div>
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

            <button class="tombol" type="submit">Masuk</button>

            <div class="tautan-lain">
                Guru, pengawas, atau admin? <a href="{{ route('staf.masuk') }}">Masuk sebagai staf</a>
            </div>
        </form>
    </main>
</div>
@endsection
