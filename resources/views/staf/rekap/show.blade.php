@extends('layouts.staf')
@section('judul', 'Rekap: '.$j->nama)

@php
    $nm = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $namaStatus = ['belum' => 'Belum mulai', 'berlangsung' => 'Mengerjakan', 'selesai' => 'Selesai', 'diblokir' => 'Terblokir'];
    $admin = auth('web')->user()->role === 'admin';
@endphp

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">{{ $j->nama }}
            @if ($j->nilaiDikunci()) <span class="lencana" style="vertical-align:middle">Nilai dikunci</span> @endif</h1>
        <p class="sub">{{ $j->mapel->nama }} · KKM {{ $kkm }} · bobot PG {{ $j->bobot_pg }}% / esai {{ $j->bobotEsai() }}%</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil garis" href="{{ route('staf.rekap.index') }}"><x-ikon nama="panah-kiri" :ukuran="16" />Daftar</a>
        <a class="tombol kecil garis" href="{{ route('staf.rekap.ekspor', $j) }}"><x-ikon nama="unduh" :ukuran="16" />Excel</a>
        <a class="tombol kecil garis" href="{{ route('staf.rekap.cetak', $j) }}" target="_blank" rel="noopener">Cetak</a>
    </div>
</div>

<div class="kartu">
    <div class="ringkasan" style="margin-bottom:0">
        <div><strong>{{ $ringkasan['peserta'] }}</strong><span>Peserta</span></div>
        <div><strong>{{ $ringkasan['selesai'] }}</strong><span>Selesai</span></div>
        <div><strong>{{ $ringkasan['tidak_mengikuti'] }}</strong><span>Tidak mengikuti</span></div>
        <div><strong>{{ $nm($ringkasan['rata_rata']) }}</strong><span>Rata-rata</span></div>
        <div><strong>{{ $nm($ringkasan['tertinggi']) }}</strong><span>Tertinggi</span></div>
        <div><strong>{{ $nm($ringkasan['terendah']) }}</strong><span>Terendah</span></div>
        <div><strong>{{ $ringkasan['di_bawah_kkm'] }}</strong><span>Di bawah KKM</span></div>
        <div><strong>{{ $ringkasan['belum_dikoreksi'] }}</strong><span>Belum dikoreksi</span></div>
    </div>
</div>

<div class="kartu">
    <h2 class="judul-kartu">Tindakan</h2>
    <div class="aksi-form" style="margin-top:10px">
        @if (! $j->nilaiDikunci())
            <form method="POST" action="{{ route('staf.rekap.kunci', $j) }}" onsubmit="return confirm('Kunci nilai? Setelah dikunci, nilai tidak bisa diubah dan siap dipakai untuk rapor.')">
                @csrf <button class="tombol kecil" type="submit"><x-ikon nama="centang" :ukuran="16" />Kunci nilai</button>
            </form>
        @else
            <span class="sub">Dikunci {{ $j->nilai_dikunci_pada->format('d/m/Y H:i') }} oleh {{ $j->pengunci?->nama ?? '—' }}.</span>
            @if ($admin)
                <form method="POST" action="{{ route('staf.rekap.buka-kunci', $j) }}" onsubmit="return confirm('Buka kunci nilai? Koreksi bisa diubah lagi.')">
                    @csrf <button class="tombol kecil bahaya" type="submit">Buka kunci</button>
                </form>
            @endif
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

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel">
            <thead><tr><th>No</th><th>Nama</th><th>Kelas</th><th>Status</th><th>B / S / K</th><th>PG</th><th>Esai</th><th>Akhir</th><th>Keterangan</th></tr></thead>
            <tbody>
                @forelse ($baris as $i => $b)
                    <tr class="{{ in_array($b['keterangan'], ['Belum tuntas', 'Belum dikoreksi'], true) ? 'mon-langgar' : (in_array($b['status'], ['belum'], true) ? 'mon-redup' : '') }}">
                        <td>{{ $i + 1 }}</td>
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
