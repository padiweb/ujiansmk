@extends('layouts.staf')
@section('judul', 'Nilai US: '.$mapel->nama)

@section('konten')
<form method="POST" action="{{ route('staf.us.simpan', [$mapel, $kelas]) }}">
    @csrf
    <div class="pita-info">
        <div class="pita-atas">
            <a class="tombol-pita" href="{{ route('staf.us.index') }}"><x-ikon nama="panah-kiri" :ukuran="16" />Kembali</a>
        </div>
        <div class="pita-isi">
            <div><small>Mapel</small><b>{{ $mapel->nama }}</b></div>
            <div><small>Kelas</small><b>{{ $kelas->nama }}</b></div>
            <div><small>Semester US</small><b>{{ $periodeUs?->label() ?? '–' }}</b></div>
            <div><small>KKM</small><b><span class="pita-kkm">{{ $mapel->kkm }}</span></b></div>
            <div><small>Siswa</small><b>{{ count($baris) }}</b></div>
        </div>
    </div>

    <label class="centang kartu" style="padding:12px 16px">
        <input type="checkbox" name="masuk_xii2" value="1" @checked($masuk_xii2)>
        <span>Jadikan juga nilai semester XII-2 di transkrip bila semester itu belum bernilai</span>
    </label>

    <div class="kartu tanpa-padding" style="margin-top:12px">
        <div class="gulir">
            <table class="tabel tabel-nilai tabel-kartu">
                <thead>
                    <tr>
                        <th class="no">No</th><th class="nama">Nama Siswa</th><th class="tengah grup-np">Nilai CBT</th>
                        <th class="tengah grup-nk">Nilai US (manual)</th><th class="tengah">Dipakai</th><th class="tengah">Status</th><th class="kolom-catatan">Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($baris as $i => $b)
                        <tr>
                            <td class="no">{{ $i + 1 }}</td>
                            <td class="nama"><strong>{{ $b['siswa']->nama }}</strong><small class="sub-baris">{{ $b['siswa']->nisn }}</small></td>
                            <td class="sel-hasil np">{{ $b['cbt'] !== null ? round($b['cbt'], 1) : '–' }}</td>
                            <td class="sel-nilai"><input type="text" inputmode="decimal" name="nilai[{{ $b['siswa']->id }}][nilai]" value="{{ $b['manual'] }}" placeholder="{{ $b['cbt'] !== null ? round($b['cbt']) : '' }}" aria-label="Nilai US {{ $b['siswa']->nama }}"></td>
                            <td class="sel-hasil na">{{ $b['pakai'] !== null ? round($b['pakai'], 1) : '–' }}</td>
                            <td class="sel-hasil">{{ $b['lulus'] === null ? '–' : ($b['lulus'] ? 'Lulus' : 'Remidi') }}</td>
                            <td class="kolom-catatan"><input type="text" maxlength="255" name="nilai[{{ $b['siswa']->id }}][keterangan]" value="{{ $b['keterangan'] }}" placeholder="Opsional…" aria-label="Keterangan {{ $b['siswa']->nama }}"></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="kosong">Belum ada siswa di kelas ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if (count($baris) > 0)
        <button class="tombol-simpan" type="submit"><x-ikon nama="centang" :ukuran="18" />Simpan Nilai</button>
    @endif
</form>
@endsection
