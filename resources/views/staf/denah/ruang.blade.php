@extends('layouts.staf')
@section('judul', 'Detail Ruang: '.$ruang->nama)

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Detail Ruang: {{ $ruang->nama }}</h1>
        <p class="sub">{{ $baris->count() }} dari {{ $dr->kapasitas() }} siswa</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil garis" href="{{ route('staf.ruang.index') }}"><x-ikon nama="panah-kiri" :ukuran="16" />Kembali</a>
    </div>
</div>

<div class="kartu">
    <strong>Komposisi:</strong>
    @forelse ($komposisi as $kelas => $n) <span class="lencana">{{ $kelas }} · {{ $n }}</span> @empty <span class="sub">Belum ada siswa di ruang ini.</span> @endforelse
</div>

<div class="kartu">
    <div class="aksi-form" style="margin:0;flex-wrap:wrap">
        <a class="tombol kecil" href="{{ route('staf.ruang.no-meja', $ruang) }}" target="_blank" rel="noopener">Cetak No. Meja</a>
        <a class="tombol kecil garis" href="{{ route('staf.ruang.absen', $ruang) }}"><x-ikon nama="unduh" :ukuran="16" />Download Absen</a>
        <form method="POST" action="{{ route('staf.ruang.urut-kelas', $ruang) }}">@csrf <button class="tombol kecil garis" type="submit">Urutkan per kelas</button></form>
    </div>
    <p class="sub" style="margin:10px 0 0">Seret baris untuk mengatur urutan nomor meja. <span id="drPesan" aria-live="polite"></span></p>
</div>

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel" id="drTabel" data-url="{{ route('staf.ruang.urutan', $ruang) }}" data-token="{{ csrf_token() }}" data-per="{{ max(1, (int) $dr->siswa_per_meja) }}">
            <thead><tr><th style="width:70px">No Meja</th><th style="width:36px"></th><th>Nama Siswa</th><th>Kelas</th><th>Tingkat</th><th>Pindah ke ruang lain</th></tr></thead>
            <tbody id="drIsi">
                @forelse ($baris as $p)
                    <tr data-id="{{ $p->siswa_id }}" draggable="true">
                        <td class="dr-meja"><strong>{{ $p->meja }}</strong></td>
                        <td class="dr-pegang" title="Seret" aria-hidden="true">⠿</td>
                        <td><strong>{{ $p->siswa->nama }}</strong><small class="bantuan" style="margin:0">{{ $p->siswa->nisn }}</small></td>
                        <td>{{ $kelasSiswa[$p->siswa_id]->nama ?? '—' }}</td>
                        <td>{{ $kelasSiswa[$p->siswa_id]->tingkat ?? '—' }}</td>
                        <td>
                            <form method="POST" action="{{ route('staf.ruang.pindah', $p->siswa) }}" class="aksi-form" style="margin:0;flex-wrap:nowrap">
                                @csrf
                                <select name="ruang_id" aria-label="Pindahkan {{ $p->siswa->nama }}" required>
                                    <option value="">Pilih ruang…</option>
                                    @foreach ($ruangLain as $x) <option value="{{ $x->id }}">{{ $x->nama }}</option> @endforeach
                                    <option value="keluar">Keluarkan</option>
                                </select>
                                <button class="tombol kecil garis" type="submit">Pindah</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="kosong">Ruang ini kosong.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('skrip')
<script src="{{ asset('js/denah.js') }}?v={{ filemtime(public_path('js/denah.js')) }}" defer></script>
@endpush
