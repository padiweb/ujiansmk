# Rancangan Tahap 2a: Rapor

Status: **menunggu persetujuan pemilik** (belum ada kode). Cakupan: nilai rapor per mapel, absensi, rapor per siswa, finalisasi, cetak, tampilan siswa, dan API baca-saja untuk EduWeb. **Di luar cakupan** (Tahap 2b/2c): migrasi data lama, transkrip, SKL, ekskul, impor Excel nilai lampau, kisi-kisi.

## 1. Yang sudah ada dan dipakai ulang
- Bobot rapor (`bobot_ujian/harian/tugas_akhir/praktik/adab`) dan tabel `skala_predikat` sudah ada di pengaturan sekolah. Tahap ini menambah layar pengaturannya bila belum ada.
- Nilai ujian dari `hasil_ujian.skor_akhir`, hanya dari jadwal yang **nilainya sudah dikunci** (`jadwal_ujian.nilai_dikunci_pada`).
- `pengampu` (guru, mapel, kelas, periode) menentukan siapa boleh mengisi nilai mapel mana. `kelas.wali_kelas_id` menentukan wali kelas.
- Klien EduWeb (`KlienEduweb`) untuk menarik absensi; `siswa.eduweb_id` untuk mencocokkan siswa.

## 2. Rumus (dari aplikasi lama, bobot dapat diatur)
- `NP = Ujian×20% + Harian×60% + TugasAkhir×20%` (bulat)
- `NK = Praktik×70% + Adab×30%` (bulat)
- `NA = (NP + NK) / 2` (bulat). NK=0 dan NP>0 → NA=NP. NP=0 dan NK>0 → NA=NK.
- Predikat dari `skala_predikat`. Ketuntasan dibandingkan dengan KKM mapel.
- Bila bobot yang diatur tidak berjumlah 100 per kelompok, pengaturan ditolak.
- Kelas XII semester genap dengan nilai Ujian Sekolah: ditunda ke Tahap 2c (bersama transkrip).

## 3. Alur kerja
1. **Admin** menetapkan periode aktif dan rentang tanggalnya, lalu memilih jadwal ujian mana yang menjadi sumber "Ujian" per mapel (lihat 6, pertanyaan 1).
2. **Guru mapel** membuka *Nilai Rapor → pilih kelas dan mapel* (hanya yang diampunya). Tabel siswa dengan kolom: Ujian (terisi otomatis, bisa ditimpa dengan alasan), Harian, Tugas Akhir, Praktik, Adab, Catatan. NP/NK/NA/predikat dihitung langsung di layar dan dihitung ulang di server saat disimpan. Tidak ada rumus yang dipercaya dari peramban.
3. **Wali kelas** membuka *Rapor Kelas*: melihat kelengkapan nilai semua mapel, mengisi catatan wali dan keputusan (naik/tinggal), dan menarik absensi dari EduWeb (boleh diubah manual).
4. **Finalisasi per kelas** (wali kelas atau admin): sistem memeriksa kelengkapan (ada peringatan untuk nilai kosong), lalu **membekukan** nilai beserta snapshot nama mapel, nama kelas, KKM, bobot, dan skala predikat yang berlaku. Setelah final, nilai tidak bisa diubah. **Admin** dapat membuka final dengan alasan wajib, tercatat di audit.
5. **Cetak**: PDF/halaman cetak rapor per siswa atau satu kelas sekaligus (gaya cetak seperti rapor lama, kop dari pengaturan sekolah).
6. **Siswa** masuk dan melihat rapor semester yang sudah final (baca saja, cetak sendiri). Rapor draf tidak terlihat siswa.
7. **EduWeb** membaca rapor lewat API baca-saja (bagian 5).

## 4. Data
Tabel baru (mengikuti `RANCANGAN.md` bagian 4, disederhanakan untuk 2a):
- `nilai_rapor`: siswa_id, mapel_id, periode_id, kelas_id, guru_id, ujian, ujian_dari (otomatis|manual), harian, tugas_akhir, praktik, adab, catatan_guru, np, nk, na, predikat, status (draf|final), snapshot (mapel_nama, kelas_nama, kkm, bobot dalam json, skala dalam json). Unik (siswa_id, mapel_id, periode_id).
- `rapor_siswa`: siswa_id, periode_id, kelas_id, kelas_nama (snapshot), sakit, izin, alpa, sumber_absensi (eduweb|manual), catatan_wali, keputusan, status (draf|final), difinalkan_oleh/pada. Unik (siswa_id, periode_id).
- Penambahan kolom: `periode.mulai` dan `periode.selesai` (rentang absensi), dan penanda di `jadwal_ujian` bahwa hasilnya menjadi sumber nilai ujian rapor.
- Semua perubahan nilai dan finalisasi masuk `audit_log`.

## 5. API baca-saja untuk EduWeb
- Rute baru `/api/rapor/v1/...` dengan autentikasi kunci server-ke-server (tabel `api_client`, kunci disimpan sebagai hash, dapat dicabut, dicatat pemakaiannya).
- Endpoint: `GET periode`, `GET kelas/{eduweb_id}/rapor?periode=` (daftar rapor final), `GET siswa/{eduweb_id}/rapor?periode=`.
- Hanya data **final**, hanya baca, siswa diidentifikasi lewat `eduweb_id`. Tidak ada endpoint yang menerima tulis atau hapus.
- Tampilan di sisi EduWeb adalah pekerjaan sesi EduWeb. Dari sisi ini cukup menyediakan API dan dokumentasinya.

## 6. Pertanyaan untuk pemilik (rekomendasi saya di depan)
1. **Sumber nilai Ujian rapor.** Rekomendasi: admin menandai satu jadwal ujian yang sudah dikunci per mapel/kelas/periode (misalnya PAS). Bila ada lebih dari satu, dirata-rata. Alternatif: guru mengetik sendiri seperti aplikasi lama.
2. **Siapa mengisi Harian/Tugas Akhir/Praktik/Adab.** Rekomendasi: guru mapel mengisi semuanya lewat tabel (bisa tempel dari Excel). Alternatif: nilai praktik dan adab diisi guru produktif/wali kelas.
3. **Absensi.** Rekomendasi: tarik dari EduWeb per periode (butuh rentang tanggal semester) dan boleh dikoreksi manual oleh wali kelas.
4. **Siapa memfinalkan.** Rekomendasi: wali kelas memfinalkan kelasnya, admin dapat membuka final.
5. **Format cetak.** Rekomendasi: saya meniru tata letak rapor lama di `ujian_lama` (kop, identitas, tabel nilai, absensi, catatan, tanda tangan). Bila sekolah Bapak/Ibu butuh format lain, mohon contohnya.

## 7. Pengujian
- Tes otomatis: rumus (termasuk pembulatan dan kasus NK/NP kosong), hak akses (guru hanya mapel dan kelasnya, siswa hanya rapornya sendiri), nilai ujian hanya dari jadwal terkunci, finalisasi membekukan snapshot, buka final wajib alasan, API menolak kunci salah/dicabut dan hanya mengembalikan data final.
- Uji peramban dengan SQLite sementara seperti tahap sebelumnya: alur guru → wali kelas → finalisasi → cetak → siswa melihat.
- Pemilik menguji alur lengkap di lokal (soal → ujian → koreksi → kunci → rapor) setelah tahap ini selesai.

## 8. Urutan pengerjaan (commit terpisah, uji hijau tiap langkah)
1. Migrasi, model, layanan perhitungan, dan pengaturan bobot/predikat/rentang periode.
2. Input nilai guru + nilai ujian otomatis dari jadwal terkunci.
3. Rapor kelas wali, absensi EduWeb, finalisasi dan buka final.
4. Cetak rapor dan tampilan siswa.
5. API baca-saja untuk EduWeb + dokumentasi.
