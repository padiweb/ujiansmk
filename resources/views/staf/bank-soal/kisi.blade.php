<!DOCTYPE html>
<html lang="id" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<title>Kisi-kisi {{ $bank->nama }}</title>
@include('staf.kelulusan.gaya')
<style>@page { size: 33cm 21.5cm; } .tn td, .tn th { font-size: 10pt; } .lv { text-align: center; font-weight: bold; }</style>
</head>
<body>
@unless ($word)
    <div class="alat tanpa-cetak">
        <button type="button" onclick="window.print()">Cetak</button>
        <button type="button" class="garis" onclick="window.close()">Tutup</button>
    </div>
@endunless
<div class="lembar">
    @include('staf.kelulusan.kepala')
    <div class="judul">KISI-KISI SOAL</div>
    <div class="nomor">{{ $bank->nama }}</div>
    <table class="id">
        <tr><td class="l">Mata Pelajaran</td><td class="s">:</td><td>{{ $bank->mapel->nama }}</td></tr>
        <tr><td class="l">Kelas</td><td class="s">:</td><td>{{ $bank->tingkat }}</td></tr>
        <tr><td class="l">Kurikulum</td><td class="s">:</td><td>{{ $sekolah->kurikulum }}</td></tr>
        <tr><td class="l">Jumlah Soal</td><td class="s">:</td><td>{{ $kisi['jumlah_pg'] }} pilihan ganda, {{ $kisi['jumlah_esai'] }} esai</td></tr>
        <tr><td class="l">Penyusun</td><td class="s">:</td><td>{{ $bank->pembuat->nama }}</td></tr>
    </table>

    <table class="tn" style="margin-top:10px">
        <thead>
            <tr>
                <th style="width:4%">No.</th><th style="width:16%">CP / Materi</th><th>Indikator Soal</th>
                <th style="width:9%">Level Kognitif</th><th style="width:11%">Bentuk Soal</th><th style="width:7%">Bobot</th><th style="width:8%">Kunci</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($kisi['baris'] as $b)
                <tr>
                    <td class="n">{{ $b['no'] }}</td><td>{{ $bank->mapel->nama }}</td><td>{{ $b['indikator'] }}</td>
                    <td class="lv">{{ $b['level'] }}</td><td class="n">{{ $b['bentuk'] }}</td><td class="n">{{ $b['bobot'] }}</td><td class="n">{{ $b['kunci'] ?? '–' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="n">Bank ini belum memiliki soal.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($kisi['baris'])
        <p class="p">Sebaran level kognitif:
            L1 (pengetahuan/pemahaman) {{ $kisi['sebaran']['L1'] }} soal,
            L2 (penerapan) {{ $kisi['sebaran']['L2'] }} soal,
            L3 (penalaran) {{ $kisi['sebaran']['L3'] }} soal.</p>
    @endif

    <table class="ttd"><tr><td style="width:55%"></td><td>
        {{ trim(($sekolah->kota ? $sekolah->kota.', ' : '').now()->locale('id')->translatedFormat('j F Y')) }}<br>Guru Mata Pelajaran<br><br><br><br><br>
        <strong>{{ $bank->pembuat->nama }}</strong>
    </td></tr></table>
</div>
</body>
</html>
