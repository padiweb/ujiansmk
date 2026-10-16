@extends('layouts.staf')
@section('judul', 'Sinkron EduWeb')

@php
    $nama = ['tahun_ajaran' => 'Tahun ajaran', 'periode' => 'Semester', 'jurusan' => 'Jurusan', 'kelas' => 'Kelas', 'mapel' => 'Mata pelajaran', 'guru' => 'Guru', 'siswa' => 'Siswa'];
    $hasil = session('hasil');
@endphp

@section('konten')
<div class="kepala-halaman">
    <div>
        <h1 class="judul-halaman">Sinkron EduWeb</h1>
        <p class="sub">Tarik data siswa, kelas, mapel, dan guru dari EduWeb. Aplikasi ini hanya membaca dari EduWeb.</p>
    </div>
</div>

@if (! $terkonfigurasi)
    <div class="pesan-salah" role="alert"><x-ikon nama="peringatan" :ukuran="18" /><span>Penghubung belum diatur. Isi <code>EDUWEB_URL</code> dan <code>EDUWEB_KUNCI</code> di berkas .env server, lalu muat ulang halaman ini.</span></div>
@endif

<div class="kartu">
    <h2 class="judul-kartu">Status</h2>
    <dl class="rincian">
        <div><dt>Penghubung</dt><dd>{{ $terkonfigurasi ? 'Sudah diatur' : 'Belum diatur' }}</dd></div>
        <div><dt>Sinkron penuh terakhir</dt><dd>{{ $p->eduweb_sinkron_penuh_pada?->format('d/m/Y H:i') ?? 'Belum pernah' }}</dd></div>
        <div><dt>Kejadian diterapkan sampai</dt><dd>#{{ $p->eduweb_kejadian_terakhir }}</dd></div>
        <div><dt>Mode sinkron</dt><dd>{{ $p->mode_sinkron_eduweb ? 'Aktif: data master dikelola EduWeb dan terkunci di sini' : 'Mati: data master bisa diisi di sini' }}</dd></div>
        <div><dt>Sinkron otomatis</dt><dd>
            @if (! $otomatisAktif) Mati (atur <code>EDUWEB_OTOMATIS</code> dan penghubung di .env)
            @elseif ($ujianBerlangsung) <span class="lencana kuning">Ditahan</span> Ada ujian berlangsung; perubahan diterapkan otomatis setelah selesai
            @elseif ($p->eduweb_kejadian_tertahan) <span class="lencana kuning">Menunggu</span> Ada perubahan yang tertahan, diterapkan sebentar lagi
            @else Aktif: perubahan di EduWeb diterapkan otomatis @endif</dd></div>
        <div><dt>Sinyal dari EduWeb</dt><dd>
            @if ($webhookSiap) {{ $p->eduweb_webhook_terakhir?->format('d/m/Y H:i:s') ?? 'Siap, belum pernah menerima sinyal' }}
            @else Belum diatur: isi <code>EDUWEB_WEBHOOK_RAHASIA</code> di .env. Tanpa itu, perubahan dicek tiap {{ $cekMenit }} menit saat halaman dibuka. @endif</dd></div>
        <div><dt>Pengecekan terakhir</dt><dd>{{ $p->eduweb_cek_terakhir?->format('d/m/Y H:i:s') ?? 'Belum pernah' }}</dd></div>
        <div><dt>Kata sandi dari EduWeb</dt><dd>
            @if ($p->eduweb_kredensial === 'aktif') Aktif: guru dan siswa masuk dengan nama pengguna dan kata sandi EduWeb
            @elseif ($p->eduweb_kredensial === 'belum_aktif') <span class="lencana kuning">Belum aktif</span> Nyalakan akses kredensial untuk kunci ini di menu Integrasi Ujian EduWeb, lalu sinkron penuh. Sementara, siswa memakai sandi dari Kartu Ujian.
            @else Belum diketahui: jalankan sinkron penuh @endif</dd></div>
    </dl>
    <div class="aksi-form" style="margin-top:12px">
        <form method="POST" action="{{ route('staf.sinkron.uji') }}">@csrf <button class="tombol kecil garis" type="submit" @disabled(! $terkonfigurasi)>Uji koneksi</button></form>
        <form method="POST" action="{{ route('staf.sinkron.penuh') }}" onsubmit="return confirm('Jalankan sinkron penuh? Data lokal yang cocok dengan EduWeb akan diperbarui. Jangan lakukan saat ujian sedang berlangsung.')">
            @csrf <button class="tombol kecil" type="submit" @disabled(! $terkonfigurasi)><x-ikon nama="unduh" :ukuran="16" />Sinkron penuh</button></form>
        <form method="POST" action="{{ route('staf.sinkron.kejadian') }}">@csrf
            <button class="tombol kecil garis" type="submit" @disabled(! $terkonfigurasi || ! $p->eduweb_sinkron_penuh_pada)>Ikuti kejadian (kenaikan kelas dll.)</button></form>
    </div>
    <small class="bantuan">Jangan jalankan sinkron manual saat ujian berlangsung; sinkron otomatis berhenti sendiri selama ujian. Bila kata sandi EduWeb belum bisa dibawa, siswa memakai sandi dari Kartu Ujian.</small>
    <small class="bantuan">Alamat sinyal untuk diisi di EduWeb: <code>{{ $urlWebhook }}</code></small>
</div>

@if ($hasil)
    <div class="kartu">
        <h2 class="judul-kartu">Hasil sinkron</h2>
        @if ($hasil['entitas'])
            <table class="tabel" style="margin-top:6px"><thead><tr><th>Data</th><th>Baru</th><th>Diperbarui</th><th>Dilewati</th></tr></thead><tbody>
                @foreach ($hasil['entitas'] as $k => $v)
                    <tr><td>{{ $nama[$k] ?? $k }}</td><td>{{ $v['baru'] }}</td><td>{{ $v['diperbarui'] }}</td><td>{{ $v['dilewati'] }}</td></tr>
                @endforeach
            </tbody></table>
        @endif
        @if ($hasil['lain'])
            <ul class="petunjuk" style="margin-top:10px">@foreach ($hasil['lain'] as $k => $n) <li>{{ str_replace('_', ' ', $k) }}: <strong>{{ $n }}</strong></li> @endforeach</ul>
        @endif
        @if (session('sandi_token'))
            <div class="info" style="margin-top:12px"><x-ikon nama="info" :ukuran="18" /><span>Ada akun guru baru. Kata sandi sementara hanya bisa diunduh <strong>sekali</strong>:
                <a href="{{ route('staf.sinkron.sandi', session('sandi_token')) }}"><strong>unduh daftar akun</strong></a>.</span></div>
        @endif
        @if ($hasil['peringatan'])
            <h3 style="margin-top:14px;font-size:.95rem">Perlu diperiksa</h3>
            <ul class="petunjuk">@foreach ($hasil['peringatan'] as $w) <li>{{ $w }}</li> @endforeach</ul>
            @if ($hasil['peringatan_terpotong'] > 0) <small class="bantuan">dan {{ $hasil['peringatan_terpotong'] }} peringatan lain.</small> @endif
        @endif
    </div>
@endif

<div class="kartu tanpa-padding">
    <h2 class="judul-kartu" style="padding:16px 20px 0">Riwayat sinkron</h2>
    <div class="gulir">
        <table class="tabel">
            <thead><tr><th>Waktu</th><th>Jenis</th><th>Status</th><th>Ringkasan</th><th>Lama</th></tr></thead>
            <tbody>
                @forelse ($log as $l)
                    <tr>
                        <td>{{ $l->created_at->format('d/m/Y H:i:s') }}</td>
                        <td>{{ $l->jenis === 'penuh' ? 'Sinkron penuh' : 'Kejadian' }}</td>
                        <td><span class="lencana {{ $l->status === 'berhasil' ? '' : 'kuning' }}">{{ $l->status === 'berhasil' ? 'Berhasil' : 'Gagal' }}</span></td>
                        <td>
                            @if ($l->galat) {{ $l->galat }}
                            @else
                                @foreach (($l->ringkasan['entitas'] ?? []) as $k => $v) {{ $nama[$k] ?? $k }}: +{{ $v['baru'] }}/~{{ $v['diperbarui'] }}@if (! $loop->last), @endif @endforeach
                                @if (isset($l->ringkasan['lain']['kejadian_diterapkan'])) {{ $l->ringkasan['lain']['kejadian_diterapkan'] }} kejadian diterapkan @endif
                            @endif
                        </td>
                        <td>{{ number_format($l->durasi_ms / 1000, 1, ',', '.') }} dtk</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="kosong">Belum ada sinkron.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
