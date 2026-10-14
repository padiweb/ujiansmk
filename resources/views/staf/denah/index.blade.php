@extends('layouts.staf')
@section('judul', 'Denah Ruang')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Denah Ruang Ujian</h1>
        <p class="sub">Penempatan siswa ke ruang dan meja. Satu meja diisi siswa dari tingkat berbeda.</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil" href="{{ route('staf.denah.create') }}"><x-ikon nama="tambah" :ukuran="16" />Buat denah</a>
    </div>
</div>

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel">
            <thead><tr><th>Denah</th><th>Semester</th><th>Ruang</th><th>Siswa</th><th>Dipakai jadwal</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($denah as $d)
                    <tr>
                        <td><a href="{{ route('staf.denah.show', $d) }}"><strong>{{ $d->nama }}</strong></a></td>
                        <td>{{ ucfirst($d->periode->semester) }} {{ $d->periode->tahunAjaran->nama }}</td>
                        <td>{{ $d->ruang_count }}</td>
                        <td>{{ $d->penempatan_count }}</td>
                        <td>{{ $d->jadwal_count }}</td>
                        <td class="kanan aksi-baris"><a href="{{ route('staf.denah.show', $d) }}">Buka</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="kosong">Belum ada denah. Buat denah, tambahkan ruang, lalu bagi siswa secara otomatis.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
