@extends('layouts.staf')
@section('judul', 'Profil Saya')

@section('konten')
<div class="kartu profil-kartu">
    <div class="profil-atas">
        <span class="profil-foto">
            @if ($pengguna->foto)
                <img src="{{ route('staf.profil.foto', ['v' => substr(md5($pengguna->foto), 0, 8)]) }}" alt="Foto profil {{ $pengguna->nama }}" id="profilPratinjau">
            @else
                <span class="profil-inisial" id="profilInisial">{{ mb_strtoupper(mb_substr($pengguna->nama, 0, 1)) }}</span>
                <img src="" alt="" id="profilPratinjau" hidden>
            @endif
        </span>
        <div>
            <h2 class="profil-nama">{{ $pengguna->nama }}</h2>
            <p class="profil-sub"><span class="lencana">{{ $pengguna->role }}</span> <span>{{ $pengguna->username }}</span></p>
        </div>
    </div>

    <form method="POST" action="{{ route('staf.profil.simpan') }}" enctype="multipart/form-data" class="profil-form">
        @csrf
        <div class="bidang @error('foto') salah @enderror">
            <label for="foto">Foto profil</label>
            <input id="foto" name="foto" type="file" accept="image/jpeg,image/png,image/webp" required>
            <small class="bantuan">JPG, PNG, atau WEBP, maksimal 5 MB. Foto dipotong persegi dari tengah dan ditampilkan di bilah atas.</small>
            @error('foto') <small class="galat-bidang">{{ $message }}</small> @enderror
        </div>
        <div class="aksi-form">
            <button class="tombol" type="submit">Simpan foto</button>
        </div>
    </form>

    @if ($pengguna->foto)
        <form method="POST" action="{{ route('staf.profil.simpan') }}" class="profil-hapus" onsubmit="return confirm('Hapus foto profil?')">
            @csrf
            <input type="hidden" name="hapus" value="1">
            <button class="tautan" type="submit">Hapus foto</button>
        </form>
    @endif
</div>
@endsection

@push('skrip')
<script>
    // Pratinjau foto yang dipilih sebelum disimpan (hanya di peramban, tidak dikirim ke mana pun).
    (function () {
        var input = document.getElementById('foto'), gambar = document.getElementById('profilPratinjau'), inisial = document.getElementById('profilInisial');
        if (!input || !gambar || !window.URL) return;
        input.addEventListener('change', function () {
            var f = input.files && input.files[0];
            if (!f || f.type.indexOf('image/') !== 0) return;
            if (gambar.dataset.blob) URL.revokeObjectURL(gambar.dataset.blob);
            var alamat = URL.createObjectURL(f);
            gambar.dataset.blob = alamat;
            gambar.src = alamat; gambar.hidden = false;
            if (inisial) inisial.hidden = true;
        });
    })();
</script>
@endpush
