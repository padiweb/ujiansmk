<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>No. Meja {{ $ruang->nama }} — {{ config('ujian.merek') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 16px; }
        .aksi { margin-bottom: 14px; }
        .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .kartu { border: 2px solid #111; border-radius: 6px; padding: 12px 14px; text-align: center; page-break-inside: avoid; }
        .kartu small { display: block; color: #444; font-size: 11px; }
        .no { font-size: 64px; font-weight: 700; line-height: 1; margin: 4px 0; }
        .nama { font-size: 18px; font-weight: 700; }
        @media print { .aksi { display: none; } body { margin: 8mm; } }
    </style>
</head>
<body>
    <div class="aksi"><button type="button" onclick="window.print()">Cetak</button></div>
    <div class="grid">
        @foreach ($baris as $p)
            <div class="kartu">
                <small>{{ $d->nama }} · {{ $ruang->nama }}</small>
                <div class="no">{{ $p->meja }}</div>
                <div class="nama">{{ $p->siswa->nama }}</div>
                <small>{{ $kelasSiswa[$p->siswa_id]->nama ?? '' }} · {{ $p->siswa->nisn }}</small>
            </div>
        @endforeach
    </div>
</body>
</html>
