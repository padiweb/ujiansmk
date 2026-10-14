@php
    $nm = fn ($n) => $n === null ? '' : rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Rekap {{ $j->nama }} — {{ config('ujian.merek') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .sub { color: #444; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #555; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #eee; }
        td.n, th.n { text-align: right; white-space: nowrap; }
        .ringkas { margin: 12px 0; display: flex; gap: 18px; flex-wrap: wrap; }
        .ringkas b { font-size: 14px; }
        .aksi { margin-bottom: 14px; }
        .ttd { margin-top: 36px; display: flex; justify-content: flex-end; }
        .ttd div { width: 220px; text-align: center; }
        .ttd .garis { margin-top: 56px; border-top: 1px solid #111; padding-top: 3px; }
        @media print { .aksi { display: none; } body { margin: 10mm; } tr { page-break-inside: avoid; } thead { display: table-header-group; } }
    </style>
</head>
<body>
    <div class="aksi"><button type="button" onclick="window.print()">Cetak</button></div>
    <h1>{{ config('ujian.sekolah') }}</h1>
    <p class="sub"><strong>Rekap nilai: {{ $j->nama }}</strong> · {{ $j->mapel->nama }} · KKM {{ $kkm }} · {{ $j->mulai_pada->format('d/m/Y') }}</p>

    <table>
        <thead><tr><th class="n">No</th><th>Nama</th><th>NISN</th><th>Kelas</th><th class="n">PG</th><th class="n">Esai</th><th class="n">Akhir</th><th>Keterangan</th></tr></thead>
        <tbody>
            @foreach ($baris as $i => $b)
                <tr>
                    <td class="n">{{ $i + 1 }}</td><td>{{ $b['nama'] }}</td><td>{{ $b['nisn'] }}</td><td>{{ $b['kelas'] }}</td>
                    <td class="n">{{ $nm($b['skor_pg']) }}</td><td class="n">{{ $nm($b['skor_esai']) }}</td><td class="n"><strong>{{ $nm($b['skor_akhir']) }}</strong></td><td>{{ $b['keterangan'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="ringkas">
        <span>Peserta: <b>{{ $ringkasan['peserta'] }}</b></span>
        <span>Rata-rata: <b>{{ $nm($ringkasan['rata_rata']) ?: '-' }}</b></span>
        <span>Tertinggi: <b>{{ $nm($ringkasan['tertinggi']) ?: '-' }}</b></span>
        <span>Terendah: <b>{{ $nm($ringkasan['terendah']) ?: '-' }}</b></span>
        <span>Di bawah KKM: <b>{{ $ringkasan['di_bawah_kkm'] }}</b></span>
    </div>

    <div class="ttd"><div>Guru mata pelajaran<div class="garis">&nbsp;</div></div></div>
</body>
</html>
