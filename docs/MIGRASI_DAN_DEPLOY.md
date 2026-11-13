# Migrasi data lama dan deploy

## A. Memindahkan data dari aplikasi lama

Yang dipindahkan: **nilai rapor semester lama**, **nilai Ujian Sekolah (US)**, dan **bank soal**. Data induk (siswa, guru, kelas, mapel) tidak
dipindahkan: datang dari EduWeb. Siswa dicocokkan lewat **NISN**, mapel lewat **nama** (tanpa membedakan huruf besar/kecil).

Aplikasi lama tidak disentuh sama sekali (hanya dibaca dari salinan).

1. **Cadangkan** database aplikasi baru (`ujian_baru`) dan jalankan `php artisan migrate` bila ada migrasi baru.
2. Di aplikasi lama (phpMyAdmin Hostinger) **ekspor** database lama (format SQL). Impor ke database **salinan** di MySQL lokal, misalnya `ujian_lama_salinan`.
   Jangan mengarahkan perintah ini ke database produksi aplikasi lama.
3. Isi di `.env` aplikasi baru (sementara):
   `LAMA_DB_DATABASE=ujian_lama_salinan` (dan `LAMA_DB_USERNAME`, `LAMA_DB_PASSWORD` bila perlu). Lalu `php artisan config:clear`.
4. Pastikan **Sinkron EduWeb sudah dijalankan** (siswa dan mapel sudah ada di aplikasi baru).
5. **Uji coba dulu** (tidak menyimpan apa pun):
   `php artisan migrasi:lama`
   Baca tabel hasil dan peringatannya (siswa/mapel yang tidak ditemukan, soal yang dilewati).
6. Bila sudah sesuai, jalankan **simpan**:
   `php artisan migrasi:lama --simpan`
   Bisa per bagian: `--bagian=nilai`, `--bagian=us`, `--bagian=soal`. Aman diulang (nilai ditimpa pada siswa+semester+mapel yang sama; soal yang sama persis dilewati).
7. Kosongkan lagi `LAMA_DB_*` di `.env`.

Catatan:
- Semester lama dibuat otomatis sebagai semester **"rapor tidak diisi di sini"**; semester yang rapornya diisi di aplikasi baru tidak pernah ditimpa.
- Gambar soal **tidak ikut dipindah** (jumlahnya dilaporkan sebagai `soal_gambar_tidak_dipindah`); unggah ulang di Bank Soal bila perlu.
- Siswa yang sudah tidak ada di EduWeb (misalnya alumni lama) dilaporkan sebagai tidak ditemukan.

## B. Daftar periksa deploy ke Hostinger

Hanya dikerjakan setelah pemilik menyetujui.

- [ ] Cadangkan database `ujian_baru` dan `eduweb` (lokal) sebelum push.
- [ ] Push kedua repo ke GitHub (EduWeb dipush oleh pemilik).
- [ ] Server: `git pull`, `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `php artisan optimize`.
- [ ] `.env` produksi: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` HTTPS, database produksi, `EDUWEB_URL` (HTTPS) dan `EDUWEB_KUNCI` baru, rahasia webhook baru.
- [ ] Buat akun admin produksi (`php artisan pengguna:buat --peran=admin`), jangan memakai akun uji.
- [ ] Cabut kunci uji (`exu_…`, `rpr_…`) dan kunci Gemini lama; terbitkan yang baru.
- [ ] OPcache aktif di PHP server (tanpa itu halaman lambat).
- [ ] Uji beban (banyak siswa mengerjakan bersamaan) dan uji di Android.
- [ ] Tautan Storage / izin folder `storage` dan `bootstrap/cache`.
