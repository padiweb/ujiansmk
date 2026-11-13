<!DOCTYPE html>
<html lang="id" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<title>{{ $judul }}</title>
@include('staf.kelulusan.gaya')
<style>.poin { margin: 6px 0 6px 18px; } .poin p { margin: 4px 0; text-align: justify; }</style>
</head>
<body data-rapor="1">
@unless ($word)
    <div class="alat tanpa-cetak">
        <button type="button" onclick="window.print()">Cetak</button>
        <button type="button" class="garis" onclick="window.close()">Tutup</button>
    </div>
@endunless

@foreach ($lembar as $l)
    @php
        $s = $l['siswa'];
        $ttl = trim(($s->tempat_lahir ? \Illuminate\Support\Str::upper($s->tempat_lahir).', ' : '').($s->tgl_lahir ? $s->tgl_lahir->locale('id')->translatedFormat('j F Y') : ''), ', ') ?: '-';
        $titik = '……………………………';
    @endphp
    <div class="lembar">
        @include('staf.kelulusan.kepala')
        <div class="judul">SURAT KETERANGAN MUTASI</div>
        <div class="nomor">Nomor : {{ $noSurat ?: '' }}</div>

        <p class="p">Yang bertanda tangan di bawah ini, Kepala {{ $sekolah->nama }}, menerangkan bahwa :</p>

        <table class="id">
            <tr><td class="l">Nama</td><td class="s">:</td><td><strong>{{ \Illuminate\Support\Str::upper($s->nama) }}</strong></td></tr>
            <tr><td class="l">Tempat, Tanggal Lahir</td><td class="s">:</td><td>{{ $ttl }}</td></tr>
            <tr><td class="l">Nama Orang Tua</td><td class="s">:</td><td>{{ \Illuminate\Support\Str::upper($s->nama_wali ?: '-') }}</td></tr>
            <tr><td class="l">NIS / NISN</td><td class="s">:</td><td>{{ $s->nis ?: '-' }} / {{ $s->nisn ?: '-' }}</td></tr>
            <tr><td class="l">Konsentrasi Keahlian</td><td class="s">:</td><td>{{ $kelas->jurusan?->nama ?: '-' }}</td></tr>
        </table>

        <p class="p">Menyatakan bahwa siswa tersebut di atas :</p>
        <div class="poin">
            <p>1. Telah menyelesaikan seluruh program pendidikan di {{ $sekolah->nama }}. Serta telah mengikuti serangkaian ujian sekolah sesuai dengan ketentuan kurikulum yang berlaku.</p>
            <p>2. Berdasarkan hasil rapat dewan guru pada tanggal {{ $tglRapat ?: '……………………………' }}, yang bersangkutan dinyatakan : <strong>LULUS</strong>.</p>
        </div>

        <p class="p">Surat keterangan ini dikeluarkan sebagai pernyataan mutasi keluar dikarenakan siswa telah menyelesaikan pendidikan (tamat belajar) dari satuan pendidikan {{ $sekolah->nama }} untuk keperluan melanjutkan pendidikan ke jenjang yang lebih tinggi atau memasuki dunia kerja.</p>

        <table class="ttd"><tr><td style="width:55%"></td><td>
            {{ $kotaTanggal }}<br>Kepala {{ $sekolah->nama }},<br><br><br><br><br>
            <strong>{{ $sekolah->kepala_sekolah }}</strong><br>NIP. {{ $sekolah->nip_kepala_sekolah ?: '-' }}
        </td></tr></table>
    </div>
@endforeach
</body>
</html>
