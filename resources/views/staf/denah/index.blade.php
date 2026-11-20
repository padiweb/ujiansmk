@extends('layouts.staf')
@section('judul', 'Ruang Ujian')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Ruang Ujian</h1>
        <p class="sub">{{ $ruang->count() }} ruang · {{ number_format($jumlah, 0, ',', '.') }} siswa sudah punya ruang</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil garis" href="{{ route('staf.ruang.otomatis') }}">Isi otomatis</a>
        <a class="tombol kecil" href="{{ route('staf.ruang.create') }}"><x-ikon nama="tambah" :ukuran="16" />Tambah</a>
    </div>
</div>

@if ($belumPunyaRuang > 0)
    <div class="pesan-salah" role="status"><x-ikon nama="peringatan" :ukuran="18" /><span>{{ number_format($belumPunyaRuang, 0, ',', '.') }} siswa aktif belum punya ruang. Masukkan kelasnya lewat tautan Siswa pada ruang yang dituju, atau pakai <a href="{{ route('staf.ruang.otomatis') }}">Isi otomatis</a>.</span></div>
@endif

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel padat-denah">
            <thead><tr><th class="no">No</th><th>Ruang</th><th>Kapasitas</th><th>Jenis</th><th>Siswa</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($ruang as $r)
                    <tr>
                        <td class="no">{{ $loop->iteration }}</td>
                        <td><a href="{{ route('staf.ruang.show', $r) }}"><strong>{{ $r->nama }}</strong></a></td>
                        <td>{{ $r->kapasitas }}</td>
                        <td>{{ $r->campur ? 'Campur tingkat' : 'Biasa' }}</td>
                        <td>
                            @if (($terisi[$r->id] ?? 0) > 0)
                                <span class="lencana">{{ $terisi[$r->id] }}</span>
                                @foreach (($komposisi[$r->id] ?? collect()) as $kelas => $n) <span class="lencana pucat">{{ $kelas }} · {{ $n }}</span> @endforeach
                            @else
                                —
                            @endif
                        </td>
                        <td class="kanan aksi-baris">
                            <a href="{{ route('staf.ruang.show', $r) }}">Siswa</a>
                            <a href="{{ route('staf.ruang.edit', $r) }}">Ubah</a>
                            <form method="POST" action="{{ route('staf.ruang.destroy', $r) }}" onsubmit="return confirm('Hapus ruang {{ e($r->nama) }}? Siswa di dalamnya tidak dihapus, hanya dilepas dari ruang.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="tautan">Hapus</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="kosong">Belum ada ruang.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
