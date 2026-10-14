@extends('layouts.staf')
@section('judul', 'Beranda Staf')

@section('konten')
<h1 class="judul-halaman">Beranda</h1>
<p class="sub">Selamat datang, {{ $user->nama }}.</p>

@if ($user->role === 'admin')
    <div class="kartu">
        <h2 class="judul-kartu">Persiapan data</h2>
        <p class="sub">Isi berurutan dari atas. Setiap langkah bisa lewat impor Excel.</p>
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
    <div class="kartu">Menu pengelolaan akan muncul di sini sesuai peran Anda.</div>
@endif
@endsection
