@extends('layouts.staf')
@section('judul', 'Denah: '.$d->nama)

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">{{ $d->nama }}</h1>
        <p class="sub">{{ $d->periode->label() }} · {{ $jumlah }} siswa di ruang
            @if ($jadwalPakai->isNotEmpty()) · dipakai {{ $jadwalPakai->map(fn ($j) => $j->nama)->implode(', ') }} @endif</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil garis" href="{{ route('staf.denah.index') }}"><x-ikon nama="panah-kiri" :ukuran="16" />Daftar</a>
        <form method="POST" action="{{ route('staf.denah.destroy', $d) }}" onsubmit="return confirm('Hapus denah ini beserta seluruh penempatannya?')">
            @csrf @method('DELETE')
            <button class="tombol kecil bahaya" type="submit"><x-ikon nama="hapus" :ukuran="16" />Hapus</button>
        </form>
    </div>
</div>

@if ($belumPunyaRuang > 0)
    <div class="pesan-salah catatan" role="status"><x-ikon nama="peringatan" :ukuran="18" /><span>Ada <strong>{{ $belumPunyaRuang }} siswa aktif</strong> yang belum punya ruang.</span></div>
@endif

<form class="kartu tanpa-padding" method="POST" action="{{ route('staf.denah.ruang', $d) }}">
    @csrf
    <h2 class="judul-kartu" style="padding:16px 20px 0">Ruang dan kapasitas</h2>
    <div class="gulir">
        <table class="tabel">
            <thead><tr><th>Ruang</th><th>Kapasitas</th><th>Siswa</th><th>Komposisi</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($baris as $b)
                    <tr>
                        <td><strong>{{ $b['ruang']->nama }}</strong></td>
                        <td><input type="number" name="kapasitas[{{ $b['ruang']->id }}]" min="0" max="500" value="{{ old('kapasitas.'.$b['ruang']->id, $b['kapasitas']) }}" placeholder="Tidak dipakai" aria-label="Kapasitas {{ $b['ruang']->nama }}" style="width:120px"></td>
                        <td>@if ($b['terisi'] > 0) <span class="lencana">{{ $b['terisi'] }} siswa</span> @else <span class="sub">Kosong</span> @endif</td>
                        <td>@foreach ($b['komposisi'] as $kelas => $n) <span class="lencana pucat">{{ $kelas }} · {{ $n }}</span> @endforeach</td>
                        <td class="kanan aksi-baris">@if ($b['kapasitas']) <a href="{{ route('staf.denah.ruang.show', [$d, $b['ruang']]) }}">Lihat siswa</a> @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="kosong">Belum ada ruang. Tambahkan di menu Ruang Ujian.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="aksi-form" style="padding:12px 20px 16px"><button class="tombol kecil" type="submit" @disabled($baris->isEmpty())>Simpan ruang</button></div>
</form>

<form class="kartu" method="POST" action="{{ route('staf.denah.bagi', $d) }}" onsubmit="return confirm('Bagi ulang seluruh siswa? Penempatan yang ada diganti.')">
    @csrf
    <h2 class="judul-kartu">Bagi siswa ke ruang</h2>
    <fieldset class="opsi-blok">
        <legend>Kelas peserta</legend>
        @foreach ($daftarKelas->groupBy('tingkat') as $tingkat => $kelasTingkat)
            <div class="baris-bank" style="display:block">
                <strong>Tingkat {{ $tingkat }}</strong>
                @foreach ($kelasTingkat as $k)
                    <label class="centang" style="display:inline-flex;margin-right:14px"><input type="checkbox" name="kelas_ids[]" value="{{ $k->id }}" @checked(in_array($k->id, array_map('intval', old('kelas_ids', $kelasKegiatan)), true))> {{ $k->nama }}</label>
                @endforeach
            </div>
        @endforeach
        @error('kelas_ids') <small class="galat-bidang">{{ $message }}</small> @enderror
    </fieldset>
    <label class="centang"><input type="checkbox" name="campur" value="1" @checked(old('campur'))> Campur tingkat (2 siswa per meja)</label>
    <div class="aksi-form"><button class="tombol kecil" type="submit" @disabled($baris->whereNotNull('kapasitas')->isEmpty())>Bagi siswa</button></div>
</form>
@endsection
