@extends('layouts.staf')
@section('judul', 'Pengawas')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Pengawas</h1>
        <p class="sub">Petugas pengawas ujian.</p>
    </div>
</div>

<form class="saring" method="GET" action="{{ route('staf.pengawas.index') }}">
    <input type="search" name="q" value="{{ $q }}" placeholder="Cari nama atau nama pengguna…" aria-label="Cari">
    <button class="tombol kecil garis" type="submit">Cari</button>
</form>

<div class="kartu tanpa-padding">
    <h2 class="judul-kartu" style="padding:16px 20px 0">Pengawas ({{ $pengawas->count() }})</h2>
    <div class="gulir">
        <table class="tabel tabel-kartu">
            <thead><tr><th class="no">No</th><th>Nama</th><th>Nama pengguna</th><th>Sumber</th><th>Status</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($pengawas as $p)
                    <tr>
                        <td class="no">{{ $loop->iteration }}</td>
                        <td><strong>{{ $p->nama }}</strong></td>
                        <td>{{ $p->username }}</td>
                        <td>{{ $p->role === 'guru' ? 'Guru (EduWeb)' : 'Akun pengawas lokal' }}</td>
                        <td>@if ($p->aktif) <span class="lencana">Aktif</span> @else <span class="lencana kuning">Nonaktif</span> @endif</td>
                        <td class="kanan aksi-baris">
                            <form method="POST" action="{{ route('staf.pengawas.ubah', $p) }}" style="display:inline"
                                  @if ($p->role === 'guru' || $p->aktif) onsubmit="return confirm('{{ $p->role === 'guru' ? 'Cabut tugas pengawas' : 'Nonaktifkan akun' }} {{ e($p->nama) }}?')" @endif>
                                @csrf @method('PUT')
                                @if ($p->role === 'guru')
                                    <button class="tombol kecil garis" type="submit">Cabut tugas</button>
                                @elseif ($p->aktif)
                                    <button class="tombol kecil garis" type="submit">Nonaktifkan</button>
                                @else
                                    <input type="hidden" name="aktif" value="1">
                                    <button class="tombol kecil garis" type="submit">Aktifkan</button>
                                @endif
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="kosong">Belum ada pengawas. Pilih dari daftar guru di bawah, atau tambahkan akun pengawas baru.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="kartu tanpa-padding">
    <h2 class="judul-kartu" style="padding:16px 20px 0">Guru</h2>
    <div class="gulir">
        <table class="tabel tabel-kartu">
            <thead><tr><th>Nama</th><th>Nama pengguna</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($guru as $g)
                    <tr>
                        <td>{{ $g->nama }}</td>
                        <td>{{ $g->username }}</td>
                        <td class="kanan aksi-baris">
                            <form method="POST" action="{{ route('staf.pengawas.ubah', $g) }}" style="display:inline">
                                @csrf @method('PUT')
                                <input type="hidden" name="aktif" value="1">
                                <button class="tombol kecil" type="submit">Jadikan pengawas</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="kosong">{{ $q !== '' ? 'Tidak ada guru yang cocok.' : 'Semua guru aktif sudah menjadi pengawas, atau belum ada data guru.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<form class="kartu formulir" method="POST" action="{{ route('staf.pengawas.tambah') }}">
    @csrf
    <h2 class="judul-kartu">Pengawas non-guru</h2>
    @foreach ([['nama', 'Nama lengkap', 'text'], ['username', 'Nama pengguna', 'text'], ['password', 'Kata sandi (minimal 10 karakter)', 'password']] as [$n, $l, $t])
        <div class="bidang @error($n) salah @enderror">
            <label for="p-{{ $n }}">{{ $l }} <span class="wajib">*</span></label>
            <input id="p-{{ $n }}" name="{{ $n }}" type="{{ $t }}" value="{{ $t === 'password' ? '' : old($n) }}" required autocomplete="off">
            @error($n) <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>
    @endforeach
    <div class="aksi-form"><button class="tombol kecil" type="submit">Tambah pengawas</button></div>
</form>
@endsection
