@extends('layouts.staf')
@section('judul', $j->nama)

@php
    $label = ['draf' => ['Draf', 'pucat'], 'aktif' => ['Aktif', ''], 'selesai' => ['Selesai', 'pucat']];
    $jendela = ['belum_dibuka' => 'Belum dibuka', 'dibuka' => 'Sedang dibuka', 'ditutup' => 'Sudah ditutup'];
    $namaStatus = ['belum' => 'Belum mulai', 'berlangsung' => 'Mengerjakan', 'selesai' => 'Selesai', 'diblokir' => 'Terblokir'];
@endphp

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">{{ $j->nama }} <span class="lencana {{ $label[$j->status][1] }}" style="vertical-align:middle">{{ $label[$j->status][0] }}</span></h1>
        <p class="sub">{{ $j->mapel->nama }} · {{ ucfirst($j->periode->semester) }} {{ $j->periode->tahunAjaran->nama }} · dibuat oleh {{ $j->pembuat->nama }}</p>
    </div>
    <div class="aksi-halaman">
        @if ($j->status !== 'selesai')
            <a class="tombol kecil garis" href="{{ route('staf.jadwal.edit', $j) }}"><x-ikon nama="ubah" :ukuran="16" />Ubah</a>
        @endif
        @if ($j->status === 'draf')
            <form method="POST" action="{{ route('staf.jadwal.destroy', $j) }}" onsubmit="return confirm('Hapus jadwal ini?')">
                @csrf @method('DELETE')
                <button class="tombol kecil bahaya" type="submit"><x-ikon nama="hapus" :ukuran="16" />Hapus</button>
            </form>
        @endif
    </div>
</div>

@if ($j->status === 'aktif')
    <div class="kartu token-kartu">
        <div>
            <span class="token-label">Token ujian</span>
            <div class="token-nilai" aria-label="Token ujian">{{ $j->token }}</div>
            <small class="bantuan">Bacakan token ini kepada siswa di ruang ujian.</small>
        </div>
        <div class="aksi-form" style="margin:0">
            <form method="POST" action="{{ route('staf.jadwal.token', $j) }}" onsubmit="return confirm('Buat token baru? Token lama tidak berlaku untuk siswa yang belum masuk.')">
                @csrf <button class="tombol kecil garis" type="submit">Buat token baru</button>
            </form>
        </div>
    </div>
@endif

<div class="kartu">
    <h2 class="judul-kartu">Pengaturan</h2>
    <dl class="rincian">
        <div><dt>Dibuka</dt><dd>{{ $j->mulai_pada->format('d/m/Y H:i') }}</dd></div>
        <div><dt>Ditutup</dt><dd>{{ $j->tutup_pada->format('d/m/Y H:i') }} @if ($j->status === 'aktif') <span class="lencana pucat">{{ $jendela[$j->jendela()] }}</span> @endif</dd></div>
        <div><dt>Durasi</dt><dd>{{ $j->durasi_menit }} menit sejak siswa mulai</dd></div>
        <div><dt>Acak</dt><dd>Soal: {{ $j->acak_soal ? 'ya' : 'tidak' }} · Opsi: {{ $j->acak_opsi ? 'ya' : 'tidak' }}</dd></div>
        <div><dt>Batas pelanggaran</dt><dd>{{ $j->batas_pelanggaran }} (blokir pada pelanggaran ke-{{ $j->batas_pelanggaran }})</dd></div>
        <div><dt>Bobot nilai</dt><dd>PG {{ $j->bobot_pg }}% · Esai {{ $j->bobotEsai() }}%</dd></div>
        <div><dt>Nilai ke siswa</dt><dd>{{ $j->tampilkan_hasil === 'langsung' ? 'Langsung setelah dikirim' : 'Ditahan' }}</dd></div>
    </dl>
</div>

<div class="kartu">
    <h2 class="judul-kartu">Ruang</h2>
    <p>Denah: @if ($j->denah) <a href="{{ route('staf.denah.show', $j->denah) }}"><strong>{{ $j->denah->nama }}</strong></a> @else <span class="sub">belum dipasang</span> @endif</p>
    @if (! is_null($tanpaTempat) && $tanpaTempat > 0)
        <div class="pesan-salah catatan" role="status" style="margin-top:8px"><x-ikon nama="peringatan" :ukuran="18" /><span>{{ $tanpaTempat }} peserta belum punya tempat di denah ini. Mereka tetap bisa ujian, tetapi tanpa ruang dan nomor meja.</span></div>
    @endif
</div>

<div class="kartu">
    <h2 class="judul-kartu">Kelas peserta</h2>
    <p>{{ $j->kelas->map(fn ($k) => $k->nama)->implode(', ') ?: '—' }}</p>
    @if (! is_null($calonPeserta))
        <p class="sub" style="margin-top:6px">{{ $calonPeserta }} siswa aktif akan menjadi peserta saat jadwal diaktifkan.</p>
    @endif
</div>

<div class="kartu">
    <h2 class="judul-kartu">Soal</h2>
    <ul class="petunjuk" style="margin-top:6px">
        @foreach ($j->bank as $jb)
            <li><strong>{{ $jb->bankSoal?->nama ?? '(bank dihapus)' }}</strong> —
                PG: {{ $jb->jumlah_pg ?? 'semua yang siap' }}, esai: {{ $jb->jumlah_esai ?? 'semua yang siap' }}</li>
        @endforeach
    </ul>
    @if ($j->status !== 'draf')
        <p style="margin-top:10px"><strong>{{ $soalPg }}</strong> soal PG dan <strong>{{ $soalEsai }}</strong> soal esai sudah dibekukan untuk ujian ini.</p>
    @endif
</div>

@if ($j->status !== 'draf')
    <div class="kartu">
        <h2 class="judul-kartu">Peserta</h2>
        <div class="ringkasan" style="margin-top:10px">
            @foreach ($namaStatus as $kode => $nama)
                <div><strong>{{ $statusSesi[$kode] ?? 0 }}</strong><span>{{ $nama }}</span></div>
            @endforeach
        </div>
    </div>
@endif

<div class="kartu">
    <h2 class="judul-kartu">Kartu dan daftar hadir</h2>
    <div class="aksi-form" style="margin-top:10px">
        <a class="tombol kecil garis" href="{{ route('staf.kartu.index', $j) }}">Kartu ujian</a>
        <a class="tombol kecil garis" href="{{ route('staf.hadir', $j) }}" target="_blank" rel="noopener">Daftar hadir</a>
    </div>
</div>

<div class="kartu">
    <h2 class="judul-kartu">Tindakan</h2>
    <div class="aksi-form" style="margin-top:10px">
        @if ($j->status === 'draf')
            <form method="POST" action="{{ route('staf.jadwal.aktifkan', $j) }}" onsubmit="return confirm('Aktifkan jadwal? Soal dan peserta akan dibekukan.')">
                @csrf <button class="tombol kecil" type="submit"><x-ikon nama="centang" :ukuran="16" />Aktifkan jadwal</button>
            </form>
        @elseif ($j->status === 'aktif')
            @if (($statusSesi['belum'] ?? 0) === $statusSesi->sum())
                <form method="POST" action="{{ route('staf.jadwal.batalkan', $j) }}" onsubmit="return confirm('Batalkan aktivasi? Jadwal kembali menjadi draf.')">
                    @csrf <button class="tombol kecil garis" type="submit">Batalkan aktivasi</button>
                </form>
            @endif
            <form method="POST" action="{{ route('staf.jadwal.selesaikan', $j) }}" onsubmit="return confirm('Tandai jadwal ini selesai?')">
                @csrf <button class="tombol kecil bahaya" type="submit">Tandai selesai</button>
            </form>
        @else
            <span class="sub">Jadwal ini sudah selesai dan disimpan sebagai arsip.</span>
        @endif
    </div>
</div>
@endsection
