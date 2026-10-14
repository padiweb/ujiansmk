<?php

namespace App\Otomatis;

class AnthropicPenyusun extends PenyusunSoal
{
    protected function alamat(): string
    {
        return 'https://api.anthropic.com/v1/messages';
    }

    protected function header(): array
    {
        return ['x-api-key' => (string) $this->penyedia->kunci, 'anthropic-version' => '2023-06-01'];
    }

    protected function badan(string $instruksi, string $permintaan): array
    {
        return [
            'model' => $this->penyedia->model,
            'max_tokens' => 8192,
            'system' => $instruksi,
            'messages' => [['role' => 'user', 'content' => $permintaan]],
        ];
    }

    protected function teksJawaban(array $respons): ?string
    {
        return collect($respons['content'] ?? [])->where('type', 'text')->pluck('text')->implode('') ?: null;
    }
}
