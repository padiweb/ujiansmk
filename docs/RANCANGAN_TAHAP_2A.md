# Rancangan Tahap 2a: Rapor (revisi 2, 21 Okt 2026)

Status: **menunggu persetujuan pemilik** (belum ada kode). Cakupan: Input Nilai (guru mapel), Wali Kelas, finalisasi, cetak rapor, tampilan siswa, API baca-saja untuk EduWeb. **Di luar cakupan** (2b/2c): Kenaikan Kelas, Ekskul, Transkrip/US, SKL & Mutasi, Arsip Siswa, Statistik, kisi-kisi, impor Excel nilai lampau, migrasi data lama.

Revisi 2 memuat keputusan pemilik (21 Okt 2026): nilai Ujian otomatis dari jadwal terkunci tetapi **bisa diedit guru**; **nilai tugas dari EduWeb** ikut membentuk nilai; guru mapel mengisi semua komponen; absensi ditarik dari EduWeb lalu boleh dikoreksi wali kelas; wali kelas memfinalkan, admin dapat membuka final. Revisi ini juga menyesuaikan dengan perubahan sejak revisi 1: kunci nilai kini **per jadwal dan kelas** (`kunci_nilai`), jadwal berada di bawah **Kegiatan Ujian**, dan menu mengikuti aplikasi lama (**Input Nilai**, **Wali Kelas**).

## 1. Yang sudah ada dan dipakai ulang
- Bobot rapor dan skala predikat di pengaturan sekolah (ditambah layar pengaturan bila belum ada).
- `hasil_ujian.skor_akhir`, hanya dari jadwal yang **kelasnya sudah dikunci** (`kunci_nilai`: jadwal + kelas).
- `pengampu` (guru, mapel, kelas) menentukan siapa mengisi nilai mapel mana; `kelas.wali_kelas_id` menentukan wali kelas.
- Klien EduWeb (`KlienEduweb`), `siswa.eduweb_id`, `mapel.eduweb_id`, `kelas.eduweb_id` untuk mencocokkan data.

## 2. Rumus (dari aplikasi lama; bobot dapat diatur admin)
- Bobot **semua komponen diatur admin** di Pengaturan Rapor (tiap sekolah bisa berbeda). Komponen berbobot 0 tidak dipakai.
- `NP (Pengetahuan) = Ujian + Tugas + Harian + TugasAkhir`, masing-masing × bobotnya (bawaan lama: 20/0/60/20; total 100; bulat)
- `NK (Keterampilan) = Praktik + Adab` × bobot (bawaan 70/30; total 100, atau semua 0 bila tidak dipakai; bulat)
- `NA = NP × bobot NP + NK × bobot NK` (bawaan 50/50; bulat). NK=0 dan NP>0 → NA=NP; NP=0 dan NK>0 → NA=NK.
- Predikat dari skala; ketuntasan dibanding KKM mapel. Bobot yang tidak berjumlah 100 per kelompok ditolak.
- Rumus dihitung ulang di server setiap simpan; tidak ada rumus yang dipercaya dari peramban.

## 3. Sumber tiap komponen (keputusan 21 Okt)
| Komponen | Sumber awal | Dapat diedit guru |
|---|---|---|
| Ujian | otomatis: rata-rata `skor_akhir` jadwal yang dipilih sebagai sumber, hanya bila kelas siswa sudah **dikunci** | ya (ditandai "diubah guru") |
| Harian | otomatis: **rata-rata nilai tugas biasa dan kuis siswa di EduWeb** (mapel + kelas + rentang semester; dinormalkan ke 0–100 memakai `max_score`; hanya yang sudah dinilai). Nilai tugas EduWeb adalah komponen Harian itu sendiri (bukan komponen terpisah); bobotnya diatur admin | ya (ditandai "diubah guru") |
| Harian, Tugas Akhir, Praktik, Adab | diisi guru mapel | ya |
Penanda sumber (`otomatis`/`manual`) disimpan per komponen; tombol "Ambil ulang dari sumber" mengembalikan nilai otomatis. Bila EduWeb tidak terjangkau, kolom Harian kosong dan guru mengisi sendiri (tidak menghalangi).

## 4. Alur kerja (nama menu mengikuti aplikasi lama)
1. **Pengaturan** (admin): periode aktif + rentang tanggalnya (untuk absensi dan tugas), pemilihan jadwal/kegiatan yang menjadi sumber nilai Ujian, bobot, skala predikat.
2. **Input Nilai** (guru mapel): daftar mapel + kelas yang diampu dengan "Status Input" → tabel siswa: Ujian, Harian (terisi otomatis), Tugas Akhir, Praktik, Adab, Catatan. NP/NK/NA/predikat tampil langsung. Bisa tempel dari Excel.
3. **Wali Kelas** (wali): kelengkapan nilai semua mapel, absensi (tarik dari EduWeb per semester, boleh diubah manual), catatan wali, keputusan (naik/tinggal).
4. **Finalisasi** per kelas oleh wali kelas: cek kelengkapan lalu **membekukan** nilai + snapshot (nama mapel, nama kelas, KKM, bobot, skala). Setelah final tidak bisa diubah; **admin** membuka final dengan alasan wajib (audit).
5. **Cetak**: rapor per siswa atau satu kelas; tata letak meniru rapor lama (kop, identitas, tabel nilai, absensi, catatan, tanda tangan).
6. **Siswa** melihat rapor yang sudah final (baca saja). Draf tidak terlihat.
7. **EduWeb** membaca rapor lewat API baca-saja (bagian 6).

## 5. Data
- `nilai_rapor`: siswa_id, mapel_id, periode_id, kelas_id, guru_id, ujian, harian, tugas_akhir, praktik, adab (masing-masing + `*_sumber`), catatan_guru, np, nk, na, predikat, status (draf|final), snapshot (mapel_nama, kelas_nama, kkm, bobot json, skala json). Unik (siswa_id, mapel_id, periode_id).
- `rapor_siswa`: siswa_id, periode_id, kelas_id, kelas_nama (snapshot), sakit, izin, alpa, sumber_absensi, catatan_wali, keputusan, status (draf|final), difinalkan_oleh/pada. Unik (siswa_id, periode_id).
- Kolom baru: `periode.mulai`, `periode.selesai`; penanda sumber nilai ujian rapor pada `jadwal_ujian` (atau kegiatan).
- Semua perubahan nilai dan finalisasi masuk `audit_log`.

## 6. Integrasi EduWeb (dua arah, keduanya server-ke-server)
**Dibutuhkan perubahan di EduWeb (repo terpisah; pemilik yang mem-push):**
- Endpoint baru di API ujian yang sudah ada: `GET /api/exam/v1/assignment-scores?classroom_id=&subject_id=&from=&to=` → per siswa: rata-rata nilai tugas (0–100), jumlah tugas dinilai, jumlah tugas. Memakai kunci yang sama dengan sinkron master data dan lingkup baru (mis. `scores`) yang bisa dinyalakan/dicabut di menu Integrasi Ujian.
- Endpoint absensi per siswa per rentang tanggal (sakit/izin/alpa) bila belum ada.
**Sisi ujian:** klien memanggilnya saat guru membuka Input Nilai atau menekan "Ambil ulang"; hasil disimpan ke `nilai_rapor` (bukan dipanggil terus-menerus). Panggilan dibatasi per kelas.
**API baca-saja untuk EduWeb:** `/api/rapor/v1/...` (kunci `api_client` berhash, dapat dicabut, dicatat): `GET periode`, `GET kelas/{eduweb_id}/rapor?periode=`, `GET siswa/{eduweb_id}/rapor?periode=`. Hanya rapor final, hanya baca.

## 7. Pengujian
- Rumus (pembulatan, NK/NP kosong), hak akses (guru hanya mapel+kelasnya, wali hanya kelasnya, siswa hanya rapornya), nilai ujian hanya dari kelas terkunci, penimpaan guru bertahan saat "ambil ulang" ditolak/diizinkan sesuai penanda, finalisasi membekukan snapshot, buka final wajib alasan, EduWeb mati tidak menghalangi, API menolak kunci salah/dicabut dan hanya memberi data final.
- Uji peramban dengan SQLite sementara; pemilik menguji alur lengkap di lokal.

## 8. Urutan pengerjaan (commit terpisah, uji hijau tiap langkah)
1. Migrasi, model, layanan perhitungan, pengaturan bobot/predikat/rentang periode.
2. Input Nilai guru + nilai ujian otomatis dari kelas terkunci.
3. EduWeb: endpoint nilai tugas + klien di ujian + isian Harian otomatis (commit EduWeb dipisah, pemilik push).
4. Wali Kelas: absensi EduWeb, catatan, keputusan, finalisasi dan buka final.
5. Cetak rapor, tampilan siswa, API baca-saja + dokumentasi.

## 9. Hal yang perlu dikonfirmasi
- **Pemetaan tugas → Harian** (keputusan pemilik, 22 Okt 2026): rata-rata nilai tugas EduWeb mengisi komponen **Harian**; tidak ada komponen "Tugas" terpisah (kolom `harian_sumber` menandai otomatis/manual).
- Tugas bertipe kuis dan tugas biasa **sama-sama** dihitung selama sudah bernilai.
