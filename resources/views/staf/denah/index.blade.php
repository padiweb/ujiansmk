@extends('layouts.staf')
@section('judul', 'Ruang')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Manajemen Ruang Ujian</h1>
        <p class="sub">{{ $jumlah }} siswa sudah punya ruang.</p>
    </div>
</div>

@if ($belumPunyaRuang > 0)
    <div class="pesan-salah catatan" role="status"><x-ikon nama="peringatan" :ukuran="18" /><span>Ada <strong>{{ $belumPunyaRuang }} siswa aktif</strong> yang belum ditugaskan ke ruang ujian. <a href="#bagi">Bagi otomatis</a> atau atur dari halaman ruang.</span></div>
@endif

<form class="kartu formulir" method="POST" action="{{ $edit ? route('staf.ruang.update', $edit) : route('staf.ruang.store') }}" style="max-width:600px">
    @csrf
    @if ($edit) @method('PUT') @endif
    <h2 class="judul-kartu">{{ $edit ? 'Edit Data Ruang' : 'Tambah Ruang Baru' }}</h2>
    <div class="bidang @error('nama') salah @enderror">
        <label for="r-nama">Nama Ruang</label>
        <input id="r-nama" name="nama" type="text" maxlength="50" value="{{ old('nama', $edit?->nama) }}" placeholder="Contoh: LAB TKJ 1" required>
        @error('nama') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>
    <div class="bidang @error('kapasitas') salah @enderror">
        <label for="r-kap">Kapasitas (siswa)</label>
        <input id="r-kap" name="kapasitas" type="number" min="1" max="500" value="{{ old('kapasitas', $edit?->kapasitas ?? 36) }}" style="width:140px" required>
        @error('kapasitas') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>
    <div class="aksi-form">
        <button class="tombol kecil" type="submit">{{ $edit ? 'Simpan Perubahan' : 'Simpan Ruang' }}</button>
        @if ($edit) <a class="tombol kecil garis" href="{{ route('staf.ruang.index') }}">Batal</a> @endif
    </div>
</form>

<div class="kartu tanpa-padding">
    <h2 class="judul-kartu" style="padding:16px 20px 0">Daftar Ruang &amp; Kapasitas</h2>
    <div class="gulir">
        <table class="tabel">
            <thead><tr><th>Nama Ruang</th><th>Kapasitas</th><th>Alokasi Siswa</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($ruang as $r)
                    <tr>
                        <td><strong>{{ $r->nama }}</strong></td>
                        <td>{{ $r->kapasitas }}</td>
                        <td>@if (($terisi[$r->id] ?? 0) > 0) <span class="lencana">{{ $terisi[$r->id] }} Siswa</span> @else <span class="sub">Kosong</span> @endif</td>
                        <td class="kanan aksi-baris">
                            <a href="{{ route('staf.ruang.index', ['edit_id' => $r->id]) }}">Edit</a>
                            <a href="{{ route('staf.ruang.show', $r) }}">Lihat Siswa</a>
                            <form method="POST" action="{{ route('staf.ruang.destroy', $r) }}" style="display:inline" onsubmit="return confirm('Hapus ruang {{ e($r->nama) }}? Siswa di dalamnya tidak dihapus, hanya dilepas dari ruang.')">
                                @csrf @method('DELETE')
                                <button class="tombol kecil bahaya" type="submit">Hapus</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="kosong">Belum ada ruang. Tambahkan ruang di atas.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<form class="kartu" id="bagi" method="POST" action="{{ route('staf.ruang.bagi') }}" onsubmit="return confirm('Bagi ulang siswa ke ruang? Penempatan yang ada diganti.')">
    @csrf
    <h2 class="judul-kartu">Bagi Siswa Otomatis</h2>
    <fieldset class="opsi-blok">
        <legend>Kelas</legend>
        @foreach ($daftarKelas->groupBy('tingkat') as $tingkat => $kelasTingkat)
            <div class="baris-bank" style="display:block">
                <strong>Tingkat {{ $tingkat }}</strong>
                @foreach ($kelasTingkat as $k)
                    <label class="centang" style="display:inline-flex;margin-right:14px"><input type="checkbox" name="kelas_ids[]" value="{{ $k->id }}" @checked(in_array($k->id, array_map('intval', old('kelas_ids', [])), true))> {{ $k->nama }}</label>
                @endforeach
            </div>
        @endforeach
        @error('kelas_ids') <small class="galat-bidang">{{ $message }}</small> @enderror
    </fieldset>
    <label class="centang"><input type="checkbox" name="campur" value="1" @checked(old('campur'))> Campur tingkat (2 siswa per meja)</label>
    <div class="aksi-form"><button class="tombol kecil" type="submit" @disabled($ruang->isEmpty())>Bagi Siswa</button></div>
</form>
@endsection
