@extends('layouts.staf')
@section('judul', $soal ? 'Ubah Soal' : 'Tambah Soal')

@php
    $pg = $tipe === 'pg';
    $jumlahOpsi = (int) old('jumlah_opsi', $soal ? max(4, $soal->opsi->count()) : $bank->jumlah_opsi_bawaan);
    $kunciLama = old('kunci', $soal?->kunci());
@endphp

@section('konten')
<div class="pita-info pita-form">
    <div class="pita-atas">
        <a class="tombol-pita" href="{{ route('staf.bank-soal.show', $bank) }}"><x-ikon nama="panah-kiri" :ukuran="16" />Kembali</a>
    </div>
    <h1>{{ $soal ? 'Ubah' : 'Tambah' }} soal {{ $pg ? 'pilihan ganda' : 'esai' }}</h1>
    <p>{{ $bank->nama }}</p>
</div>

<form class="kartu formulir" method="POST" enctype="multipart/form-data"
      action="{{ $soal ? route('staf.soal.update', [$bank, $soal]) : route('staf.soal.store', $bank) }}">
    @csrf
    @if ($soal) @method('PUT') @endif
    <input type="hidden" name="tipe" value="{{ $tipe }}">

    <div class="bidang @error('pertanyaan') salah @enderror">
        <label for="pertanyaan">Pertanyaan <span class="wajib">*</span></label>
        <textarea id="pertanyaan" name="pertanyaan" rows="5" maxlength="5000" required>{{ old('pertanyaan', $soal?->pertanyaan) }}</textarea>
        @error('pertanyaan') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    <div class="bidang @error('gambar') salah @enderror">
        <label for="gambar">Gambar (opsional)</label>
        @if ($soal?->gambar)
            <img class="soal-gambar kecil" src="{{ route('staf.soal.gambar', $soal) }}" alt="Gambar soal saat ini">
            <label class="centang"><input type="checkbox" name="hapus_gambar" value="1"> Hapus gambar ini</label>
        @endif
        <input id="gambar" name="gambar" type="file" accept=".jpg,.jpeg,.png,.webp">
        <small class="bantuan">JPG, PNG, atau WEBP, maksimal 2 MB. Mengunggah gambar baru menggantikan yang lama.</small>
        @error('gambar') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    @if ($pg)
        <div class="bidang">
            <label for="jumlah_opsi">Jumlah opsi</label>
            <select id="jumlah_opsi" name="jumlah_opsi">
                <option value="4" @selected($jumlahOpsi === 4)>4 opsi (A–D)</option>
                <option value="5" @selected($jumlahOpsi === 5)>5 opsi (A–E)</option>
            </select>
        </div>

        <fieldset class="opsi-blok @error('kunci') salah @enderror">
            <legend>Opsi jawaban — pilih satu sebagai kunci <span class="wajib">*</span></legend>
            @foreach (['A', 'B', 'C', 'D', 'E'] as $h)
                <div class="opsi-baris {{ $h === 'E' ? 'opsi-e' : '' }}" @if ($h === 'E' && $jumlahOpsi < 5) hidden @endif>
                    <label class="kunci-pilih" title="Jadikan kunci">
                        <input type="radio" name="kunci" value="{{ $h }}" @checked($kunciLama === $h)>
                        <span>{{ $h }}</span>
                    </label>
                    <input type="text" name="opsi[{{ $h }}]" maxlength="1000" aria-label="Opsi {{ $h }}"
                           value="{{ old("opsi.$h", $soal?->opsi->firstWhere('label', $h)?->teks) }}"
                           @if ($h === 'E') data-opsi-e @endif>
                </div>
                @error("opsi.$h") <small class="galat-bidang">{{ $message }}</small> @enderror
            @endforeach
            @error('kunci') <small class="galat-bidang">{{ $message }}</small> @enderror
        </fieldset>
    @else
        <div class="bidang @error('rubrik_esai') salah @enderror">
            <label for="rubrik_esai">Jawaban acuan (opsional)</label>
            <textarea id="rubrik_esai" name="rubrik_esai" rows="4" maxlength="5000">{{ old('rubrik_esai', $soal?->rubrik_esai) }}</textarea>
            <small class="bantuan">Membantu guru saat mengoreksi. Tidak ditampilkan kepada siswa.</small>
            @error('rubrik_esai') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>
    @endif

    <div class="bidang @error('bobot') salah @enderror">
        <label for="bobot">Bobot <span class="wajib">*</span></label>
        <input id="bobot" name="bobot" type="number" step="0.01" min="0.01" max="100" required
               value="{{ old('bobot', $soal ? rtrim(rtrim($soal->bobot, '0'), '.') : 1) }}">
        @error('bobot') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    <div class="bidang">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="siap" @selected(old('status', $soal?->status ?? 'siap') === 'siap')>Siap dipakai</option>
            <option value="draf" @selected(old('status', $soal?->status ?? 'siap') === 'draf')>Draf (belum bisa masuk ujian)</option>
        </select>
    </div>

    <div class="aksi-form">
        <button class="tombol kecil" type="submit">Simpan</button>
        <a class="tombol kecil garis" href="{{ route('staf.bank-soal.show', $bank) }}">Batal</a>
    </div>
</form>

@if ($pg)
<script>
    (function () {
        var pilih = document.getElementById('jumlah_opsi');
        var barisE = document.querySelector('.opsi-e');
        var isiE = document.querySelector('[data-opsi-e]');
        function atur() {
            var lima = pilih.value === '5';
            barisE.hidden = !lima;
            isiE.required = lima;
            if (!lima) { isiE.value = ''; var r = barisE.querySelector('input[type=radio]'); if (r.checked) r.checked = false; }
        }
        pilih.addEventListener('change', atur);
        atur();
        document.querySelectorAll('.opsi-baris:not(.opsi-e) input[type=text]').forEach(function (e) { e.required = true; });
    })();
</script>
@endif
@endsection
