@extends('layouts.staf')
@section('judul', 'Dashboard')

@section('konten')
<h1 class="judul-halaman">Selamat datang, {{ $user->nama }}</h1>
<p class="sub">{{ config('ujian.sekolah') }}</p>

@if ($user->role === 'admin')
    @if ($dataEduweb)
        <div class="{{ $dataEduweb['masalah'] ? 'pesan-salah' : 'info' }}" role="status"><x-ikon :nama="$dataEduweb['masalah'] ? 'peringatan' : 'info'" :ukuran="18" /><span>{{ $dataEduweb['teks'] }}</span></div>
    @endif
    <div class="kartu">
        <h2 class="judul-kartu">Persiapan data</h2>
        <p class="sub" style="margin-top:0">Isi berurutan dari atas. Setiap langkah bisa lewat impor Excel.</p>
        <ol class="langkah">
            @foreach ($langkah as $l)
                <li class="{{ $l['jumlah'] > 0 ? 'selesai' : '' }}">
                    <a href="{{ route('staf.master.index', $l['slug']) }}">{{ $l['label'] }}</a>
                    <span class="hitung">{{ $l['jumlah'] }}</span>
                </li>
            @endforeach
        </ol>
    </div>
@else
    <div class="kartu">Gunakan menu <strong>Bank Soal</strong> di samping untuk menyusun soal ujian.</div>
@endif
@endsection
