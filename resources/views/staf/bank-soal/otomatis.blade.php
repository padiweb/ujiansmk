@extends('layouts.staf')
@section('judul', 'Susun Soal Otomatis')

@section('konten')
<h1 class="judul-halaman">Susun soal otomatis</h1>
<p class="sub">{{ $bank->nama }} · {{ $bank->mapel->nama }} · Kelas {{ $bank->tingkat }}</p>

@if (! $penyedia)
    <div class="kartu">
        <div class="pesan-salah" style="margin:0"><x-ikon nama="peringatan" :ukuran="18" />
            <span>Fitur susun soal otomatis belum tersedia di instalasi ini. Hubungi pengelola aplikasi.</span></div>
    </div>
@else
    <div class="info" style="margin-top:18px"><x-ikon nama="info" :ukuran="18" />
        <span>Hasil selalu masuk sebagai <strong>draf</strong>. Baca setiap soal, perbaiki bila perlu, lalu tandai siap. Jangan memakai soal otomatis tanpa memeriksa kuncinya.
        Sisa permintaan hari ini: <strong>{{ $sisa }}</strong> dari {{ $batas }}.</span></div>

    <form class="kartu formulir" method="POST" action="{{ route('staf.bank-soal.otomatis.proses', $bank) }}" id="formOtomatis">
        @csrf

        <div class="bidang @error('topik') salah @enderror">
            <label for="topik">Topik <span class="wajib">*</span></label>
            <input id="topik" name="topik" type="text" maxlength="300" value="{{ old('topik') }}" required autofocus>
            <small class="bantuan">Contoh: Persamaan linear satu variabel.</small>
            @error('topik') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>

        <div class="bidang @error('materi') salah @enderror">
            <label for="materi">Materi acuan (opsional)</label>
            <textarea id="materi" name="materi" rows="5" maxlength="4000">{{ old('materi') }}</textarea>
            <small class="bantuan">Tempel ringkasan materi agar soal sesuai buku ajar Anda. Maksimal 4.000 karakter.</small>
            @error('materi') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>

        <div class="bidang @error('tipe') salah @enderror">
            <label for="tipe">Jenis soal</label>
            <select id="tipe" name="tipe">
                <option value="pg" @selected(old('tipe', 'pg') === 'pg')>Pilihan ganda</option>
                <option value="esai" @selected(old('tipe') === 'esai')>Esai</option>
                <option value="campuran" @selected(old('tipe') === 'campuran')>Campuran (separuh pilihan ganda, separuh esai)</option>
            </select>
        </div>

        <div class="bidang @error('jumlah') salah @enderror">
            <label for="jumlah">Jumlah soal (1–{{ $maks }})</label>
            <input id="jumlah" name="jumlah" type="number" min="1" max="{{ $maks }}" value="{{ old('jumlah', 5) }}" required>
            @error('jumlah') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>

        <div class="bidang">
            <label for="soal_per_bacaan">Soal berbasis bacaan</label>
            <select id="soal_per_bacaan" name="soal_per_bacaan">
                <option value="0" @selected((int) old('soal_per_bacaan', 0) === 0)>Tanpa bacaan</option>
                @foreach ([2, 3, 4, 5] as $n)
                    <option value="{{ $n }}" @selected((int) old('soal_per_bacaan', 0) === $n)>Satu bacaan untuk {{ $n }} soal</option>
                @endforeach
            </select>
            <small class="bantuan">Penyusun menulis cerita atau pernyataan sekali, lalu beberapa soal merujuknya.</small>
        </div>

        <div class="bidang">
            <label for="jumlah_opsi">Jumlah opsi pilihan ganda</label>
            <select id="jumlah_opsi" name="jumlah_opsi">
                <option value="4" @selected((int) old('jumlah_opsi', $bank->jumlah_opsi_bawaan) === 4)>4 opsi (A–D)</option>
                <option value="5" @selected((int) old('jumlah_opsi', $bank->jumlah_opsi_bawaan) === 5)>5 opsi (A–E)</option>
            </select>
        </div>

        <div class="bidang">
            <label for="kesulitan">Tingkat kesulitan</label>
            <select id="kesulitan" name="kesulitan">
                @foreach (['mudah' => 'Mudah', 'sedang' => 'Sedang', 'sulit' => 'Sulit'] as $k => $l)
                    <option value="{{ $k }}" @selected(old('kesulitan', 'sedang') === $k)>{{ $l }}</option>
                @endforeach
            </select>
        </div>

        <div class="aksi-form">
            <button class="tombol kecil" type="submit" id="tombolSusun" @disabled($sisa <= 0)><x-ikon nama="otomatis" :ukuran="16" /><span>Susun</span></button>
            <a class="tombol kecil garis" href="{{ route('staf.bank-soal.show', $bank) }}">Batal</a>
        </div>
        <small class="bantuan" id="infoTunggu" hidden>Sedang menyusun soal, biasanya 10–40 detik. Jangan tutup halaman ini.</small>
    </form>

    @push('skrip')
    <script>
        document.getElementById('formOtomatis').addEventListener('submit', function () {
            var t = document.getElementById('tombolSusun');
            t.disabled = true; t.querySelector('span').textContent = 'Menyusun…';
            document.getElementById('infoTunggu').hidden = false;
        });
    </script>
    @endpush
@endif
@endsection
