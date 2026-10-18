@extends('layouts.staf')
@section('judul', 'Denah Ruang')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Denah Ruang</h1>
        <p class="sub">Pilih kegiatan ujian untuk mengatur ruang dan nomor meja.</p>
    </div>
</div>

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel">
            <thead><tr><th>Kegiatan</th><th>Semester</th><th>Jadwal</th><th>Siswa di ruang</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($kegiatan as $k)
                    <tr>
                        <td><strong>{{ $k->nama }}</strong> @unless ($k->aktif) <span class="lencana pucat">Tidak aktif</span> @endunless</td>
                        <td>{{ $k->periode->label() }}</td>
                        <td>{{ $k->jadwal_count }}</td>
                        <td>{{ $k->denah ? ($terisi[$k->denah->id] ?? 0) : '—' }}</td>
                        <td class="kanan aksi-baris"><a href="{{ route('staf.denah.kegiatan', $k) }}">Atur denah</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="kosong">Belum ada kegiatan. Tambahkan dulu di menu <a href="{{ route('staf.kegiatan.index') }}">Kegiatan Ujian</a>.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($denahLama->isNotEmpty())
    <div class="kartu tanpa-padding">
        <h2 class="judul-kartu" style="padding:16px 20px 0">Denah lama</h2>
        <div class="gulir">
            <table class="tabel">
                <thead><tr><th>Denah</th><th>Siswa</th><th class="kanan">Aksi</th></tr></thead>
                <tbody>
                    @foreach ($denahLama as $d)
                        <tr>
                            <td><strong>{{ $d->nama }}</strong></td>
                            <td>{{ $d->penempatan_count }}</td>
                            <td class="kanan aksi-baris"><a href="{{ route('staf.denah.show', $d) }}">Buka</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
