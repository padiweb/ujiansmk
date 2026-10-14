@php
    $contoh = [[0, 1, 0, 0], [0, 0, 0, 1], [1, 0, 0, 0], [0, 0, 1, 0]];
@endphp
<aside class="masuk-panel">
    <div class="merek">
        <div>
            <strong>SMK Alhikmah Tanon</strong>
            <span>Sistem Ujian Berbasis Komputer</span>
        </div>
    </div>

    <div class="panel-isi">
        <h1>{!! $judulPanel !!}</h1>
        <p>{{ $tentangPanel }}</p>

        <div class="ljk" aria-hidden="true">
            @foreach ($contoh as $i => $baris)
                <span class="no">{{ $i + 1 }}</span>
                @foreach (['A', 'B', 'C', 'D'] as $k => $huruf)
                    <span class="gel {{ $baris[$k] ? 'isi' : '' }}">{{ $huruf }}</span>
                @endforeach
            @endforeach
        </div>
    </div>

    <div class="panel-kaki">Jawaban tersimpan otomatis selama ujian berlangsung.</div>
</aside>
