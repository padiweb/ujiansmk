<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('judul', 'Ujian') — {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/ujian.css') }}">
    <link rel="stylesheet" href="{{ asset('css/staf.css') }}">
</head>
<body>
    @yield('isi')
</body>
</html>
