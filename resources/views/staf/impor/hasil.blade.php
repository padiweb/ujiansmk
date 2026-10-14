@extends('layouts.staf')
@section('judul', 'Hasil impor '.$p->judul())

@section('konten')
<h1 class="judul-halaman">{{ $selesai ? 'Impor selesai' : 'Hasil pemeriksaan' }}: {{ $p->judul() }}</h1>

@if ($hasil->berhasil())
    <div class="kartu">
        <div class="ringkasan">
            <div><strong>{{ $hasil->baru }}</strong><span>data baru</span></div>
            <div><strong>{{ $hasil->diperbarui }}</strong><span>diperbarui</span></div>
        </div>

        @if ($selesai)
            <p>Data sudah disimpan.</p>
            @if ($tokenSandi)
                <div class="pesan-salah catatan" style="margin-top:14px">
                    Ada {{ count($hasil->sandi) }} akun baru dengan kata sandi acak. Daftarnya hanya bisa diunduh <strong>satu kali</strong>
                    dan tidak tersimpan di sistem.
                    <p style="margin-top:10px"><a class="tombol kecil" href="{{ route('staf.impor.sandi', $tokenSandi) }}">Unduh daftar kata sandi</a></p>
                </div>
            @endif
        @else
            <p>Semua baris valid. Belum ada yang disimpan.</p>
            <form method="POST" action="{{ str_replace(':token', $token, $rute['simpan']) }}" class="aksi-form">
                @csrf
                <button class="tombol kecil" type="submit">Simpan {{ $hasil->total() }} data</button>
                <a class="tombol kecil garis" href="{{ $rute['form'] }}">Batal</a>
            </form>
        @endif
    </div>
@else
    <div class="pesan-salah" role="alert">
        Impor dibatalkan: ada {{ count($hasil->galat) }} galat. Tidak ada data yang disimpan. Perbaiki berkas, lalu unggah ulang.
    </div>
    <div class="kartu tanpa-padding">
        <table class="tabel">
            <thead><tr><th style="width:90px">Baris</th><th>Masalah</th></tr></thead>
            <tbody>
                @foreach (array_slice($hasil->galat, 0, 200) as $g)
                    <tr><td>{{ $g['baris'] ?: '—' }}</td><td>{{ $g['pesan'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if (count($hasil->galat) > 200)
        <p class="sub">Menampilkan 200 galat pertama dari {{ count($hasil->galat) }}.</p>
    @endif
    <p style="margin-top:16px"><a class="tombol kecil garis" href="{{ $rute['form'] }}">Unggah ulang</a></p>
@endif

<p style="margin-top:20px"><a href="{{ $rute['kembali'] }}">← Kembali ke {{ $rute['kembaliLabel'] }}</a></p>
@endsection
