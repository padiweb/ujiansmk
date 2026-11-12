@extends('layouts.staf')
@section('judul', 'Monitor: '.$j->nama)

@section('konten')
<div class="pita-info">
    <div class="pita-atas">
        <a class="tombol-pita" href="{{ route('staf.monitor.index') }}"><x-ikon nama="panah-kiri" :ukuran="16" />Daftar</a>
        <div class="aksi-halaman">
            @if ($bolehBertindak)
                <a class="tombol-pita" href="{{ route('staf.hadir', $j) }}" target="_blank" rel="noopener">Daftar hadir</a>
            @endif
            <button type="button" id="monBunyi" class="tombol-pita" aria-pressed="false">Bunyi: mati</button>
        </div>
    </div>
    <div class="pita-isi">
        <div><small>Jadwal</small><b>{{ $j->nama }}</b></div>
        <div><small>Mapel</small><b>{{ $j->mapel->nama }}</b></div>
        <div><small>Durasi</small><b>{{ $j->durasi_menit }} menit</b></div>
        <div><small>Batas pelanggaran</small><b>{{ $j->batas_pelanggaran }}</b></div>
        <div><small>Waktu server</small><b><span id="monWaktu">{{ $data['waktu'] }}</span></b> <span id="monKoneksi" class="lencana kuning" hidden>Koneksi terputus</span></div>
    </div>
</div>

@if ($bolehBertindak && $j->token)
    <div class="kartu token-kartu">
        <div>
            <span class="token-label">Token ujian</span>
            <div class="token-nilai" aria-label="Token ujian">{{ $j->token }}</div>
            <small class="bantuan">Bacakan token ini kepada siswa di ruang ujian.</small>
        </div>
    </div>
@endif

<div class="db-angka rata" id="monRingkasan">
    @foreach (['mengerjakan' => 'Mengerjakan', 'selesai' => 'Selesai', 'melanggar' => 'Melanggar', 'terblokir' => 'Terblokir', 'belum' => 'Belum mulai', 'senyap' => 'Tidak ada kabar'] as $k => $l)
        <div class="db-kotak"><b data-ringkas="{{ $k }}">{{ $data['ringkasan'][$k] }}</b><span>{{ $l }}</span></div>
    @endforeach
</div>

<form class="bar-saring" onsubmit="return false">
    <div class="bar-isi bar-cari">
        <label for="monCari">Cari</label>
        <input type="search" id="monCari" placeholder="Ketik nama atau NISN…" autocomplete="off">
    </div>
    <div class="bar-isi">
        <label for="monStatus">Status</label>
        <select id="monStatus">
            <option value="">Semua status</option>
            <option value="berlangsung">Mengerjakan</option>
            <option value="melanggar">Melanggar</option>
            <option value="diblokir">Terblokir</option>
            <option value="senyap">Tidak ada kabar</option>
            <option value="belum">Belum mulai</option>
            <option value="selesai">Selesai</option>
        </select>
    </div>
    @if ($ruang->isNotEmpty())
        <div class="bar-isi">
            <label for="monRuang">Ruang</label>
            <select id="monRuang">
                <option value="">Semua ruang</option>
                @foreach ($ruang as $r)
                    <option value="{{ $r->id }}">{{ $r->nama }}</option>
                @endforeach
            </select>
        </div>
    @endif
</form>

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel tabel-monitor" id="monTabel"
               data-url="{{ route('staf.monitor.data', $j) }}"
               data-aksi="{{ url('staf/monitor/'.$j->id.'/sesi') }}"
               data-token="{{ csrf_token() }}"
               data-boleh="{{ implode(',', $aksiBoleh) }}"
               data-total="{{ $data['total_soal'] }}">
            <thead><tr><th>Siswa</th><th>Kelas</th><th>Status</th><th>Pelanggaran</th><th>Dijawab</th><th>Kabar</th><th>Perangkat</th><th class="kanan">Tindakan</th></tr></thead>
            <tbody id="monIsi"><tr><td colspan="8" class="kosong">Memuat…</td></tr></tbody>
        </table>
    </div>
</div>

<div class="kartu">
    <h2 class="judul-kartu">Tindakan terakhir</h2>
    <ul class="petunjuk" id="monRiwayat" style="margin-top:6px">
        @forelse ($aksiTerakhir as $a)
            <li>{{ $a->created_at->format('H:i') }} · {{ $a->pelaku?->nama ?? '—' }} — {{ \App\Models\AksiPengawas::LABEL[$a->aksi] ?? $a->aksi }} · {{ $a->sesi->siswa->nama }}@if ($a->detail) ({{ $a->detail }})@endif</li>
        @empty
            <li class="kosong-riwayat">Belum ada tindakan.</li>
        @endforelse
    </ul>
</div>

<div id="monDialog" class="lapisan" hidden role="dialog" aria-modal="true" aria-labelledby="monDialogJudul">
    <div class="lapisan-kotak">
        <h2 id="monDialogJudul"></h2>
        <p id="monDialogIsi"></p>
        <div id="monDialogMenit" class="bidang" hidden style="text-align:left">
            <label for="monMenit">Tambahan waktu (menit)</label>
            <input type="number" id="monMenit" min="1" max="120" value="10" inputmode="numeric">
        </div>
        <p id="monDialogGalat" class="galat-bidang" hidden></p>
        <div class="aksi-form" style="justify-content:center">
            <button type="button" id="monDialogBatal" class="tombol kecil garis">Batal</button>
            <button type="button" id="monDialogOk" class="tombol kecil">Lanjutkan</button>
        </div>
    </div>
</div>

<script type="application/json" id="monAwal">@json($data, JSON_HEX_TAG | JSON_HEX_AMP)</script>
@endsection

@push('skrip')
<script src="{{ asset('js/monitor.js') }}?v={{ filemtime(public_path('js/monitor.js')) }}" defer></script>
@endpush
