@extends('layouts.staf')
@section('judul', 'Koreksi: '.$j->nama)

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">{{ $j->nama }}</h1>
        <p class="sub">{{ $j->mapel->nama }} · Kelas {{ $kelasPilihan }} · bobot PG {{ $j->bobot_pg }}% · esai {{ $j->bobotEsai() }}%</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil garis" href="{{ route('staf.koreksi.index') }}"><x-ikon nama="panah-kiri" :ukuran="16" />Daftar</a>
    </div>
</div>

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel">
            <thead><tr><th>Soal esai</th><th>Bobot</th><th>Kemajuan</th><th>Kosong</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($soal as $s)
                    <tr>
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
                    <tr><td colspan="5" class="kosong">Jadwal ini tidak memiliki soal esai.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
