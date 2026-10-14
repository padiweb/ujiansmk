@extends('layouts.staf')
@section('judul', 'Koreksi esai')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">{{ $j->nama }}</h1>
        <p class="sub">{{ $j->mapel->nama }}</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil garis" href="{{ route('staf.koreksi.show', $j) }}"><x-ikon nama="panah-kiri" :ukuran="16" />Soal esai</a>
        <button type="button" class="tombol kecil garis" id="korNama" aria-pressed="false">Tampilkan nama</button>
    </div>
</div>

<div class="kartu">
    <h2 class="judul-kartu">Pertanyaan</h2>
    <div class="teks-soal">{!! nl2br(e($soal->pertanyaan)) !!}</div>
    @if (filled($soal->rubrik_esai))
        <div class="rubrik"><strong>Jawaban acuan (rubrik)</strong><div>{!! nl2br(e($soal->rubrik_esai)) !!}</div></div>
    @else
        <p class="bantuan">Soal ini belum memiliki jawaban acuan.</p>
    @endif
</div>

<div class="kartu" id="korKotak"
     data-nilai="{{ url('staf/koreksi/'.$j->id.'/jawaban') }}"
     data-saran="{{ route('staf.koreksi.saran', [$j, $soal]) }}"
     data-token="{{ csrf_token() }}">
    <div class="kepala-halaman" style="margin-bottom:8px">
        <div><strong id="korProgres">{{ $kemajuan['dinilai'] }}/{{ $kemajuan['total'] }}</strong> jawaban sudah dinilai</div>
        <div class="aksi-halaman">
            <a class="tombol kecil garis" href="{{ request()->fullUrlWithQuery(['saring' => $belum ? null : 'belum', 'page' => null]) }}">{{ $belum ? 'Tampilkan semua' : 'Hanya yang belum dinilai' }}</a>
            @if ($penyedia)
                <button type="button" class="tombol kecil garis" id="korSaran" title="Sisa permintaan hari ini: {{ $sisa }}">Saran nilai Otomatis (halaman ini)</button>
            @endif
        </div>
    </div>
    <p id="korPesan" class="galat-bidang" hidden></p>

    @forelse ($jawaban as $i => $j2)
        <article class="jawaban-esai" data-id="{{ $j2->id }}">
            <header>
                <strong>Jawaban {{ $jawaban->firstItem() + $i }}</strong>
                <span class="kor-nama" hidden data-nama="{{ $j2->sesi->siswa->nama }} ({{ $j2->sesi->siswa->nisn }})"></span>
                <span class="kor-simpan" aria-live="polite"></span>
            </header>
            <div class="teks-jawaban">{!! nl2br(e($j2->teks_esai)) !!}</div>
            <div class="kor-nilai">
                <label>Nilai (0–100)
                    <input type="text" inputmode="decimal" class="kor-input" value="{{ $j2->nilai_esai !== null ? rtrim(rtrim((string) $j2->nilai_esai, '0'), '.') : '' }}" autocomplete="off">
                </label>
                <div class="kor-cepat">
                    @foreach ([0, 25, 50, 75, 100] as $n)
                        <button type="button" class="tombol kecil garis kor-set" data-nilai="{{ $n }}">{{ $n }}</button>
                    @endforeach
                </div>
            </div>
            <div class="kor-saran" @if ($j2->saran_nilai === null) hidden @endif data-saran="{{ $j2->saran_nilai !== null ? rtrim(rtrim((string) $j2->saran_nilai, '0'), '.') : '' }}">
                <span class="lencana pucat">Saran Otomatis</span>
                <strong class="kor-saran-nilai">{{ $j2->saran_nilai !== null ? rtrim(rtrim((string) $j2->saran_nilai, '0'), '.') : '' }}</strong>
                <span class="kor-saran-catatan">{{ $j2->saran_catatan }}</span>
                <button type="button" class="tombol kecil garis kor-pakai">Pakai saran</button>
            </div>
        </article>
    @empty
        <p class="kosong-kartu" style="padding:24px;text-align:center;color:var(--teks-3)">{{ $belum ? 'Semua jawaban sudah dinilai.' : 'Belum ada jawaban yang bisa dikoreksi.' }}</p>
    @endforelse

    {{ $jawaban->links() }}
</div>
@endsection

@push('skrip')
<script src="{{ asset('js/koreksi.js') }}?v={{ filemtime(public_path('js/koreksi.js')) }}" defer></script>
@endpush
