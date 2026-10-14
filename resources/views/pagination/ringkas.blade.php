@if ($paginator->hasPages())
    <nav class="halaman-nav" role="navigation" aria-label="Halaman">
        @if ($paginator->onFirstPage())
            <span class="mati">‹ Sebelumnya</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Sebelumnya</a>
        @endif

        <span class="posisi">Halaman {{ $paginator->currentPage() }} dari {{ $paginator->lastPage() }}</span>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">Berikutnya ›</a>
        @else
            <span class="mati">Berikutnya ›</span>
        @endif
    </nav>
@endif
