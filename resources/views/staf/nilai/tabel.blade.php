@extends('layouts.staf')
@section('judul', 'Nilai: '.$mapel->nama.' '.$kelas->nama)

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">{{ $mapel->nama }} · {{ $kelas->nama }}</h1>
        <p class="sub">{{ $periode->label() }} · KKM {{ $mapel->kkm }} · {{ count($baris) }} siswa</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil garis" href="{{ route('staf.nilai.index', ['periode' => $periode->id]) }}"><x-ikon nama="panah-kiri" :ukuran="16" />Daftar</a>
        @if (in_array('ujian', $aktif, true) || in_array('harian', $aktif, true))
            <form method="POST" action="{{ route('staf.nilai.ambil-ulang', [$mapel, $kelas, 'periode' => $periode->id]) }}" onsubmit="return confirm('Ambil ulang nilai Ujian (hasil terkunci) dan Harian (tugas EduWeb) semua siswa? Nilai yang Anda ubah akan tertimpa.')">
                @csrf <button class="tombol kecil garis" type="submit">Ambil ulang nilai otomatis</button>
            </form>
        @endif
    </div>
</div>

<form method="POST" action="{{ route('staf.nilai.simpan', [$mapel, $kelas, 'periode' => $periode->id]) }}" id="formNilai"
      data-bobot='@json($bobot)' data-skala='@json($skala)' data-kkm="{{ $mapel->kkm }}" data-aktif='@json($aktif)'>
    @csrf
    <div class="kartu tanpa-padding">
        <div class="gulir">
            <table class="tabel tabel-nilai">
                <thead>
                    <tr>
                        <th>No</th><th>Nama</th><th class="tengah" title="Pintas: isi NP langsung, komponen Pengetahuan terisi sama">Isi NP</th><th class="tengah" title="Pintas: isi NK langsung, komponen Keterampilan terisi sama">Isi NK</th>
                        @foreach ($aktif as $k) <th class="tengah">{{ $label[$k] }} <small>{{ $bobot[$k] }}%</small></th> @endforeach
                        <th class="tengah">NP</th><th class="tengah">NK</th><th class="tengah">NA</th><th class="tengah">Predikat</th><th>Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($baris as $i => $b)
                        @php $s = $b['siswa']; $kunci = $b['final']; @endphp
                        <tr data-baris>
                            <td>{{ $i + 1 }}</td>
                            <td><strong>{{ $s->nama }}</strong><small class="bantuan" style="margin:0">{{ $s->nisn }}</small></td>
                            @foreach (['np' => array_intersect(['ujian', 'harian', 'tugas_akhir'], $aktif), 'nk' => array_intersect(['praktik', 'adab'], $aktif)] as $pintas => $komponen)
                                <td class="tengah"><input type="text" inputmode="decimal" data-pintas="{{ $pintas }}" autocomplete="off" placeholder="—" @disabled($kunci || count($komponen) === 0) aria-label="Isi {{ strtoupper($pintas) }} langsung {{ $s->nama }}"></td>
                            @endforeach
                            @foreach ($aktif as $k)
                                <td class="tengah">
                                    <input type="text" inputmode="decimal" name="nilai[{{ $s->id }}][{{ $k }}]" data-k="{{ $k }}" autocomplete="off"
                                           value="{{ $b['nilai'][$k] !== null ? rtrim(rtrim(number_format($b['nilai'][$k], 2, '.', ''), '0'), '.') : '' }}" @disabled($kunci) aria-label="{{ $label[$k] }} {{ $s->nama }}">
                                    @if ($k === 'ujian' && $b['auto_ujian'] !== null)
                                        <small class="bantuan" style="margin:2px 0 0">{{ $b['ujian_sumber'] === 'otomatis' ? 'dari ujian' : 'diubah (ujian: '.rtrim(rtrim(number_format($b['auto_ujian'], 2, '.', ''), '0'), '.').')' }}</small>
                                    @endif
                                    @if ($k === 'harian' && $b['harian_sumber'] !== null)
                                        <small class="bantuan" style="margin:2px 0 0">{{ $b['harian_sumber'] === 'otomatis' ? 'dari tugas EduWeb' : 'diubah guru' }}</small>
                                    @endif
                                </td>
                            @endforeach
                            <td class="tengah" data-hasil="np">{{ $b['np'] }}</td>
                            <td class="tengah" data-hasil="nk">{{ $b['nk'] }}</td>
                            <td class="tengah"><strong data-hasil="na">{{ $b['na'] }}</strong></td>
                            <td class="tengah" data-hasil="predikat">{{ $b['predikat'] }}</td>
                            <td><input type="text" maxlength="500" name="nilai[{{ $s->id }}][catatan]" value="{{ $b['catatan'] }}" @disabled($kunci) aria-label="Catatan {{ $s->nama }}"></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($aktif) + 9 }}" class="kosong">Belum ada siswa aktif di kelas ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="aksi-form" style="margin-top:14px">
        <button class="tombol kecil" type="submit" @disabled(count($baris) === 0)>Simpan nilai</button>
        <span class="sub">Tempel dari Excel: klik kolom pertama lalu tekan Ctrl+V.</span>
    </div>
</form>
@endsection

@push('skrip')
<script src="{{ asset('js/nilai.js') }}?v={{ filemtime(public_path('js/nilai.js')) }}" defer></script>
@endpush
