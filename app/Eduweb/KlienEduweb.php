<?php

namespace App\Eduweb;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Klien API EduWeb (server-ke-server, hanya baca). Kunci dikirim lewat header Bearer dan tidak pernah
 * masuk ke alamat, log, maupun pesan galat. HTTP polos hanya diizinkan untuk host lokal (pengembangan).
 */
class KlienEduweb
{
    public function __construct(private ?string $url = null, private ?string $kunci = null, private ?int $timeout = null)
    {
        $this->url ??= config('eduweb.url');
        $this->kunci ??= config('eduweb.kunci');
        $this->timeout ??= (int) config('eduweb.timeout');
    }

    public function terkonfigurasi(): bool
    {
        return filled($this->url) && filled($this->kunci);
    }

    /**
     * @param  array<string,scalar>  $query
     * @return array<string,mixed>
     *
     * @throws KesalahanEduweb
     */
    public function get(string $jalur, array $query = []): array
    {
        $this->periksaKonfigurasi();

        try {
            $respons = Http::withToken((string) $this->kunci)
                ->acceptJson()
                ->connectTimeout(min(10, $this->timeout))
                ->timeout($this->timeout)
                ->withoutRedirecting()
                ->get(rtrim((string) $this->url, '/').'/'.ltrim($jalur, '/'), $query);
        } catch (ConnectionException) {
            throw new KesalahanEduweb('Tidak dapat terhubung ke EduWeb. Periksa EDUWEB_URL, koneksi internet server, dan sertifikat HTTPS.', 'koneksi');
        }

        return $this->baca($respons);
    }

    private function periksaKonfigurasi(): void
    {
        if (! $this->terkonfigurasi()) {
            throw new KesalahanEduweb('Penghubung EduWeb belum diatur. Isi EDUWEB_URL dan EDUWEB_KUNCI di .env.', 'belum_diatur');
        }

        if (! str_starts_with((string) $this->kunci, 'exu_')) {
            throw new KesalahanEduweb('Format kunci EduWeb tidak dikenal (harus berawalan exu_).', 'kunci_salah_format');
        }

        $bagian = parse_url((string) $this->url);
        $skema = strtolower($bagian['scheme'] ?? '');
        $host = strtolower($bagian['host'] ?? '');
        if ($host === '' || ! in_array($skema, ['http', 'https'], true)) {
            throw new KesalahanEduweb('EDUWEB_URL tidak valid.', 'url_salah');
        }

        $lokal = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
        if ($skema !== 'https' && ! $lokal) {
            throw new KesalahanEduweb('EDUWEB_URL wajib memakai HTTPS agar kunci tidak terkirim polos.', 'url_tidak_aman');
        }
    }

    /** @return array<string,mixed> */
    private function baca(Response $r): array
    {
        if ($r->status() === 401) {
            throw new KesalahanEduweb('EduWeb menolak kunci. Pastikan kunci benar, belum dicabut, penghubung sudah diaktifkan di EduWeb, dan IP server ini diizinkan.', 'ditolak', 401);
        }
        if ($r->status() === 429) {
            throw new KesalahanEduweb('Terlalu banyak permintaan ke EduWeb. Coba lagi sebentar lagi.', 'dibatasi', 429);
        }
        if ($r->status() === 404) {
            throw new KesalahanEduweb('Alamat API tidak ditemukan di EduWeb. Periksa EDUWEB_URL (harus berakhir di /api/exam/v1) dan pastikan EduWeb versi terbaru terpasang.', 'tidak_ditemukan', 404);
        }
        if ($r->redirect()) {
            throw new KesalahanEduweb('EduWeb mengalihkan permintaan (redirect). Periksa EDUWEB_URL (mungkin http dialihkan ke https atau alamat berbeda).', 'dialihkan', $r->status());
        }
        if ($r->serverError()) {
            throw new KesalahanEduweb('EduWeb sedang bermasalah (kode '.$r->status().'). Coba lagi nanti.', 'server', $r->status());
        }
        if (! $r->successful()) {
            throw new KesalahanEduweb('Permintaan ditolak EduWeb (kode '.$r->status().').', 'ditolak_lain', $r->status());
        }

        $json = $r->json();
        if (! is_array($json)) {
            throw new KesalahanEduweb('Jawaban EduWeb bukan JSON yang valid. Periksa EDUWEB_URL.', 'bukan_json', $r->status());
        }

        return $json;
    }
}
