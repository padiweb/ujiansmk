<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Kartu ujian — {{ config('ujian.merek') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #111; margin: 0; background: #f2f2f2; }
        .aksi { padding: 12px 16px; background: #fff; border-bottom: 1px solid #ddd; }
        .halaman { width: 210mm; margin: 8px auto; padding: 8mm; background: #fff; display: grid; grid-template-columns: 1fr 1fr; grid-auto-rows: 1fr; gap: 5mm; height: 297mm; }
        .kartu { border: 1.5px solid #333; border-radius: 6px; padding: 8px 10px; display: flex; flex-direction: column; overflow: hidden; page-break-inside: avoid; }
        .kartu header { display: flex; gap: 8px; align-items: center; border-bottom: 1px solid #333; padding-bottom: 5px; margin-bottom: 5px; }
        .kartu header img { width: 34px; height: 34px; object-fit: contain; }
        .kartu header b { display: block; font-size: 12px; }
        .kartu header small { color: #444; font-size: 10px; }
        dl { margin: 0; display: grid; grid-template-columns: 78px 1fr; gap: 2px 6px; font-size: 11.5px; }
        dt { color: #444; }
        dd { margin: 0; font-weight: bold; word-break: break-word; }
        .sandi { margin-top: auto; padding-top: 5px; border-top: 1px dashed #666; font-size: 11px; }
        .sandi code { font-size: 15px; letter-spacing: 1.5px; font-weight: bold; }
        .catatan { font-size: 9.5px; color: #444; margin-top: 4px; }
        @media print {
            body { background: #fff; }
            .aksi { display: none; }
            .halaman { margin: 0; box-shadow: none; break-after: page; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="aksi"><button type="button" onclick="window.print()">Cetak</button>
        @if ($sandi) <strong style="margin-left:12px">Kata sandi baru hanya tampil di halaman ini. Cetak atau simpan sekarang; memuat ulang tidak menampilkannya lagi.</strong> @endif</div>

    @foreach ($peserta->chunk(6) as $halaman)
        <div class="halaman">
            @foreach ($halaman as $p)
                <section class="kartu">
                    <header>
                        <img src="{{ asset('images/logo_smk.png') }}" alt="">
                        <div><b>KARTU PESERTA UJIAN</b><small>{{ config('ujian.sekolah') }}</small></div>
                    </header>
                    <dl>
                        <dt>Nama</dt><dd>{{ $p['nama'] }}</dd>
                        <dt>Kelas</dt><dd>{{ $p['kelas'] }}</dd>
                        <dt>Ujian</dt><dd>{{ $j->nama }} ({{ $j->mapel->nama }})</dd>
                        <dt>Waktu</dt><dd>{{ $j->mulai_pada->translatedFormat('d F Y, H:i') }}</dd>
                        <dt>Ruang / meja</dt><dd>{{ $p['ruang'] ?? '—' }}{{ $p['meja'] ? ' / meja '.$p['meja'] : '' }}</dd>
                        <dt>Nama pengguna</dt><dd>{{ $p['pengguna'] }}</dd>
                    </dl>
                    <div class="sandi">
                        Kata sandi:
                        @if ($sandi && isset($sandi[$p['siswa']->id])) <code>{{ $sandi[$p['siswa']->id] }}</code> @else <span>gunakan kata sandi Anda</span> @endif
                    </div>
                    <div class="catatan">Token ujian diberikan pengawas di ruang ujian. Jaga kerahasiaan kartu ini.</div>
                </section>
            @endforeach
        </div>
    @endforeach

    @if ($peserta->isEmpty())
        <div class="halaman" style="display:block;height:auto">Tidak ada peserta pada pilihan ini.</div>
    @endif
</body>
</html>
