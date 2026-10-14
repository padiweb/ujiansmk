<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Daftar hadir {{ $j->nama }} — {{ config('ujian.merek') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #111; margin: 24px; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        h2 { font-size: 14px; margin: 18px 0 6px; }
        .sub { color: #444; margin: 0 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #555; padding: 5px 6px; text-align: left; }
        th { background: #eee; }
        td.n { width: 50px; text-align: center; }
        td.ttd { width: 160px; }
        .ruang { break-inside: avoid-page; }
        .ruang + .ruang { break-before: page; }
        .ttd-pengawas { margin-top: 28px; display: flex; justify-content: flex-end; }
        .ttd-pengawas div { width: 220px; text-align: center; }
        .ttd-pengawas .garis { margin-top: 52px; border-top: 1px solid #111; padding-top: 3px; }
        .aksi { margin-bottom: 14px; }
        @media print { .aksi { display: none; } body { margin: 10mm; } tr { page-break-inside: avoid; } thead { display: table-header-group; } }
    </style>
</head>
<body>
    <div class="aksi"><button type="button" onclick="window.print()">Cetak</button></div>
    @forelse ($perRuang as $ruang => $isi)
        <section class="ruang">
            <h1>Daftar Hadir Ujian — {{ config('ujian.sekolah') }}</h1>
            <p class="sub"><strong>{{ $j->nama }}</strong> · {{ $j->mapel->nama }} · {{ $j->mulai_pada->translatedFormat('d F Y, H:i') }}</p>
            <h2>{{ $ruang }} ({{ $isi->count() }} siswa)</h2>
            <table>
                <thead><tr><th>Meja</th><th>Nama</th><th>NISN</th><th>Kelas</th><th>Tanda tangan</th></tr></thead>
                <tbody>
                    @foreach ($isi as $p)
                        <tr><td class="n">{{ $p['meja'] ?? '' }}</td><td>{{ $p['nama'] }}</td><td>{{ $p['nisn'] }}</td><td>{{ $p['kelas'] }}</td><td class="ttd">&nbsp;</td></tr>
                    @endforeach
                </tbody>
            </table>
            <div class="ttd-pengawas"><div>Pengawas ruang<div class="garis">&nbsp;</div></div></div>
        </section>
    @empty
        <p>Tidak ada peserta.</p>
    @endforelse
</body>
</html>
