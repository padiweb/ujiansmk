@extends('layouts.staf')
@section('judul', 'Denah: '.$d->nama)

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">{{ $d->nama }}</h1>
        <p class="sub">{{ ucfirst($d->periode->semester) }} {{ $d->periode->tahunAjaran->nama }} · {{ $jumlah }} siswa ditempatkan
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

<div class="kartu">
    <h2 class="judul-kartu">1. Ruang yang dipakai</h2>
    <table class="tabel" style="margin-top:8px">
        <thead><tr><th>Ruang</th><th>Meja</th><th>Siswa per meja</th><th>Kapasitas</th><th>Terisi</th><th class="kanan">Aksi</th></tr></thead>
        <tbody>
            @forelse ($ruangDenah as $x)
                <tr>
                    <td><strong>{{ $x['r']->ruang->nama }}</strong></td><td>{{ $x['r']->jumlah_meja }}</td><td>{{ $x['r']->siswa_per_meja }}</td>
                    <td>{{ $x['r']->kapasitas() }}</td><td>{{ $x['terisi'] }}</td>
                    <td class="kanan aksi-baris">
                        <form method="POST" action="{{ route('staf.denah.ruang.hapus', [$d, $x['r']]) }}" onsubmit="return confirm('Lepas ruang ini beserta penempatannya?')">
                            @csrf @method('DELETE') <button class="tombol kecil bahaya" type="submit">Lepas</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="kosong">Belum ada ruang.</td></tr>
            @endforelse
        </tbody>
    </table>

    <form method="POST" action="{{ route('staf.denah.ruang', $d) }}" class="saring" style="margin-top:14px;align-items:flex-end">
        @csrf
        <label class="bidang" style="margin:0">Ruang
            <select name="ruang_id" required>
                @foreach ($daftarRuang as $r) <option value="{{ $r->id }}" @selected((int) old('ruang_id') === $r->id)>{{ $r->nama }}</option> @endforeach
                @if ($daftarRuang->isEmpty()) <option value="">(semua ruang sudah dipakai)</option> @endif
            </select></label>
        <label class="bidang" style="margin:0">Jumlah meja <input type="number" name="jumlah_meja" min="1" max="200" value="{{ old('jumlah_meja', 15) }}" style="width:100px" required></label>
        <label class="bidang" style="margin:0">Siswa per meja
            <select name="siswa_per_meja" style="width:90px">@foreach ([1, 2, 3] as $n) <option value="{{ $n }}" @selected((int) old('siswa_per_meja', 2) === $n)>{{ $n }}</option> @endforeach</select></label>
        <button class="tombol kecil garis" type="submit" @disabled($daftarRuang->isEmpty())>Simpan ruang</button>
    </form>
    <small class="bantuan">Menyimpan ruang yang sudah ada mengubah jumlah meja atau siswa per meja ruang itu. Daftar ruang berasal dari Master Data → Ruang Ujian.</small>
</div>

<div class="kartu">
    <h2 class="judul-kartu">2. Bagi siswa otomatis</h2>
    <p class="sub" style="margin-top:0">Siswa diacak lalu ditempatkan sehingga satu meja berisi tingkat yang berbeda. Penempatan yang ada akan diganti.</p>
    <form method="POST" action="{{ route('staf.denah.bagi', $d) }}" onsubmit="return confirm('Bagi ulang seluruh siswa? Penempatan yang ada diganti.')">
        @csrf
        <fieldset class="opsi-blok">
            <legend>Kelas peserta</legend>
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
        <div class="aksi-form"><button class="tombol kecil" type="submit" @disabled($ruangDenah->isEmpty())>Bagi sekarang</button></div>
    </form>
</div>

<div class="kartu">
    <h2 class="judul-kartu">3. Ubah manual</h2>
    <form method="POST" action="{{ route('staf.denah.tempatkan', $d) }}" class="saring" style="align-items:flex-end">
        @csrf
        <label class="bidang" style="margin:0">NISN siswa <input name="nisn" value="{{ old('nisn') }}" maxlength="20" required style="width:160px"></label>
        <label class="bidang" style="margin:0">Ruang
            <select name="ruang_id" required>@foreach ($ruangDenah as $x) <option value="{{ $x['r']->ruang_id }}" @selected((int) old('ruang_id') === $x['r']->ruang_id)>{{ $x['r']->ruang->nama }}</option> @endforeach</select></label>
        <label class="bidang" style="margin:0">Meja <input type="number" name="meja" min="1" value="{{ old('meja', 1) }}" style="width:90px" required></label>
        <label class="bidang" style="margin:0">Posisi <input type="number" name="posisi" min="1" max="3" value="{{ old('posisi', 1) }}" style="width:80px" required></label>
        <button class="tombol kecil garis" type="submit" @disabled($ruangDenah->isEmpty())>Tempatkan</button>
    </form>
    <small class="bantuan">Bila tempat itu sudah terisi, kedua siswa bertukar tempat.</small>
</div>

@foreach ($ruangDenah as $x)
    <div class="kartu">
        <h2 class="judul-kartu">{{ $x['r']->ruang->nama }} <small class="bantuan" style="display:inline">· {{ $x['terisi'] }} dari {{ $x['r']->kapasitas() }}</small></h2>
        <div class="denah-grid">
            @foreach ($x['meja'] as $m)
                <div class="meja {{ $m['sejenis'] ? 'sejenis' : '' }} {{ $m['orang']->isEmpty() ? 'kosong' : '' }}">
                    <strong>Meja {{ $m['no'] }}</strong>
                    @forelse ($m['orang'] as $o)
                        <div class="meja-siswa">
                            <span>{{ $o['nama'] }}<small>{{ $o['kelas'] }} · {{ $o['nisn'] }}</small></span>
                            <form method="POST" action="{{ route('staf.denah.lepas', [$d, $o['siswa_id']]) }}">@csrf <button type="submit" class="meja-lepas" title="Lepas dari denah" aria-label="Lepas {{ $o['nama'] }}"><x-ikon nama="tutup" :ukuran="12" /></button></form>
                        </div>
                    @empty
                        <small class="bantuan">kosong</small>
                    @endforelse
                </div>
            @endforeach
        </div>
    </div>
@endforeach
@endsection
