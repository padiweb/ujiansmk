@extends('layouts.staf')
@section('judul', 'Koreksi: '.$j->nama)

@section('konten')
<div class="pita-info">
    <div class="pita-atas">
        <a class="tombol-pita" href="{{ route('staf.koreksi.index') }}"><x-ikon nama="panah-kiri" :ukuran="16" />Kembali</a>
    </div>
    <div class="pita-isi">
        <div><small>Jadwal</small><b>{{ $j->nama }}</b></div>
        <div><small>Mapel</small><b>{{ $j->mapel->nama }}</b></div>
        <div><small>Kelas</small><b>{{ $kelasPilihan }}</b></div>
        <div><small>Bobot PG</small><b>{{ $j->bobot_pg }}%</b></div>
        <div><small>Bobot Esai</small><b>{{ $j->bobotEsai() }}%</b></div>
    </div>
</div>

<div class="db-panel">
    <header><h3>Soal Esai</h3><small>{{ count($soal) }} soal</small></header>
    <div class="gulir">
        <table class="tabel tabel-baris">
            <thead><tr><th class="no">No</th><th>Soal esai</th><th>Bobot</th><th>Kemajuan</th><th>Kosong</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($soal as $s)
                    <tr>
                        <td class="no">{{ $loop->iteration }}</td>
                        <td style="max-width:460px">{{ \Illuminate\Support\Str::limit(strip_tags($s['soal']->pertanyaan), 140) }}</td>
                        <td>{{ rtrim(rtrim(number_format($s['bobot'], 2, ',', ''), '0'), ',') }}</td>
                        <td>
                            <strong>{{ $s['dinilai'] }}/{{ $s['total'] }}</strong> dinilai
                            <div class="mon-bar"><span style="width:{{ $s['total'] ? round($s['dinilai'] / $s['total'] * 100) : 0 }}%"></span></div>
                        </td>
                        <td>{{ $s['kosong'] }}</td>
                        <td class="kanan aksi-baris"><a href="{{ route('staf.koreksi.soal', array_filter([$j, $s['soal'], 'kelas' => $kelasId])) }}">{{ $s['total'] ? 'Koreksi' : 'Lihat' }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="kosong">Jadwal ini tidak memiliki soal esai.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
