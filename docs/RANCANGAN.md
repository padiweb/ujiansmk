# Rancangan Aplikasi Ujian Baru (Laravel) — SMK Alhikmah Tanon

Status: **DISETUJUI (bagian 4 dan 5).** Belum ada kode. Disusun dari serah terima, dump struktur DB lama, dan pembacaan kode lama (`ujian_lama`).

## 1. Prinsip
- Semua rahasia di `.env`. Kata sandi hanya bcrypt/argon. Query hanya Eloquent/binding. Relasi berbasis ID.
- Setiap nilai terikat ke ID (`mapel_id`, `kelas_id`, dst) **dan** menyimpan snapshot nama saat itu untuk arsip.
- Entitas dari EduWeb menyimpan `eduweb_id` (unik, boleh kosong sebelum tahap 3). Tidak pernah dicocokkan lewat nama.
- Satu instalasi = satu sekolah (database sendiri). Skema tidak mengunci ke satu sekolah, jadi bisa dikembangkan ke banyak sekolah nanti, tapi itu bukan lingkup sekarang.
- Data master (siswa, guru, kelas, jurusan, mapel, tahun ajaran, pengampu, identitas sekolah) adalah **salinan lokal dari EduWeb**. Pengaturan `mode_sinkron_eduweb`: bila aktif (Tahap 3) data master hanya bisa dibaca di aplikasi ujian; bila mati, master data boleh diisi lewat impor Excel sehingga aplikasi tetap bisa berdiri sendiri.

## 2. Hasil baca kode lama yang menentukan rancangan
| Temuan | Keputusan di aplikasi baru |
|---|---|
| Tabel pengguna yang dipakai: `tbl_users` (`tbl_admin` tidak dipakai kode) | Satu tabel `users`, `tbl_admin` tidak dimigrasi |
| Kunci jawaban tidak dikirim ke browser, dinilai di server | Dipertahankan |
| Jawaban PG hanya di `localStorage`, dikirim sekali di akhir | Disimpan ke server per soal (tahan HP mati/refresh) |
| Durasi tetap 7200 dtk, timer di `localStorage` | Durasi per jadwal, batas waktu dihitung server |
| Jadwal dicari dari nama mapel (`LIMIT 1`) | Jadwal punya banyak kelas peserta, siswa dicocokkan lewat kelasnya |
| Blokir menempel di akun siswa (`status=Blokir`) | Blokir per sesi ujian, bukan per akun |
| Soal terikat `mapel`(teks)+`tingkat`+`id_tahun` | Soal terikat `mapel_id`; jadwal membekukan daftar soalnya |
| Kunci API Gemini tertulis di `config.php` | Di `.env`, dan **kunci lama wajib dicabut** |
| Rumus nilai rapor ada di `config.php` | Dipindah ke satu kelas layanan, bobot dapat diatur per sekolah |

## 3. Rumus nilai rapor (dari `config.php` lama)
- `NP = Ujian×20% + Harian×60% + TugasAkhir×20%` (dibulatkan ke bilangan bulat)
- `NK = Praktik×70% + Adab×30%` (dibulatkan)
- `NA = (NP + NK) / 2` dibulatkan. Jika NK=0 dan NP>0 maka NA=NP. Jika NP=0 dan NK>0 maka NA=NK.
- Nilai "Ujian" diambil dari input guru; bila kosong dipakai nilai CBT; bila ada `nilai_manual` dan harian/tugas akhir kosong, `nilai_manual` jadi nilai ujian.
- Predikat lama (hanya contoh bawaan): ≥95 A+, ≥90 A, ≥85 A-, ≥80 B+, ≥75 B-, ≥70 C, selain itu D. **Di aplikasi baru predikat diatur admin** (tabel `skala_predikat`), tiap sekolah bebas.
- Kelas XII semester genap: jika ada nilai Ujian Sekolah (US), NP=NK=NA=nilai US.
- Transkrip US: rata-rata semua semester yang terisi (>0) per mapel.
- Kenaikan: X→XI, XI→XII, XII→Lulus; status riwayat Naik/Tinggal/Lulus.
- Di aplikasi baru bobot dan batas predikat disimpan di pengaturan sekolah (bawaan = angka di atas), dan hasil NP/NK/NA **dibekukan** saat rapor difinalkan agar transkrip alumni tidak berubah bila bobot diubah.

## 4. Skema tabel

### Identitas dan akses
- `users`: id, eduweb_id?, nama, username, password, role (admin|guru|pengawas), aktif, login_terakhir
- `siswa`: id, eduweb_id?, nisn (unik, = username login), nis, nama, jk, tempat_lahir, tgl_lahir, nama_wali, no_peserta_us, status (aktif|lulus|keluar), password (hash)
- `pengaturan_sekolah`: nama, alamat, kota, provinsi, npsn, kepala_sekolah, nip_ks, kurikulum, bobot rapor, logo, mode_sinkron_eduweb, penyedia_soal_otomatis
- `skala_predikat`: nilai_min, predikat, keterangan (diatur admin)
- `penyedia_otomatis`: nama, driver (gemini|openai|anthropic|lainnya), model, kunci (terenkripsi), aktif
- `audit_log`: user/siswa, aksi, detail, ip, waktu
- `api_client`: nama, kunci (hash), aktif, terakhir_dipakai; `sinkron_log`: arah, entitas, status, payload ringkas

### Akademik (sumber: EduWeb)
- `tahun_ajaran`: id, eduweb_id?, nama (2025/2026), aktif
- `periode`: id, tahun_ajaran_id, semester (ganjil|genap), tipe (reguler|ujian_sekolah), aktif
- `jurusan`: id, eduweb_id?, kode, nama, program_keahlian
- `kelas`: id, eduweb_id?, tahun_ajaran_id, tingkat (X|XI|XII), jurusan_id, nama, wali_kelas_id → users
- `keanggotaan_kelas`: siswa_id, kelas_id, status (aktif|naik|tinggal|lulus). Menggantikan `tbl_riwayat_kelas`
- `mapel`: id, eduweb_id?, kode, nama, nama_skl, kelompok (A|B|C1|C2|C3|ML), kkm
- `pengampu`: guru_id, mapel_id, kelas_id, periode_id
- `ruang`: id, nama

### Bank soal dan jadwal
- `soal`: id, mapel_id, tingkat, tipe (pg|esai), pertanyaan, gambar, bobot, dibuat_oleh, arsip
- `opsi_soal`: soal_id, label (A–E), teks, gambar, benar (bool). Tidak lagi terbatas 4 opsi
- `jadwal_ujian`: id, periode_id, mapel_id, nama, durasi_menit, mulai_pada, tutup_pada, token (unik), status (draf|aktif|selesai), acak_soal, acak_opsi, batas_pelanggaran, bobot_pg/esai
- `jadwal_kelas`: jadwal_id, kelas_id (satu jadwal boleh banyak kelas dan tingkat)
- `jadwal_soal`: jadwal_id, soal_id, urutan. Dibekukan saat jadwal diaktifkan, jadi mengedit bank soal tidak mengubah ujian berjalan

### Pelaksanaan
- `sesi_ujian`: id, jadwal_id, siswa_id, ruang_id, status (belum|berlangsung|selesai|diblokir), mulai_pada, batas_selesai (dihitung server), selesai_pada, jumlah_pelanggaran, token_perangkat (satu sesi aktif), platform. Unik (jadwal_id, siswa_id)
- `jawaban`: sesi_id, soal_id, opsi_id?, teks_esai?, urutan_tampil, urutan_opsi (json), nilai_esai?, dijawab_pada. Unik (sesi_id, soal_id)
- `pelanggaran`: sesi_id, jenis, keterangan, info_peramban, platform, waktu
- `hasil_ujian`: sesi_id (unik), benar, salah, skor_pg, skor_esai, skor_akhir, status_koreksi, **snapshot**: mapel_id + mapel_nama, kelas_nama, nama_jadwal

### Rapor, transkrip, SKL
- `nilai_rapor`: siswa_id, mapel_id, periode_id, kelas_id, guru_id, ujian, harian, tugas_akhir, praktik, adab, nilai_manual, catatan_guru, np, nk, na, predikat, status (draf|final), **snapshot**: mapel_nama, kelas_nama, kkm. Unik (siswa_id, mapel_id, periode_id)
- `rapor_siswa`: siswa_id, periode_id, kelas_nama (snapshot), sakit, izin, alpa, sumber_absensi (eduweb|manual), catatan_wali, keputusan, status_naik
- `ekskul`, `nilai_ekskul`: siswa_id, periode_id, ekskul_id, nilai, deskripsi
- `nilai_us`: siswa_id, mapel_id, periode_id, nilai_cbt, nilai_us, keterangan
- `kelulusan`: siswa_id, status (belum|lulus|tidak_lulus), no_ijazah, no_skl, tgl_kelulusan

### Migrasi (Tahap 2)
- `pemetaan_mapel`: nama_lama → mapel_id (diperiksa admin)
- `pemetaan_kelas`: nama_lama + tahun → kelas_id (diperiksa admin)
- Baris yatim di data lama (nilai tanpa siswa, dst) dilaporkan, tidak diimpor diam-diam

## 5. Fitur lama dan tahap
| Tahap | Fitur |
|---|---|
| 1 | Login 4 peran; master data lokal (impor Excel sementara); bank soal PG+esai+gambar; penyusun soal otomatis; jadwal (durasi, token, kelas, ruang); pelaksanaan ujian; anti curang 11 lapis + 1 sesi aktif; monitor pengawas (pelanggaran, blokir, aktifkan kembali, notifikasi); koreksi esai; rekap nilai & statistik; cetak kartu ujian & no. meja |
| 2 | Migrasi data lama; input & cetak rapor; rapor ekstra/ekskul; transkrip kelas 12 + US; SKL; mutasi; kisi-kisi (cetak/Word); impor nilai rapor Excel; input nilai lampau; arsip siswa |
| 3 | API EduWeb (master data, kenaikan kelas, absensi, hash sandi; endpoint rapor/nilai) |
| 4 | Pembungkus Android + anti curang lanjutan |

Fitur bantu otomatis lama (`ai_soal`, `ai_kisi_kisi`, `ai_koreksi_esai`) dibangun ulang di tahap 1–2 dengan label **"Otomatis"**; kunci layanan di `.env`, tiap panggilan dicatat.

## 6. Keputusan pemilik (12 Okt 2026)
1. Satu instalasi per sekolah dulu; multi-sekolah kelak.
2. Predikat diatur admin per sekolah.
3. Penyusun soal otomatis: Gemini bawaan, penyedia lain (berbayar) bisa dipilih lewat pengaturan. Dibangun sebagai antarmuka `PenyusunSoal` dengan driver per penyedia; kunci disimpan terenkripsi, bukan di kode.
