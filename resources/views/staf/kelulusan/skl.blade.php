<!DOCTYPE html>
<html lang="id" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<title>{{ $judul }}</title>
@include('staf.kelulusan.gaya')
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
        $ttl = trim(($s->tempat_lahir ? $s->tempat_lahir.', ' : '').($s->tgl_lahir ? $s->tgl_lahir->locale('id')->translatedFormat('j F Y') : ''), ', ') ?: '-';
        $fmt = fn ($v) => $v === null ? '' : number_format($v, 2, ',', '.');
        $tahun = $kelas->tahunAjaran?->nama;
        $titik = '……………………………';
    @endphp
    <div class="lembar">
        @include('staf.kelulusan.kepala')
        <div class="judul">SURAT KETERANGAN LULUS</div>
        <div class="nomor">Nomor : {{ $noSurat ?: '' }}</div>

        <p class="p">Yang bertanda tangan di bawah ini, Kepala {{ $sekolah->nama }}@if ($sekolah->kota) {{ $sekolah->kota }}@endif @if ($sekolah->provinsi), Provinsi {{ $sekolah->provinsi }}@endif menerangkan bahwa :</p>

        <table class="id">
            <tr><td class="l">Satuan Pendidikan</td><td class="s">:</td><td>{{ $sekolah->nama }}</td></tr>
            <tr><td class="l">Nomor Pokok Satuan Pendidikan</td><td class="s">:</td><td>{{ $sekolah->npsn ?: $titik }}</td></tr>
            <tr><td class="l">Nama Lengkap</td><td class="s">:</td><td><strong>{{ \Illuminate\Support\Str::upper($s->nama) }}</strong></td></tr>
            <tr><td class="l">Tempat, Tanggal Lahir</td><td class="s">:</td><td>{{ $ttl }}</td></tr>
            <tr><td class="l">Nomor Induk Siswa Nasional</td><td class="s">:</td><td>{{ $s->nisn ?: '-' }}</td></tr>
            <tr><td class="l">Nomor Ijazah</td><td class="s">:</td><td>{{ $noIjazah ?: $titik }}</td></tr>
            <tr><td class="l">Tanggal Kelulusan</td><td class="s">:</td><td>{{ $tglLulus }}</td></tr>
            <tr><td class="l">Kurikulum</td><td class="s">:</td><td>{{ $sekolah->kurikulum }}</td></tr>
            <tr><td class="l">Program Keahlian</td><td class="s">:</td><td>{{ $kelas->jurusan?->program_keahlian ?: '-' }}</td></tr>
            <tr><td class="l">Konsentrasi Keahlian</td><td class="s">:</td><td>{{ $kelas->jurusan?->nama ?: '-' }}</td></tr>
        </table>

        <p class="p">Dinyatakan <strong>LULUS</strong> dari Satuan Pendidikan berdasarkan kriteria kelulusan {{ $sekolah->nama }}@if ($sekolah->kota) {{ $sekolah->kota }}@endif Tahun Ajaran {{ $tahun }}, dengan nilai sebagai berikut :</p>

        <table class="tn">
            <thead><tr><th style="width:8%">No.</th><th style="text-align:left">Mata Pelajaran</th><th style="width:18%">Nilai</th></tr></thead>
            <tbody>
                @php $no = 1; @endphp
                @forelse ($l['kelompok'] as $kode => $baris)
                    <tr><td class="kel" colspan="3">{{ $namaKelompok[$kode] }}</td></tr>
                    @foreach ($baris as $b)
                        <tr><td class="n">{{ $no++ }}</td><td>{{ $b['nama'] }}</td><td class="n">{{ $fmt($b['rata']) }}</td></tr>
                    @endforeach
                @empty
                    <tr><td colspan="3" class="n">Belum ada nilai.</td></tr>
                @endforelse
                @if ($l['ada'])
                    <tr class="rata"><td colspan="2" class="n">Rata - rata</td><td class="n">{{ $fmt($l['rata_semua']) }}</td></tr>
                @endif
            </tbody>
        </table>

        <p class="p">Surat Keterangan Lulus ini berlaku sementara sampai dengan diterbitkannya Ijazah Tahun Ajaran {{ $tahun }}, untuk menjadikan maklum bagi yang berkepentingan.</p>

        <table class="ttd"><tr><td style="width:55%"></td><td>
            {{ $kotaTanggal }}<br>Kepala {{ $sekolah->nama }},<br><br><br><br><br>
            <strong>{{ $sekolah->kepala_sekolah }}</strong><br>NIP. {{ $sekolah->nip_kepala_sekolah ?: '' }}
        </td></tr></table>
    </div>
@endforeach
</body>
</html>
