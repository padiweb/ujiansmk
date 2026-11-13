<?php

namespace App\Http\Controllers\Staf;

use App\Impor\NilaiLampauPengimpor;
use App\Impor\Pengimpor;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Impor Excel Nilai Lampau (admin). Memakai alur pratinjau-lalu-simpan yang sama dengan impor data induk, tanpa terkunci oleh sinkron EduWeb. */
class ImporNilaiLampauController extends ImporController
{
    private const SLUG = 'nilai-lampau';

    public function formLampau(): View
    {
        return $this->form(self::SLUG);
    }

    public function templatLampau(): StreamedResponse
    {
        return $this->templat(self::SLUG);
    }

    public function pratinjauLampau(Request $request): View
    {
        return $this->pratinjau($request, self::SLUG);
    }

    public function simpanLampau(Request $request, string $token): View
    {
        return $this->simpan($request, self::SLUG, $token);
    }

    protected function pengimpor(string $entitas): Pengimpor
    {
        return new NilaiLampauPengimpor;
    }

    protected function rute(string $entitas): array
    {
        return [
            'templat' => route('staf.lampau.impor.templat'),
            'pratinjau' => route('staf.lampau.impor.pratinjau'),
            'simpan' => route('staf.lampau.impor.simpan', [':token']),
            'form' => route('staf.lampau.impor.form'),
            'kembali' => route('staf.lampau.index'),
            'kembaliLabel' => 'Nilai Lampau',
        ];
    }
}
