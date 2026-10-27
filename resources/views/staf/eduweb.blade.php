@extends('layouts.staf')
@section('judul', 'Sinkron EduWeb')

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Sinkron EduWeb</h1>
        <p class="sub">Data tahun ajaran, kelas, siswa, guru, dan mata pelajaran diambil dari EduWeb. Biasanya masuk otomatis; tombol di bawah untuk memaksa sekarang.</p>
    </div>
</div>

@if (! $terkonfigurasi)
    <div class="pesan-salah" role="alert"><x-ikon nama="peringatan" :ukuran="18" /><span>Penghubung ke EduWeb belum diatur di server. Hubungi pengelola aplikasi.</span></div>
@endif

@if (session('sandi_token'))
    <div class="pesan-salah catatan" role="status"><x-ikon nama="info" :ukuran="18" /><span>Ada akun guru baru. Kata sandi sementara hanya bisa diunduh <strong>sekali</strong>:
        <a href="{{ route('staf.eduweb.sandi', session('sandi_token')) }}"><strong>unduh daftar akun</strong></a>.</span></div>
@endif

<div class="kartu">
    <h2 class="judul-kartu">Status</h2>
    <dl class="rincian">
        <div><dt>Sinkron penuh terakhir</dt><dd>{{ $p->eduweb_sinkron_penuh_pada?->format('d/m/Y H:i') ?? 'Belum pernah' }}</dd></div>
        <div><dt>Pengecekan otomatis terakhir</dt><dd>{{ $p->eduweb_cek_terakhir?->format('d/m/Y H:i') ?? '—' }}</dd></div>
        <div><dt>Sinyal terakhir dari EduWeb</dt><dd>{{ $p->eduweb_webhook_terakhir?->format('d/m/Y H:i') ?? 'Belum pernah' }}</dd></div>
        <div><dt>Perubahan otomatis</dt><dd>
            @if ($ujianBerlangsung) <span class="lencana kuning">Ditahan</span> Ada ujian yang sedang atau akan segera berlangsung; perubahan dari EduWeb diterapkan setelah ujian selesai.
            @elseif ($p->eduweb_kejadian_tertahan) <span class="lencana kuning">Menunggu</span> Ada perubahan yang tertahan; akan diterapkan otomatis.
            @else <span class="lencana pucat">Normal</span> @endif
        </dd></div>
    </dl>
    <form method="POST" action="{{ route('staf.eduweb.sekarang') }}" class="aksi-form" onsubmit="return confirm('Sinkron dengan EduWeb sekarang? Data yang cocok akan diperbarui sesuai EduWeb.')">
        @csrf
        <button class="tombol" type="submit" @disabled(! $terkonfigurasi || $ujianBerlangsung)>Sinkron sekarang</button>
    </form>
</div>

<div class="kartu tanpa-padding" style="margin-top:16px">
    <h2 class="judul-kartu" style="padding:16px 20px 0">Riwayat terbaru</h2>
    <div class="gulir">
        <table class="tabel">
            <thead><tr><th>Waktu</th><th>Jenis</th><th>Status</th><th>Keterangan</th></tr></thead>
            <tbody>
                @forelse ($log as $l)
                    <tr>
                        <td>{{ $l->created_at->format('d/m/Y H:i:s') }}</td>
                        <td>{{ $l->jenis === 'penuh' ? 'Sinkron penuh' : 'Perubahan' }}</td>
                        <td><span class="lencana {{ $l->status === 'berhasil' ? '' : 'kuning' }}">{{ $l->status === 'berhasil' ? 'Berhasil' : 'Gagal' }}</span></td>
                        <td>{{ $l->galat ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="kosong">Belum ada sinkron.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
