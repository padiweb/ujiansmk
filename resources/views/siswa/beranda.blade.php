@extends('layouts.dasar')
@section('judul', 'Beranda Siswa')

@php
    $jendelaLabel = ['belum_dibuka' => 'Belum dibuka', 'dibuka' => 'Sedang dibuka', 'ditutup' => 'Sudah ditutup'];
@endphp

@section('isi')
<header class="siswa-atas">
    <h1><img src="{{ asset('images/logo_smk.png') }}" alt="">{{ config('ujian.merek') }}</h1>
    <form method="POST" action="{{ route('siswa.keluar') }}" style="margin:0">
        @csrf
        <button class="tombol kecil garis" type="submit"><x-ikon nama="keluar" :ukuran="16" />Keluar</button>
    </form>
</header>

<main class="siswa-isi">
    @if (session('gagal'))
        <div class="pesan-salah" role="alert"><x-ikon nama="peringatan" :ukuran="18" /><span>{{ session('gagal') }}</span></div>
    @endif

    <div class="kartu siswa-sapa" style="margin-top:0">
        <span class="profil-foto profil-foto-kecil">
            @if ($fotoSiswa = $siswa->fotoUrl())
                <img src="{{ $fotoSiswa }}" alt="" referrerpolicy="no-referrer" onerror="this.remove()">
            @endif
            <span class="profil-inisial">{{ mb_strtoupper(mb_substr($siswa->nama, 0, 1)) }}</span>
        </span>
        <div>
            <h2 class="judul-kartu" style="font-size:1.2rem">Halo, {{ $siswa->nama }}</h2>
            <p class="sub" style="color:var(--teks-3)">NISN {{ $siswa->nisn }}</p>
        </div>
    </div>

    @if ($rapor->isNotEmpty())
        <h2 class="judul-bagian">Rapor</h2>
        <div class="kartu tanpa-padding" style="margin-top:0">
            <div class="gulir">
                <table class="tabel">
                    <tbody>
                        @foreach ($rapor as $r)
                            <tr>
                                <td><strong>{{ $r->periode->label() }}</strong><br><small class="bantuan" style="margin:0">{{ $r->kelas_nama }}</small></td>
                                <td class="kanan"><a class="tombol kecil garis" href="{{ route('siswa.rapor', $r->periode_id) }}">Lihat rapor</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <h2 class="judul-bagian">Ujian untuk Anda</h2>
    @forelse ($tersedia as $j)
        @php
            $s = $sesi[$j->id] ?? null;
            $berjalan = $s && in_array($s->status, ['berlangsung', 'diblokir'], true);
            $jendela = $j->jendela();
        @endphp
        <article class="ujian-kartu">
            <div class="ujian-info">
                <h3>{{ $j->nama }}</h3>
                <p>{{ $j->mapel->nama }} · {{ $j->durasi_menit }} menit</p>
                <p class="bantuan" style="margin:2px 0 0">
                    Dibuka {{ $j->mulai_pada->format('d/m/Y H:i') }} — ditutup {{ $j->tutup_pada->format('d/m/Y H:i') }}
                </p>
            </div>
            <div class="ujian-aksi">
                @if ($berjalan)
                    <span class="lencana kuning">Sedang dikerjakan</span>
                    <a class="tombol kecil" href="{{ route('siswa.ujian.token', $j) }}">Lanjutkan</a>
                @elseif ($jendela === 'dibuka')
                    <a class="tombol kecil" href="{{ route('siswa.ujian.token', $j) }}">Mulai</a>
                @else
                    <span class="lencana pucat">{{ $jendelaLabel[$jendela] }}</span>
                @endif
            </div>
        </article>
    @empty
        <div class="kartu kosong-kartu">Belum ada ujian yang aktif untuk kelas Anda.</div>
    @endforelse

    @if ($riwayat->isNotEmpty())
        <h2 class="judul-bagian">Riwayat ujian</h2>
        <div class="kartu tanpa-padding" style="margin-top:0">
            <div class="gulir">
                <table class="tabel">
                    <thead><tr><th>Ujian</th><th>Selesai</th><th>Nilai PG</th></tr></thead>
                    <tbody>
                        @foreach ($riwayat as $r)
                            <tr>
                                <td><strong>{{ $r->jadwal->nama }}</strong><br><small class="bantuan" style="margin:0">{{ $r->jadwal->mapel->nama }}</small></td>
                                <td>{{ $r->selesai_pada?->format('d/m/Y H:i') }}</td>
                                <td>
                                    @if ($r->jadwal->tampilkan_hasil === 'langsung' && $r->hasil?->skor_pg !== null)
                                        <strong>{{ rtrim(rtrim(number_format((float) $r->hasil->skor_pg, 2, ',', '.'), '0'), ',') }}</strong>
                                    @else — @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</main>
@endsection
