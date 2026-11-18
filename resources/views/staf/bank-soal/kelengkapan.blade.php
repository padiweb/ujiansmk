@extends('layouts.staf')
@section('judul', 'Kelengkapan Bank Soal')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Kelengkapan Bank Soal</h1>
        <p class="sub">
            @if (! $tahun)Belum ada tahun ajaran aktif.
            @elseif (! $kegiatan)Belum ada kegiatan ujian.
            @else{{ $kegiatan->nama }} · {{ number_format($total, 0, ',', '.') }} baris @endif
        </p>
    </div>
    <a class="tombol kecil garis" href="{{ route('staf.bank-soal.index') }}">Kembali ke Bank Soal</a>
</div>

<form class="bar-saring" method="GET" action="{{ route('staf.bank-soal.kelengkapan') }}">
    <div class="bar-isi">
        <label for="f-kegiatan">Kegiatan ujian</label>
        <select id="f-kegiatan" name="kegiatan" onchange="this.form.submit()">
            @foreach ($daftarKegiatan as $k)
                <option value="{{ $k->id }}" @selected($kegiatan && $kegiatan->id === $k->id)>{{ $k->labelLengkap() }}</option>
            @endforeach
        </select>
    </div>
</form>

<div class="bank-chip-baris">
    @foreach ($labelStatus as $kunci => $label)
        <a class="bank-chip" href="{{ route('staf.bank-soal.kelengkapan', ['kegiatan' => $kegiatan?->id, 'status' => $kunci]) }}">{{ $label }} : <strong>{{ $ringkas[$kunci] }}</strong></a>
    @endforeach
</div>

<form class="bar-saring" method="GET" action="{{ route('staf.bank-soal.kelengkapan') }}">
    <input type="hidden" name="kegiatan" value="{{ $kegiatan?->id }}">
    <div class="bar-isi">
        <label for="f-status">Status</label>
        <select id="f-status" name="status" onchange="this.form.submit()">
            <option value="">Semua</option>
            @foreach ($labelStatus as $kunci => $label)
                <option value="{{ $kunci }}" @selected($status === $kunci)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="bar-isi">
        <label for="f-tingkat">Tingkat</label>
        <select id="f-tingkat" name="tingkat" onchange="this.form.submit()">
            <option value="">Semua</option>
            @foreach (\App\Models\Kelas::TINGKAT as $t)
                <option value="{{ $t }}" @selected($tingkat === $t)>Kelas {{ $t }}</option>
            @endforeach
        </select>
    </div>
    <div class="bar-isi bar-cari">
        <label for="f-q">Cari</label>
        <input id="f-q" type="search" name="q" value="{{ $q }}" placeholder="Nama guru atau mata pelajaran…" autocomplete="off">
    </div>
    <button class="tombol kecil" type="submit">Cari</button>
    @if ($status !== '' || $tingkat !== '' || $q !== '')<a class="tautan" href="{{ route('staf.bank-soal.kelengkapan', ['kegiatan' => $kegiatan?->id]) }}">Atur ulang</a>@endif
    <span class="bar-total">Tampil: <b>{{ number_format($baris->count(), 0, ',', '.') }}</b> baris</span>
</form>

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel tabel-daftar tabel-baris">
            <thead>
                <tr><th class="no">No</th><th>Guru</th><th>Mata pelajaran</th><th>Tingkat</th><th>Kelas yang diajar</th><th>Status</th><th class="kanan">Jumlah soal</th></tr>
            </thead>
            <tbody>
                @forelse ($baris as $r)
                    <tr>
                        <td class="no">{{ $loop->iteration }}</td>
                        <td><strong>{{ $r['guru'] }}</strong></td>
                        <td>{{ $r['mapel'] }}</td>
                        <td>{{ $r['tingkat'] }}</td>
                        <td>{{ implode(', ', $r['kelas']) }}</td>
                        <td>
                            @if (in_array($r['status'], ['belum', 'kosong'], true))<span class="lencana kuning">{{ $labelStatus[$r['status']] }}</span>
                            @elseif ($r['status'] === 'lain')<span class="lencana pucat">{{ $labelStatus[$r['status']] }}</span>
                            @else<span class="lencana">{{ $labelStatus[$r['status']] }}</span>@endif
                        </td>
                        <td class="kanan">{{ $r['status'] === 'belum' || $r['status'] === 'kosong' ? '—' : number_format($r['soal'], 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="kosong">Tidak ada data yang sesuai.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
