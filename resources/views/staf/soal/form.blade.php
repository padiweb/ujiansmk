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

    @include('staf.partials.alat-rumus')

    <div class="bidang @error('bacaan_id') salah @enderror">
        <label for="bacaan_id">Bacaan / stimulus (opsional)</label>
        <select id="bacaan_id" name="bacaan_id">
            <option value="">Tanpa bacaan</option>
            @foreach ($daftarBacaan as $b)
                <option value="{{ $b->id }}" @selected((int) old('bacaan_id', $bacaanAwal) === $b->id)>{{ $b->label() }}</option>
            @endforeach
        </select>
        <small class="bantuan">Satu bacaan bisa dipakai beberapa soal. <a href="{{ route('staf.bacaan.create', $bank) }}">Tambah bacaan baru</a> (simpan soal ini dulu bila sudah terisi).</small>
        @error('bacaan_id') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    <div class="bidang @error('pertanyaan') salah @enderror">
        <label for="pertanyaan">Pertanyaan <span class="wajib">*</span></label>
        <textarea id="pertanyaan" data-rumus name="pertanyaan" rows="5" maxlength="5000" required>{{ old('pertanyaan', $soal?->pertanyaan) }}</textarea>
        @error('pertanyaan') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    <div class="bidang @error('gambar') salah @enderror">
        <label for="gambar">Gambar (opsional)</label>
        @if ($soal?->gambar)
            <img class="soal-gambar kecil" src="{{ route('staf.soal.gambar', $soal) }}" alt="Gambar soal saat ini">
            <label class="centang"><input type="checkbox" name="hapus_gambar" value="1"> Hapus gambar ini</label>
        @endif
        <input id="gambar" name="gambar" type="file" accept=".jpg,.jpeg,.png,.webp" data-unggah>
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
                    @php $opsiLama = $soal?->opsi->firstWhere('label', $h); @endphp
                    <div class="opsi-isi">
                        <input type="text" data-rumus name="opsi[{{ $h }}]" maxlength="1000" aria-label="Opsi {{ $h }}" placeholder="Teks opsi (boleh kosong bila memakai gambar)"
                               value="{{ old("opsi.$h", $opsiLama?->teks) }}"
                               @if ($h === 'E') data-opsi-e @endif>
                        <div class="opsi-gambar">
                            @if ($opsiLama?->gambar)
                                <img src="{{ route('staf.soal.gambar-opsi', $opsiLama) }}" alt="Gambar opsi {{ $h }}" class="opsi-mini">
                                <label class="centang"><input type="checkbox" name="hapus_gambar_opsi[{{ $h }}]" value="1"> Hapus gambar</label>
                            @endif
                            <input type="file" name="gambar_opsi[{{ $h }}]" accept=".jpg,.jpeg,.png,.webp" data-unggah aria-label="Gambar opsi {{ $h }}">
                        </div>
                    </div>
                </div>
                @error("opsi.$h") <small class="galat-bidang">{{ $message }}</small> @enderror
            @endforeach
            @error('kunci') <small class="galat-bidang">{{ $message }}</small> @enderror
        </fieldset>
    @else
        <div class="bidang @error('rubrik_esai') salah @enderror">
            <label for="rubrik_esai">Jawaban acuan (opsional)</label>
            <textarea id="rubrik_esai" data-rumus name="rubrik_esai" rows="4" maxlength="5000">{{ old('rubrik_esai', $soal?->rubrik_esai) }}</textarea>
            <small class="bantuan">Membantu guru saat mengoreksi. Tidak ditampilkan kepada siswa.</small>
            @error('rubrik_esai') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>
    @endif

    <div class="bidang @error('level') salah @enderror">
        <label for="level">Level kognitif (opsional)</label>
        <select id="level" name="level">
            <option value="">Belum ditentukan</option>
            @foreach (\App\Models\Soal::LEVEL as $k => $l)
                <option value="{{ $k }}" @selected(old('level', $soal?->level) === $k)>{{ $l }}</option>
            @endforeach
        </select>
        @error('level') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    <div class="bidang @error('pembahasan') salah @enderror">
        <label for="pembahasan">Pembahasan / keterangan (opsional)</label>
        <textarea id="pembahasan" data-rumus name="pembahasan" rows="3" maxlength="3000">{{ old('pembahasan', $soal?->pembahasan) }}</textarea>
        <small class="bantuan">Catatan Anda sendiri, misalnya alasan jawaban benar. Tidak ditampilkan kepada siswa.</small>
        @error('pembahasan') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

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

@push('skrip')
<script src="{{ asset('js/unggah-gambar.js') }}?v={{ filemtime(public_path('js/unggah-gambar.js')) }}" defer></script>
@endpush
@endsection
