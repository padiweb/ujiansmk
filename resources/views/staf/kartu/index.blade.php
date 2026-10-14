@extends('layouts.staf')
@section('judul', 'Kartu Ujian')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Kartu ujian</h1>
        <p class="sub">{{ $j->nama }} · {{ $j->mapel->nama }} · {{ $peserta->count() }} peserta</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil garis" href="{{ route('staf.jadwal.show', $j) }}"><x-ikon nama="panah-kiri" :ukuran="16" />Jadwal</a>
    </div>
</div>

@if (! $j->denah_id)
    <div class="pesan-salah catatan" role="status"><x-ikon nama="info" :ukuran="18" /><span>Jadwal ini belum dipasang ke denah ruang, jadi kartu tidak memuat ruang dan nomor meja. Pasang denah lewat Ubah jadwal.</span></div>
@elseif ($tanpaTempat > 0)
    <div class="pesan-salah catatan" role="status"><x-ikon nama="peringatan" :ukuran="18" /><span>{{ $tanpaTempat }} peserta belum punya tempat di denah. Kartu mereka tanpa ruang dan meja.</span></div>
@endif

<form method="POST" action="{{ route('staf.kartu.sandi', $j) }}" class="kartu" id="formKartu">
    @csrf
    <h2 class="judul-kartu">Pilih kelas</h2>
    <fieldset class="opsi-blok">
        <legend>Kelas peserta</legend>
        @foreach ($j->kelas->sortBy('nama') as $k)
            <label class="centang"><input type="checkbox" name="kelas[]" value="{{ $k->id }}" @checked(in_array($k->id, array_map('intval', old('kelas', [])), true))> {{ $k->nama }} <small class="bantuan" style="display:inline">({{ $perKelas[$k->id] ?? 0 }} siswa)</small></label>
        @endforeach
        @error('kelas') <small class="galat-bidang">{{ $message }}</small> @enderror
    </fieldset>

    <div class="aksi-form">
        <button class="tombol kecil garis" type="submit" formaction="{{ route('staf.kartu.cetak', $j) }}" formtarget="_blank">Cetak kartu (tanpa kata sandi)</button>
    </div>

    <hr style="border:0;border-top:1px solid var(--garis);margin:18px 0">
    <h2 class="judul-kartu">Kata sandi sementara massal</h2>
    <div class="pesan-salah catatan" role="note"><x-ikon nama="peringatan" :ukuran="18" /><span>Kata sandi lama siswa di kelas terpilih <strong>diganti</strong> dengan sandi baru dan hanya tampil sekali pada kartu atau berkas yang dihasilkan. Siswa tidak wajib menggantinya. Maksimal {{ $maks }} siswa per kali.</span></div>
    <div class="aksi-form">
        <button class="tombol kecil" type="submit" name="format" value="cetak" formtarget="_blank" onclick="return confirm('Buat kata sandi baru untuk kelas terpilih? Sandi lama tidak berlaku lagi.')">Buat sandi baru dan cetak kartu</button>
        <button class="tombol kecil garis" type="submit" name="format" value="excel" onclick="return confirm('Buat kata sandi baru untuk kelas terpilih? Sandi lama tidak berlaku lagi.')">Buat sandi baru dan unduh Excel</button>
    </div>
</form>
@endsection
