<!DOCTYPE html>
<html lang="id" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<title>{{ $judul }}</title>
<style>
@page { size: 21.5cm 33cm; margin: 1.5cm 2cm 2cm 2cm; }
* { box-sizing: border-box; }
body { font-family: 'Times New Roman', Times, serif; font-size: 12pt; color: #000; margin: 0; background: #fff; line-height: 1.35; }
@media print { .tanpa-cetak { display: none !important; } }
table { border-collapse: collapse; width: 100%; }
thead.ulang { display: table-header-group; }
thead.ulang td { border: none; background: #fff; }
tr { page-break-inside: avoid; }
.halaman-baru { display: block; page-break-before: always; height: 0; }
.hindari { page-break-inside: avoid; }
.id td { padding: 4px 0; border: none; vertical-align: top; }
.cb { border: 1px solid #000; padding: 6px 7px; vertical-align: middle; }
.chd { border: 1px solid #000; padding: 7px; font-weight: bold; text-align: center; vertical-align: middle; }
.kel { background: #f0f0f0; font-weight: bold; }
.judul td { text-align: center; font-weight: bold; font-size: 13pt; padding: 18px 0 10px; border: none; }
.bagian td { border: none; padding-top: 12px; padding-bottom: 4px; font-weight: bold; font-size: 12pt; }
.ttd td { border: none; text-align: center; vertical-align: top; }
.alat { position: fixed; top: 10px; right: 10px; display: flex; gap: 8px; z-index: 9; }
.alat button { padding: 8px 18px; background: #3960FB; color: #fff; border: 0; border-radius: 6px; font-weight: 700; font-size: 13px; cursor: pointer; }
.alat button.garis { background: #fff; color: #333; border: 1px solid #ccc; }
</style>
</head>
<body>
@unless ($word)
    <div class="alat tanpa-cetak">
        <button type="button" onclick="window.print()">Cetak</button>
        <button type="button" class="garis" onclick="window.close()">Tutup</button>
    </div>
@endunless

@foreach ($lembar as $l)
    @if (! $loop->first)<div class="halaman-baru"></div>@endif
    @php $s = $l['siswa']; @endphp
    <table border="0" cellspacing="0" cellpadding="0">
        <thead class="ulang">
            <tr><td colspan="6">
                <div style="height:4px"></div>
                <table class="id" border="0" cellspacing="0" cellpadding="0" width="100%">
                    <tr>
                        <td width="55%" valign="top">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr><td width="24%">Nama</td><td width="3%">:</td><td><strong>{{ $s->nama }}</strong></td></tr>
                                <tr><td>NIS / NISN</td><td>:</td><td>{{ ($s->nis ?: '-') . ' / ' . ($s->nisn ?: '-') }}</td></tr>
                                <tr><td>Sekolah</td><td>:</td><td>{{ $sekolah->nama ?: config('ujian.sekolah') }}</td></tr>
                                <tr><td>Alamat</td><td>:</td><td>{{ $sekolah->alamat }}</td></tr>
                            </table>
                        </td>
                        <td width="45%" valign="top">
                            <table width="100%" border="0" cellspacing="0" cellpadding="0">
                                <tr><td width="35%">Kelas</td><td width="3%">:</td><td>{{ $l['kelas'] }}@if ($l['jurusan']) ({{ $l['jurusan'] }})@endif</td></tr>
                                <tr><td>Fase</td><td>:</td><td>{{ $l['fase'] }}</td></tr>
                                <tr><td>Semester</td><td>:</td><td>{{ $l['semester'] }}</td></tr>
                                <tr><td>Tahun Ajaran</td><td>:</td><td>{{ $l['tahun_ajaran'] }}</td></tr>
                            </table>
                        </td>
                    </tr>
                </table>
                <div style="height:8px"></div>
            </td></tr>
        </thead>

        <tbody><tr class="judul"><td colspan="6">LAPORAN HASIL BELAJAR</td></tr></tbody>

        <tbody>
            <tr class="bagian"><td colspan="6">A. MATA PELAJARAN</td></tr>
            <tr>
                <th class="chd" width="5%">No</th>
                <th class="chd" width="40%" style="text-align:left">Mata Pelajaran</th>
                <th class="chd" width="12%">Pengetahuan<br>(NP)</th>
                <th class="chd" width="12%">Keterampilan<br>(NK)</th>
                <th class="chd" width="13%">Nilai Akhir<br>(NA)</th>
                <th class="chd" width="10%">Predikat</th>
            </tr>
            @foreach ([['Kelompok Mata Pelajaran Umum', $l['umum']], ['Kelompok Mata Pelajaran Kejuruan', $l['kejuruan']]] as [$namaKelompok, $daftar])
                @if (count($daftar) > 0)
                    <tr><td colspan="6" class="cb kel">{{ $namaKelompok }}</td></tr>
                    @foreach ($daftar as $m)
                        <tr>
                            <td class="cb" align="center">{{ $m['no'] }}</td>
                            <td class="cb" align="left">{{ $m['nama'] }}</td>
                            <td class="cb" align="center">{{ $m['np'] ?? '-' }}</td>
                            <td class="cb" align="center">{{ $m['nk'] ?? '-' }}</td>
                            <td class="cb" align="center" style="font-weight:bold">{{ $m['na'] ?? '-' }}</td>
                            <td class="cb" align="center">{{ $m['predikat'] ?: '-' }}</td>
                        </tr>
                    @endforeach
                @endif
            @endforeach
            @if (count($l['umum']) + count($l['kejuruan']) === 0)
                <tr><td colspan="6" class="cb" style="text-align:center">Data mapel belum tersedia.</td></tr>
            @endif
        </tbody>

        <tbody class="hindari">
            <tr class="bagian"><td colspan="6"><br>B. EKSTRAKURIKULER</td></tr>
            <tr>
                <th class="chd">No.</th>
                <th class="chd" colspan="2" style="text-align:left">Kegiatan Ekstrakurikuler</th>
                <th class="chd">Nilai</th>
                <th class="chd">Predikat</th>
                <th class="chd">Keterangan</th>
            </tr>
            @forelse ($l['ekskul'] as $i => $e)
                <tr>
                    <td class="cb" align="center">{{ $i + 1 }}</td>
                    <td class="cb" colspan="2">{{ $e['nama'] }}</td>
                    <td class="cb" align="center">{{ $e['nilai'] ?? '-' }}</td>
                    <td class="cb" align="center">{{ $e['predikat'] ?: '-' }}</td>
                    <td class="cb" align="center">{{ $e['keterangan'] }}</td>
                </tr>
            @empty
                <tr>
                    <td class="cb" align="center">-</td><td class="cb" colspan="2">-</td>
                    <td class="cb" align="center">-</td><td class="cb" align="center">-</td><td class="cb" align="center">-</td>
                </tr>
            @endforelse
        </tbody>

        <tbody class="hindari">
            <tr class="bagian"><td colspan="6"><br>C. KETIDAKHADIRAN</td></tr>
            <tr><td class="cb" colspan="4">Sakit</td><td class="cb" colspan="2" align="center">{{ (int) $l['sakit'] }} hari</td></tr>
            <tr><td class="cb" colspan="4">Izin</td><td class="cb" colspan="2" align="center">{{ (int) $l['izin'] }} hari</td></tr>
            <tr><td class="cb" colspan="4">Tanpa Keterangan (Alpa)</td><td class="cb" colspan="2" align="center">{{ (int) $l['alpa'] }} hari</td></tr>
        </tbody>

        <tbody class="hindari">
            <tr class="bagian"><td colspan="6"><br>D. CATATAN WALI KELAS</td></tr>
            <tr><td class="cb" colspan="6" style="padding:12px 14px">{!! nl2br(e($l['catatan'] ?: ' ')) !!}</td></tr>
        </tbody>

        @if ($l['bagian_keputusan'])
            <tbody class="hindari">
                <tr class="bagian"><td colspan="6"><br>{{ $l['xii'] ? 'E. KELULUSAN' : 'E. KENAIKAN KELAS' }}</td></tr>
                <tr>
                    <td class="cb" colspan="6" style="padding:12px 15px">
                        @if ($l['xii'])
                            @if ($l['keputusan'] === 'lulus')
                                <strong>LULUS</strong> / <s>Tidak Lulus</s>
                            @elseif ($l['keputusan'] === 'tinggal')
                                <s>Lulus</s> / <strong>TIDAK LULUS</strong>
                            @else
                                Lulus / Tidak Lulus
                            @endif
                        @else
                            @if ($l['keputusan'] === 'naik')
                                <strong>Naik</strong> / <s>Tidak Naik</s> Kelas {{ $l['kelas_tujuan'] }}
                            @elseif ($l['keputusan'] === 'tinggal')
                                <s>Naik</s> / <strong>Tidak Naik</strong> Kelas {{ $l['kelas'] }}
                            @else
                                Naik / Tidak Naik Kelas {{ $l['kelas_tujuan'] }}
                            @endif
                        @endif
                    </td>
                </tr>
            </tbody>
        @endif

        <tbody>
            <tr><td colspan="6" style="border:none; padding-top:24px">
                <table class="ttd" width="100%" border="0" cellspacing="0" cellpadding="0">
                    <tr>
                        <td width="45%" align="center" valign="top">
                            Mengetahui,<br>Orang Tua / Wali,
                            <br><br><br><br><br>
                            <strong>{{ $s->nama_wali ?: '..............................' }}</strong>
                        </td>
                        <td width="10%">&nbsp;</td>
                        <td width="45%" align="center" valign="top">
                            {{ $kotaTanggal }}<br>Wali Kelas,
                            <br><br><br><br><br>
                            <strong>{{ $l['wali_kelas'] ?: '..............................' }}</strong>
                        </td>
                    </tr>
                </table>
                <table class="ttd" width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top:22px">
                    <tr>
                        <td width="25%">&nbsp;</td>
                        <td width="50%" align="center" valign="top">
                            Mengetahui,<br>Kepala Sekolah,
                            <br><br><br><br><br>
                            <strong>{{ $sekolah->kepala_sekolah ?: '..............................' }}</strong><br>
                            {{ $sekolah->nip_kepala_sekolah ? 'NIP. '.$sekolah->nip_kepala_sekolah : '' }}&nbsp;
                        </td>
                        <td width="25%">&nbsp;</td>
                    </tr>
                </table>
            </td></tr>
        </tbody>
    </table>
@endforeach
</body>
</html>
