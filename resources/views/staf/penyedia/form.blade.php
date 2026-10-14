@extends('layouts.staf')
@section('judul', $p ? 'Ubah Penyedia' : 'Tambah Penyedia')

@section('konten')
<h1 class="judul-halaman">{{ $p ? 'Ubah' : 'Tambah' }} penyedia otomatis</h1>

<form class="kartu formulir" method="POST" action="{{ $p ? route('staf.penyedia.update', $p) : route('staf.penyedia.store') }}" autocomplete="off">
    @csrf
    @if ($p) @method('PUT') @endif

    <div class="bidang @error('nama') salah @enderror">
        <label for="nama">Nama <span class="wajib">*</span></label>
        <input id="nama" name="nama" type="text" maxlength="80" value="{{ old('nama', $p?->nama) }}" required autofocus>
        <small class="bantuan">Nama bebas untuk mengenali penyedia, mis. "Gemini sekolah".</small>
        @error('nama') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    <div class="bidang @error('driver') salah @enderror">
        <label for="driver">Jenis penyedia <span class="wajib">*</span></label>
        <select id="driver" name="driver" required>
            @foreach (\App\Models\PenyediaOtomatis::DRIVER as $kode => $label)
                <option value="{{ $kode }}" @selected(old('driver', $p?->driver ?? 'gemini') === $kode)>{{ $label }}</option>
            @endforeach
        </select>
        @error('driver') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    <div class="bidang @error('model') salah @enderror">
        <label for="model">Nama model <span class="wajib">*</span></label>
        <input id="model" name="model" type="text" maxlength="80" value="{{ old('model', $p?->model) }}" required spellcheck="false">
        <small class="bantuan">Salin persis dari dokumentasi penyedia, mis. gemini-2.5-flash, gpt-4o-mini, atau claude-haiku-5-5.</small>
        @error('model') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    <div class="bidang @error('kunci') salah @enderror">
        <label for="kunci">Kunci API @unless ($p)<span class="wajib">*</span>@endunless</label>
        <input id="kunci" name="kunci" type="password" maxlength="500" autocomplete="new-password" spellcheck="false"
               placeholder="{{ $p ? $p->petunjukKunci().' (kosongkan bila tidak diganti)' : '' }}" @required(! $p)>
        <small class="bantuan">Disimpan terenkripsi dan tidak pernah ditampilkan lagi. Buat kunci khusus untuk aplikasi ini agar mudah dicabut.</small>
        @error('kunci') <small class="galat-bidang">{{ $message }}</small> @enderror
    </div>

    @unless ($p)
        <label class="centang"><input type="checkbox" name="aktif" value="1" @checked(old('aktif', true))> Jadikan penyedia aktif sekarang</label>
    @endunless

    <div class="aksi-form">
        <button class="tombol kecil" type="submit">Simpan</button>
        <a class="tombol kecil garis" href="{{ route('staf.penyedia.index') }}">Batal</a>
    </div>
</form>
@endsection
