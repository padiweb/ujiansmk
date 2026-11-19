<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="@yield('viewport', 'width=device-width, initial-scale=1')">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#3960FB">
    {{-- Pemasangan sebagai aplikasi (PWA): Android lewat manifest, iPhone/iPad lewat Safari > Bagikan > Tambahkan ke Layar Utama. --}}
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <link rel="apple-touch-icon" href="{{ asset('pwa/apple-touch-icon.png') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Altan Exam">
    <meta name="application-name" content="Altan Exam">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title>@yield('judul', 'Ujian') — {{ config('ujian.merek') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logo_smk.png') }}">
    <link rel="preload" href="{{ asset('fonts/dm-sans-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    @stack('kepala')
</head>
<body>
    @yield('isi')
    @stack('skrip')
    <script>
        // Service worker hanya untuk pesan "tidak ada koneksi" yang rapi; tidak menyimpan isi halaman. Dilewati di aplikasi Android.
        if ('serviceWorker' in navigator && navigator.userAgent.indexOf('UjianSMKAltan') === -1) {
            window.addEventListener('load', function () { navigator.serviceWorker.register('{{ asset('sw.js') }}', { scope: '/' }).catch(function () {}); });
        }
    </script>
</body>
</html>
