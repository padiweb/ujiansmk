@extends('layouts.dasar')
@section('judul', 'Kebijakan Privasi')

@php
    $sekolah = \App\Models\PengaturanSekolah::ambil();
    $namaSekolah = $sekolah->nama ?: config('ujian.sekolah');
    $kontak = collect([
        $sekolah->alamat ? trim($sekolah->alamat.' '.$sekolah->kota) : null,
        $sekolah->telepon ? 'Telepon: '.$sekolah->telepon : null,
        $sekolah->email ? 'Surel: '.$sekolah->email : null,
    ])->filter();
@endphp

@push('kepala')
<style>
    .dokumen { max-width: 760px; margin: 0 auto; padding: 28px 18px 60px; line-height: 1.65; color: var(--teks); }
    .dokumen h1 { font-size: 1.5rem; margin: 0 0 4px; }
    .dokumen h2 { font-size: 1.05rem; margin: 26px 0 6px; }
    .dokumen .tanggal { color: var(--teks-3); font-size: .88rem; margin: 0 0 18px; }
    .dokumen ul { padding-left: 20px; margin: 6px 0; }
    .dokumen li { margin: 3px 0; }
    .dokumen a { color: var(--biru); }
</style>
@endpush

@section('isi')
<main class="dokumen">
    <h1>Kebijakan Privasi</h1>
    <p class="tanggal">Aplikasi Altan Exam dan situs ujian {{ $namaSekolah }}. Diperbarui November 2026.</p>

    <p>Altan Exam adalah aplikasi ujian berbasis komputer milik {{ $namaSekolah }}. Aplikasi Android hanyalah pintu masuk ke situs ujian sekolah, sehingga data yang dijelaskan di bawah disimpan dan dikelola oleh sekolah di server ujiannya, bukan oleh pihak lain.</p>

    <h2>Data yang kami kumpulkan</h2>
    <ul>
        <li><strong>Identitas siswa:</strong> nama, NISN atau NIS, kelas, dan foto profil bila ada, yang diambil dari data induk sekolah.</li>
        <li><strong>Identitas guru dan staf:</strong> nama, nama pengguna, peran, dan foto profil bila ada.</li>
        <li><strong>Data ujian:</strong> jawaban, waktu mulai dan selesai, serta nilai.</li>
        <li><strong>Data pengawasan:</strong> catatan pelanggaran selama ujian (misalnya berpindah aplikasi, melepas sematan layar, atau mencoba mengambil tangkapan layar), jenis perangkat, dan alamat IP pada log aktivitas.</li>
        <li><strong>Kata sandi</strong> disimpan dalam bentuk terenkripsi (hash) dan tidak dapat dibaca oleh siapa pun, termasuk admin.</li>
    </ul>
    <p>Kami tidak mengumpulkan lokasi, kontak, galeri, mikrofon, atau kamera. Aplikasi hanya meminta akses internet.</p>

    <h2>Untuk apa data dipakai</h2>
    <ul>
        <li>Menyelenggarakan ujian, menilai jawaban, dan menyampaikan hasil kepada siswa, guru, dan wali kelas.</li>
        <li>Menjaga kejujuran ujian dan mencatat pelanggaran yang diputuskan oleh server.</li>
        <li>Menyusun rekap nilai dan arsip sekolah.</li>
    </ul>
    <p>Data tidak dijual dan tidak dipakai untuk iklan.</p>

    <h2>Layar dan keamanan perangkat</h2>
    <p>Selama ujian, aplikasi menyembunyikan isi layar dari tangkapan layar dan rekaman layar (FLAG_SECURE), menyematkan layar agar siswa tidak berpindah aplikasi, dan memantau fokus aplikasi. Tujuannya semata-mata menjaga kejujuran ujian. Aplikasi juga dapat menolak berjalan di emulator atau perangkat yang di-root. Tidak ada isi layar atau data pribadi lain yang direkam atau dikirim dari fitur ini selain catatan pelanggarannya.</p>

    <h2>Pihak lain yang menerima data</h2>
    <ul>
        <li><strong>EduWeb</strong>, sistem data induk sekolah, menjadi sumber data siswa dan guru.</li>
        <li><strong>Penyedia hosting</strong> menyimpan server ujian dengan koneksi terenkripsi (HTTPS).</li>
        <li><strong>Layanan penilai otomatis (opsional):</strong> bila guru mengaktifkan koreksi esai atau penyusunan soal otomatis, teks jawaban esai atau soal tanpa nama siswa dapat dikirim ke penyedia layanan pihak ketiga untuk mendapat saran. Hasilnya hanya saran dan dapat diubah guru.</li>
    </ul>

    <h2>Penyimpanan dan penghapusan</h2>
    <p>Data disimpan selama diperlukan untuk keperluan akademik dan arsip sekolah. Siswa yang lulus atau pindah dapat diarsipkan. Permintaan koreksi atau penghapusan data diajukan ke sekolah (kontak di bawah) dan dipenuhi sepanjang tidak bertentangan dengan kewajiban pencatatan akademik.</p>

    <h2>Keamanan</h2>
    <p>Seluruh komunikasi memakai HTTPS. Akses staf dibatasi menurut peran, dan hanya guru yang berhak yang dapat melihat soal dan nilai kelasnya. Meski begitu, tidak ada sistem yang sepenuhnya bebas risiko.</p>

    <h2>Anak di bawah umur</h2>
    <p>Pengguna utama adalah siswa SMK. Data siswa dikelola oleh sekolah atas nama sekolah dan orang tua atau wali.</p>

    <h2>Perubahan kebijakan</h2>
    <p>Bila kebijakan ini berubah, versi terbaru ditampilkan di halaman ini beserta tanggal berlakunya.</p>

    <h2>Kontak</h2>
    <p>{{ $namaSekolah }}@if ($kontak->isNotEmpty())<br>{!! $kontak->map(fn ($k) => e($k))->implode('<br>') !!}@else. Hubungi operator ujian di sekolah.@endif</p>
</main>
@endsection
