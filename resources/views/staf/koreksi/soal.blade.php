@extends('layouts.staf')
@section('judul', 'Koreksi esai')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">{{ $j->nama }}</h1>
        <p class="sub">{{ $j->mapel->nama }} · Kelas {{ $kelasPilihan }}</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil garis" href="{{ route('staf.koreksi.show', array_filter([$j, 'kelas' => $kelasId])) }}"><x-ikon nama="panah-kiri" :ukuran="16" />Soal esai</a>
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

@if ($terkunci)
    <div class="info" role="status" id="korKunci"><x-ikon nama="info" :ukuran="18" /><span>Nilai kelas ini sedang dikunci. Bila Bapak/Ibu mengubah atau menambah nilai, kunci terbuka otomatis. Setelah selesai, kunci lagi di <a href="{{ route('staf.rekap.show', array_filter([$j, 'kelas' => $kelasId])) }}">Rekap Nilai</a>.</span></div>
@endif

<form class="kartu" method="POST" action="{{ route('staf.koreksi.nilai-semua', [$j, $soal]) }}" onsubmit="return confirm('Beri nilai ini ke semua jawaban pada soal ini ('+(this.timpa.checked ? 'termasuk yang sudah dinilai' : 'yang belum dinilai')+')?')">
    @csrf
    @if ($kelasId) <input type="hidden" name="kelas" value="{{ $kelasId }}"> @endif
    <h2 class="judul-kartu">Nilai yang sama untuk semua jawaban</h2>
    <div class="aksi-form" style="align-items:flex-end;flex-wrap:wrap;gap:12px;margin-top:8px">
        <div class="bidang" style="margin:0"><label for="nilaiSemua">Nilai (0–100)</label>
            <input id="nilaiSemua" name="nilai" type="text" inputmode="decimal" style="width:110px" required autocomplete="off"></div>
        <label class="centang" style="margin:0"><input type="checkbox" name="timpa" value="1"> Timpa nilai yang sudah ada</label>
        <button class="tombol kecil" type="submit">Terapkan ke semua</button>
    </div>
    <small class="bantuan">Mengisi jawaban yang belum dinilai pada kelas {{ $kelasPilihan }}. Jawaban kosong tetap bernilai 0.</small>
</form>

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
                <strong>{{ $j2->sesi->siswa->nama }}</strong>
                <span class="kor-nama">· {{ $j2->sesi->siswa->kelas->whereIn('nama', explode(', ', $kelasPilihan))->pluck('nama')->implode(', ') }} · {{ $j2->sesi->siswa->nisn }}</span>
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
