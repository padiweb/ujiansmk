<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gambar unggahan tidak pernah disimpan apa adanya: isinya diperiksa sebagai gambar
 * sungguhan, diperkecil, lalu dibuat ulang (menghapus metadata dan muatan tersembunyi).
 * Disimpan di disk privat dengan nama acak.
 */
class GambarAman
{
    public const MAKS_LEBAR = 1600;

    private const MAKS_PIKSEL = 25_000_000; // cegah gambar raksasa yang menghabiskan memori

    public static function simpan(UploadedFile $berkas, string $bidang = 'gambar', string $folder = 'soal'): string
    {
        $jalur = $berkas->getRealPath();
        $info = $jalur ? @getimagesize($jalur) : false;

        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages([$bidang => 'Berkas bukan gambar JPG, PNG, atau WEBP yang valid.']);
        }

        if ($info[0] * $info[1] > self::MAKS_PIKSEL) {
            throw ValidationException::withMessages([$bidang => 'Dimensi gambar terlalu besar.']);
        }

        $gambar = @imagecreatefromstring((string) file_get_contents($jalur));
        if (! $gambar) {
            throw ValidationException::withMessages([$bidang => 'Gambar tidak dapat diproses.']);
        }

        if (imagesx($gambar) > self::MAKS_LEBAR) {
            $kecil = imagescale($gambar, self::MAKS_LEBAR);
            if ($kecil) {
                $gambar = $kecil;
            }
        }

        $webp = function_exists('imagewebp');
        imagealphablending($gambar, false);
        imagesavealpha($gambar, true);

        ob_start();
        $webp ? imagewebp($gambar, null, 85) : imagejpeg(self::latarPutih($gambar), null, 85);
        $isi = (string) ob_get_clean();

        $nama = $folder.'/'.Str::random(40).($webp ? '.webp' : '.jpg');
        Storage::disk('local')->put($nama, $isi);

        return $nama;
    }

    /**
     * Foto yang akan dikirim ke penyedia AI: diperiksa sebagai gambar sungguhan, diperkecil, dibuat ulang sebagai JPEG
     * (metadata dibuang), dan TIDAK disimpan. @return array{0:string,1:string} [isi, tipe mime]
     */
    public static function bytesUntukAi(UploadedFile $berkas, string $bidang = 'foto'): array
    {
        $jalur = $berkas->getRealPath();
        $info = $jalur ? @getimagesize($jalur) : false;
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages([$bidang => 'Berkas bukan gambar JPG, PNG, atau WEBP yang valid.']);
        }
        if ($info[0] * $info[1] > self::MAKS_PIKSEL) {
            throw ValidationException::withMessages([$bidang => 'Dimensi gambar terlalu besar.']);
        }
        $gambar = @imagecreatefromstring((string) file_get_contents($jalur));
        if (! $gambar) {
            throw ValidationException::withMessages([$bidang => 'Gambar tidak dapat diproses.']);
        }
        if (max(imagesx($gambar), imagesy($gambar)) > 2000) {
            $kecil = imagescale($gambar, imagesx($gambar) >= imagesy($gambar) ? 2000 : (int) round(imagesx($gambar) * 2000 / imagesy($gambar)));
            if ($kecil) {
                $gambar = $kecil;
            }
        }
        ob_start();
        imagejpeg(self::latarPutih($gambar), null, 88);

        return [(string) ob_get_clean(), 'image/jpeg'];
    }

    public static function hapus(?string $jalur): void
    {
        if ($jalur) {
            Storage::disk('local')->delete($jalur);
        }
    }

    /** Salin berkas ke nama baru (dipakai saat menyalin bank soal). */
    public static function salin(?string $jalur): ?string
    {
        if (! $jalur || ! Storage::disk('local')->exists($jalur)) {
            return null;
        }

        $baru = Str::beforeLast($jalur, '/').'/'.Str::random(40).'.'.Str::afterLast($jalur, '.');
        Storage::disk('local')->copy($jalur, $baru);

        return $baru;
    }

    private static function latarPutih(\GdImage $gambar): \GdImage
    {
        $dasar = imagecreatetruecolor(imagesx($gambar), imagesy($gambar));
        imagefill($dasar, 0, 0, imagecolorallocate($dasar, 255, 255, 255));
        imagecopy($dasar, $gambar, 0, 0, 0, 0, imagesx($gambar), imagesy($gambar));

        return $dasar;
    }
}
