# Pesan untuk sesi EduWeb: sertakan foto guru dan siswa di API ujian

Aplikasi ujian ingin menampilkan foto akun (bilah atas staf dan sapaan siswa). Foto sudah ada di EduWeb, tetapi API
`/api/exam/v1/teachers` dan `/api/exam/v1/students` belum menyertakannya. Perubahan di bawah hanya MENAMBAH satu kolom
(`photo_path`) pada kedua respons; tidak ada kolom yang diubah atau dihapus, jadi klien lama tidak terpengaruh.

## Isi `photo_path`
Jalur berkas di dalam folder `storage/app/public` (yang sama dengan yang dipakai `asset('storage/'.$photo)`), tanpa awalan
`storage/` dan tanpa host. Contoh: `photos/teachers/abc123.jpg`. Nilai `null` bila akun belum punya foto.

Urutan sumber sama dengan `User::getPhotoUrlAttribute()`: foto biodata (`teacher_details.photo_path` atau
`student_details.photo_path`), lalu `users.avatar_path`.

Aplikasi ujian membentuk alamat gambarnya sendiri: `https://<host EDUWEB_URL>/storage/<photo_path>`. Karena itu folder
`public/storage` di server EduWeb harus dapat diakses publik (sudah demikian untuk `asset('storage/...')`).

## Perubahan di `app/Http/Controllers/Api/Exam/MasterDataController.php`

### `teachers()`
Muat relasi `teacherDetail` pada kueri dan tambahkan kolom di `transform`:

```php
$page = User::withTrashed()
    ->with('teacherDetail:id,user_id,photo_path')          // BARU
    ->where('school_id', $schoolId)
    // ... (bagian where/orderBy/paginate tidak berubah)

$page->getCollection()->transform(fn ($u) => [
    'id'         => $u->id,
    // ... kolom yang sudah ada ...
    'deleted'    => $u->trashed(),
    'photo_path' => $u->teacherDetail?->photo_path ?: $u->avatar_path,   // BARU
]);
```

### `students()`
Tambahkan `photo_path` ke daftar kolom `studentDetail` yang dimuat, lalu ke `transform`:

```php
->with(['studentDetail:id,user_id,gender,birth_place,birth_date,photo_path', 'classrooms:id,academic_year_id'])   // photo_path BARU

$page->getCollection()->transform(fn ($u) => [
    'id'            => $u->id,
    // ... kolom yang sudah ada ...
    'student_number' => $u->classrooms->mapWithKeys(fn ($c) => [$c->id => $c->pivot->student_number]),
    'photo_path'     => $u->studentDetail?->photo_path ?: $u->avatar_path,   // BARU
]);
```

## Perubahan foto memicu sinkron
Aplikasi ujian membaca daftar guru dan siswa tiap sinkron penuh dan tiap kejadian `master.changed`. Bila EduWeb sudah
mengirim kejadian `master.changed` saat data biodata berubah, foto baru ikut terbawa. Bila foto diganti tanpa kejadian,
foto baru akan terbaca pada sinkron berikutnya (tombol Sinkron EduWeb atau sinkron berkala).

## Pengujian
Tambahkan pada tes API ujian yang ada: guru dan siswa dengan `photo_path` terisi mengembalikan nilainya, dan yang
tidak punya foto mengembalikan `null`. Tidak ada perubahan skema database.
