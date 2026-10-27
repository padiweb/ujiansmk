@extends('layouts.staf')
@section('judul', 'Wali Kelas '.$kelas->nama)

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">{{ $kelas->nama }}</h1>
        <p class="sub">{{ $periode->label() }} · Wali kelas: {{ $kelas->waliKelas?->nama ?? '—' }} · {{ count($baris) }} siswa @if ($final)· <span class="lencana pucat">Final</span>@endif</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil garis" href="{{ route('staf.wali.index', ['periode' => $periode->id]) }}"><x-ikon nama="panah-kiri" :ukuran="16" />Daftar</a>
        @unless ($final)
            <form method="POST" action="{{ route('staf.wali.absensi', [$kelas, 'periode' => $periode->id]) }}" onsubmit="return confirm('Ambil absensi dan nilai ekskul semua siswa dari EduWeb? Isian sakit, izin, dan alpa yang sudah Anda ubah akan tertimpa.')">
                @csrf <button class="tombol kecil garis" type="submit">Ambil absensi &amp; ekskul dari EduWeb</button>
            </form>
        @endunless
    </div>
</div>

@if ($sudahPindah > 0)
    <div class="pesan-salah catatan" role="status"><x-ikon nama="info" :ukuran="18" /><span>{{ $sudahPindah }} siswa sudah dinaikkan, ditinggalkan, atau diluluskan dari kelas ini di EduWeb. Rapor kelas ini belum final; selesaikan dan finalkan segera.</span></div>
@endif

<div class="kartu tanpa-padding" style="margin-bottom:16px">
    <div class="gulir">
        <table class="tabel">
            <thead><tr><th>Mata pelajaran</th><th>Guru</th><th>Nilai lengkap</th><th>Status</th></tr></thead>
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
                        <th>No</th><th>Nama</th><th class="tengah">Mapel lengkap</th><th class="tengah">Rata-rata</th>
                        <th class="tengah">Sakit</th><th class="tengah">Izin</th><th class="tengah">Alpa</th><th>Catatan wali kelas</th><th>Keputusan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($baris as $i => $b)
                        @php $s = $b['siswa']; $r = $b['rapor']; $kunci = $b['final']; @endphp
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td><strong>{{ $s->nama }}</strong><small class="bantuan" style="margin:0">{{ $s->nisn }}</small></td>
                            <td class="tengah">{{ $b['mapel_lengkap'] }}/{{ $b['mapel_total'] }}</td>
                            <td class="tengah">{{ $b['rata'] ?? '—' }}</td>
                            @foreach (['sakit', 'izin', 'alpa'] as $k)
                                <td class="tengah">
                                    <input type="number" min="0" max="999" name="rapor[{{ $s->id }}][{{ $k }}]" value="{{ $r?->{$k} ?? 0 }}" @disabled($kunci) aria-label="{{ ucfirst($k) }} {{ $s->nama }}">
                                    @if ($k === 'sakit' && $r?->sumber_absensi)
                                        <small class="bantuan" style="margin:2px 0 0">{{ $r->sumber_absensi === 'eduweb' ? 'dari EduWeb' : 'diubah' }}</small>
                                    @endif
                                </td>
                            @endforeach
                            <td><input type="text" maxlength="500" name="rapor[{{ $s->id }}][catatan]" value="{{ $r?->catatan_wali }}" @disabled($kunci) aria-label="Catatan {{ $s->nama }}"></td>
                            <td>
                                <select name="rapor[{{ $s->id }}][keputusan]" @disabled($kunci) aria-label="Keputusan {{ $s->nama }}">
                                    <option value="">—</option>
                                    @foreach ($keputusan as $kode => $label)
                                        <option value="{{ $kode }}" @selected($r?->keputusan === $kode)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="kosong">Belum ada siswa aktif di kelas ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="aksi-form" style="margin-top:14px">
        <button class="tombol kecil" type="submit" @disabled(count($baris) === 0 || $final)>Simpan</button>
    </div>
</form>

@if (count($baris) > 0)
    <div class="kartu" style="margin-top:20px">
        <h2 class="judul-kartu">Cetak rapor</h2>
        <p class="sub">Pratinjau bisa dilihat kapan saja untuk mengecek isi rapor; nilai yang belum diisi guru tertulis 0. Cetak dan Word hanya untuk siswa yang rapornya sudah final. Tanggal tanda tangan boleh diubah sesuai tanggal penyerahan rapor.</p>
        <form id="formCetakRapor" method="GET" action="{{ route('staf.wali.cetak', $kelas) }}" target="_blank" class="aksi-form" style="flex-wrap:wrap;align-items:flex-end;gap:10px">
            <input type="hidden" name="periode" value="{{ $periode->id }}">
            <label>Siswa
                <select name="siswa">
                    <option value="">Semua siswa</option>
                    @foreach ($baris as $b) <option value="{{ $b['siswa']->id }}">{{ $b['siswa']->nama }}@unless ($b['final']) (belum final)@endunless</option> @endforeach
                </select>
            </label>
            <label>Tanggal tanda tangan
                <input type="date" name="tgl" value="{{ now()->format('Y-m-d') }}" required>
            </label>
            <button class="tombol kecil garis" type="submit" name="format" value="pratinjau">Pratinjau</button>
            @if (count($siswaFinal) > 0)
                <button class="tombol kecil" type="submit" name="format" value="cetak">Cetak</button>
                <button class="tombol kecil garis" type="submit" name="format" value="word">Unduh Word</button>
            @endif
        </form>
        <div class="pesan-salah" id="pesanCetakRapor" role="alert" hidden style="margin-top:10px"></div>
    </div>
    @push('skrip')
        <script src="{{ asset('js/cetak-rapor.js') }}?v={{ filemtime(public_path('js/cetak-rapor.js')) }}" defer></script>
    @endpush
@endif

<div class="kartu" style="margin-top:20px">
    <h2 class="judul-kartu">Finalisasi rapor</h2>
    @if ($final)
        <p class="sub">Rapor kelas ini sudah final: nilai, absensi, catatan, dan keputusan tidak dapat diubah.</p>
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
        <p class="sub">Semua nilai sudah lengkap. Setelah difinalkan, nilai dan data rapor kelas ini tidak dapat diubah.</p>
        <form method="POST" action="{{ route('staf.wali.finalkan', [$kelas, 'periode' => $periode->id]) }}" onsubmit="return confirm('Finalkan rapor kelas {{ $kelas->nama }}? Nilai, absensi, dan catatan tidak dapat diubah lagi.')">
            @csrf <button class="tombol" type="submit">Finalkan rapor kelas</button>
        </form>
    @endif
</div>
@endsection
