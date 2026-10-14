<?php

namespace App\Otomatis;

class GeminiPenyusun extends PenyusunSoal
{
    protected function alamat(): string
    {
        // Nama model sudah divalidasi saat disimpan (huruf, angka, titik, strip, garis bawah).
        return 'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($this->penyedia->model).':generateContent';
    }

    protected function header(): array
    {
        // Kunci lewat header, bukan query string, agar tidak tercatat di log alamat.
        return ['x-goog-api-key' => (string) $this->penyedia->kunci];
    }

    protected function badan(string $instruksi, string $permintaan): array
    {
        return [
            'systemInstruction' => ['parts' => [['text' => $instruksi]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $permintaan]]]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.7],
        ];
    }

    protected function teksJawaban(array $respons): ?string
    {
        $bagian = $respons['candidates'][0]['content']['parts'] ?? [];

        return collect($bagian)->pluck('text')->filter()->implode('') ?: null;
    }
}
