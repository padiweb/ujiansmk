@extends('layouts.dasar')
@section('judul', 'Siap Ujian')

@section('isi')
<div class="login-latar">
    <div class="login-kartu" style="max-width:520px;text-align:left">
        <span class="lencana">{{ $jadwal->mapel->nama }}</span>
        <h1 style="font-size:1.3rem;margin-top:8px">{{ $jadwal->nama }}</h1>

        @if ($sesi->status !== 'belum')
            <div class="info" style="margin-top:14px"><x-ikon nama="info" :ukuran="18" /><span>Ujian Anda sedang berjalan. Waktu tidak berhenti saat Anda keluar dari halaman ujian.</span></div>
        @endif

        <h2 class="judul-kartu" style="margin-top:18px">Baca dulu aturannya</h2>
        <ul class="aturan">
            <li>Waktu pengerjaan <strong>{{ $jadwal->durasi_menit }} menit</strong>, dihitung sejak Anda menekan Mulai dan <strong>tidak berhenti</strong> walau halaman ditutup.</li>
            <li>Jawaban tersimpan otomatis. Anda tidak perlu menekan tombol simpan.</li>
            <li>Kerjakan sendiri. Jangan membuka aplikasi atau tab lain, dan jangan keluar dari layar penuh.</li>
            <li>Pelanggaran dicatat. Peringatan diberikan sebanyak <strong>{{ $jadwal->batas_pelanggaran - 1 }}</strong> kali; pelanggaran ke-<strong>{{ $jadwal->batas_pelanggaran }}</strong> memblokir ujian Anda sampai pengawas mengaktifkannya kembali.</li>
            <li>Ujian hanya bisa dikerjakan di <strong>satu perangkat</strong>. Bila perangkat bermasalah, panggil pengawas.</li>
            <li>Tekan <strong>Kirim jawaban</strong> bila sudah selesai. Waktu habis berarti jawaban terkirim otomatis.</li>
        </ul>

        <form method="POST" action="{{ route('siswa.ujian.mulai', $jadwal) }}" style="margin-top:20px">
            @csrf
            <input type="hidden" name="mode_tampil" id="mode-tampil" value="peramban">
            <input type="hidden" name="sentuh" id="sentuh" value="0">
            <button class="tombol" type="submit">{{ $sesi->status === 'belum' ? 'Mulai ujian' : 'Lanjutkan ujian' }}</button>
        </form>
        <script>
            (function () {
                try {
                    var m = window.matchMedia;
                    var terpasang = (m && (m('(display-mode: standalone)').matches || m('(display-mode: fullscreen)').matches)) || window.navigator.standalone === true;
                    document.getElementById('mode-tampil').value = terpasang ? 'pwa' : 'peramban';
                    document.getElementById('sentuh').value = (navigator.maxTouchPoints || 0) > 1 ? '1' : '0';
                } catch (e) {}
            })();
        </script>
        <div class="login-tautan"><a href="{{ route('siswa.beranda') }}">← Kembali ke beranda</a></div>
    </div>
</div>
@endsection
