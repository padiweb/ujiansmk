<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="@yield('viewport', 'width=device-width, initial-scale=1')">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#3960FB">
    <title>@yield('judul', 'Ujian') — {{ config('ujian.merek') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logo_smk.png') }}">
    <link rel="preload" href="{{ asset('fonts/dm-sans-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    @stack('kepala')
</head>
<body>
    @yield('isi')
    @stack('skrip')
</body>
</html>
