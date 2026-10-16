# Rancangan: login satu akun dengan EduWeb + sinkron otomatis

Disetujui pemilik (16 Okt 2026): cara **A** (hash kata sandi disinkronkan), sinkron otomatis lewat webhook + pengecekan cadangan + jeda saat ujian, dikerjakan **sebelum Tahap 2a**.

## 1. Tujuan
1. Guru dan siswa masuk ke aplikasi ujian dengan **nama pengguna dan kata sandi yang sama dengan EduWeb**.
2. Perubahan data di EduWeb (siswa, guru, kelas, mapel, jurusan, tahun ajaran, penugasan, sandi) sampai ke aplikasi ujian **tanpa tombol manual dan tanpa cron**.

## 2. Aturan login (mengikuti EduWeb)
- EduWeb: pengguna masuk dengan `username`, dan bila tidak ada, dengan NISN. Aplikasi ujian meniru urutan yang sama untuk siswa: cocokkan `username` dulu, lalu `nisn` (NISN kosong di EduWeb → NIS dipakai seperti sebelumnya).
- Guru: `username` EduWeb. Bila guru belum punya username di EduWeb: NIP, lalu NIY (perilaku lama).
- Akun admin lokal tidak pernah disentuh sinkron, dan tidak pernah menerima hash dari EduWeb.
- Kata sandi tidak pernah disimpan atau dicatat dalam bentuk asli. Yang disimpan hanya hash bcrypt (sama dengan EduWeb). Hash tidak pernah masuk log, riwayat sinkron, audit, maupun tampilan.
- Penerimaan hash divalidasi bentuknya (`$2y$…` bcrypt). Bentuk lain ditolak dan dilaporkan sebagai peringatan, akun tetap memakai sandi lama.
- Kolom baru `eduweb_hash_sandi` (di `users` dan `siswa`) menyimpan hash EduWeb terakhir yang dilihat. Sandi lokal hanya diganti bila hash EduWeb **berubah**. Dengan begitu sandi sementara dari Kartu Ujian (cadangan bagi siswa yang lupa) tidak tertimpa oleh sinkron ulang, kecuali sandi di EduWeb memang diganti.

## 3. Kontrak yang harus disediakan EduWeb (pekerjaan sesi EduWeb)
Semua di bawah `/api/exam/v1`, kunci `exu_...` yang sama.

### 3.1 `username` pada daftar
`GET teachers` dan `GET students`: tambahkan kolom `username` (string atau null).

### 3.2 Kredensial (akses terpisah dan opsional)
`GET credentials?role=student|teacher&page=&per_page=` dan `GET credentials?ids=1,2,3` (maksimal 200 id):
```json
{ "current_page":1, "last_page":3, "data":[ {"id":12, "role":"student", "username":"budi", "password_hash":"$2y$12$...", "password_changed_at":"2026-10-15T08:00:00+07:00"} ] }
```
- Hanya aktif bila kunci punya lingkup (*scope*) `credentials`. Lingkup ini **mati secara bawaan** dan dinyalakan admin EduWeb di menu Integrasi Ujian. Tanpa lingkup: balas 403.
- Hanya pengguna sekolah pemilik kunci dengan peran siswa/guru/wali kelas. Tidak pernah admin/superadmin.
- Hanya kolom di atas. Tidak ada email atau data pribadi lain. Setiap pemanggilan dicatat di log EduWeb.

### 3.3 Kejadian baru pada `GET events`
Jenis yang sudah ada tetap: `student.changed`. Tambahan (idempoten, `payload` tanpa data sensitif):
| type | kapan | payload |
|---|---|---|
| `master.changed` | siswa, guru, kelas, mapel, jurusan, tahun ajaran, atau penugasan dibuat/diubah/dihapus | `{entity: student\|teacher\|classroom\|subject\|major\|academic_year\|assignment, id, action: created\|updated\|deleted}` |
| `credential.changed` | kata sandi atau username pengguna berubah | `{entity: student\|teacher, id}` |

Cara paling mudah: *observer* model yang memanggil `ExamSyncEvent::record(...)`. Perubahan massal (impor, kenaikan kelas) boleh dikumpulkan satu kejadian per entitas.

### 3.4 Webhook ke aplikasi ujian
Setiap kali `ExamSyncEvent` dibuat, EduWeb mengirim sinyal (tanpa data) setelah respons selesai, dengan *debounce* (maksimal satu per 5 detik, digabung):
```
POST {EDUWEB_WEBHOOK_URL}           (mis. https://ujian.sekolah.sch.id/api/eduweb/webhook)
X-Eduweb-Timestamp: 1760600000      (detik, UTC)
X-Eduweb-Signature: hex(HMAC-SHA256(rahasia, timestamp + "." + body))
Content-Type: application/json
{"event_id": 1234}
```
- Rahasia dibuat di EduWeb, disalin sekali ke `.env` ujian (`EDUWEB_WEBHOOK_RAHASIA`). Tidak pernah dikirim lewat API.
- Gagal kirim tidak boleh mengganggu proses di EduWeb (abaikan galat, tidak perlu antrean). Pengecekan cadangan di sisi ujian menutup kejadian yang terlewat.
- HTTPS wajib kecuali host lokal.

## 4. Sisi aplikasi ujian
### 4.1 Pemicu sinkron otomatis
1. **Webhook** `POST /api/eduweb/webhook`: tanda tangan dan stempel waktu (toleransi 5 menit) diperiksa, tanda tangan yang sama tidak diterima dua kali, ukuran isi dibatasi, dibatasi laju. Hasil: balas 202 segera, lalu sinkron kejadian berjalan **setelah respons terkirim**. Webhook palsu hanya bisa memicu sinkron yang aman diulang, tidak bisa menyuntikkan data.
2. **Cadangan tanpa cron**: saat halaman staf atau halaman masuk dibuka, bila sinkron terakhir lebih dari 5 menit lalu, sinkron kejadian dijalankan setelah respons. Satu proses saja (kunci cache); yang lain tidak menunggu.
3. Tombol manual tetap ada (sinkron penuh, ikuti kejadian).

### 4.2 Jeda saat ujian
Sinkron otomatis **tidak jalan** bila ada jadwal aktif yang sedang di antara `mulai_pada` dan `tutup_pada`, atau ada sesi `berlangsung`. Kejadian tetap tercatat di EduWeb dan diterapkan otomatis setelah ujian selesai. Halaman Sinkron EduWeb menampilkan "Tertahan karena ujian berlangsung". Tombol manual tetap bisa dipakai admin (dengan peringatan, seperti sekarang).

### 4.3 Penerapan kejadian
- `student.changed`: seperti sekarang.
- `master.changed`: menandai perlu penyegaran data master. Setelah semua kejadian di batch dibaca, **satu** sinkron penuh dijalankan (sudah idempoten, cukup cepat). Posisi kejadian baru disimpan **setelah** penyegaran berhasil; bila gagal, kejadian dibaca ulang pada percobaan berikutnya.
- `credential.changed`: mengambil kredensial hanya untuk id terkait (`credentials?ids=`), lalu menerapkan username dan hash.
- Sinkron penuh juga mengambil seluruh kredensial (bila lingkup aktif). Bila EduWeb membalas 403/404, sinkron data tetap berjalan dan muncul peringatan "kredensial belum diaktifkan di EduWeb"; login jatuh kembali ke sandi lokal (Kartu Ujian).

### 4.4 Perubahan data lokal
- `siswa.username` (unik, boleh kosong), `siswa.eduweb_hash_sandi`, `users.eduweb_hash_sandi`.
- `pengaturan_sekolah`: waktu pengecekan terakhir, waktu webhook terakhir, jumlah kejadian tertahan (hanya untuk tampilan).
- Konfigurasi `.env`: `EDUWEB_WEBHOOK_RAHASIA`, `EDUWEB_CEK_MENIT` (bawaan 5).
- Guru baru tanpa hash dari EduWeb tetap memakai sandi acak sekali-unduh (perilaku lama). Guru baru dengan hash EduWeb tidak butuh daftar sandi.

### 4.5 Halaman Sinkron EduWeb
Menampilkan: status otomatis (webhook terakhir diterima, pengecekan terakhir, tertahan/tidak), status kredensial (aktif/belum diaktifkan di EduWeb), peringatan bila rahasia webhook belum diisi. Teks "siswa baru belum punya kata sandi" diubah sesuai kenyataan baru.

## 5. Keamanan (ringkas)
- Hash sandi: hanya lewat HTTPS (atau host lokal), kunci server-ke-server, lingkup khusus mati-bawaan di EduWeb, tidak pernah dicatat/ditampilkan, bentuk bcrypt divalidasi.
- Webhook: HMAC-SHA256, bandingkan dengan `hash_equals`, stempel waktu, penolakan ulang, batas ukuran, batas laju, tanpa data di isi pesan.
- Pencegahan penyalahgunaan: sinkron otomatis memakai kunci `eduweb:sinkron` yang sama dengan sinkron manual (tidak bisa jalan dobel), dan tidak menyentuh akun admin.
- Pengguna yang dinonaktifkan atau dihapus di EduWeb menjadi nonaktif di sini pada sinkron berikutnya, jadi tidak bisa masuk.

## 6. Pengujian
Tes otomatis dengan `Http::fake` untuk seluruh kontrak (tanpa EduWeb asli): username & hash diterapkan; hash tak valid ditolak; sandi lokal tidak tertimpa bila hash EduWeb tak berubah; admin tidak pernah diubah; 403 kredensial menjadi peringatan; kejadian `master.changed` memicu satu sinkron penuh dan kursor maju hanya bila berhasil; webhook: tanda tangan salah/kedaluwarsa/ulang ditolak, yang benar menjalankan sinkron; jeda saat ujian berlangsung; pengecekan cadangan hanya satu kali per 5 menit; login siswa lewat username dan lewat NISN. Uji peramban memakai SQLite sementara. Uji nyata ke EduWeb menunggu sesi EduWeb menyelesaikan bagiannya.

## 7. Urutan commit
1. Dokumen ini + kontrak untuk sesi EduWeb (`docs/PESAN_UNTUK_SESI_EDUWEB.md`).
2. Migrasi + login (username/NISN) + penerapan username dan hash pada sinkron penuh.
3. Kejadian baru (`master.changed`, `credential.changed`) dan kursor aman.
4. Pemicu otomatis: webhook, pengecekan cadangan, jeda saat ujian, tampilan status.
