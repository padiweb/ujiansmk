<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<title>Peringkat {{ $kelas->nama }}</title>
@include('staf.kelulusan.gaya')
</head>
<body>
<div class="alat tanpa-cetak">
    <button type="button" onclick="window.print()">Cetak</button>
    <button type="button" class="garis" onclick="window.close()">Tutup</button>
</div>
<div class="lembar">
    @include('staf.kelulusan.kepala')
    <div class="judul">DAFTAR PERINGKAT SISWA</div>
    <div class="nomor">Kelas {{ $kelas->nama }} · {{ $periode->label() }}</div>
    <table class="tn" style="margin-top:10px">
        <thead><tr><th style="width:8%">Rank</th><th>Nama Siswa</th><th style="width:20%">NISN</th><th style="width:14%">Mapel</th><th style="width:16%">Rata-rata</th></tr></thead>
        <tbody>
            @foreach ($baris as $r)
                <tr><td class="n">{{ $r->peringkat }}</td><td>{{ $r->nama }}</td><td class="n">{{ $r->nisn }}</td><td class="n">{{ $r->mapel }}</td><td class="n"><strong>{{ number_format($r->rata, 2, ',', '.') }}</strong></td></tr>
            @endforeach
        </tbody>
    </table>
    <table class="ttd"><tr><td style="width:55%"></td><td>
        {{ trim(($sekolah->kota ? $sekolah->kota.', ' : '').now()->locale('id')->translatedFormat('j F Y')) }}<br>Kepala {{ $sekolah->nama }}<br><br><br><br><br>
        <strong>{{ $sekolah->kepala_sekolah }}</strong><br>NIP. {{ $sekolah->nip_kepala_sekolah ?: '-' }}
    </td></tr></table>
</div>
</body>
</html>
