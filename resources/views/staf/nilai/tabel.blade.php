@extends('layouts.staf')
@section('judul', 'Nilai: '.$mapel->nama.' '.$kelas->nama)

@section('konten')
@php
    $np = array_values(array_intersect(['ujian', 'harian', 'tugas_akhir'], $aktif));
    $nk = array_values(array_intersect(['praktik', 'adab'], $aktif));
    $angka = fn ($v) => $v !== null ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : '';
@endphp
<div class="pita-info">
    <div class="pita-atas">
        <a class="tombol-pita" href="{{ route('staf.nilai.index', ['periode' => $periode->id]) }}"><x-ikon nama="panah-kiri" :ukuran="16" />Kembali</a>
        @if (in_array('ujian', $aktif, true) || in_array('harian', $aktif, true))
            <form method="POST" action="{{ route('staf.nilai.ambil-ulang', [$mapel, $kelas, 'periode' => $periode->id]) }}" onsubmit="return confirm('Ambil ulang nilai Ujian (hasil terkunci) dan Harian (tugas EduWeb) semua siswa? Nilai yang Anda ubah akan tertimpa.')">
                @csrf <button class="tombol-pita" type="submit">Ambil ulang nilai otomatis</button>
            </form>
        @endif
    </div>
    <div class="pita-isi">
        <div><small>Mapel</small><b>{{ $mapel->nama }}</b></div>
        <div><small>Kelas</small><b>{{ $kelas->nama }}</b></div>
        <div><small>Semester</small><b>{{ $periode->label() }}</b></div>
        <div><small>KKM</small><b><span class="pita-kkm">{{ $mapel->kkm }}</span></b></div>
        <div><small>Siswa</small><b>{{ count($baris) }}</b></div>
    </div>
</div>

<form method="POST" action="{{ route('staf.nilai.simpan', [$mapel, $kelas, 'periode' => $periode->id]) }}" id="formNilai"
      data-bobot='@json($bobot)' data-skala='@json($skala)' data-kkm="{{ $mapel->kkm }}" data-aktif='@json($aktif)'>
    @csrf
    <div class="kartu tanpa-padding">
        <div class="gulir gulir-nilai">
            <table class="tabel tabel-nilai">
                <thead>
                    <tr>
                        <th rowspan="2" class="lekat no">No</th>
                        <th rowspan="2" class="lekat nama">Nama Siswa</th>
                        <th rowspan="2" class="tengah" title="Pintas: isi NP langsung, komponen Pengetahuan terisi sama">Input<br>NP</th>
                        <th rowspan="2" class="tengah" title="Pintas: isi NK langsung, komponen Keterampilan terisi sama">Input<br>NK</th>
                        @if (count($np))<th colspan="{{ count($np) + 1 }}" class="tengah grup-np">Komponen NP (Pengetahuan)</th>@endif
                        @if (count($nk))<th colspan="{{ count($nk) + 1 }}" class="tengah grup-nk">Komponen NK (Keterampilan)</th>@endif
                        <th rowspan="2" class="tengah">KKM</th>
                        <th rowspan="2" class="tengah grup-na">NA</th>
                        <th rowspan="2" class="tengah">Pred</th>
                        <th rowspan="2" class="tengah">Status</th>
                        <th rowspan="2" class="kolom-catatan">Catatan Guru</th>
                    </tr>
                    <tr>
                        @foreach ($np as $k) <th class="tengah grup-np">{{ $label[$k] }}<small>{{ $bobot[$k] }}%</small></th> @endforeach
                        @if (count($np))<th class="tengah grup-np hasil">NP</th>@endif
                        @foreach ($nk as $k) <th class="tengah grup-nk">{{ $label[$k] }}<small>{{ $bobot[$k] }}%</small></th> @endforeach
                        @if (count($nk))<th class="tengah grup-nk hasil">NK</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($baris as $i => $b)
                        @php $s = $b['siswa']; $kunci = $b['final']; @endphp
                        <tr data-baris>
                            <td class="lekat no">{{ $i + 1 }}</td>
                            <td class="lekat nama"><strong>{{ $s->nama }}</strong></td>
                            @foreach (['np' => $np, 'nk' => $nk] as $pintas => $komponen)
                                <td class="sel-pintas"><input type="text" inputmode="decimal" data-pintas="{{ $pintas }}" autocomplete="off" placeholder="—" @disabled($kunci || count($komponen) === 0) aria-label="Isi {{ strtoupper($pintas) }} langsung {{ $s->nama }}"></td>
                            @endforeach
                            @foreach (['np' => $np, 'nk' => $nk] as $hasil => $grup)
                                @foreach ($grup as $k)
                                    <td class="sel-nilai">
                                        <input type="text" inputmode="decimal" name="nilai[{{ $s->id }}][{{ $k }}]" data-k="{{ $k }}" autocomplete="off"
                                               value="{{ $angka($b['nilai'][$k]) }}" @disabled($kunci) aria-label="{{ $label[$k] }} {{ $s->nama }}"
                                               @if ($k === 'ujian' && $b['auto_ujian'] !== null) title="{{ $b['ujian_sumber'] === 'otomatis' ? 'dari ujian' : 'diubah (ujian: '.$angka($b['auto_ujian']).')' }}" @endif
                                               @if ($k === 'harian' && $b['harian_sumber'] !== null) title="{{ $b['harian_sumber'] === 'otomatis' ? 'dari tugas EduWeb' : 'diubah guru' }}" @endif>
                                    </td>
                                @endforeach
                                @if (count($grup))
                                    <td class="sel-hasil {{ $hasil }}" data-hasil="{{ $hasil }}">{{ $b[$hasil] }}</td>
                                @endif
                            @endforeach
                            <td class="sel-hasil redup">{{ $mapel->kkm }}</td>
                            <td class="sel-hasil na" data-hasil="na">{{ $b['na'] }}</td>
                            <td class="sel-hasil" data-hasil="predikat">{{ $b['predikat'] }}</td>
                            <td class="tengah"><span class="lencana pucat" data-hasil="status">–</span></td>
                            <td class="kolom-catatan"><input type="text" maxlength="500" name="nilai[{{ $s->id }}][catatan]" value="{{ $b['catatan'] }}" placeholder="Catatan..." @disabled($kunci) aria-label="Catatan {{ $s->nama }}"></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ 8 + count($aktif) + (count($np) ? 1 : 0) + (count($nk) ? 1 : 0) }}" class="kosong">Belum ada siswa aktif di kelas {{ $kelas->nama }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <p class="sub" style="margin-top:10px">Tempel dari Excel: klik kolom nilai pertama lalu tekan Ctrl+V. Kolom Ujian dan Harian dapat terisi otomatis dari hasil ujian dan tugas EduWeb.</p>
    <button class="tombol-simpan" type="submit" @disabled(count($baris) === 0)><x-ikon nama="centang" :ukuran="18" />Simpan Nilai</button>
</form>
@endsection

@push('skrip')
<script src="{{ asset('js/nilai.js') }}?v={{ filemtime(public_path('js/nilai.js')) }}" defer></script>
@endpush
