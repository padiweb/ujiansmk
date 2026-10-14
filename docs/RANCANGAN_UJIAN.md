# Rancangan Jadwal, Pelaksanaan Ujian, Anti Curang, dan Monitor (Tahap 1, langkah 4–7)

Status: **DISETUJUI (revisi 3).** Memasukkan jawaban pemilik (bagian 2), target kapasitas di shared hosting (bagian 9), kebijakan **tanpa cron** (bagian 5), dan aplikasi ujian di **akun hosting yang sama** dengan EduWeb (bagian 12).

## 1. Hasil baca aplikasi lama

**Alur lama:** siswa login → dashboard → pilih mapel → isi token → `ujian.php` (semua soal dimuat sekaligus) → jawaban di `localStorage` → tombol Kirim mengirim semuanya sekali → server menilai PG.

**Kelemahan yang harus tidak terulang:**
| Masalah lama | Akibat |
|---|---|
| Jawaban hanya di `localStorage` sampai Kirim | HP mati/data browser terhapus = jawaban hilang |
| Durasi tetap 7200 dtk, batas waktu di `localStorage` | Siswa bisa mengubah batas waktu sendiri |
| Jadwal dicari lewat nama mapel, `LIMIT 1` | Dua kelas ujian mapel sama bisa tertukar |
| Penghitung pelanggaran di sesi PHP | Bisa direset dengan login ulang/hapus cookie |
| Blokir menempel di akun siswa | Berlaku untuk semua ujian |
| `ORDER BY RAND()`; opsi tidak diacak | Soal berubah tiap muat ulang; opsi tidak teracak |
| Tidak ada penetapan kelas/ruang di jadwal | Peserta ditebak dari tingkat kelas |
| Status jadwal Aktif/Selesai manual | Mudah lupa menutup |

**Dipertahankan:** kunci jawaban tidak pernah ke peramban, PG dinilai di server, 2 peringatan + 1 blokir, notifikasi suara di monitor, jawaban dipulihkan setelah diaktifkan kembali, dedup pelanggaran sejenis, kekebalan papan ketik.

## 2. Keputusan pemilik (isi sebagai acuan)
1. **Satu perangkat:** perangkat kedua **ditolak**; **pengawas yang mereset**.
2. **Waktu:** durasi dihitung **sejak siswa memulai**. Ujian bisa dimulai pada jam yang ditentukan, atau di jam lain selama jadwal masih aktif (di dalam rentang buka–tutup jadwal).
3. **Soal:** **satu set soal per jadwal**; **urutan soal dan opsi diacak per siswa** agar tidak bisa menyontek teman.
4. **Batas pelanggaran:** **2 peringatan + blokir** (pelanggaran ke-3), **dapat diubah per jadwal**.
5. **Nilai:** **langsung tampil untuk PG** begitu siswa mengirim. Esai menunggu koreksi. Yang tampil hanya skor, bukan kunci atau pembahasan.
6. **Kapasitas:** tidak dibatasi; kira-kira **300–2.000 siswa serentak atau lebih**. Hosting: **shared hosting**, kemungkinan pindah ke VPS.

## 3. Prinsip
- **Server adalah sumber kebenaran:** waktu, jumlah pelanggaran, status sesi, dan nilai dihitung di server. Peramban hanya melapor.
- **Kunci jawaban tidak pernah meninggalkan server** sebelum ujian berakhir.
- **Permintaan ke server dibuat sesedikit mungkin** (bagian 9), karena itu penentu utama apakah 300–2.000 siswa bisa dilayani di shared hosting.
- **Keterbatasan jujur:** pendeteksi anti curang berbasis web dapat dilewati siswa yang paham teknis (mis. mengubah JavaScript). Web hanya bisa **menyulitkan dan mencatat**; lapis yang kuat adalah pembungkus Android (Tahap 4). Semua kejadian terekam sebagai bukti.

## 4. Tabel

### Jadwal
- `jadwal_ujian`: id, periode_id, mapel_id, nama, durasi_menit, mulai_pada, tutup_pada (batas terakhir mulai), token (5 huruf/angka, unik), status (`draf`|`aktif`|`selesai`), acak_soal, acak_opsi, batas_pelanggaran (bawaan 3), bobot_pg (0–100; esai = 100 − bobot_pg), tampilkan_hasil (bawaan `langsung`), dibuat_oleh, timestamps
- `jadwal_kelas`: jadwal_id, kelas_id — boleh banyak kelas dan tingkat berbeda untuk mapel yang sama pada jam yang sama.
- `jadwal_bank`: jadwal_id, bank_soal_id, jumlah_pg, jumlah_esai — dari bank mana dan berapa soal diambil.
- `jadwal_soal`: jadwal_id, soal_id, urutan, bobot — **dibekukan saat diaktifkan**.
- `jadwal_pengawas`: jadwal_id, user_id, ruang_id?

### Pelaksanaan
- `sesi_ujian`: id, jadwal_id, siswa_id, ruang_id?, status (`belum`|`berlangsung`|`selesai`|`diblokir`), mulai_pada, batas_selesai, selesai_pada, alasan_selesai (`kirim`|`waktu_habis`|`dipaksa`), jumlah_pelanggaran, token_perangkat (hash), platform, info_peramban, ip, terakhir_aktif, susunan (json: urutan soal dan opsi per siswa), tambahan_menit. Unik (jadwal_id, siswa_id).
- `jawaban`: sesi_id, soal_id, opsi_label?, teks_esai?, dijawab_klien, diterima_pada, nilai_esai?, dikoreksi_oleh?, dikoreksi_pada?. Unik (sesi_id, soal_id).
- `pelanggaran`: sesi_id, jenis, keterangan, dihitung (bool), info (json), created_at.
- `hasil_ujian`: sesi_id (unik), benar, salah, kosong, skor_pg, skor_esai?, skor_akhir?, status_koreksi, snapshot (nama mapel, kelas, jadwal).
- `aksi_pengawas`: sesi_id, user_id, aksi (`aktifkan_kembali`|`reset_perangkat`|`tambah_waktu`|`paksa_selesai`), detail, created_at.

## 5. Alur ujian

### Admin/guru menyiapkan jadwal
1. Buat jadwal: mapel, nama, periode, durasi, rentang buka–tutup, kelas, bank soal dan jumlahnya, bobot PG/esai, batas pelanggaran, pengawas.
2. **Aktifkan:** sistem memeriksa (ada soal siap, ada kelas, ada siswa aktif), membekukan `jadwal_soal`, **menyiapkan paket soal** (bagian 9), membuat token, dan membuat `sesi_ujian` berstatus `belum` untuk semua peserta.
3. Pengawas membacakan token di ruang. Token bisa dibuat ulang; sesi yang sedang berjalan tidak terpengaruh.
4. **Selesai:** otomatis setelah rentang tutup lewat dan semua sesi selesai, atau manual ("Selesaikan jadwal" memaksa semua sesi berjalan selesai dan menilai).

### Siswa
1. Login NISN → beranda menampilkan jadwal yang cocok (kelasnya, aktif, dalam rentang, belum selesai).
2. Pilih ujian → isi token (dibatasi percobaan, perbandingan waktu-konstan) → halaman aturan + tombol **Mulai** (masuk layar penuh).
3. Server menyusun urutan soal dan opsi **khusus siswa itu**, menetapkan `batas_selesai = sekarang + durasi`, membuat `token_perangkat`, dan mengirim paket soal **tanpa kunci**.
4. Siswa mengerjakan; jawaban ditulis dulu ke penyimpanan lokal peramban dan **disinkronkan berkala** ke server (bagian 9).
5. **Kirim** (atau waktu habis): sinkron terakhir → server menandai selesai dan menilai PG → halaman hasil menampilkan skor PG (esai: "menunggu koreksi guru").

### Satu perangkat aktif
Saat sesi berlangsung, perangkat/peramban lain **ditolak**. Muat ulang di perangkat yang sama tetap bisa melanjutkan. Bila HP siswa rusak/mati, **pengawas menekan "Reset perangkat"** (tercatat); jawaban yang sudah tersinkron dan sisa waktu tetap.

### Waktu
- Jam server menentukan batas. Penghitung di layar dikoreksi dengan waktu server pada tiap sinkron, jadi mengubah jam HP atau `localStorage` tidak berpengaruh.
- Waktu **tetap berjalan saat koneksi putus**. Pengawas dapat menambah menit per siswa bila ada gangguan (tercatat).
- Sinkron terakhir diterima sampai **90 detik setelah batas** (untuk meredam lonjakan ketika banyak siswa selesai bersamaan); jawaban yang diterima setelah batas ditandai.
- **Tanpa cron.** Sesi yang waktunya habis ditutup dan dinilai secara **oportunistik**: setiap sinkron siswa, setiap pembaruan monitor pengawas, dan setiap pembukaan halaman hasil/rekap memicu penyapuan sesi kedaluwarsa (dibatasi satu penyapu per 30 detik dengan kunci cache). Karena status dan sisa waktu selalu dihitung dari jam server, tampilan tidak pernah salah walau penyapuan belum jalan; penyapuan hanya memastikan nilai terbentuk. Bila ada cron yang berfungsi di kemudian hari, perintah `ujian:tutup-kadaluarsa` tersedia sebagai tambahan, bukan syarat.

## 6. Penilaian
- **PG:** otomatis di server dengan peta kunci yang di-cache per jadwal. Skor PG = (jumlah bobot soal benar ÷ jumlah bobot semua soal PG) × 100.
- **Esai:** dikoreksi guru (langkah berikutnya). Selama ada esai belum dikoreksi: status "Belum dikoreksi", skor akhir kosong; skor PG tetap tampil.
- **Skor akhir** = skor PG × bobot_pg% + skor esai × bobot_esai%. Tanpa esai: skor akhir = skor PG.
- Nilai dibekukan di `hasil_ujian` dengan snapshot.

## 7. Anti curang: pemetaan dan perbaikan

Semua kejadian dikirim lewat sinkron/endpoint pelanggaran, dihitung di server, dan masuk `pelanggaran`. **Pelanggaran dilaporkan segera** (tidak menunggu jadwal sinkron berkala).

| # | Lapis (kode lama) | Di aplikasi baru |
|---|---|---|
| 1 | `visibilitychange` (pindah tab/aplikasi, > 1,2 dtk) | Sama; durasi hilang dicatat |
| 2 | `blur`/`focus` jendela | Sama, kekebalan papan ketik 800 ms |
| 3 | Polling `document.hasFocus()` tiap 200 ms (2× = 400 ms) | Sama (utama untuk aplikasi mengambang) |
| 4 | Ukuran jendela/split screen (lebar < 65%) | Sama (hanya lebar) |
| 5 | `VisualViewport` menyempit > 8% | Sama |
| 6 | Halaman membeku (layar mati/latar belakang lama) | Sama |
| 7 | Pancingan DevTools | Dicatat sebagai jenis tersendiri |
| 8 | `IntersectionObserver` (soal tertutup overlay) | Sama |
| 9 | Picture-in-Picture | Sama |
| 10 | `pagehide` + `sendBeacon` | Sama |
| 11 | Pemantauan properti jendela | Sama |
| + | Layar penuh wajib; keluar = pelanggaran | Sama |
| + | Kunci riwayat (Kembali), `beforeunload` | Sama |
| + | Zona sentuh tepi diblokir | Sama |
| + | Blok salin/tempel/klik kanan/seleksi/Ctrl-Alt-F-key/PrintScreen | Sama |
| + | Tab/jendela ganda | Sama + perangkat kedua ditolak server |
| + | Sematan layar dilepas (tersembunyi > 8 dtk) | Sama |
| + | Jembatan aplikasi Android | Tahap 4 |

**Perbaikan:** penghitung di server (tidak bisa direset siswa); blokir **per sesi ujian**; pelanggaran sejenis dalam 8 dtk tidak dihitung dua kali (tetap dicatat); status "tidak ada kabar" dipisah dari pelanggaran; kode deteksi satu berkas `public/js/ujian-pengaman.js` tanpa jQuery dengan ambang di satu objek konfigurasi.

## 8. Monitor pengawas
- Daftar jadwal aktif yang ditugaskan (admin: semua). **Ringkasan** (mengerjakan, selesai, melanggar, terblokir, belum mulai, tidak ada kabar) dan **tabel peserta** (nama, kelas, ruang, status, pelanggaran, keterangan terakhir + waktu, kemajuan dijawab/total, usia kabar terakhir, platform).
- Pembaruan tiap 5 detik lewat JSON; **bunyi notifikasi** saat ada pelanggaran/blokir baru; baris berwarna; filter per ruang. Data satu jadwal di-cache 5 detik sehingga banyak pengawas membuka halaman tidak melipatgandakan beban basis data.
- Tindakan (tercatat di `aksi_pengawas`): **Aktifkan kembali**, **Reset perangkat**, **Tambah waktu**, **Paksa selesai**.
- Hak akses: pengawas hanya jadwal yang ditugaskan; admin semua; guru melihat jadwal mapelnya (baca saja).

## 9. Kapasitas: 300–2.000+ siswa serentak di shared hosting

**Terus terang:** shared hosting membatasi CPU, jumlah proses PHP serentak, dan koneksi MySQL. Rancangan "simpan ke server setiap jawaban dipilih + heartbeat 15 detik" untuk 2.000 siswa menghasilkan sekitar 130–250 permintaan per detik, yang **tidak akan kuat** di shared hosting mana pun. Maka rancangan diubah agar server hanya dipanggil seperlunya:

1. **Paket soal diunduh sekali** saat mulai (satu berkas JSON tanpa kunci, di-cache per jadwal; urutan khusus siswa berupa daftar id kecil). Seluruh soal ditampilkan dan berpindah soal tanpa ke server.
2. **Jawaban ditulis dulu ke peramban** (penyimpanan lokal) dan **disinkronkan berkala** dalam satu permintaan berisi seluruh jawaban yang berubah, sekaligus berfungsi sebagai detak (heartbeat). Selang sinkron **ditentukan server secara otomatis** menurut jumlah sesi aktif: sekitar 20 dtk (< 300 siswa) sampai 90 dtk (> 1.500 siswa). Sinkron juga dikirim segera saat ada pelanggaran, saat tab disembunyikan, dan saat Kirim. Selang yang lebih jarang berarti: bila HP rusak/data peramban terhapus, jawaban sejak sinkron terakhir (paling lama sekitar selang itu) bisa hilang. Itu pertukaran yang disengaja demi kapasitas.
3. **Titik akhir ujian dibuat sangat ringan:** tanpa sesi/cookie (autentikasi memakai token sesi ujian acak), tanpa lapisan yang tidak perlu, `INSERT … ON DUPLICATE KEY UPDATE` satu pernyataan untuk banyak jawaban, peta kunci di-cache untuk penilaian. `php artisan optimize` (cache konfigurasi, rute, tampilan) wajib dijalankan di server.
4. **Gambar soal disalin ke folder statis dengan nama acak** saat jadwal diaktifkan (dihapus setelah selesai), sehingga dilayani langsung oleh server web tanpa PHP. Jika memakai rute PHP berizin, 2.000 siswa × 10 gambar = 20.000 permintaan PHP sekaligus. Pertukarannya: siapa pun yang tahu alamat gambar acak itu (hanya peserta setelah memasukkan token) bisa membukanya.
5. **Lonjakan di awal dan akhir diredam:** klien mengulang dengan jeda acak bila server sibuk (kode 429/503); penutupan otomatis saat batas dengan jeda acak 0–20 detik; toleransi 90 detik untuk sinkron terakhir.
6. **Penilaian PG ringan** (peta kunci dari cache; satu `SELECT` + satu `INSERT` per siswa).
7. **Beban login:** hash bcrypt cost 10 ≈ 0,1 detik CPU per login. 2.000 login dalam satu menit ≈ 3–4 detik CPU per detik, **tidak mungkin** di shared hosting. Karena itu siswa dianjurkan **login lebih awal** dan **token dibacakan per ruang/gelombang**.

**Perkiraan (harus diukur, bukan dipercaya):**
| Siswa serentak | Shared hosting | Catatan |
|---|---|---|
| ≤ 300 | Layak | Dengan rancangan di atas |
| 300–800 | Mungkin; **wajib diuji beban** di hosting yang sama | Atur gelombang masuk per ruang |
| 800–2.000+ | **Tidak realistis** di shared hosting | Bagi ke beberapa **gelombang** (maks ±500–800 serentak), atau pindah ke **VPS** |

Hal lain yang perlu diperhatikan:
- **Berbagi kuota dengan EduWeb (berlaku, karena satu akun):** CPU, RAM, dan jumlah proses PHP dibagi dua aplikasi. Hari ujian bisa memperlambat EduWeb (absensi, jadwal, dll.), dan sebaliknya; bila melewati batas, penyedia hosting dapat membatasi seluruh akun. Karena itu angka aman **dikurangi dari perkiraan** (anggap sekitar **200–300 siswa serentak** sampai diukur), dan ujian sebaiknya **tidak dijadwalkan bersamaan dengan jam sibuk EduWeb** (mis. saat absensi pagi).
- **Tidak bergantung pada cron** (lihat bagian 5).
- **Hostinger Premium adalah paket entry-level**, dengan batas CPU/proses paling kecil di antara paket Hostinger. Anggap angka aman awalnya **sekitar 300 siswa serentak**; batas sebenarnya diukur di tahap F. Naik ke paket Business/Cloud atau VPS bila perlu.
- Kode ditulis agar **pindah ke VPS tidak perlu mengubah aplikasi**: cukup mengganti cache/sesi ke Redis dan menambah proses PHP.
- Tahap F (uji beban) mengukur batas sebenarnya di hosting yang dipakai, lalu angkanya dijadikan panduan resmi jumlah siswa per gelombang.

## 10. Tahapan pengerjaan
| Tahap | Isi | Verifikasi |
|---|---|---|
| A | Jadwal: tabel, CRUD, pilih kelas/bank/pengawas, aktifkan (bekukan soal, siapkan paket, buat sesi), token, penyelesaian | Tes otomatis |
| B | Alur siswa: beranda, token, mulai, paket soal tanpa kunci, sinkron berkala, timer server, kirim, nilai PG, penutup otomatis | Tes otomatis ketat (perangkat ganda, jawaban telat, kunci tidak bocor, kelas salah) |
| C | Anti curang: berkas JS, pelaporan, blokir | Tes server + uji di peramban |
| D | Monitor pengawas dan tindakannya | Tes otomatis + uji di peramban |
| E | Koreksi esai, rekap nilai, kartu ujian dan no. meja | Tes otomatis |
| F | Uji beban di hosting nyata, menetapkan jumlah siswa per gelombang | Skrip uji beban |

## 11. Yang perlu disetujui dari revisi ini
1. **Sinkron berkala** (selang otomatis 20–90 dtk) menggantikan "simpan tiap jawaban". Risiko: bila perangkat rusak, jawaban sejak sinkron terakhir bisa hilang. Setuju?
2. **Gambar soal dilayani statis dengan nama acak** (bukan lewat PHP). Setuju?
3. Untuk > 800 siswa serentak di shared hosting, **ujian dibagi gelombang atau pindah VPS**. Setuju sebagai kebijakan?
4. Tolong cek di hPanel: **nama paket hosting** (mis. Premium/Business/Cloud), apakah **cron job** tersedia, dan apakah aplikasi ujian akan berada di **akun yang sama dengan EduWeb**.

## 12. Satu akun hosting dengan EduWeb, tetap dua aplikasi terpisah
Aplikasi ujian dan EduWeb berada di **akun hosting yang sama**, tetapi **tetap dua aplikasi**: domain/folder sendiri, database sendiri, pengguna database sendiri dengan hak minimum, `.env` sendiri. Integrasi tetap lewat **API server-ke-server** dengan kunci rahasia, bukan membaca database satu sama lain. Alasannya: batas yang jelas, mudah dipindah ke akun/VPS lain kelak tanpa mengubah aplikasi, dan satu aplikasi yang bermasalah tidak langsung merusak data yang lain.
- EduWeb memanggil API aplikasi ujian (master data, kenaikan kelas, absensi, hash kata sandi); aplikasi ujian dipanggil EduWeb untuk rapor dan nilai (read-only). Semua panggilan memakai HTTPS, kunci di `.env`, dicatat, dan idempoten.
- **Jebakan satu server:** bila satu aplikasi memanggil alamat aplikasi lainnya di server yang sama, **satu permintaan memakai dua proses PHP sekaligus** (penelepon menunggu yang dipanggil). Maka panggilan antar-aplikasi dibuat **singkat, tanpa menahan permintaan siswa**, dengan batas waktu pendek, dan sinkron besar dijalankan di luar jam ujian.
- **Hari ujian:** jangan jalankan sinkron massal EduWeb ↔ ujian saat ujian berlangsung.
- **Jalur keluar:** bila beban ujian mengganggu EduWeb, pindahkan aplikasi ujian ke akun atau VPS sendiri. Karena integrasinya sudah lewat API, perpindahan itu hanya mengganti alamat dan kunci.
