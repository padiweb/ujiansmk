@extends('layouts.staf')
@section('judul', 'Monitor Ujian')

@php
    $jendela = ['belum_dibuka' => 'Belum dibuka', 'dibuka' => 'Sedang dibuka', 'ditutup' => 'Sudah ditutup'];
@endphp

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Monitor Ujian</h1>
        <p class="sub">{{ $pengawas ? 'Pilih ruang yang akan Anda awasi.' : 'Jadwal yang sedang aktif.' }}</p>
    </div>
</div>

@if ($pengawas)
    <h2 class="judul-kartu" style="margin-top:6px">Pilih ruang</h2>
    @forelse ($ruang as $r)
        @php $s = $ringkasRuang[$r->id] ?? ['siswa' => 0, 'mengerjakan' => 0, 'terblokir' => 0]; @endphp
        <a class="kartu kartu-monitor" href="{{ route('staf.monitor.ruang', $r) }}">
            <div>
                <strong>{{ $r->nama }}</strong>
                <small class="bantuan" style="margin:2px 0 0">{{ $r->campur ? 'Campur tingkat' : 'Biasa' }} · kapasitas {{ $r->kapasitas }}</small>
            </div>
            <div class="ringkasan" style="margin:0">
                <div><strong>{{ $s['siswa'] }}</strong><span>Siswa</span></div>
                <div><strong>{{ $s['mengerjakan'] }}</strong><span>Mengerjakan</span></div>
                <div><strong>{{ $s['terblokir'] }}</strong><span>Terblokir</span></div>
            </div>
        </a>
    @empty
        <div class="kartu kosong-kartu">Belum ada ruang. Tambahkan di menu Ruang Ujian.</div>
    @endforelse

    @if ($jadwal->isNotEmpty())
        <h2 class="judul-kartu" style="margin-top:24px">Per ujian</h2>
    @endif
@endif

@forelse ($jadwal as $j)
    @php $h = $hitung[$j->id] ?? collect(); @endphp
    <a class="kartu kartu-monitor" href="{{ route('staf.monitor.show', $j) }}">
        <div>
            <strong>{{ $j->nama }}</strong>
            <small class="bantuan" style="margin:2px 0 0">{{ $j->mapel->nama }} · {{ $j->mulai_pada->format('d/m/Y H:i') }}–{{ $j->tutup_pada->format('H:i') }} · {{ $jendela[$j->jendela()] }}</small>
        </div>
        <div class="ringkasan" style="margin:0">
            <div><strong>{{ $h['berlangsung'] ?? 0 }}</strong><span>Mengerjakan</span></div>
            <div><strong>{{ $h['diblokir'] ?? 0 }}</strong><span>Terblokir</span></div>
            <div><strong>{{ $h['selesai'] ?? 0 }}</strong><span>Selesai</span></div>
            <div><strong>{{ $h['belum'] ?? 0 }}</strong><span>Belum mulai</span></div>
        </div>
    </a>
@empty
    @unless ($pengawas)
        <div class="kartu kosong-kartu">Tidak ada jadwal ujian aktif.</div>
    @endunless
@endforelse
@endsection
