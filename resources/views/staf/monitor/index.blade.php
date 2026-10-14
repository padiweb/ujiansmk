@extends('layouts.staf')
@section('judul', 'Monitor Ujian')

@php
    $jendela = ['belum_dibuka' => 'Belum dibuka', 'dibuka' => 'Sedang dibuka', 'ditutup' => 'Sudah ditutup'];
@endphp

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Monitor Ujian</h1>
        <p class="sub">Jadwal aktif{{ auth('web')->user()->role === 'pengawas' ? ' yang ditugaskan kepada Anda' : '' }}.</p>
    </div>
</div>

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
    <div class="kartu kosong-kartu">Tidak ada jadwal ujian aktif{{ auth('web')->user()->role === 'pengawas' ? ' yang ditugaskan kepada Anda' : '' }}.</div>
@endforelse
@endsection
