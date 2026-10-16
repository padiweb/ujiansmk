<?php

namespace App\Http\Controllers\Api;

use App\Eduweb\SinkronOtomatis;
use App\Http\Controllers\Controller;
use App\Models\PengaturanSekolah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

use function Illuminate\Support\defer;

/**
 * Sinyal dari EduWeb bahwa ada perubahan. Isinya tidak dipercaya sebagai data: hanya memicu pengambilan kejadian
 * lewat API (yang aman diulang). Tanda tangan HMAC-SHA256 atas "stempel_waktu.isi" wajib sah.
 */
class WebhookEduwebController extends Controller
{
    private const MAKS_BYTE = 4096;

    private const TOLERANSI_DETIK = 300;

    public function __invoke(Request $request, SinkronOtomatis $otomatis): JsonResponse
    {
        $rahasia = (string) config('eduweb.webhook_rahasia');
        if ($rahasia === '' || ! config('eduweb.otomatis')) {
            return response()->json(['message' => 'Webhook belum diaktifkan.'], 503);
        }

        $isi = (string) $request->getContent();
        if (strlen($isi) > self::MAKS_BYTE) {
            return response()->json(['message' => 'Isi terlalu besar.'], 413);
        }

        $waktu = (string) $request->header('X-Eduweb-Timestamp', '');
        $tanda = strtolower((string) $request->header('X-Eduweb-Signature', ''));
        $sah = ctype_digit($waktu) && abs(time() - (int) $waktu) <= self::TOLERANSI_DETIK
            && preg_match('/^[a-f0-9]{64}$/', $tanda) === 1
            && hash_equals(hash_hmac('sha256', $waktu.'.'.$isi, $rahasia), $tanda);

        // Satu pesan untuk semua penolakan; tanda tangan yang sama tidak diterima dua kali (cegah pemutaran ulang).
        if (! $sah || ! Cache::add('eduweb:webhook-tanda:'.$tanda, true, now()->addSeconds(self::TOLERANSI_DETIK * 2))) {
            return response()->json(['message' => 'Tidak sah.'], 401);
        }

        PengaturanSekolah::ambil()->update(['eduweb_webhook_terakhir' => now()]);
        defer(fn () => $otomatis->dariWebhook()); // dijalankan setelah respons terkirim

        return response()->json(['diterima' => true], 202);
    }
}
