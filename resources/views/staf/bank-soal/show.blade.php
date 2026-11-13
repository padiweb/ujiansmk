@extends('layouts.staf')
@section('judul', $bank->nama)

@section('konten')
<div class="pita-info">
    <div class="pita-atas">
        <a class="tombol-pita" href="{{ route('staf.bank-soal.index') }}"><x-ikon nama="panah-kiri" :ukuran="16" />Kembali</a>
        <div class="aksi-halaman">
            <a class="tombol-pita" href="{{ route('staf.bank-soal.edit', $bank) }}"><x-ikon nama="ubah" :ukuran="16" />Ubah</a>
            <form method="POST" action="{{ route('staf.bank-soal.salin', $bank) }}">
                @csrf
                <button class="tombol-pita" type="submit"><x-ikon nama="salin" :ukuran="16" />Salin bank</button>
            </form>
            <form method="POST" action="{{ route('staf.bank-soal.destroy', $bank) }}" onsubmit="return confirm('Hapus bank soal ini beserta seluruh soalnya?')">
                @csrf
                @method('DELETE')
                <button class="tombol-pita" type="submit"><x-ikon nama="hapus" :ukuran="16" />Hapus</button>
            </form>
        </div>
    </div>
    <div class="pita-isi">
        <div><small>Nama bank</small><b>{{ $bank->nama }}</b></div>
        <div><small>Mata pelajaran</small><b>{{ $bank->mapel->nama }}</b></div>
        <div><small>Tingkat</small><b>Kelas {{ $bank->tingkat }}</b></div>
        <div><small>Pembuat</small><b>{{ $bank->pembuat->nama }}</b></div>
    </div>
</div>

<div class="db-angka rata">
    <div class="db-kotak"><b>{{ $ringkasan['pg'] }}</b><span>Pilihan ganda</span></div>
    <div class="db-kotak"><b>{{ $ringkasan['esai'] }}</b><span>Esai</span></div>
    <div class="db-kotak"><b>{{ $ringkasan['draf'] }}</b><span>Masih draf</span></div>
</div>

<form class="bar-saring" method="GET" action="{{ route('staf.bank-soal.show', $bank) }}">
    <div class="bar-isi">
        <label for="f-tipe">Jenis soal</label>
        <select id="f-tipe" name="tipe" onchange="this.form.submit()">
            <option value="">Semua jenis</option>
            <option value="pg" @selected(request('tipe') === 'pg')>Pilihan ganda</option>
            <option value="esai" @selected(request('tipe') === 'esai')>Esai</option>
        </select>
    </div>
    <div class="bar-isi">
        <label for="f-status">Status</label>
        <select id="f-status" name="status" onchange="this.form.submit()">
            <option value="">Semua status</option>
            <option value="siap" @selected(request('status') === 'siap')>Siap</option>
            <option value="draf" @selected(request('status') === 'draf')>Draf</option>
        </select>
    </div>
    <div class="bar-aksi">
        <a class="tombol kecil" href="{{ route('staf.soal.create', [$bank, 'tipe' => 'pg']) }}"><x-ikon nama="tambah" :ukuran="16" />Pilihan ganda</a>
        <a class="tombol kecil" href="{{ route('staf.soal.create', [$bank, 'tipe' => 'esai']) }}"><x-ikon nama="tambah" :ukuran="16" />Esai</a>
        @if (\App\Models\PenyediaOtomatis::tersedia())
            <a class="tombol kecil garis" href="{{ route('staf.bank-soal.otomatis', $bank) }}"><x-ikon nama="otomatis" :ukuran="16" />Susun otomatis</a>
        @endif
        <a class="tombol kecil garis" href="{{ route('staf.bank-soal.impor.form', $bank) }}"><x-ikon nama="unggah" :ukuran="16" />Impor Excel</a>
        <a class="tombol kecil garis" href="{{ route('staf.bank-soal.kisi', $bank) }}" data-cetak><x-ikon nama="cetak" :ukuran="16" />Kisi-kisi</a>
        <a class="tombol kecil garis" href="{{ route('staf.bank-soal.kisi', [$bank, 'format' => 'word']) }}"><x-ikon nama="berkas" :ukuran="16" />Kisi-kisi Word</a>
        @if ($ringkasan['draf'] > 0)
            <button class="tombol kecil garis" type="submit" form="formSiapSemua">Tandai {{ $ringkasan['draf'] }} draf siap</button>
        @endif
    </div>
</form>
@if ($ringkasan['draf'] > 0)
    <form id="formSiapSemua" method="POST" action="{{ route('staf.bank-soal.siapkan-semua', $bank) }}">@csrf</form>
@endif

<div class="db-panel" style="margin-bottom:16px">
    <header>
        <h3>Bacaan / Stimulus</h3>
        <a href="{{ route('staf.bacaan.create', $bank) }}">+ Tambah bacaan</a>
    </header>
    @if ($daftarBacaan->isEmpty())
        <p class="sub" style="padding:12px 18px;margin:0">Belum ada bacaan. Tambahkan bacaan agar satu cerita atau pernyataan dapat dipakai beberapa soal.</p>
    @else
        <div class="gulir">
            <table class="tabel tabel-daftar">
                <thead><tr><th class="no">No</th><th>Bacaan</th><th>Dipakai</th><th class="kanan">Aksi</th></tr></thead>
                <tbody>
                    @foreach ($daftarBacaan as $b)
                        <tr>
                            <td class="no">{{ $loop->iteration }}</td>
                            <td><strong>{{ $b->label() }}</strong></td>
                            <td>{{ $b->soal_count }} soal</td>
                            <td class="kanan aksi-baris">
                                <a href="{{ route('staf.soal.create', [$bank, 'tipe' => 'pg', 'bacaan' => $b->id]) }}">+ Soal PG</a>
                                <a href="{{ route('staf.soal.create', [$bank, 'tipe' => 'esai', 'bacaan' => $b->id]) }}">+ Soal Esai</a>
                                <a href="{{ route('staf.bacaan.edit', [$bank, $b]) }}">Ubah</a>
                                <form method="POST" action="{{ route('staf.bacaan.destroy', [$bank, $b]) }}" onsubmit="return confirm('Hapus bacaan ini? Soal yang memakainya tetap ada tanpa bacaan.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="tautan">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@forelse ($soal as $i => $s)
    <article class="soal-kartu">
        <header class="soal-kepala">
            <span class="soal-no">{{ ($soal->currentPage() - 1) * $soal->perPage() + $i + 1 }}</span>
            <span class="lencana">{{ $s->tipe === 'pg' ? 'Pilihan ganda' : 'Esai' }}</span>
            @if ($s->status === 'draf') <span class="lencana kuning">Draf</span> @endif
            @if ($s->sumber !== 'manual') <span class="lencana pucat">{{ $s->sumber === 'otomatis' ? 'Otomatis' : 'Impor' }}</span> @endif
            <span class="soal-bobot">Bobot {{ rtrim(rtrim($s->bobot, '0'), '.') }}</span>
        </header>

        @if ($s->bacaan)
            <details class="soal-bacaan">
                <summary>Bacaan: {{ $s->bacaan->label() }}</summary>
                <div>{!! nl2br(e($s->bacaan->teks)) !!}</div>
            </details>
        @endif

        <div class="soal-teks">{!! nl2br(e($s->pertanyaan)) !!}</div>

        @if ($s->gambar)
            <img class="soal-gambar" src="{{ route('staf.soal.gambar', $s) }}" alt="Gambar soal" loading="lazy">
        @endif

        @if ($s->tipe === 'pg')
            <ul class="opsi-daftar">
                @foreach ($s->opsi as $o)
                    <li class="{{ $o->benar ? 'benar' : '' }}"><b>{{ $o->label }}.</b> {{ $o->teks }}@if ($o->benar) <span class="tanda-kunci">kunci</span>@endif</li>
                @endforeach
            </ul>
        @elseif ($s->rubrik_esai)
            <div class="rubrik"><b>Jawaban acuan:</b> {!! nl2br(e($s->rubrik_esai)) !!}</div>
        @endif

        <footer class="soal-aksi">
            <a href="{{ route('staf.soal.edit', [$bank, $s]) }}">Ubah</a>
            <form method="POST" action="{{ route('staf.soal.status', [$bank, $s]) }}">
                @csrf
                <input type="hidden" name="status" value="{{ $s->status === 'siap' ? 'draf' : 'siap' }}">
                <button type="submit" class="tautan">{{ $s->status === 'siap' ? 'Kembalikan ke draf' : 'Tandai siap' }}</button>
            </form>
            <form method="POST" action="{{ route('staf.soal.destroy', [$bank, $s]) }}" onsubmit="return confirm('Hapus soal ini?')">
                @csrf
                @method('DELETE')
                <button type="submit" class="tautan">Hapus</button>
            </form>
        </footer>
    </article>
@empty
    <div class="kartu kosong-kartu">Belum ada soal. Tambahkan lewat tombol di atas atau impor dari Excel.</div>
@endforelse

{{ $soal->links('pagination.ringkas') }}
@endsection
