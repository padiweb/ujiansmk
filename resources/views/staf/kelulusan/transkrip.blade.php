<!DOCTYPE html>
<html lang="id" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<title>{{ $judul }}</title>
@include('staf.kelulusan.gaya')
</head>
<body>
@unless ($word)
    <div class="alat tanpa-cetak">
        <button type="button" onclick="window.print()">Cetak</button>
        <button type="button" class="garis" onclick="window.close()">Tutup</button>
    </div>
@endunless

@foreach ($lembar as $l)
    @php
        $s = $l['siswa'];
        $ttl = trim(($s->tempat_lahir ? $s->tempat_lahir.', ' : '').($s->tgl_lahir ? $s->tgl_lahir->locale('id')->translatedFormat('j F Y') : ''), ', ') ?: '-';
        $fmt = fn ($v) => $v === null ? '' : number_format($v, 2, ',', '.');
    @endphp
    <div class="lembar">
        @include('staf.kelulusan.kepala')
        <div class="judul">TRANSKRIP NILAI</div>
        <div class="nomor">Nomor : {{ $noSurat ?: '' }}</div>

        <table class="id">
            <tr><td class="l">Nama</td><td class="s">:</td><td><strong>{{ $s->nama }}</strong></td></tr>
            <tr><td class="l">Tempat dan Tanggal Lahir</td><td class="s">:</td><td>{{ $ttl }}</td></tr>
            <tr><td class="l">Nomor Induk</td><td class="s">:</td><td>{{ $s->nis ?: '-' }}</td></tr>
            <tr><td class="l">Nomor Induk Siswa Nasional</td><td class="s">:</td><td>{{ $s->nisn ?: '-' }}</td></tr>
            <tr><td class="l">Nomor Peserta Ujian</td><td class="s">:</td><td>{{ $s->no_peserta_us ?: '-' }}</td></tr>
            <tr><td class="l">Kompetensi Keahlian</td><td class="s">:</td><td>{{ $kelas->jurusan?->nama ?: '-' }}</td></tr>
        </table>

        <table class="tn" style="margin-top:10px">
            <thead>
                <tr>
                    <th rowspan="3" style="width:4%">No</th><th rowspan="3" style="width:29%">MATA PELAJARAN</th>
                    <th colspan="6">Nilai Raport Semester</th>
                    <th rowspan="3" style="width:8%">Nilai<br>Ujian<br>Sekolah</th><th rowspan="3" style="width:8%">Nilai<br>Rata-<br>Rata</th>
                </tr>
                <tr><th colspan="2">Kelas X</th><th colspan="2">Kelas XI</th><th colspan="2">Kelas XII</th></tr>
                <tr>@foreach ($slot as $nama)<th style="width:5%">{{ $nama }}</th>@endforeach</tr>
            </thead>
            <tbody>
                @php $no = 1; @endphp
                @forelse ($l['kelompok'] as $kode => $baris)
                    <tr><td class="kel" colspan="10">{{ $namaKelompok[$kode] }}</td></tr>
                    @foreach ($baris as $b)
                        <tr>
                            <td class="n">{{ $no++ }}</td><td>{{ $b['nama'] }}</td>
                            @foreach ($b['slot'] as $v)<td class="n">{{ $fmt($v) }}</td>@endforeach
                            <td class="n">{{ $fmt($b['us']) }}</td><td class="n"><strong>{{ $fmt($b['rata']) }}</strong></td>
                        </tr>
                    @endforeach
                @empty
                    <tr><td colspan="10" class="n">Belum ada nilai.</td></tr>
                @endforelse
                @if ($l['ada'])
                    <tr class="rata">
                        <td colspan="2" class="n">Rata-rata</td>
                        @foreach ($l['rata_slot'] as $v)<td class="n">{{ $fmt($v) }}</td>@endforeach
                        <td class="n">{{ $fmt($l['rata_us']) }}</td><td class="n">{{ $fmt($l['rata_semua']) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>

        <table class="ttd"><tr><td style="width:55%"></td><td>
            {{ $kotaTanggal }}<br>Kepala {{ $sekolah->nama }}<br><br><br><br><br>
            <strong>{{ $sekolah->kepala_sekolah }}</strong><br>NIP. {{ $sekolah->nip_kepala_sekolah ?: '-' }}
        </td></tr></table>
    </div>
@endforeach
</body>
</html>
