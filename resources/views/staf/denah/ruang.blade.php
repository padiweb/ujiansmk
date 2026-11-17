@extends('layouts.staf')
@section('judul', 'Ruang: '.$ruang->nama)

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">{{ $ruang->nama }}</h1>
        <p class="sub">{{ $baris->count() }} dari {{ $dr->kapasitas() }} siswa · {{ $ruang->campur ? 'Campur tingkat' : 'Biasa' }}</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil garis" href="{{ route('staf.ruang.index') }}"><x-ikon nama="panah-kiri" :ukuran="16" />Daftar</a>
        <a class="tombol kecil garis" href="{{ route('staf.ruang.absen', $ruang) }}"><x-ikon nama="unduh" :ukuran="16" />Absen</a>
        <a class="tombol kecil" href="{{ route('staf.ruang.no-meja', $ruang) }}" target="_blank" rel="noopener">Cetak no. meja</a>
    </div>
</div>

<div class="kartu">
    <h2 class="judul-kartu">Kelas di ruang ini</h2>
    @forelse ($komposisi as $kelas => $n)
        <span class="lencana">{{ $kelas }} · {{ $n }}</span>
        @if ($idKelasRuang->search($kelas) !== false)
            <form method="POST" action="{{ route('staf.ruang.kelas.keluar', [$ruang, $idKelasRuang->search($kelas)]) }}" style="display:inline" onsubmit="return confirm('Keluarkan semua siswa kelas {{ e($kelas) }} dari ruang ini?')">
                @csrf @method('DELETE')
                <button class="meja-lepas" type="submit" title="Keluarkan kelas {{ $kelas }}" aria-label="Keluarkan kelas {{ $kelas }}"><x-ikon nama="tutup" :ukuran="12" /></button>
            </form>
        @endif
    @empty <span class="sub">Belum ada siswa di ruang ini.</span> @endforelse
    @if ($ruang->campur)
        <p class="sub" style="margin:8px 0 0">Maksimal {{ $ruang->porsiTingkat($dr->kapasitas()) }} siswa per tingkat.
            @foreach ($tingkatIsi as $t => $n) Tingkat {{ $t }}: {{ $n }}@if (! $loop->last), @endif @endforeach</p>
    @endif
</div>

<form class="kartu" method="POST" action="{{ route('staf.ruang.kelas', $ruang) }}">
    @csrf
    <h2 class="judul-kartu">Masukkan kelas</h2>
    @if ($kelasBelum->isEmpty())
        <p class="sub" style="margin:0">Semua siswa aktif sudah punya ruang.</p>
    @else
        <fieldset class="opsi-blok">
            <legend>Kelas yang masih punya siswa tanpa ruang</legend>
            @foreach ($kelasBelum->groupBy('tingkat') as $tingkat => $kelasTingkat)
                <div class="baris-bank" style="display:block">
                    <strong>Tingkat {{ $tingkat }}</strong>
                    @foreach ($kelasTingkat as $k)
                        <label class="centang" style="display:inline-flex;margin-right:14px"><input type="checkbox" name="kelas_ids[]" value="{{ $k->id }}"> {{ $k->nama }} <small class="bantuan" style="margin:0">({{ $k->belum }} siswa)</small></label>
                    @endforeach
                </div>
            @endforeach
            @error('kelas_ids') <small class="galat-bidang">{{ $message }}</small> @enderror
        </fieldset>
        <div class="aksi-form"><button class="tombol kecil" type="submit">Masukkan</button></div>
    @endif
</form>

<div class="aksi-form" style="margin:0 0 12px;align-items:center">
    <form method="POST" action="{{ route('staf.ruang.urut-kelas', $ruang) }}">@csrf <button class="tombol kecil garis" type="submit">Urutkan per kelas</button></form>
    <span class="sub">Seret baris untuk mengatur nomor meja. <span id="drPesan" aria-live="polite"></span></span>
</div>

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel padat-denah-ruang" id="drTabel" data-url="{{ route('staf.ruang.urutan', $ruang) }}" data-token="{{ csrf_token() }}" data-per="{{ max(1, (int) $dr->siswa_per_meja) }}">
            <thead><tr><th style="width:70px">No Meja</th><th style="width:36px"></th><th>Nama Siswa</th><th>Kelas</th><th>Tingkat</th><th>Pindah ke ruang lain</th></tr></thead>
            <tbody id="drIsi">
                @forelse ($baris as $p)
                    <tr data-id="{{ $p->siswa_id }}" draggable="true">
                        <td class="dr-meja"><strong>{{ $p->meja }}</strong></td>
                        <td class="dr-pegang" title="Seret" aria-hidden="true">⠿</td>
                        <td><strong>{{ $p->siswa->nama }}</strong><small class="bantuan" style="margin:0">{{ $p->siswa->nisn }}</small></td>
                        <td>{{ $kelasSiswa[$p->siswa_id]->nama ?? '—' }}</td>
                        <td>{{ $kelasSiswa[$p->siswa_id]->tingkat ?? '—' }}</td>
                        <td>
                            <form method="POST" action="{{ route('staf.ruang.pindah', $p->siswa) }}" class="aksi-form" style="margin:0;flex-wrap:nowrap">
                                @csrf
                                <select name="ruang_id" aria-label="Pindahkan {{ $p->siswa->nama }}" required>
                                    <option value="">Pilih ruang…</option>
                                    @foreach ($ruangLain as $x) <option value="{{ $x->id }}">{{ $x->nama }}</option> @endforeach
                                    <option value="keluar">Keluarkan</option>
                                </select>
                                <button class="tombol kecil garis" type="submit">Pindah</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="kosong">Ruang ini kosong.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('skrip')
<script src="{{ asset('js/denah.js') }}?v={{ filemtime(public_path('js/denah.js')) }}" defer></script>
@endpush
