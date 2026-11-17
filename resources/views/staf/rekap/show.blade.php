@extends('layouts.staf')
@section('judul', 'Rekap: '.$j->nama)

@php
    $nm = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $namaStatus = ['belum' => 'Belum mulai', 'berlangsung' => 'Mengerjakan', 'selesai' => 'Selesai', 'diblokir' => 'Terblokir'];
@endphp

@section('konten')
<div class="pita-info">
    <div class="pita-atas">
        <a class="tombol-pita" href="{{ route('staf.rekap.index') }}"><x-ikon nama="panah-kiri" :ukuran="16" />Kembali</a>
        <div class="aksi-halaman">
            <a class="tombol-pita" href="{{ route('staf.rekap.ekspor', array_filter([$j, 'kelas' => $kelasId])) }}"><x-ikon nama="unduh" :ukuran="16" />Excel</a>
            <a class="tombol-pita" href="{{ route('staf.rekap.cetak', array_filter([$j, 'kelas' => $kelasId])) }}" target="_blank" rel="noopener">Cetak</a>
        </div>
    </div>
    <div class="pita-isi">
        <div><small>Jadwal</small><b>{{ $j->nama }}</b></div>
        <div><small>Mapel</small><b>{{ $j->mapel->nama }}</b></div>
        <div><small>Kelas</small><b>{{ $kelasPilihan }}</b></div>
        <div><small>KKM</small><b><span class="pita-kkm">{{ $kkm }}</span></b></div>
        <div><small>Bobot PG / Esai</small><b>{{ $j->bobot_pg }}% / {{ $j->bobotEsai() }}%</b></div>
        <div><small>Nilai</small><b>{{ $kunci['terkunci'] ? 'Dikunci' : 'Belum dikunci' }}</b></div>
    </div>
</div>

<div class="db-angka rata">
    <div class="db-kotak biru"><b>{{ $ringkasan['peserta'] }}</b><span>Peserta</span></div>
    <div class="db-kotak"><b>{{ $ringkasan['selesai'] }}</b><span>Selesai</span></div>
    <div class="db-kotak"><b>{{ $ringkasan['tidak_mengikuti'] }}</b><span>Tidak mengikuti</span></div>
    <div class="db-kotak gelap"><b>{{ $nm($ringkasan['rata_rata']) }}</b><span>Rata-rata</span></div>
    <div class="db-kotak"><b>{{ $nm($ringkasan['tertinggi']) }}</b><span>Tertinggi</span></div>
    <div class="db-kotak"><b>{{ $nm($ringkasan['terendah']) }}</b><span>Terendah</span></div>
    <div class="db-kotak kuning"><b>{{ $ringkasan['di_bawah_kkm'] }}</b><span>Di bawah KKM</span></div>
    <div class="db-kotak kuning"><b>{{ $ringkasan['belum_dikoreksi'] }}</b><span>Belum dikoreksi</span></div>
</div>

<div class="kartu">
    <h2 class="judul-kartu">Tindakan</h2>
    <div class="aksi-form" style="margin-top:10px">
        @if (! $kunci['terkunci'])
            <form method="POST" action="{{ route('staf.rekap.kunci', $j) }}" onsubmit="return confirm('Kunci nilai kelas {{ $kelasPilihan }}? Setelah dikunci, nilai siap dipakai untuk rapor. Bila perlu, kunci bisa dibuka lagi.')">
                @csrf @if ($kelasId) <input type="hidden" name="kelas" value="{{ $kelasId }}"> @endif
                <button class="tombol kecil" type="submit"><x-ikon nama="centang" :ukuran="16" />Kunci nilai</button>
            </form>
            @if ($kunci['sebagian']) <span class="sub">Sebagian kelas sudah dikunci.</span> @endif
        @endif
        @if ($kunci['terkunci'] || $kunci['sebagian'])
            @if ($kunci['pada']) <span class="sub">Dikunci {{ $kunci['pada']->format('d/m/Y H:i') }} oleh {{ $kunci['oleh'] ?? '—' }}.</span> @endif
            <form method="POST" action="{{ route('staf.rekap.buka-kunci', $j) }}" onsubmit="return confirm('Buka kunci nilai kelas {{ $kelasPilihan }}? Nilai bisa diubah atau ditambah lagi. Kunci lagi setelah selesai.')">
                @csrf @if ($kelasId) <input type="hidden" name="kelas" value="{{ $kelasId }}"> @endif
                <button class="tombol kecil garis" type="submit">Buka kunci</button>
            </form>
        @endif
        @if ($j->tampilkan_hasil === 'ditahan')
            <form method="POST" action="{{ route('staf.rekap.tampilkan', $j) }}" onsubmit="return confirm('Tampilkan hasil kepada siswa?')">
                @csrf <button class="tombol kecil garis" type="submit">Tampilkan hasil ke siswa</button>
            </form>
        @else
            <span class="sub">Hasil tampil kepada siswa.</span>
        @endif
    </div>
</div>

<div class="db-panel">
    <header><h3>Daftar Nilai Siswa</h3><small>{{ count($baris) }} siswa</small></header>
    <div class="gulir">
        <table class="tabel tabel-kartu">
            <thead><tr><th class="no">No</th><th>Nama</th><th>Kelas</th><th>Status</th><th>B / S / K</th><th>PG</th><th>Esai</th><th>Akhir</th><th>Keterangan</th></tr></thead>
            <tbody>
                @forelse ($baris as $i => $b)
                    <tr class="{{ in_array($b['keterangan'], ['Belum tuntas', 'Belum dikoreksi'], true) ? 'mon-langgar' : (in_array($b['status'], ['belum'], true) ? 'mon-redup' : '') }}">
                        <td class="no">{{ $i + 1 }}</td>
                        <td><strong>{{ $b['nama'] }}</strong><small class="bantuan" style="margin:0">{{ $b['nisn'] }}{{ $b['ruang'] ? ' · '.$b['ruang'] : '' }}</small></td>
                        <td style="white-space:nowrap">{{ $b['kelas'] ?: '—' }}</td>
                        <td>{{ $namaStatus[$b['status']] ?? $b['status'] }}@if ($b['pelanggaran'] > 0)<small class="bantuan" style="margin:0">{{ $b['pelanggaran'] }} pelanggaran</small>@endif</td>
                        <td>{{ $b['benar'] !== null ? $b['benar'].' / '.$b['salah'].' / '.$b['kosong'] : '—' }}</td>
                        <td>{{ $nm($b['skor_pg']) }}</td>
                        <td>{{ $nm($b['skor_esai']) }}</td>
                        <td><strong>{{ $nm($b['skor_akhir']) }}</strong></td>
                        <td>{{ $b['keterangan'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="kosong">Belum ada peserta.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
