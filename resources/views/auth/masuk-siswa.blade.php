@extends('layouts.dasar')
@section('judul', 'Masuk Siswa')

@section('isi')
<div class="login-latar">
    <form class="login-kartu" method="POST" action="{{ route('siswa.masuk.kirim') }}">
        @csrf
        <img class="login-logo" src="{{ asset('images/logo_smk.png') }}" alt="Logo {{ config('ujian.sekolah') }}">
        <h1>Sistem Ujian</h1>
        <p class="sekolah">{{ config('ujian.sekolah') }}</p>

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
            <input id="username" name="username" type="text" autocomplete="username" autocapitalize="none" spellcheck="false"
                   value="{{ old('username') }}" maxlength="50" required autofocus>
        </div>

        <div class="bidang">
            <label for="password">Kata sandi</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>

        <button class="tombol" type="submit">Masuk Ujian</button>

        <details class="pasang" id="pasang" hidden>
            <summary>Pasang sebagai aplikasi di layar utama</summary>
            <div id="pasang-isi"></div>
        </details>

        <div class="login-tautan">
            <a href="{{ route('staf.masuk') }}">Login Admin, Guru, Pengawas</a>
            <a href="{{ route('privasi') }}">Kebijakan privasi</a>
        </div>
    </form>
</div>
@push('skrip')
<script>
    (function () {
        var kotak = document.getElementById('pasang'), isi = document.getElementById('pasang-isi');
        var ua = navigator.userAgent || '';
        var terpasang = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
        if (!kotak || terpasang || ua.indexOf('UjianSMKAltan') !== -1) return;

        function langkah(daftar) {
            var ol = document.createElement('ol');
            daftar.forEach(function (t) { var li = document.createElement('li'); li.textContent = t; ol.appendChild(li); });
            isi.appendChild(ol);
        }

        var ios = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && (navigator.maxTouchPoints || 0) > 1);
        if (ios) {
            langkah([
                'Buka halaman ini di Safari.',
                'Ketuk tombol Bagikan (kotak dengan panah ke atas).',
                'Pilih "Tambahkan ke Layar Utama", lalu ketuk Tambah.',
                'Aplikasi "Altan Exam" akan muncul di layar utama.'
            ]);
            kotak.hidden = false;
            return;
        }

        var tertunda = null;
        window.addEventListener('beforeinstallprompt', function (e) {
            e.preventDefault();
            tertunda = e;
            isi.textContent = '';
            var tombol = document.createElement('button');
            tombol.type = 'button';
            tombol.className = 'tombol kecil';
            tombol.textContent = 'Pasang Altan Exam';
            tombol.addEventListener('click', function () { tertunda.prompt(); tertunda = null; kotak.hidden = true; });
            isi.appendChild(tombol);
            kotak.hidden = false;
            kotak.open = true;
        });
        if (/Android/.test(ua)) {
            langkah(['Ketuk menu titik tiga di pojok kanan atas peramban.', 'Pilih "Pasang aplikasi" atau "Tambahkan ke layar utama".']);
            kotak.hidden = false;
        }
    })();
</script>
@endpush
@endsection
