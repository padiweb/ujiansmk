@extends('layouts.staf')
@section('judul', 'Penyedia Otomatis')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Penyedia Otomatis</h1>
        <p class="sub">Layanan penyusun soal otomatis. Hanya satu penyedia yang aktif dalam satu waktu.</p>
    </div>
    <div class="aksi-halaman">
        <a class="tombol kecil" href="{{ route('staf.penyedia.create') }}"><x-ikon nama="tambah" :ukuran="16" />Tambah penyedia</a>
    </div>
</div>

@if ($penyedia->where('aktif', true)->isEmpty())
    <div class="pesan-salah"><x-ikon nama="peringatan" :ukuran="18" /><span>Belum ada penyedia aktif, sehingga fitur "Susun otomatis" belum bisa dipakai guru.</span></div>
@endif

<div class="kartu tanpa-padding">
    <div class="gulir">
        <table class="tabel">
            <thead><tr><th>Nama</th><th>Jenis</th><th>Model</th><th>Kunci</th><th>Status</th><th class="kanan">Aksi</th></tr></thead>
            <tbody>
                @forelse ($penyedia as $p)
                    <tr>
                        <td><strong>{{ $p->nama }}</strong></td>
                        <td>{{ $p->namaDriver() }}</td>
                        <td>{{ $p->model }}</td>
                        <td><code>{{ $p->petunjukKunci() }}</code></td>
                        <td>@if ($p->aktif) <span class="lencana">Aktif</span> @else <span class="lencana pucat">Nonaktif</span> @endif</td>
                        <td class="kanan aksi-baris">
                            @if ($p->aktif)
                                <form method="POST" action="{{ route('staf.penyedia.nonaktifkan', $p) }}">@csrf<button class="tautan" type="submit">Nonaktifkan</button></form>
                            @else
                                <form method="POST" action="{{ route('staf.penyedia.aktifkan', $p) }}">@csrf<button class="tautan" type="submit">Aktifkan</button></form>
                            @endif
                            <a href="{{ route('staf.penyedia.edit', $p) }}">Ubah</a>
                            <form method="POST" action="{{ route('staf.penyedia.destroy', $p) }}" onsubmit="return confirm('Hapus penyedia ini beserta kuncinya?')">
                                @csrf @method('DELETE')
                                <button class="tautan" type="submit">Hapus</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="kosong">Belum ada penyedia. Klik "Tambah penyedia" dan masukkan kunci dari Google AI Studio, OpenAI, atau Anthropic.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="kartu">
    <h2 class="judul-kartu">Pemakaian terbaru</h2>
    <p class="sub" style="margin-top:0">Batas {{ $batas }} permintaan per pengguna per hari. Satu permintaan = satu kali menekan "Susun".</p>
</div>
<div class="kartu tanpa-padding" style="margin-top:12px">
    <div class="gulir">
        <table class="tabel">
            <thead><tr><th>Waktu</th><th>Pengguna</th><th>Penyedia</th><th>Diminta</th><th>Jadi</th><th>Hasil</th></tr></thead>
            <tbody>
                @forelse ($log as $l)
                    <tr>
                        <td>{{ $l->created_at->format('d/m/Y H:i') }}</td>
                        <td>{{ $l->user->nama }}</td>
                        <td>{{ $l->penyedia?->nama ?? '—' }}</td>
                        <td>{{ $l->jumlah_diminta }}</td>
                        <td>{{ $l->jumlah_dihasilkan }}</td>
                        <td>
                            @if ($l->status === 'berhasil') <span class="lencana">Berhasil</span>
                            @else <span class="lencana kuning" title="{{ $l->pesan }}">Gagal</span> <small class="bantuan" style="display:inline">{{ $l->pesan }}</small> @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="kosong">Belum ada pemakaian.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
