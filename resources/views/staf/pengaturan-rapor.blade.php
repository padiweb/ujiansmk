@extends('layouts.staf')
@section('judul', 'Pengaturan Rapor')

@php
    $kelompok = [
        'Pengetahuan (NP)' => ['ujian', 'harian', 'tugas_akhir'],
        'Keterampilan (NK)' => ['praktik', 'adab'],
    ];
    $label = \App\Services\LayananRapor::LABEL;
@endphp

@section('konten')
<div class="pita-info pita-form">
    <h1>Pengaturan Rapor</h1>
    <p>Bobot nilai, predikat, dan rentang semester.</p>
</div>

<form class="kartu formulir" method="POST" action="{{ route('staf.rapor.bobot') }}" id="formBobot" style="max-width:760px">
    @csrf
    @method('PUT')
    <h2 class="judul-kartu">Bobot nilai (%)</h2>
    @error('bobot') @foreach ($errors->get('bobot') as $m) <div class="pesan-salah" role="alert">{{ $m }}</div> @endforeach @enderror

    @foreach ($kelompok as $judul => $kunci)
        <fieldset class="opsi-blok" data-kelompok>
            <legend>{{ $judul }} — total <strong data-total>0</strong></legend>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px">
                @foreach ($kunci as $k)
                    <div class="bidang @error($k) salah @enderror" style="margin:0">
                        <label for="b-{{ $k }}">{{ $label[$k] }}</label>
                        <input id="b-{{ $k }}" name="{{ $k }}" type="number" min="0" max="100" value="{{ old($k, $bobot[$k]) }}" required>
                    </div>
                @endforeach
            </div>
        </fieldset>
    @endforeach

    <fieldset class="opsi-blok" data-kelompok>
        <legend>Nilai akhir (NA) — total <strong data-total>0</strong></legend>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px">
            @foreach (['np' => 'Pengetahuan (NP)', 'nk' => 'Keterampilan (NK)'] as $k => $l)
                <div class="bidang" style="margin:0">
                    <label for="b-{{ $k }}">{{ $l }}</label>
                    <input id="b-{{ $k }}" name="{{ $k }}" type="number" min="0" max="100" value="{{ old($k, $bobot[$k]) }}" required>
                </div>
            @endforeach
        </div>
    </fieldset>

    <div class="aksi-form"><button class="tombol kecil" type="submit">Simpan bobot</button></div>
</form>

<form class="kartu formulir" method="POST" action="{{ route('staf.rapor.skala') }}" style="max-width:760px">
    @csrf
    @method('PUT')
    <h2 class="judul-kartu">Skala predikat</h2>
    @error('skala') @foreach ($errors->get('skala') as $m) <div class="pesan-salah" role="alert">{{ $m }}</div> @endforeach @enderror
    <table class="tabel" style="margin-top:8px">
        <thead><tr><th>Batas bawah (≥)</th><th>Predikat</th><th>Keterangan</th></tr></thead>
        <tbody>
            @php $baris = old('skala') ?: $skala->map(fn ($s) => ['nilai_min' => $s->nilai_min, 'predikat' => $s->predikat, 'keterangan' => $s->keterangan])->all(); @endphp
            @foreach (array_merge(array_values($baris), [[], [], []]) as $i => $s)
                <tr>
                    <td><input type="number" min="0" max="100" name="skala[{{ $i }}][nilai_min]" value="{{ $s['nilai_min'] ?? '' }}" style="width:110px" aria-label="Batas bawah"></td>
                    <td><input type="text" maxlength="10" name="skala[{{ $i }}][predikat]" value="{{ $s['predikat'] ?? '' }}" style="width:110px" aria-label="Predikat"></td>
                    <td><input type="text" maxlength="100" name="skala[{{ $i }}][keterangan]" value="{{ $s['keterangan'] ?? '' }}" aria-label="Keterangan"></td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="aksi-form"><button class="tombol kecil" type="submit">Simpan skala</button></div>
</form>

<form class="kartu formulir" method="POST" action="{{ route('staf.rapor.periode') }}" style="max-width:760px">
    @csrf
    @method('PUT')
    <h2 class="judul-kartu">Semester</h2>
    @error('periode') <div class="pesan-salah" role="alert">{{ $message }}</div> @enderror
    @foreach ($errors->get('periode.*') as $m) @foreach ($m as $x) <div class="pesan-salah" role="alert">{{ $x }}</div> @endforeach @endforeach
    <table class="tabel" style="margin-top:8px">
        <thead><tr><th>Semester</th><th>Mulai</th><th>Selesai</th><th>Rapor di sini</th><th>Tampilkan ke siswa</th></tr></thead>
        <tbody>
            @forelse ($periode as $p)
                <tr>
                    <td>{{ $p->label() }}@if ($p->aktif) <span class="lencana">Aktif</span> @endif</td>
                    @php $dariEduweb = $sinkron && $p->tipe === 'reguler'; @endphp
                    <td><input type="date" name="periode[{{ $p->id }}][mulai]" value="{{ old("periode.{$p->id}.mulai", $p->mulai?->format('Y-m-d')) }}" aria-label="Mulai {{ $p->label() }}" @readonly($dariEduweb)></td>
                    <td><input type="date" name="periode[{{ $p->id }}][selesai]" value="{{ old("periode.{$p->id}.selesai", $p->selesai?->format('Y-m-d')) }}" aria-label="Selesai {{ $p->label() }}" @readonly($dariEduweb)></td>
                    <td class="tengah"><input type="hidden" name="periode[{{ $p->id }}][rapor_di_sini]" value="0"><input type="checkbox" name="periode[{{ $p->id }}][rapor_di_sini]" value="1" @checked(old("periode.{$p->id}.rapor_di_sini", $p->rapor_di_sini)) aria-label="Rapor {{ $p->label() }} diisi di aplikasi ini"></td>
                    <td class="tengah"><input type="hidden" name="periode[{{ $p->id }}][tampil_siswa]" value="0"><input type="checkbox" name="periode[{{ $p->id }}][tampil_siswa]" value="1" @checked(old("periode.{$p->id}.tampil_siswa", $p->tampil_siswa)) aria-label="Rapor {{ $p->label() }} terlihat oleh siswa"></td>
                </tr>
            @empty
                <tr><td colspan="5" class="kosong">Belum ada semester.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="aksi-form"><button class="tombol kecil" type="submit" @disabled($periode->isEmpty())>Simpan semester</button></div>
</form>

<form class="kartu formulir" method="POST" action="{{ route('staf.rapor.periode-us') }}" style="max-width:760px;margin-top:16px">
    @csrf
    <h2 class="judul-kartu">Semester Ujian Sekolah (kelas XII)</h2>
    @php $us = $periode->where('tipe', 'ujian_sekolah'); @endphp
    @if ($us->isNotEmpty())
        <ul class="petunjuk" style="margin:8px 0">
            @foreach ($us as $p) <li>{{ $p->label() }}</li> @endforeach
        </ul>
    @endif
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-top:8px">
        <div class="bidang" style="margin:0;min-width:180px">
            <label for="us-tahun">Tahun ajaran</label>
            <select id="us-tahun" name="tahun_ajaran_id" required>
                @foreach ($tahunAjaran as $t) <option value="{{ $t->id }}">{{ $t->nama }}</option> @endforeach
            </select>
        </div>
        <div class="bidang" style="margin:0;min-width:140px">
            <label for="us-sem">Semester</label>
            <select id="us-sem" name="semester" required><option value="genap">Genap</option><option value="ganjil">Ganjil</option></select>
        </div>
        <button class="tombol kecil" type="submit" @disabled($tahunAjaran->isEmpty())>Tambah semester US</button>
    </div>
</form>

<div class="kartu" style="max-width:760px;margin-top:16px">
    <h2 class="judul-kartu">Akses EduWeb ke status rapor</h2>
    @if (session('kunci_api_baru'))
        <div class="pesan-salah catatan" role="status"><x-ikon nama="info" :ukuran="18" /><span>Kunci baru: <code>{{ session('kunci_api_baru') }}</code></span></div>
    @endif
    <table class="tabel" style="margin-top:8px">
        <thead><tr><th>Nama</th><th>Kunci</th><th>Terakhir dipakai</th><th>Status</th><th class="kanan">Aksi</th></tr></thead>
        <tbody>
            @forelse ($klienApi as $k)
                <tr>
                    <td>{{ $k->nama }}</td>
                    <td><code>{{ $k->awalan }}…</code></td>
                    <td>{{ $k->terakhir_dipakai ? $k->terakhir_dipakai->diffForHumans().' dari '.$k->ip_terakhir : 'belum pernah' }}</td>
                    <td>@if ($k->aktif()) <span class="lencana">Aktif</span> @else <span class="lencana pucat">Dicabut</span> @endif</td>
                    <td class="kanan aksi-baris">
                        @if ($k->aktif())
                            <form method="POST" action="{{ route('staf.rapor.klien-cabut', $k) }}" onsubmit="return confirm('Cabut kunci ini? Aplikasi yang memakainya langsung kehilangan akses.')" style="display:inline">
                                @csrf <button type="submit" class="tombol kecil garis">Cabut</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="kosong">Belum ada kunci.</td></tr>
            @endforelse
        </tbody>
    </table>
    <form method="POST" action="{{ route('staf.rapor.klien-baru') }}" class="aksi-form" style="margin-top:10px">
        @csrf
        <input type="text" name="nama" required maxlength="80" placeholder="Nama (mis. EduWeb)" aria-label="Nama kunci" style="max-width:260px">
        <button class="tombol kecil" type="submit">Buat kunci</button>
    </form>
    @error('nama') <div class="pesan-salah" role="alert">{{ $message }}</div> @enderror
</div>
@endsection

@push('skrip')
<script>
(function () {
    // Menampilkan total bobot tiap kelompok saat diketik (hanya bantuan; server tetap memeriksa).
    document.querySelectorAll('#formBobot [data-kelompok]').forEach(function (g) {
        var total = g.querySelector('[data-total]');
        function hitung() {
            var n = 0;
            g.querySelectorAll('input[type=number]').forEach(function (i) { n += parseInt(i.value, 10) || 0; });
            total.textContent = n;
        }
        g.addEventListener('input', hitung);
        hitung();
    });
})();
</script>
@endpush
