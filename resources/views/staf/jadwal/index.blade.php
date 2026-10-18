@extends('layouts.staf')
@section('judul', 'Jadwal Ujian')

@php
    $label = ['draf' => ['Draf', 'pucat'], 'aktif' => ['Aktif', ''], 'selesai' => ['Selesai', 'pucat']];
    $jendela = ['belum_dibuka' => 'Belum dibuka', 'dibuka' => 'Sedang dibuka', 'ditutup' => 'Sudah ditutup'];
@endphp

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Jadwal Ujian</h1>
        <p class="sub">{{ number_format($jadwal->total(), 0, ',', '.') }} jadwal</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil" href="{{ route('staf.jadwal.create') }}"><x-ikon nama="tambah" :ukuran="16" />Buat jadwal</a>
    </div>
</div>

<form class="saring" method="GET" action="{{ route('staf.jadwal.index') }}">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Cari nama jadwal…" aria-label="Cari">
    <select name="status" aria-label="Status">
        <option value="">Semua status</option>
        @foreach (['draf' => 'Draf', 'aktif' => 'Aktif', 'selesai' => 'Selesai'] as $k => $l)
            <option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>
        @endforeach
    </select>
    <button class="tombol kecil garis" type="submit">Terapkan</button>
</form>

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel">
            <thead><tr><th>Jadwal</th><th>Mapel</th><th>Waktu</th><th>Durasi</th><th>Kelas</th><th>Peserta</th><th>Status</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($jadwal as $j)
                    <tr>
                        <td><a href="{{ route('staf.jadwal.show', $j) }}"><strong>{{ $j->nama }}</strong></a>
                            <small class="bantuan" style="margin:0">{{ $j->periode->label() }}@if ($j->kegiatan && ! $j->kegiatan->aktif) · kegiatan tidak aktif @endif</small></td>
                        <td>{{ $j->mapel->nama }}</td>
                        <td>{{ $j->mulai_pada->format('d/m/Y H:i') }}<br><small class="bantuan" style="margin:0">s.d. {{ $j->tutup_pada->format('d/m/Y H:i') }}</small></td>
                        <td>{{ $j->durasi_menit }} menit</td>
                        <td>{{ $j->kelas_count }}</td>
                        <td>{{ $j->sesi_count ?: '—' }}</td>
                        <td>
                            <span class="lencana {{ $label[$j->status][1] }}">{{ $label[$j->status][0] }}</span>
                            @if ($j->status === 'aktif') <small class="bantuan" style="margin:2px 0 0">{{ $jendela[$j->jendela()] }}</small> @endif
                        </td>
                        <td class="kanan aksi-baris"><a href="{{ route('staf.jadwal.show', $j) }}">Buka</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="kosong">Belum ada jadwal. Klik "Buat jadwal" untuk memulai.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{ $jadwal->links('pagination.ringkas') }}
@endsection
