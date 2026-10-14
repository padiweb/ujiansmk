# Rancangan Bank Soal (Tahap 1, langkah 3)

Status: **DRAF, menunggu persetujuan.** Belum ada kode.

## 1. Yang dilakukan aplikasi lama (hasil baca kode)
- "Bank soal" = gabungan **nama mapel (teks) + tingkat + semester**. Soal PG (opsi A–D, kunci, gambar) dan esai (pertanyaan, gambar) ada di tabel terpisah.
- Soal terikat ke semester, jadi setiap semester soal dibuat ulang atau disalin.
- Gambar diunggah langsung ke folder publik (`move_uploaded_file`), tanpa pemeriksaan isi gambar yang ketat.
- Penyusun soal otomatis (Gemini): admin/guru mengisi topik dan jumlah (1–10), hasilnya jadi soal PG, esai, atau campuran.
- Kisi-kisi dicetak dari soal yang ada (ditunda ke Tahap 2).

## 2. Rancangan baru

### Tabel
- `bank_soal`: id, mapel_id, tingkat (X|XI|XII), nama, dibuat_oleh → users, timestamps. Satu bank = kumpulan soal satu mapel dan tingkat; bisa dipakai ulang di banyak semester.
- `soal`: id, bank_soal_id, tipe (pg|esai), pertanyaan, gambar (jalur berkas), bobot (bawaan 1), rubrik_esai (jawaban acuan untuk koreksi), status (draf|siap), sumber (manual|otomatis|impor), urutan, timestamps.
- `opsi_soal`: id, soal_id, label (A–E), teks, gambar?, benar (bool). Tepat satu opsi benar untuk soal PG; 2–5 opsi.
- `penyedia_otomatis` (sudah di rancangan awal): nama, driver (gemini|openai|anthropic), model, kunci terenkripsi, aktif.
- `log_otomatis`: siapa, penyedia, jumlah soal diminta, hasil (berhasil/gagal), waktu. Untuk batas pemakaian dan audit biaya.

### Hak akses
- **Admin:** semua bank soal.
- **Guru:** hanya bank untuk mapel dan tingkat yang diampunya (dari tabel `pengampu`).
- **Pengawas:** tidak mengelola soal.
- Yang membuat soal tidak bisa melihat soal milik guru lain kecuali admin.

### Aturan penting
- **Hanya soal berstatus `siap` yang bisa masuk jadwal ujian.** Soal hasil penyusun otomatis selalu masuk sebagai `draf` dan harus ditinjau guru (baca, perbaiki, tandai siap). Keluaran model bahasa tidak boleh langsung dipakai ujian.
- Kunci jawaban tidak pernah dikirim ke peramban siswa (seperti aplikasi lama).
- Jadwal ujian membekukan daftar soalnya saat diaktifkan, jadi mengedit bank soal sesudahnya tidak mengubah ujian yang berjalan.
- Soal yang sudah pernah dipakai ujian tidak dihapus keras, hanya diarsipkan, supaya hasil lama tetap bisa dilacak.

### Gambar
- Hanya JPG, PNG, WEBP; maksimal 2 MB. Isi berkas diperiksa sebagai gambar sungguhan dan dibuat ulang (bukan sekadar cek ekstensi), nama berkas diacak.
- Disimpan di folder privat dan **disajikan lewat rute yang memeriksa hak akses** (staf, atau siswa yang sedang mengerjakan ujian itu). Tidak ada URL publik, jadi gambar soal tidak bisa dibuka orang luar sebelum ujian, dan tidak butuh symlink di hosting.

### Impor soal dari Excel
Kolom: Tipe (PG/Esai), Pertanyaan, Opsi A–E, Kunci, Bobot, Rubrik. Memakai mesin impor yang sama (periksa dulu, semua-atau-tidak-sama-sekali). Gambar ditambahkan manual setelah impor.

### Penyusun soal otomatis
- Antarmuka `PenyusunSoal` dengan satu driver per penyedia (Gemini lebih dulu; OpenAI/Anthropic menyusul lewat pengaturan).
- Kunci disimpan terenkripsi di database (admin mengisi di Pengaturan), tidak di kode.
- Batas pemakaian per guru per hari (bawaan 30 permintaan) dan maksimal 10 soal per permintaan, dicatat di `log_otomatis`.
- Isian guru (topik, materi) dikirim sebagai data, bukan perintah; keluaran divalidasi ketat (JSON sesuai skema, tepat satu kunci benar) sebelum disimpan sebagai draf.
- Tampilan memakai label **"Otomatis"**, tanpa tulisan "AI".

### Layar
1. Daftar bank soal (filter mapel/tingkat, jumlah soal PG/esai/draf).
2. Detail bank: daftar soal, tambah PG, tambah esai, impor Excel, susun otomatis, tinjau draf.
3. Formulir soal: pertanyaan, gambar, opsi, kunci, bobot, status.
4. Pengaturan penyedia otomatis (admin).

## 3. Pertanyaan untuk Bapak/Ibu
1. **Format teks soal.** Awal: teks biasa (baris baru dipertahankan) + gambar. Atau perlu editor yang bisa tebal/miring/tabel/rumus matematika? Editor kaya lebih lama dibuat dan butuh pembersihan keamanan ekstra; rumus bisa ditambahkan nanti. Saran: mulai dengan teks biasa + gambar.
2. **Bank dipakai ulang lintas semester** (bukan dibuat ulang tiap semester seperti aplikasi lama). Setuju?
3. **Jumlah opsi PG.** Boleh 2–5 per soal (A–E). Setuju, atau tetap 4 (A–D)?
4. **Soal otomatis selalu draf** dan harus ditinjau guru sebelum dipakai. Setuju?
5. **Hak guru:** hanya bank untuk mapel dan tingkat yang diampu. Setuju?
