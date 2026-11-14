{{-- Hasil susunan: diperiksa dan boleh diubah dulu; baru masuk ke bank lewat "Tambahkan soal". --}}
@php $semua = $rancangan['butir']; @endphp
<form method="POST" action="{{ route('staf.bank-soal.rancangan.tambah', $bank) }}" id="formRancangan" class="rancangan">
    @csrf
    <div class="rancangan-kepala">
        <h2>{{ count($semua) }} soal menunggu</h2>
        <span class="rancangan-aksi">
            <label class="centang"><input type="checkbox" id="pilihSemua" checked> Pilih semua</label>
            <button class="tombol kecil" type="submit" name="tambah" value="pilih"><x-ikon nama="tambah" :ukuran="14" />Tambahkan yang dipilih</button>
            <button class="tombol kecil garis" type="submit" name="buang" value="semua" formaction="{{ route('staf.bank-soal.rancangan.buang', $bank) }}" onclick="return confirm('Buang semua soal di bawah?')">Buang semua</button>
        </span>
    </div>

    @php $bacaanTampil = []; @endphp
    @foreach ($semua as $i => $b)
        @php $kode = $b['bacaan_kode'] ?? null; $pg = $b['tipe'] === 'pg'; @endphp
        <article class="soal-kartu">
            <header class="soal-kepala">
                <label class="centang" title="Pilih soal ini"><input type="checkbox" name="pilih[]" value="{{ $i }}" class="pilih-soal" checked></label>
                <span class="soal-no {{ $pg ? 'pg' : 'esai' }}">{{ $i + 1 }}</span>
                <span class="soal-jenis">{{ $pg ? 'PILIHAN GANDA' : 'ESAI' }}</span>
                @if (! empty($b['level']))<span class="lencana pucat">{{ $b['level'] }}</span>@endif
                @if (str_contains((string) ($b['pembahasan'] ?? ''), 'BELUM DIPASTIKAN'))<span class="lencana kuning">Periksa kunci</span>@endif
                <span class="soal-aksi-kepala">
                    <button class="tombol kecil garis" type="submit" name="buang" value="{{ $i }}" formaction="{{ route('staf.bank-soal.rancangan.buang', $bank) }}"><x-ikon nama="hapus" :ukuran="13" />Buang</button>
                    <button class="tombol kecil" type="submit" name="tambah" value="{{ $i }}"><x-ikon nama="tambah" :ukuran="13" />Tambahkan soal</button>
                </span>
            </header>

            @if ($kode && isset($rancangan['bacaan'][$kode]) && ! in_array($kode, $bacaanTampil, true))
                @php $bacaanTampil[] = $kode; $bc = $rancangan['bacaan'][$kode]; @endphp
                <details class="soal-bacaan">
                    <summary>Bacaan: {{ $bc['judul'] ?: 'Tanpa judul' }}</summary>
                    <div class="render-rumus rancangan-pratinjau">{!! nl2br(e($bc['teks'])) !!}</div>
                    <div class="rancangan-ubah">
                        <input type="text" name="bacaan[{{ $kode }}][judul]" value="{{ $bc['judul'] }}" maxlength="150" placeholder="Judul bacaan">
                        <textarea name="bacaan[{{ $kode }}][teks]" rows="6" maxlength="8000">{{ $bc['teks'] }}</textarea>
                    </div>
                </details>
            @elseif ($kode)
                <div class="soal-bacaan rancangan-rujuk">Memakai bacaan: {{ $rancangan['bacaan'][$kode]['judul'] ?? 'bacaan di atas' }}</div>
            @endif

            <div class="soal-teks render-rumus" data-sinkron="p{{ $i }}">{!! nl2br(e($b['pertanyaan'])) !!}</div>

            @if ($pg)
                <div class="opsi-grid" style="margin:10px 18px 0">
                    @foreach ($b['opsi'] as $label => $teks)
                        <label class="opsi-kotak {{ $b['kunci'] === $label ? 'benar' : '' }}">
                            <input type="radio" name="butir[{{ $i }}][kunci]" value="{{ $label }}" @checked($b['kunci'] === $label) title="Kunci jawaban">
                            <b>{{ $label }}.</b> <span class="render-rumus" data-sinkron="o{{ $i }}{{ $label }}">{{ $teks }}</span>
                        </label>
                    @endforeach
                </div>
            @elseif (! empty($b['rubrik']))
                <div class="rubrik"><b>Jawaban acuan:</b> <span class="render-rumus" data-sinkron="r{{ $i }}">{!! nl2br(e($b['rubrik'])) !!}</span></div>
            @endif

            @if (! empty($b['pembahasan']))
                <div class="rubrik"><b>Pembahasan:</b> <span class="render-rumus" data-sinkron="h{{ $i }}">{!! nl2br(e($b['pembahasan'])) !!}</span></div>
            @endif

            <details class="rancangan-edit">
                <summary><x-ikon nama="ubah" :ukuran="13" />Ubah soal</summary>
                <div class="rancangan-ubah">
                    <label>Pertanyaan</label>
                    <textarea name="butir[{{ $i }}][pertanyaan]" rows="4" maxlength="5000" data-ke="p{{ $i }}">{{ $b['pertanyaan'] }}</textarea>
                    @if ($pg)
                        @foreach ($b['opsi'] as $label => $teks)
                            <label>Pilihan {{ $label }}</label>
                            <input type="text" name="butir[{{ $i }}][opsi][{{ $label }}]" value="{{ $teks }}" maxlength="1000" data-ke="o{{ $i }}{{ $label }}">
                        @endforeach
                    @else
                        <label>Jawaban acuan</label>
                        <textarea name="butir[{{ $i }}][rubrik]" rows="3" maxlength="5000" data-ke="r{{ $i }}">{{ $b['rubrik'] ?? '' }}</textarea>
                    @endif
                    <label>Pembahasan</label>
                    <textarea name="butir[{{ $i }}][pembahasan]" rows="3" maxlength="3000" data-ke="h{{ $i }}">{{ $b['pembahasan'] ?? '' }}</textarea>
                    <label>Level</label>
                    <select name="butir[{{ $i }}][level]">
                        <option value="">Tanpa level</option>
                        @foreach (['L1', 'L2', 'L3'] as $lv)
                            <option value="{{ $lv }}" @selected(($b['level'] ?? null) === $lv)>{{ $lv }}</option>
                        @endforeach
                    </select>
                </div>
            </details>
        </article>
    @endforeach
</form>

@push('skrip')
    @include('staf.partials.skrip-rumus')
    <script>
        (function () {
            var form = document.getElementById('formRancangan');
            if (!form) return;
            var semua = document.getElementById('pilihSemua');
            semua.addEventListener('change', function () {
                form.querySelectorAll('.pilih-soal').forEach(function (c) { c.checked = semua.checked; });
            });
            form.querySelectorAll('input[type=radio]').forEach(function (r) {
                r.addEventListener('change', function () {
                    form.querySelectorAll('input[name="' + r.name + '"]').forEach(function (x) { x.closest('.opsi-kotak').classList.toggle('benar', x.checked); });
                });
            });
            form.addEventListener('input', function (e) {
                var ke = e.target.getAttribute && e.target.getAttribute('data-ke');
                if (!ke) return;
                var sasaran = form.querySelector('[data-sinkron="' + ke + '"]');
                if (!sasaran) return;
                sasaran.textContent = e.target.value;
                sasaran.style.whiteSpace = 'pre-line';
                if (window.Rumus) window.Rumus.render(sasaran);
            });
        })();
    </script>
@endpush
