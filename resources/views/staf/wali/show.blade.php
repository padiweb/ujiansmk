@extends('layouts.staf')
@section('judul', 'Wali Kelas '.$kelas->nama)

@section('konten')
<div class="pita-info">
    <div class="pita-atas">
        <a class="tombol-pita" href="{{ route('staf.wali.index', ['periode' => $periode->id]) }}"><x-ikon nama="panah-kiri" :ukuran="16" />Kembali</a>
        <span id="statusEduweb" class="status-eduweb" data-url="{{ route('staf.wali.segar', [$kelas, 'periode' => $periode->id]) }}" role="status" aria-live="polite"></span>
        @unless ($final)
            <form method="POST" action="{{ route('staf.wali.absensi', [$kelas, 'periode' => $periode->id]) }}" onsubmit="return confirm('Ambil absensi dan nilai ekskul semua siswa dari EduWeb? Isian sakit, izin, dan alpa yang sudah Anda ubah akan tertimpa.')">
                @csrf <button class="tombol-pita" type="submit">Ambil absensi &amp; ekskul dari EduWeb</button>
            </form>
        @endunless
    </div>
    <div class="pita-isi">
        <div><small>Kelas</small><b>{{ $kelas->nama }}</b></div>
        <div><small>Semester</small><b>{{ $periode->label() }}</b></div>
        <div><small>Wali Kelas</small><b>{{ $kelas->waliKelas?->nama ?? '–' }}</b></div>
        <div><small>Siswa</small><b>{{ count($baris) }}</b></div>
        <div><small>Status Rapor</small><b>{{ $final ? 'Final' : 'Belum final' }}</b></div>
    </div>
</div>

@if ($sudahPindah > 0)
    <div class="pesan-salah catatan" role="status"><x-ikon nama="info" :ukuran="18" /><span>{{ $sudahPindah }} siswa sudah dinaikkan, ditinggalkan, atau diluluskan dari kelas ini di EduWeb. Rapor kelas ini belum final; selesaikan dan finalkan segera.</span></div>
@endif

@push('skrip')
    <script src="{{ asset('js/absensi-otomatis.js') }}?v={{ filemtime(public_path('js/absensi-otomatis.js')) }}" defer></script>
@endpush

@if (count($baris) > 0)
    <form id="formCetakRapor" class="bar-saring bar-cetak" method="GET" action="{{ route('staf.wali.cetak', $kelas) }}" target="_blank">
        <input type="hidden" name="periode" value="{{ $periode->id }}">
        <input type="hidden" name="siswa" value="">
        <div class="bar-isi">
            <label for="tgl-rapor">Tgl Rapor</label>
            <input id="tgl-rapor" type="date" name="tgl" value="{{ now()->format('Y-m-d') }}" required>
        </div>
        <div class="bar-aksi">
            <button class="tombol kecil garis" type="submit" name="format" value="pratinjau">Pratinjau Kelas</button>
            @if (count($siswaFinal) > 0)
                <button class="tombol kecil" type="submit" name="format" value="cetak"><x-ikon nama="berkas" :ukuran="16" />Cetak Kelas</button>
                <button class="tombol kecil garis" type="submit" name="format" value="word"><x-ikon nama="unduh" :ukuran="16" />Word Kelas</button>
            @endif
        </div>
        <div class="pesan-salah" id="pesanCetakRapor" role="alert" hidden></div>
    </form>
    @push('skrip')
        <script src="{{ asset('js/cetak-rapor.js') }}?v={{ filemtime(public_path('js/cetak-rapor.js')) }}" defer></script>
    @endpush
@endif

<div class="db-panel" style="margin-bottom:16px">
    <header><h3>Kelengkapan Nilai Mata Pelajaran</h3><small>{{ count($mapel) }} mapel</small></header>
    <div class="gulir">
        <table class="tabel">
            <thead><tr><th>Mata Pelajaran</th><th>Guru</th><th>Nilai Lengkap</th><th>Status</th></tr></thead>
            <tbody>
                @forelse ($mapel as $m)
                    <tr>
                        <td><strong>{{ $m['mapel']->nama }}</strong></td>
                        <td>{{ $m['guru'] ?? '—' }}</td>
                        <td>{{ $m['lengkap'] }} dari {{ $m['siswa'] }}</td>
                        <td>
                            @if ($m['final']) <span class="lencana pucat">Final</span>
                            @elseif ($m['siswa'] > 0 && $m['lengkap'] >= $m['siswa']) <span class="lencana">Lengkap</span>
                            @else <span class="lencana kuning">Belum lengkap</span> @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="kosong">Belum ada mata pelajaran yang diampu di kelas ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<form method="POST" action="{{ route('staf.wali.simpan', [$kelas, 'periode' => $periode->id]) }}">
    @csrf
    <div class="kartu tanpa-padding">
        <div class="gulir">
            <table class="tabel tabel-nilai">
                <thead>
                    <tr>
                        <th rowspan="2" class="lekat no">No</th><th rowspan="2" class="lekat nama">Nama Siswa</th>
                        <th rowspan="2" class="tengah">Mapel<br>Lengkap</th><th rowspan="2" class="tengah">Rata-<br>rata</th>
                        <th colspan="3" class="tengah grup-nk">Absensi (hari)</th>
                        <th rowspan="2" class="kolom-catatan">Catatan Wali Kelas</th><th rowspan="2">Keputusan</th><th rowspan="2">Status Kenaikan di EduWeb</th><th rowspan="2" class="tengah">Aksi</th>
                    </tr>
                    <tr><th class="tengah grup-nk">Sakit</th><th class="tengah grup-nk">Izin</th><th class="tengah grup-nk">Alpa</th></tr>
                </thead>
                <tbody>
                    @forelse ($baris as $i => $b)
                        @php $s = $b['siswa']; $r = $b['rapor']; $kunci = $b['final']; @endphp
                        <tr>
                            <td class="lekat no">{{ $i + 1 }}</td>
                            <td class="lekat nama"><div class="sel-siswa"><span class="avatar-siswa">{{ \Illuminate\Support\Str::of($s->nama)->explode(' ')->filter()->take(2)->map(fn ($w) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($w, 0, 1)))->implode('') }}</span><div><strong>{{ $s->nama }}</strong><small>{{ $s->nisn }}</small></div></div></td>
                            <td class="sel-hasil">{{ $b['mapel_lengkap'] }}/{{ $b['mapel_total'] }}</td>
                            <td class="sel-hasil rata">{{ $b['rata'] ?? '–' }}</td>
                            @foreach (['sakit', 'izin', 'alpa'] as $k)
                                <td class="sel-absen">
                                    <input type="number" min="0" max="999" name="rapor[{{ $s->id }}][{{ $k }}]" value="{{ $r?->{$k} ?? 0 }}" @disabled($kunci) aria-label="{{ ucfirst($k) }} {{ $s->nama }}"
                                           @if ($k === 'sakit' && $r?->sumber_absensi) title="{{ $r->sumber_absensi === 'eduweb' ? 'Absensi dari EduWeb' : 'Absensi diubah wali kelas' }}" @endif>
                                </td>
                            @endforeach
                            <td class="kolom-catatan"><input type="text" maxlength="500" name="rapor[{{ $s->id }}][catatan]" value="{{ $r?->catatan_wali }}" placeholder="Catatan..." @disabled($kunci) aria-label="Catatan {{ $s->nama }}"></td>
                            <td>
                                <select name="rapor[{{ $s->id }}][keputusan]" @disabled($kunci) aria-label="Keputusan {{ $s->nama }}">
                                    <option value="">—</option>
                                    @foreach ($keputusan as $kode => $label)
                                        <option value="{{ $kode }}" @selected($r?->keputusan === $kode)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                            @php $kn = $kenaikan[$s->id] ?? ['kode' => 'kosong', 'label' => '–', 'beda' => false]; @endphp
                            <td class="sel-kenaikan" data-kenaikan="{{ $s->id }}"><span class="kenaikan {{ $kn['kode'] }}{{ $kn['beda'] ? ' beda' : '' }}">{{ $kn['label'] }}</span></td>
                            <td class="sel-cetak">
                                <button class="tombol kecil garis tombol-ikon" type="submit" form="formCetakRapor" name="format" value="pratinjau" data-siswa="{{ $s->id }}" title="Pratinjau rapor" aria-label="Pratinjau rapor {{ $s->nama }}"><x-ikon nama="mata" :ukuran="16" /></button>
                                @if ($kunci)
                                    <button class="tombol kecil tombol-ikon" type="submit" form="formCetakRapor" name="format" value="cetak" data-siswa="{{ $s->id }}" title="Cetak rapor" aria-label="Cetak rapor {{ $s->nama }}"><x-ikon nama="cetak" :ukuran="16" /></button>
                                    <button class="tombol kecil garis tombol-ikon" type="submit" form="formCetakRapor" name="format" value="word" data-siswa="{{ $s->id }}" title="Unduh Word" aria-label="Unduh Word {{ $s->nama }}"><x-ikon nama="berkas" :ukuran="16" /></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="kosong">Belum ada siswa aktif di kelas ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @unless ($final)
        <button class="tombol-simpan" type="submit" @disabled(count($baris) === 0)><x-ikon nama="centang" :ukuran="18" />Simpan Data Kelas</button>
    @endunless
</form>

<div class="db-panel" style="margin-top:20px">
    <header><h3>Finalisasi Rapor</h3><small>{{ $final ? 'Final' : 'Belum final' }}</small></header>
    <div class="isi-panel">
    @if ($final)
        <p class="sub">Rapor final, data tidak dapat diubah.</p>
        @if ($admin)
            <form method="POST" action="{{ route('staf.wali.buka-final', [$kelas, 'periode' => $periode->id]) }}" class="aksi-form" onsubmit="return confirm('Buka final rapor kelas ini? Nilai dapat diubah kembali dan tindakan ini dicatat.')">
                @csrf
                <input type="text" name="alasan" required minlength="5" maxlength="300" placeholder="Alasan membuka final" aria-label="Alasan membuka final" style="max-width:360px">
                <button class="tombol kecil garis" type="submit">Buka final</button>
            </form>
        @endif
    @elseif (count($hambatan) > 0)
        <p class="sub">Belum bisa difinalkan:</p>
        <ul class="sub" style="margin:6px 0 0 18px">
            @foreach ($hambatan as $h) <li>{{ $h }}</li> @endforeach
        </ul>
    @else
        <form method="POST" action="{{ route('staf.wali.finalkan', [$kelas, 'periode' => $periode->id]) }}" onsubmit="return confirm('Finalkan rapor kelas {{ $kelas->nama }}? Nilai, absensi, dan catatan tidak dapat diubah lagi.')">
            @csrf <button class="tombol kecil" type="submit">Finalkan rapor kelas</button>
        </form>
    @endif
    </div>
</div>
@endsection
