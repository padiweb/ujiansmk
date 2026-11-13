@extends('layouts.staf')
@section('judul', $judul)

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">{{ $judul }}</h1>
        <p class="sub">Siswa kelas XII{{ $kelas ? ' · '.$kelas->nama : '' }}</p>
    </div>
</div>

<form class="bar-saring" method="GET" action="{{ route('staf.kelulusan.daftar', $jenis) }}">
    <div class="bar-isi">
        <label for="f-kelas">Kelas</label>
        <select id="f-kelas" name="kelas" onchange="this.form.submit()">
            @foreach ($daftarKelas as $k)
                <option value="{{ $k->id }}" @selected($kelas?->id === $k->id)>{{ $k->nama }}</option>
            @endforeach
        </select>
    </div>
    <span class="bar-total">Total: <b>{{ count($siswa) }}</b> siswa</span>
</form>

@if ($kelas)
<form id="formCetakLulus" class="bar-saring bar-cetak" method="GET" action="{{ route('staf.kelulusan.cetak', $jenis) }}" target="_blank">
    <input type="hidden" name="kelas" value="{{ $kelas->id }}">
    <input type="hidden" name="siswa" value="">
    <div class="bar-isi">
        <label for="c-no">Nomor surat</label>
        <input id="c-no" type="text" name="no_surat" maxlength="80" placeholder="Contoh: 421.3/027/SMK-AH/V/2026" autocomplete="off">
    </div>
    <div class="bar-isi">
        <label for="c-tgl">Tanggal terbit</label>
        <input id="c-tgl" type="date" name="tgl" value="{{ now()->format('Y-m-d') }}">
    </div>
    @if ($jenis === 'mutasi')
        <div class="bar-isi">
            <label for="c-rapat">Tanggal rapat dewan guru</label>
            <input id="c-rapat" type="date" name="tgl_rapat">
        </div>
    @endif
    @if ($jenis === 'skl')
        <div class="bar-isi">
            <label for="c-lulus">Tanggal kelulusan</label>
            <input id="c-lulus" type="date" name="tgl_lulus" value="{{ now()->format('Y-m-d') }}">
        </div>
    @endif
    <div class="bar-aksi">
        <button class="tombol kecil" type="submit" name="format" value="cetak"><x-ikon nama="cetak" :ukuran="16" />Cetak Kelas</button>
        <button class="tombol kecil garis" type="submit" name="format" value="word"><x-ikon nama="berkas" :ukuran="16" />Word Kelas</button>
    </div>
</form>
@endif

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel tabel-daftar">
            <thead><tr><th class="no">No</th><th>Nama Siswa</th><th>NISN</th><th>No. Peserta US</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($siswa as $s)
                    <tr>
                        <td class="no">{{ $loop->iteration }}</td>
                        <td><strong>{{ $s->nama }}</strong></td>
                        <td>{{ $s->nisn }}</td>
                        <td>{{ $s->no_peserta_us ?: '–' }}</td>
                        <td class="kanan aksi-baris">
                            <button class="tombol kecil garis tombol-ikon" type="submit" form="formCetakLulus" name="format" value="cetak" onclick="this.form.elements.siswa.value='{{ $s->id }}'" title="Cetak {{ $judul }}" aria-label="Cetak {{ $s->nama }}"><x-ikon nama="cetak" :ukuran="16" /></button>
                            <button class="tombol kecil garis tombol-ikon" type="submit" form="formCetakLulus" name="format" value="word" onclick="this.form.elements.siswa.value='{{ $s->id }}'" title="Unduh Word" aria-label="Word {{ $s->nama }}"><x-ikon nama="berkas" :ukuran="16" /></button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="kosong">Belum ada siswa di kelas ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<script>
    // Tombol "Kelas" mengosongkan pilihan siswa; tombol di baris mengisinya sebelum kirim.
    document.querySelectorAll('#formCetakLulus .bar-aksi button').forEach(function (b) {
        b.addEventListener('click', function () { this.form.elements.siswa.value = ''; });
    });
</script>
@endsection
