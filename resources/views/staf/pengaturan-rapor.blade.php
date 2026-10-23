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
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Pengaturan Rapor</h1>
        <p class="sub">Bobot nilai, predikat, dan rentang semester.</p>
    </div>
</div>

<form class="kartu formulir" method="POST" action="{{ route('staf.rapor.bobot') }}" id="formBobot" style="max-width:760px">
    @csrf
    @method('PUT')
    <h2 class="judul-kartu">Bobot nilai (%)</h2>
    <small class="bantuan">Komponen berbobot 0 tidak dipakai. Harian diisi dari rata-rata nilai tugas dan kuis di EduWeb.</small>
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
    <small class="bantuan">Nilai akhir mendapat predikat dengan batas bawah tertinggi yang terlampaui. Kosongkan baris untuk menghapusnya.</small>
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
    <small class="bantuan">Rentang tanggal dipakai untuk menarik absensi dan nilai tugas dari EduWeb. Hilangkan centang "Rapor di sini" bila rapor semester itu tidak diisi di aplikasi ini (mis. ujian kembali ke kertas).</small>
    @error('periode') <div class="pesan-salah" role="alert">{{ $message }}</div> @enderror
    @foreach ($errors->get('periode.*') as $m) @foreach ($m as $x) <div class="pesan-salah" role="alert">{{ $x }}</div> @endforeach @endforeach
    <table class="tabel" style="margin-top:8px">
        <thead><tr><th>Semester</th><th>Mulai</th><th>Selesai</th><th>Rapor di sini</th></tr></thead>
        <tbody>
            @forelse ($periode as $p)
                <tr>
                    <td>{{ $p->label() }}@if ($p->aktif) <span class="lencana">Aktif</span> @endif</td>
                    <td><input type="date" name="periode[{{ $p->id }}][mulai]" value="{{ old("periode.{$p->id}.mulai", $p->mulai?->format('Y-m-d')) }}" aria-label="Mulai {{ $p->label() }}"></td>
                    <td><input type="date" name="periode[{{ $p->id }}][selesai]" value="{{ old("periode.{$p->id}.selesai", $p->selesai?->format('Y-m-d')) }}" aria-label="Selesai {{ $p->label() }}"></td>
                    <td class="tengah"><input type="hidden" name="periode[{{ $p->id }}][rapor_di_sini]" value="0"><input type="checkbox" name="periode[{{ $p->id }}][rapor_di_sini]" value="1" @checked(old("periode.{$p->id}.rapor_di_sini", $p->rapor_di_sini)) aria-label="Rapor {{ $p->label() }} diisi di aplikasi ini"></td>
                </tr>
            @empty
                <tr><td colspan="4" class="kosong">Belum ada semester.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="aksi-form"><button class="tombol kecil" type="submit" @disabled($periode->isEmpty())>Simpan semester</button></div>
</form>
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
