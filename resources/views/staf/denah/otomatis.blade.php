@extends('layouts.staf')
@section('judul', 'Isi Ruang Otomatis')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Isi ruang otomatis</h1>
        <p class="sub">{{ $jumlahRuang }} ruang · kapasitas total {{ number_format($kapasitasTotal, 0, ',', '.') }} siswa</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil garis" href="{{ route('staf.ruang.index') }}"><x-ikon nama="panah-kiri" :ukuran="16" />Daftar ruang</a>
    </div>
</div>

<div class="info" style="margin-bottom:14px"><x-ikon nama="info" :ukuran="18" /><span>Sistem mengisi ruang satu per satu dengan menjaga kelas tetap utuh bila bisa. Ruang biasa dapat memuat tiga kelas atau lebih dari tingkat berbeda; kursi tersisa diisi kelas lain yang muat, dan siswa yang belum muat pindah ke ruang berikutnya. Ruang bertanda "Campur tingkat" tetap dibatasi 50% per tingkat. Lihat rencananya dulu sebelum diterapkan.</span></div>

<form class="kartu" method="POST" action="{{ route('staf.ruang.otomatis.proses') }}">
    @csrf
    <h2 class="judul-kartu">Kelas yang diisikan</h2>
    @error('kelas_ids') <div class="pesan-salah" role="alert"><x-ikon nama="peringatan" :ukuran="18" /><span>{{ $message }}</span></div> @enderror
    <fieldset class="opsi-blok">
        @foreach ($kelas->groupBy('tingkat') as $tingkat => $daftar)
            <div class="baris-bank" style="display:block">
                <strong>Tingkat {{ $tingkat }}</strong>
                @foreach ($daftar as $k)
                    <label class="centang" style="display:inline-flex;margin-right:14px">
                        <input type="checkbox" name="kelas_ids[]" value="{{ $k->id }}" @checked(in_array($k->id, $dipilih, true))>
                        {{ $k->nama }} <small class="bantuan" style="margin:0">({{ $k->total }} siswa{{ $k->belum !== $k->total ? ', '.$k->belum.' belum punya ruang' : '' }})</small>
                    </label>
                @endforeach
            </div>
        @endforeach
        @if ($kelas->isEmpty()) <small class="bantuan">Belum ada kelas dengan siswa aktif pada tahun ajaran ini.</small> @endif
    </fieldset>

    <div class="bidang" style="margin-top:12px">
        <label class="centang"><input type="checkbox" name="pecah" value="1" @checked($pecah)> Boleh memecah satu kelas ke dua ruang bila kursi tersisa tidak muat kelas utuh</label>
        <label class="centang"><input type="checkbox" name="ulang" value="1" @checked($ulang)> Atur ulang dari awal: kosongkan penempatan sekarang lebih dulu</label>
        <small class="bantuan">Bila "atur ulang" tidak dicentang, hanya siswa yang belum punya ruang yang ditempatkan, mengisi kursi yang masih kosong. Penempatan yang sudah ada tidak berubah.</small>
    </div>

    <div class="aksi-form">
        <button class="tombol kecil garis" type="submit" name="aksi" value="pratinjau">Lihat rencana</button>
        @if ($rencana && $rencana['ditempatkan'] > 0)
            <button class="tombol kecil" type="submit" name="aksi" value="terapkan"
                    @if ($ulang) onclick="return confirm('Penempatan siswa sekarang akan dikosongkan lalu diatur ulang. Lanjutkan?')" @endif>Terapkan rencana</button>
        @endif
    </div>
</form>

@if ($rencana)
    <div class="db-panel" style="margin-top:16px">
        <header>
            <h3>Rencana pembagian</h3>
            <small>{{ number_format($rencana['ditempatkan'], 0, ',', '.') }} siswa ditempatkan @if ($rencana['sisa_total'] > 0) · {{ number_format($rencana['sisa_total'], 0, ',', '.') }} belum muat @endif</small>
        </header>
        <div class="gulir">
            <table class="tabel tabel-daftar tabel-baris">
                <thead><tr><th>Ruang</th><th>Jenis</th><th>Terisi</th><th>Kelas yang masuk</th></tr></thead>
                <tbody>
                    @foreach ($rencana['ruang'] as $p)
                        <tr>
                            <td><strong>{{ $p['ruang']->nama }}</strong></td>
                            <td>{{ $p['ruang']->campur ? 'Campur tingkat' : 'Biasa' }}</td>
                            <td>{{ $p['terisi'] }} / {{ $p['kapasitas'] }}@if ($p['awal'] > 0)<small class="bantuan" style="margin:0">{{ $p['awal'] }} sudah ada</small>@endif</td>
                            <td>
                                @forelse ($p['tambah'] as $g)
                                    <span class="lencana{{ $g['utuh'] ? '' : ' pucat' }}">{{ $g['nama'] }} · {{ $g['jumlah'] }}{{ $g['utuh'] ? '' : ' (sebagian)' }}</span>
                                @empty
                                    —
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($rencana['sisa_total'] > 0)
        <div class="pesan-salah" role="status" style="margin-top:12px"><x-ikon nama="peringatan" :ukuran="18" /><span>{{ number_format($rencana['sisa_total'], 0, ',', '.') }} siswa belum mendapat tempat karena kursi tidak cukup:
            @foreach ($rencana['sisa'] as $s){{ $s['nama'] }} ({{ $s['jumlah'] }}){{ $loop->last ? '' : ', ' }}@endforeach.
            Tambah ruang atau kapasitasnya, lalu jalankan lagi.</span></div>
    @endif
    @if ($rencana['ditempatkan'] === 0)
        <div class="info" style="margin-top:12px"><x-ikon nama="info" :ukuran="18" /><span>Tidak ada yang bisa ditempatkan dengan pilihan ini: semua siswa pada kelas terpilih sudah punya ruang, atau tidak ada kursi tersisa.</span></div>
    @endif
@endif
@endsection
