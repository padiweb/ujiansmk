@extends('layouts.dasar')
@section('judul', 'Masuk Staf')

@section('isi')
<div class="masuk">
    @include('auth._panel', [
        'judulPanel' => 'Ruang kerja <em>guru</em>, pengawas, dan admin.',
        'tentangPanel' => 'Kelola bank soal, jadwal, pemantauan ujian, dan penilaian dari satu tempat.',
    ])

    <main class="masuk-form">
        <form class="kartu-masuk" method="POST" action="{{ route('staf.masuk.kirim') }}">
            @csrf
            <h2>Masuk Staf</h2>
            <p class="sub">Khusus admin, guru mapel, dan pengawas.</p>

            @foreach (['username', 'password'] as $bidang)
                @error($bidang)
                    <div class="pesan-salah" role="alert">{{ $message }}</div>
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

            <div class="tautan-lain">
                Anda siswa? <a href="{{ route('siswa.masuk') }}">Masuk sebagai siswa</a>
            </div>
        </form>
    </main>
</div>
@endsection
