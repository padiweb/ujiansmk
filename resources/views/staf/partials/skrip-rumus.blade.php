{{-- Pustaka tampilan rumus (KaTeX) dan furigana; dimuat lokal, tanpa internet. Urutan defer dipertahankan. --}}
<link rel="stylesheet" href="{{ asset('vendor/katex/katex.min.css') }}">
<script src="{{ asset('vendor/katex/katex.min.js') }}" defer></script>
<script src="{{ asset('vendor/katex/contrib/auto-render.min.js') }}" defer></script>
<script src="{{ asset('js/rumus.js') }}?v={{ filemtime(public_path('js/rumus.js')) }}" defer></script>
