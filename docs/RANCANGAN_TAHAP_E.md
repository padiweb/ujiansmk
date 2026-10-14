# Rancangan Tahap E: koreksi esai, rekap nilai, penempatan ruang, kartu ujian

Status: **menunggu persetujuan** (belum ada kode). Turunan dari `RANCANGAN_UJIAN.md` §6 dan §10.

## 1. Koreksi esai (guru)
- Halaman **Koreksi** per jadwal: daftar soal esai → daftar jawaban siswa. Guru melihat pertanyaan, **rubrik/jawaban acuan** bank soal, jawaban siswa, lalu mengisi nilai 0–100 per jawaban (kolom `jawaban.nilai_esai`, sudah ada).
- **Dua cara menelusuri:** per soal (satu soal untuk semua siswa, lebih adil dan cepat) atau per siswa. Bawaan: per soal.
- **Anonim:** nama siswa disembunyikan saat per soal (dibuka lewat tombol), agar koreksi tidak bias.
- Simpan per jawaban lewat permintaan kecil (tanpa muat ulang); jawaban kosong otomatis 0 dan ditandai "kosong".
- **Hak akses:** admin semua; guru hanya jadwal untuk mapel yang diampunya. Pengawas tidak.
- Setiap nilai mencatat `dikoreksi_oleh` dan `dikoreksi_pada`. Nilai bisa diubah selama jadwal belum **dikunci** (lihat §2).
- **Skor esai** siswa = rata-rata tertimbang (`bobot` soal) nilai esainya. **Skor akhir** = PG × bobot_pg% + esai × bobot_esai% (rumus §6 yang sudah disetujui). Status koreksi: `belum_dikoreksi` → `selesai` otomatis saat semua esai siswa itu bernilai; skor akhir terbentuk saat itu.
- **Saran nilai "Otomatis"** (pengganti `ai_koreksi_esai` lama): tombol per halaman (maks 10 jawaban) meminta penyedia menilai berdasarkan rubrik. Hasilnya hanya **saran** (nilai + catatan singkat) yang tersimpan di kolom terpisah; guru yang memutuskan (tombol "Pakai saran"), tidak pernah masuk nilai sendiri. Jawaban siswa diperlakukan sebagai data tak tepercaya (pagar DATA, instruksi di dalamnya diabaikan). Memakai batas harian/menit yang sama dengan penyusun soal dan tercatat di `log_otomatis`.

## 2. Rekap nilai
- Halaman **Rekap** per jadwal: tabel siswa (nama, kelas, ruang, benar/salah/kosong, skor PG, skor esai, skor akhir, status koreksi, alasan selesai), ringkasan (rata-rata, tertinggi, terendah, jumlah di bawah KKM mapel, belum mengerjakan, belum dikoreksi).
- **Ekspor Excel** rekap (satu berkas per jadwal) dan **cetak/PDF lewat cetak peramban** (tata letak khusus cetak, tanpa pustaka PDF baru).
- **Kunci nilai:** admin/guru menekan "Kunci nilai" setelah koreksi selesai → nilai tidak bisa diubah lagi dan baru bisa dipakai tahap 2 (rapor). Admin bisa membuka kunci (tercatat di audit).
- **Penahanan hasil:** jadwal dengan `tampilkan_hasil = ditahan` baru tampil ke siswa setelah guru menekan "Tampilkan ke siswa".
- Pembukaan rekap memicu penyapuan sesi kedaluwarsa (sesuai rancangan, tanpa cron).
- Siswa yang tidak mengerjakan ditampilkan "Tidak mengikuti"; nilainya **tidak** dibuat otomatis 0 (keputusan remedial ada di sekolah).

## 3. Denah ruang ujian (penempatan campuran tingkat)
Kolom `sesi_ujian.ruang_id` sudah ada tetapi **belum pernah diisi**. Satu ruang berisi siswa dari 2-3 tingkat/kelas berbeda dan satu meja bisa diisi 2-3 siswa dari tingkat berbeda (agar tidak saling menyontek), sehingga penempatan **tidak bisa melekat pada satu jadwal**. Maka dibuat **Denah Ujian** yang berdiri sendiri dan dipasang ke jadwal mana pun, **sebelum maupun sesudah jadwal diaktifkan**.
- `denah_ujian`: nama, periode. `denah_ruang`: ruang yang dipakai + **siswa per meja** (1-3) + jumlah meja (diisi saat membuat denah; master Ruang tidak punya kapasitas). `denah_penempatan`: siswa, ruang, no. meja, posisi di meja (unik per denah dan siswa).
- **Bagi otomatis:** pilih kelas peserta (boleh beda tingkat) dan ruang. Siswa diacak per tingkat; tiap meja diisi dari tingkat yang berbeda (kelompok tingkat terbanyak didahulukan), kelas yang sama disebar. Bila hanya satu tingkat tersisa, meja terakhir boleh sejenis (diberi tanda). Bisa diubah manual (pindah/tukar siswa).
- Jadwal dipasang ke denah (`jadwal_ujian.denah_id`). Saat jadwal diaktifkan, **ruang dan no. meja disalin ke sesi** siswa; bila denah diubah sesudahnya, sesi yang belum selesai ikut diperbarui. Siswa tanpa penempatan tetap bisa ujian (ruang kosong) tetapi diberi peringatan di halaman jadwal.
- Pengawas ditugaskan per ruang lewat `jadwal_pengawas.ruang_id` (kolom sudah ada).

## 4. Kartu ujian dan daftar hadir
- **Kartu peserta** (cetak, 6 per halaman A4): nama, NISN, kelas, mapel, tanggal/jam, ruang, no. meja, **nama pengguna**, dan **kata sandi hanya bila sedang dibuat ulang** (lihat bawah). Token ujian **tidak** dicetak (dibacakan pengawas).
- **Daftar hadir per ruang** (cetak): nomor meja, nama, kolom tanda tangan.
- **Kata sandi:** disimpan sebagai hash, jadi tidak bisa dicetak ulang. Admin bisa **membuat kata sandi sementara baru** untuk satu kelas atau terpilih → unduh sekali (seperti daftar sandi impor yang sudah ada) → siswa wajib menggantinya? **Diusulkan: tidak wajib ganti**, karena ujian satu kali; tetapi tercatat di audit.

## 5. Keamanan
- Semua halaman guru/admin: pemeriksaan hak akses per jadwal di pengendali (pola `LayananMonitor`).
- Perubahan nilai, kunci/buka kunci, pembuatan sandi, dan penempatan dicatat di `audit_log`.
- Jawaban siswa ditampilkan ke guru dengan escape penuh (esai adalah masukan tak tepercaya).
- Berkas ekspor dibuat di folder privat dan dihapus setelah diunduh.

## 6. Urutan pengerjaan (tiap langkah ditutup tes + commit)
1. E1 Koreksi esai + penilaian ulang skor akhir.
2. E2 Rekap, ekspor Excel, cetak, kunci nilai, penahanan hasil.
3. E3 Penempatan ruang/meja, pengawas per ruang.
4. E4 Kartu ujian, daftar hadir, sandi sementara massal.

## 7. Keputusan pemilik (disetujui)
1. Koreksi per soal, nama disembunyikan: **ya**. 2. Nilai 0-100 per jawaban: **ya**. 3. Kunci nilai: **ya**. 4. Tidak mengikuti tidak otomatis 0: **ya**.
5. Penempatan ruang **sebelum atau sesudah aktivasi**, dengan siswa berbeda tingkat dalam satu meja (lihat §3). 6. Sandi sementara massal: **ya**. 7. Saran nilai Otomatis **dikerjakan sekarang** (lihat §1).
