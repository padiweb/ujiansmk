{{-- Alat bantu menulis rumus dan furigana: letakkan sebelum kolom bertanda data-rumus. --}}
<div id="alatRumus" class="alat-rumus" data-mathlive="{{ asset('vendor/mathlive/mathlive.min.js') }}" data-fonts="{{ asset('vendor/mathlive/fonts') }}">
    <div class="alat-rumus-judul">Rumus dan simbol <small>(klik kolom yang mau diisi, lalu pilih)</small></div>
    <div class="alat-rumus-baris">
        <button type="button" class="tombol kecil" data-buka-rumus title="Editor rumus visual: ketik dan lihat hasilnya seperti di buku">Editor rumus…</button>
        <button type="button" class="tombol kecil garis" data-sisip="\(\frac{}{}\)" data-kursor="8" title="Pecahan">a/b</button>
        <button type="button" class="tombol kecil garis" data-sisip="\(\sqrt{}\)" data-kursor="8" title="Akar">√x</button>
        <button type="button" class="tombol kecil garis" data-sisip="\(x^{2}\)" title="Pangkat">x²</button>
        <button type="button" class="tombol kecil garis" data-sisip="\(x_{1}\)" title="Indeks bawah">x₁</button>
        <button type="button" class="tombol kecil garis" data-sisip="\(\sum_{i=1}^{n} x_i\)" title="Sigma">∑</button>
        <button type="button" class="tombol kecil garis" data-sisip="\(\int_{a}^{b} f(x)\,dx\)" title="Integral">∫</button>
        @foreach (['×', '÷', '±', '≤', '≥', '≠', '≈', 'π', 'α', 'β', 'θ', '°', '²', '³', '½', '→', '∞'] as $simbol)
            <button type="button" class="tombol kecil garis simbol" data-sisip="{{ $simbol }}">{{ $simbol }}</button>
        @endforeach
        <button type="button" class="tombol kecil garis" data-furigana title="Bacaan di atas huruf Jepang, ditulis @{{漢字|かんじ}}">ふりがな</button>
    </div>
</div>

<dialog id="dialogRumus" class="dialog-rumus">
    <h3>Editor rumus</h3>
    <p class="bantuan">Ketik rumus di kotak, misalnya <b>/</b> untuk pecahan atau <b>^</b> untuk pangkat. Tekan "Sisipkan" bila sudah benar.</p>
    <div data-wadah></div>
    <p class="galat-bidang" data-pesan></p>
    <div class="aksi-form">
        <button type="button" class="tombol kecil" data-sisipkan>Sisipkan</button>
        <button type="button" class="tombol kecil garis" data-batal>Batal</button>
    </div>
</dialog>
@push('skrip')
    @include('staf.partials.skrip-rumus')
    <script src="{{ asset('js/editor-rumus.js') }}?v={{ filemtime(public_path('js/editor-rumus.js')) }}" defer></script>
@endpush
