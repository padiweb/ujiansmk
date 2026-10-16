# Permintaan untuk EduWeb: login sama + sinkron otomatis dengan aplikasi ujian

Konteks: aplikasi ujian (Laravel 12) menarik data dari `/api/exam/v1` EduWeb (hanya baca). Pemilik ingin (1) guru/siswa masuk ke aplikasi ujian dengan username dan sandi yang sama dengan EduWeb, dan (2) perubahan data di EduWeb sampai otomatis ke aplikasi ujian tanpa cron. Rancangan lengkap sisi ujian: `docs/RANCANGAN_OTOMATIS_LOGIN.md` di repo ujian. Mohon kerjakan bagian EduWeb berikut (semua di bawah `exam/v1`, kunci `exu_...` yang sama).

## 1. Tambah `username` pada `GET teachers` dan `GET students`
Kolom `username` (string atau null) dari tabel `users`.

## 2. Endpoint baru `GET credentials` (lingkup `credentials`, mati bawaan)
- `?role=student|teacher&page=&per_page=` (per_page maks 500) dan `?ids=1,2,3` (maks 200 id, boleh dikombinasikan dengan role).
- Bentuk balasan seperti `students` (paginasi Laravel) dengan `data: [{id, role, username, password_hash, password_changed_at}]`.
- `password_hash` = hash bcrypt apa adanya dari `users.password`. Hanya peran siswa/guru/wali kelas milik sekolah kunci tersebut. Jangan pernah admin/superadmin. Jangan sertakan email atau data lain.
- Lingkup ini harus **dinyalakan terpisah** oleh admin di menu Integrasi Ujian (kunci lama tidak otomatis punya). Tanpa lingkup: 403 dengan pesan jelas. Catat tiap pemanggilan di log audit EduWeb (siapa, berapa baris, tanpa hash).

## 3. Kejadian baru di `ExamSyncEvent` (lewat observer model)
- `master.changed` payload `{entity: student|teacher|classroom|subject|major|academic_year|assignment, id, action: created|updated|deleted}` untuk perubahan di tabel terkait (`assignment` = jadwal pelajaran yang membentuk `teaching-assignments`).
- `credential.changed` payload `{entity: student|teacher, id}` saat `password` atau `username` pengguna berubah (termasuk reset sandi dan impor).
- Perubahan massal boleh digabung. Kejadian harus idempoten dan tanpa data sensitif.

## 4. Webhook sinyal ke aplikasi ujian
Setiap kali `ExamSyncEvent` dibuat, kirim sinyal tanpa data setelah respons selesai (debounce maks 1 kali per 5 detik; gagal kirim diabaikan, tanpa antrean):
```
POST <url webhook yang diisi admin>      (HTTPS wajib kecuali host lokal)
X-Eduweb-Timestamp: <detik UTC>
X-Eduweb-Signature: hex(hmac_sha256(rahasia, timestamp . "." . body))
Content-Type: application/json
{"event_id": 1234}
```
Tambahkan di menu Integrasi Ujian: isian URL webhook, rahasia (dibuat acak, tampil sekali untuk disalin ke `.env` ujian sebagai `EDUWEB_WEBHOOK_RAHASIA`), tombol "kirim uji", dan tampilkan status kirim terakhir.

## Catatan
- Aplikasi ujian membuang kejadian yang tidak dikenalnya, jadi menambah jenis baru aman.
- Alamat webhook di ujian: `POST /api/eduweb/webhook` (lokal: `http://127.0.0.1:8124/api/eduweb/webhook` atau port server lokal yang dipakai).
- Temuan lama: `php artisan migrate` pada DB kosong gagal ("Duplicate column name 'expense_id'").
