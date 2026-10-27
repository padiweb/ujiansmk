@extends('layouts.staf')
@section('judul', 'Kegiatan Ujian')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Kegiatan Ujian</h1>
        <p class="sub">Nama kegiatan dipilih saat membuat jadwal.</p>
    </div>
</div>

<form class="kartu formulir" method="POST" action="{{ route('staf.kegiatan.store') }}">
    @csrf
    <h2 class="judul-kartu">Tambah kegiatan</h2>
    <div class="bidang @error('periode_id') salah @enderror">
        <label for="k-periode">Semester <span class="wajib">*</span></label>
        <select id="k-periode" name="periode_id" required>
            @foreach ($daftarPeriode as $p)
                <option value="{{ $p->id }}" @selected((int) old('periode_id', $periodeAktif) === $p->id)>{{ $p->label() }}@if ($p->aktif) (aktif)@endif</option>
            @endforeach
        </select>
        @error('periode_id') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>
    <div class="bidang @error('nama') salah @enderror">
        <label for="k-nama">Nama kegiatan <span class="wajib">*</span></label>
        <input id="k-nama" name="nama" type="text" maxlength="120" value="{{ old('nama') }}" placeholder="Asesmen Semester Gasal" required>
        @error('nama') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>
    <div class="aksi-form"><button class="tombol kecil" type="submit">Tambah</button></div>
</form>

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel">
            <thead><tr><th class="no">No</th><th>Kegiatan</th><th>Semester</th><th>Jadwal</th><th>Rapor</th><th>Status</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($kegiatan as $k)
                    <tr>
                        <td class="no">{{ $loop->iteration }}</td>
                        <td>
                            <form method="POST" action="{{ route('staf.kegiatan.update', $k) }}" style="display:flex;gap:6px;align-items:center">
                                @csrf @method('PUT')
                                <input type="text" name="nama" value="{{ $k->nama }}" maxlength="120" aria-label="Nama kegiatan" required style="min-width:220px">
                                <button class="tombol kecil garis" type="submit">Simpan nama</button>
                            </form>
                        </td>
                        <td>{{ $k->periode->label() }}</td>
                        <td>{{ $k->jadwal_count }}</td>
                        <td>
                            <form method="POST" action="{{ route('staf.kegiatan.update', $k) }}">
                                @csrf @method('PUT')
                                <input type="hidden" name="untuk_rapor" value="{{ $k->untuk_rapor ? 0 : 1 }}">
                                <button type="submit" class="tautan" title="Nilai kegiatan ini menjadi sumber nilai Ujian di rapor">{{ $k->untuk_rapor ? 'Dipakai' : 'Tidak dipakai' }}</button>
                            </form>
                        </td>
                        <td>@if ($k->aktif) <span class="lencana">Aktif</span> @else <span class="lencana pucat">Tidak aktif</span> @endif</td>
                        <td class="kanan aksi-baris">
                            <form method="POST" action="{{ route('staf.kegiatan.update', $k) }}" style="display:inline">
                                @csrf @method('PUT')
                                <input type="hidden" name="aktif" value="{{ $k->aktif ? 0 : 1 }}">
                                <button class="tombol kecil garis" type="submit">{{ $k->aktif ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                            </form>
                            @if ($k->jadwal_count === 0)
                                <form method="POST" action="{{ route('staf.kegiatan.destroy', $k) }}" style="display:inline" onsubmit="return confirm('Hapus kegiatan ini?')">
                                    @csrf @method('DELETE')
                                    <button class="tombol kecil bahaya" type="submit">Hapus</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="kosong">Belum ada kegiatan. Tambahkan di atas, misalnya "Asesmen Semester Gasal".</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
