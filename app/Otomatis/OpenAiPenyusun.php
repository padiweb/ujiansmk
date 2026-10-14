<?php

namespace App\Otomatis;

class OpenAiPenyusun extends PenyusunSoal
{
    protected function alamat(): string
    {
        return 'https://api.openai.com/v1/chat/completions';
    }

    protected function header(): array
    {
        return ['Authorization' => 'Bearer '.$this->penyedia->kunci];
    }

    protected function badan(string $instruksi, string $permintaan): array
    {
        return [
            'model' => $this->penyedia->model,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $instruksi],
                ['role' => 'user', 'content' => $permintaan],
            ],
        ];
    }

    protected function teksJawaban(array $respons): ?string
    {
        return $respons['choices'][0]['message']['content'] ?? null;
    }
}
