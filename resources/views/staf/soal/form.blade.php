@extends('layouts.staf')
@section('judul', ($soal ? 'Edit ' : 'Tambah ').($tipe === 'pg' ? 'Soal Pilihan Ganda' : 'Soal Esai'))

@php
    $pg = $tipe === 'pg';
    $jumlahOpsi = (int) old('jumlah_opsi', $soal ? max(4, $soal->opsi->count()) : $bank->jumlah_opsi_bawaan);
    $kunciLama = old('kunci', $soal?->kunci());
    $judulForm = ($soal ? 'Edit ' : 'Tambah ').($pg ? 'Soal Pilihan Ganda' : 'Soal Esai');
@endphp

@section('konten')
<div class="remah">
    <a href="{{ route('staf.bank-soal.index') }}">Bank Soal</a><span>›</span>
    <a href="{{ route('staf.bank-soal.show', $bank) }}">{{ $bank->mapel->nama }} — Kelas {{ $bank->tingkat }}</a><span>›</span><span>{{ $judulForm }}</span>
</div>

<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman judul-kecil">{{ $judulForm }}</h1>
        <a class="tautan-kembali" href="{{ route('staf.bank-soal.show', $bank) }}#{{ $pg ? 'pg' : 'esai' }}">← Kembali ke Editor Soal</a>
    </div>
</div>

<form class="kartu form-soal" method="POST" enctype="multipart/form-data"
      action="{{ $soal ? route('staf.soal.update', [$bank, $soal]) : route('staf.soal.store', $bank) }}">
    @csrf
    @if ($soal) @method('PUT') @endif
    <input type="hidden" name="tipe" value="{{ $tipe }}">

    @include('staf.partials.alat-rumus')

    <div class="form-dua">
    <div class="kolom">
    <div class="bidang @error('pertanyaan') salah @enderror">
        <label for="pertanyaan">Pertanyaan <span class="wajib">*</span></label>
        <textarea id="pertanyaan" data-rumus name="pertanyaan" rows="5" maxlength="5000" required placeholder="Tulis pertanyaan di sini...">{{ old('pertanyaan', $soal?->pertanyaan) }}</textarea>
        @error('pertanyaan') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    <div class="bidang @error('gambar') salah @enderror">
        <label>Gambar Soal (Opsional)</label>
        @if ($soal?->gambar)
            <div class="gambar-sekarang">
                <img class="soal-gambar kecil" src="{{ route('staf.soal.gambar', $soal) }}" alt="Gambar soal saat ini">
                <label class="centang"><input type="checkbox" name="hapus_gambar" value="1"> Hapus gambar ini</label>
            </div>
        @endif
        <label class="zona-unggah" id="zonaGambar">
            <input id="gambar" name="gambar" type="file" accept=".jpg,.jpeg,.png,.webp" data-unggah data-zona>
            <span class="zona-isi"><x-ikon nama="unggah" :ukuran="22" /><span>Klik atau seret gambar ke sini</span><small>JPG, PNG, WEBP · Maks. 2 MB · atau tempel dengan Ctrl+V</small></span>
            <span class="zona-pratinjau" hidden><img alt="Pratinjau"><span class="zona-nama"></span><button type="button" class="zona-hapus" aria-label="Buang gambar">✕</button></span>
        </label>
        @error('gambar') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    </div>
    <div class="kolom">
    @if ($pg)
        <div class="bidang">
            <label>Pilihan Jawaban</label>
            <div class="opsi-form-grid">
                @foreach (['A', 'B', 'C', 'D', 'E'] as $h)
                    @php $opsiLama = $soal?->opsi->firstWhere('label', $h); @endphp
                    <div class="opsi-form opsi-baris {{ $h === 'E' ? 'opsi-e' : '' }}" @if ($h === 'E' && $jumlahOpsi < 5) hidden @endif>
                        <div class="opsi-form-label"><span class="opsi-lencana ok-{{ strtolower($h) }}">{{ $h }}</span>Opsi {{ $h }}</div>
                        <input type="text" data-rumus name="opsi[{{ $h }}]" maxlength="1000" aria-label="Opsi {{ $h }}" placeholder="Isi opsi {{ $h }}..."
                               value="{{ old("opsi.$h", $opsiLama?->teks) }}" @if ($h === 'E') data-opsi-e @endif>
                        <div class="opsi-gambar">
                            @if ($opsiLama?->gambar)
                                <img src="{{ route('staf.soal.gambar-opsi', $opsiLama) }}" alt="Gambar opsi {{ $h }}" class="opsi-mini">
                                <label class="centang"><input type="checkbox" name="hapus_gambar_opsi[{{ $h }}]" value="1"> Hapus gambar</label>
                            @endif
                            <label class="tombol kecil garis tombol-berkas"><x-ikon nama="unggah" :ukuran="13" />Gambar<input type="file" name="gambar_opsi[{{ $h }}]" accept=".jpg,.jpeg,.png,.webp" data-unggah aria-label="Gambar opsi {{ $h }}"></label>
                        </div>
                        @error("opsi.$h") <small class="galat-bidang">{{ $message }}</small> @enderror
                    </div>
                @endforeach
            </div>
        </div>

        <div class="baris-dua">
            <div class="bidang @error('kunci') salah @enderror">
                <label for="kunci">Kunci Jawaban <span class="wajib">*</span></label>
                <select id="kunci" name="kunci" required>
                    <option value="">— Pilih jawaban benar —</option>
                    @foreach (['A', 'B', 'C', 'D', 'E'] as $h)
                        <option value="{{ $h }}" data-opsi="{{ $h }}" @selected($kunciLama === $h) @if ($h === 'E' && $jumlahOpsi < 5) hidden @endif>{{ $h }}</option>
                    @endforeach
                </select>
                @error('kunci') <small class="galat-bidang">{{ $message }}</small> @enderror
            </div>
            <div class="bidang">
                <label for="jumlah_opsi">Jumlah opsi</label>
                <select id="jumlah_opsi" name="jumlah_opsi">
                    <option value="4" @selected($jumlahOpsi === 4)>4 opsi (A–D)</option>
                    <option value="5" @selected($jumlahOpsi === 5)>5 opsi (A–E)</option>
                </select>
            </div>
        </div>
    @else
        <div class="bidang @error('rubrik_esai') salah @enderror">
            <label for="rubrik_esai">Jawaban Acuan (Opsional)</label>
            <textarea id="rubrik_esai" data-rumus name="rubrik_esai" rows="4" maxlength="5000" placeholder="Jawaban yang diharapkan, untuk membantu koreksi">{{ old('rubrik_esai', $soal?->rubrik_esai) }}</textarea>
            @error('rubrik_esai') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>
    @endif

    </div>
    </div>

    <details class="form-lanjut" @if ($errors->hasAny(['bacaan_id', 'level', 'materi', 'indikator', 'pembahasan', 'bobot', 'status']) || $soal || $bacaanAwal) open @endif>
        <summary>Pengaturan lain: bacaan, level, bobot, status, pembahasan</summary>

        <div class="baris-dua">
            <div class="bidang @error('bacaan_id') salah @enderror">
                <label for="bacaan_id">Bacaan / Stimulus</label>
                <select id="bacaan_id" name="bacaan_id">
                    <option value="">Tanpa bacaan</option>
                    @foreach ($daftarBacaan as $b)
                        <option value="{{ $b->id }}" @selected((int) old('bacaan_id', $bacaanAwal) === $b->id)>{{ $b->label() }}</option>
                    @endforeach
                </select>
                <small class="bantuan"><a href="{{ route('staf.bacaan.create', $bank) }}">Tambah bacaan baru</a></small>
                @error('bacaan_id') <small class="galat-bidang">{{ $message }}</small> @enderror
            </div>
            <div class="bidang @error('level') salah @enderror">
                <label for="level">Level Kognitif</label>
                <select id="level" name="level">
                    <option value="">Belum ditentukan</option>
                    @foreach (\App\Models\Soal::LEVEL as $k => $l)
                        <option value="{{ $k }}" @selected(old('level', $soal?->level) === $k)>{{ $l }}</option>
                    @endforeach
                </select>
                @error('level') <small class="galat-bidang">{{ $message }}</small> @enderror
            </div>
        </div>

        <div class="baris-dua">
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
        </div>

        <div class="bidang @error('materi') salah @enderror">
            <label for="materi">Materi (untuk kisi-kisi, opsional)</label>
            <input id="materi" type="text" name="materi" maxlength="150" value="{{ old('materi', $soal?->materi) }}" placeholder="Contoh: Simple Present Tense">
            @error('materi') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>
        <div class="bidang @error('indikator') salah @enderror">
            <label for="indikator">Indikator soal (untuk kisi-kisi, opsional)</label>
            <textarea id="indikator" name="indikator" rows="2" maxlength="400" placeholder="Disajikan teks prosedur, peserta didik dapat menentukan langkah kerja. Jangan menyalin soal.">{{ old('indikator', $soal?->indikator) }}</textarea>
            <small class="bantuan">Tercetak di kisi-kisi yang dibagikan ke siswa. Tulis rumusan capaian, bukan kalimat soal.</small>
            @error('indikator') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>
        <div class="bidang @error('pembahasan') salah @enderror">
            <label for="pembahasan">Pembahasan / Keterangan (Opsional)</label>
            <textarea id="pembahasan" data-rumus name="pembahasan" rows="3" maxlength="3000" placeholder="Catatan Anda sendiri, tidak tampil ke siswa">{{ old('pembahasan', $soal?->pembahasan) }}</textarea>
            @error('pembahasan') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>
    </details>

    <div class="bidang-garis"></div>
    <div class="aksi-form aksi-form-akhir">
        <a class="tombol kecil garis" href="{{ route('staf.bank-soal.show', $bank) }}">Batal</a>
        <button class="tombol kecil" type="submit">{{ $soal ? 'Simpan Perubahan' : ($pg ? 'Simpan Soal PG' : 'Simpan Soal Esai') }}</button>
    </div>
</form>

@if ($pg)
<script>
    (function () {
        var pilih = document.getElementById('jumlah_opsi');
        var barisE = document.querySelector('.opsi-e');
        var kunci = document.getElementById('kunci');
        function atur() {
            var lima = pilih.value === '5';
            barisE.hidden = !lima;
            var opsiKunciE = kunci.querySelector('option[data-opsi="E"]');
            if (opsiKunciE) opsiKunciE.hidden = !lima;
            if (!lima) {
                barisE.querySelector('input[type=text]').value = '';
                if (kunci.value === 'E') kunci.value = '';
            }
        }
        pilih.addEventListener('change', atur);
        atur();
    })();
</script>
@endif

@push('skrip')
<script src="{{ asset('js/unggah-gambar.js') }}?v={{ filemtime(public_path('js/unggah-gambar.js')) }}" defer></script>
@endpush
@endsection
