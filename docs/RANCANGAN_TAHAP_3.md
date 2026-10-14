# Rancangan Tahap 3: sinkron data dari EduWeb

Keputusan pemilik: **1A** (kata sandi siswa sementara lewat kartu ujian; sinkron hash ditunda), **2 setuju** (akun guru dibuat dengan sandi sementara sekali unduh), **3 cocok otomatis**.

## Pemetaan (hasil melihat data asli API)
| EduWeb | Aplikasi ujian | Catatan |
|---|---|---|
| `academic-years` (satu baris per **semester**: name, semester 1/2) | `tahun_ajaran` (per nama) + `periode` (ganjil/genap) | Baris kedua dengan nama sama diarahkan ke tahun ajaran yang sama lewat tabel alias `eduweb_alias` |
| `majors` (name, code) | `jurusan` | cocok lewat kode |
| `classrooms` (grade 10/11/12, major, academic_year, wali) | `kelas` (tingkat X/XI/XII) | cocok lewat tahun ajaran + nama; wali kelas dari guru |
| `subjects` (group teks "Kelompok C3 (Kejuruan)") | `mapel` (kelompok A/B/C1/C2/C3/ML) | cocok lewat kode lalu nama; KKM lokal tidak ditimpa |
| `teachers` (nip, niy, role) | `users` role guru | username = NIP, lalu NIY, lalu dari nama; sandi acak sekali unduh; akun admin tidak pernah diubah |
| `students` (nisn bisa kosong, nis, status, classroom_ids) | `siswa` + `keanggotaan_kelas` | **NISN kosong dipakai NIS sebagai nama pengguna login**; tanpa keduanya dilewati dan dilaporkan |
| `teaching-assignments` | `pengampu` | tambah yang baru; hapus yang tidak ada di EduWeb hanya bila guru, mapel, dan kelasnya semua berasal dari EduWeb |
| `events` (`student.changed`) | status siswa + keanggotaan kelas | idempoten, posisi `last_id` disimpan |

Status siswa: aktif→aktif, alumni→lulus, keluar/pindah/dihapus→keluar. Akun yang tidak aktif di EduWeb tidak bisa login.

## Pencocokan otomatis dengan data lokal (impor Excel)
Siswa: NISN (atau NIS) = nama pengguna lokal. Kelas: tahun ajaran + nama. Jurusan: kode. Mapel: kode, lalu nama persis. Guru: username = NIP/NIY, lalu nama persis bila tunggal. Yang cocok diberi `eduweb_id`; yang konflik (sudah terhubung ke id EduWeb lain, atau ganda) **tidak diubah** dan dilaporkan. Data lokal yang tidak ditemukan di EduWeb dihitung dan dilaporkan, tidak dihapus.

## Aturan
- Hanya membaca dari EduWeb; semua penulisan lokal idempoten (boleh dijalankan berulang).
- Satu sinkron berjalan pada satu waktu (kunci cache). Tanpa cron: tombol "Sinkron penuh" dan "Ikuti kejadian" di halaman admin, serta perintah `php artisan eduweb:sinkron`.
- Sandi siswa baru: hash acak yang tidak diketahui siapa pun (satu bcrypt untuk semua); siswa masuk memakai sandi dari fitur Kartu Ujian.
- Setelah sinkron penuh pertama berhasil, **mode sinkron EduWeb otomatis aktif** (data master terkunci); admin bisa mematikannya di Pengaturan.
- Setiap sinkron dicatat di `sinkron_log` (jenis, ringkasan, galat); kunci tidak pernah dicatat.
- Kejadian yang merujuk siswa/kelas yang belum ada dilewati dengan peringatan (sinkron penuh akan memperbaikinya).
