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

## B. Deploy ke Hostinger (shared hosting, SSH, PHP 8.4)

Hanya dikerjakan setelah pemilik menyetujui. Aplikasi lama (`ujian.smkaltan.sch.id`) tetap hidup sebagai arsip sampai aplikasi baru lancar.
Selama uji coba, aplikasi baru dipasang di **subdomain sementara** (contoh `ujian2.smkaltan.sch.id`).

### B1. Persiapan lokal
- [ ] Cadangkan database `ujian_baru` dan `eduweb` (lokal).
- [ ] `php artisan test` hijau; komit; push ke GitHub atas persetujuan pemilik (EduWeb dipush oleh pemilik).
- [ ] Cabut kunci uji (`exu_…`, `rpr_…`) dan kunci Gemini lama; terbitkan yang baru khusus produksi.

### B2. Di hPanel Hostinger
1. **Subdomain** `ujian2.smkaltan.sch.id`. Atur *document root* ke folder `public` aplikasi, misalnya `domains/smkaltan.sch.id/ujian_baru/public`.
   Kode aplikasi berada **satu tingkat di atas** `public` (jangan menaruh seluruh kode di dalam `public_html`).
2. **PHP**: pilih 8.4 untuk subdomain tersebut. Aktifkan ekstensi `gd`, `mbstring`, `intl`, `zip`, `pdo_mysql`, `fileinfo`, `opcache`.
   Pada *PHP Options* naikkan `memory_limit` (256M) dan `max_execution_time` (300), `upload_max_filesize` 8M.
3. **Database MySQL** baru (`ujian_baru`) dengan pengguna khusus dan kata sandi kuat. Catat host, nama, pengguna, sandi.
4. **SSL**: aktifkan SSL gratis untuk subdomain dan paksa HTTPS.

### B3. Pasang kode lewat SSH
```
ssh -p <port> <pengguna>@<host>
cd domains/smkaltan.sch.id
git clone <alamat-repo> ujian_baru        # repo privat: pakai deploy key / token
cd ujian_baru
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```
Isi `.env` produksi (sunting dengan `nano .env`; jangan dikomit, jangan dibagikan):
```
APP_NAME="Ujian SMK Alhikmah Tanon"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ujian2.smkaltan.sch.id
APP_LOCALE=id
DB_CONNECTION=mysql
DB_HOST=... DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=...
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=sync
EDUWEB_URL=https://<alamat-eduweb>/api/exam/v1
EDUWEB_KUNCI=<kunci baru>
OTOMATIS_DRIVER=gemini
OTOMATIS_MODEL=<model>
OTOMATIS_KUNCI=<kunci baru>
MODE_PENGEMBANG=false
```
Lalu:
```
php artisan migrate --force
php artisan storage:link        # bila symlink diizinkan; bila tidak, hubungi dukungan Hostinger
php artisan optimize
php artisan pengguna:buat --peran=admin     # akun admin produksi, bukan akun uji
chmod -R ug+rwX storage bootstrap/cache
```
Webhook EduWeb diarahkan ke alamat di halaman Sistem (hanya tampil bila `MODE_PENGEMBANG=true`; matikan lagi setelah dipakai). Rahasia webhook di kedua sisi harus sama dan baru.

### B4. Sinkron dan pemindahan data
1. `php artisan eduweb:ping --penuh` (hanya membaca) untuk memastikan EduWeb terjangkau dari server.
2. `php artisan eduweb:sinkron` (sinkron penuh: tahun ajaran, kelas, siswa, guru, mapel, pengampu).
3. Pindahkan data lama sesuai bagian A. Di server, impor ekspor SQL aplikasi lama ke database **salinan** baru (bukan database aplikasi lama), isi `LAMA_DB_*` sementara, jalankan `migrasi:lama` (uji coba) lalu `--simpan`, kosongkan lagi `LAMA_DB_*`, hapus database salinan bila tidak diperlukan.
4. Cron (opsional, cadangan bila webhook terlambat): hPanel > Cron Jobs, tiap 5 menit:
   `cd /home/<pengguna>/domains/smkaltan.sch.id/ujian_baru && php artisan eduweb:sinkron --kejadian`
   Tanpa cron pun aplikasi mengecek sendiri saat ada yang membuka halaman.

### B5. Uji sebelum peralihan
- [ ] Masuk admin dan guru; nilai rapor lama dan US muncul; bank soal utuh.
- [ ] Ujian percobaan penuh (token, mengerjakan, kirim, nilai, koreksi esai, rapor) dengan beberapa HP Android.
- [ ] Uji beban: banyak siswa mengerjakan bersamaan (jumlah sesungguhnya satu angkatan).
- [ ] OPcache aktif (cek `php -i | grep opcache.enable`); halaman terasa cepat.
- [ ] Susun soal otomatis dan pindai foto berfungsi dengan kunci produksi.
- [ ] Cadangan database harian (hPanel > Backups, atau `mysqldump` terjadwal) dan satu kali pulihkan sebagai uji.

### B6. Peralihan ke ujian.smkaltan.sch.id (di luar masa ujian)
Aplikasi baru akhirnya berada di `ujian.smkaltan.sch.id` (alamat yang sama dengan aplikasi lama, supaya siswa dan guru tidak perlu alamat baru).
1. **Cadangan penuh**: ekspor database aplikasi lama (phpMyAdmin) dan unduh folder `uploads` aplikasi lama. Simpan di komputer dan satu tempat lain.
2. **Aplikasi lama jadi arsip**: buat subdomain `arsip.smkaltan.sch.id` dengan document root ke folder aplikasi lama (folder yang sama; file tidak disalin atau dihapus). Pastikan `https://arsip.smkaltan.sch.id` membuka aplikasi lama dan login berhasil.
3. **Pasang aplikasi baru di folder alamat utama**: JANGAN menghapus subdomain `ujian` di hPanel. Lewat SSH, pindahkan folder lama dan arahkan alamat itu ke `public` aplikasi baru:
   `mv <root-ujian> <root-ujian>_lama` lalu `ln -s <folder-aplikasi-baru>/public <root-ujian>`
   (bila symlink tidak diizinkan: ubah document root lewat hPanel atau minta dukungan Hostinger).
   Perhatikan: bila langkah 2 memakai folder yang sama dengan `<root-ujian>`, ubah dulu document root arsip ke `<root-ujian>_lama`.
4. Ubah `.env`: `APP_URL=https://ujian.smkaltan.sch.id`, lalu `php artisan config:clear && php artisan optimize`. Ubah alamat webhook di EduWeb.
5. Periksa: `https://ujian.smkaltan.sch.id` membuka halaman masuk siswa; alamat lama (`/mulai.php`, `/admin`) dialihkan; masuk admin, guru, siswa uji.
6. Pengguna yang sudah memasang aplikasi lama di HP otomatis dibersihkan (service worker pengganti di `public/pwa/sw.js`); bila masih tampak lama, minta tutup lalu buka ulang, atau hapus data situs.
7. Aplikasi lama (arsip) baru boleh dimatikan setelah aplikasi baru lancar **dan** seluruh data yang dibutuhkan terbukti sudah ada di aplikasi baru.

Cara mundur bila bermasalah: hapus symlink, kembalikan `<root-ujian>_lama` ke nama semula; aplikasi lama hidup kembali tanpa perubahan data.

### B7. Pembaruan berikutnya
```
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
```
Cadangkan database sebelum `migrate`. Jangan memperbarui saat ujian berlangsung.
