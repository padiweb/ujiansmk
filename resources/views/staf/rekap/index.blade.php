@extends('layouts.staf')
@section('judul', 'Rekap Nilai')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Rekap Nilai</h1>
        <p class="sub">Hasil ujian per jadwal{{ auth('web')->user()->role === 'guru' ? ' pada mata pelajaran yang Anda ampu' : '' }}.</p>
    </div>
</div>

@forelse ($jadwal as $j)
    <a class="kartu kartu-monitor" href="{{ route('staf.rekap.show', $j) }}">
        <div>
            <strong>{{ $j->nama }}</strong>
            <small class="bantuan" style="margin:2px 0 0">{{ $j->mapel->nama }} · {{ $j->mulai_pada->format('d/m/Y H:i') }}</small>
        </div>
        <div>
            @if ($j->nilaiDikunci()) <span class="lencana">Nilai dikunci</span> @endif
            <span class="lencana {{ $j->status === 'aktif' ? '' : 'pucat' }}">{{ $j->status === 'aktif' ? 'Aktif' : 'Selesai' }}</span>
        </div>
    </a>
@empty
    <div class="kartu kosong-kartu">Belum ada jadwal ujian yang dimulai.</div>
@endforelse
@endsection
