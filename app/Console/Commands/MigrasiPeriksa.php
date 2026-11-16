<?php

namespace App\Console\Commands;

use App\Models\Mapel;
use App\Models\Siswa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrasiPeriksa extends Command
{
    protected $signature = 'migrasi:periksa
        {--semua : tampilkan juga siswa lama berstatus Lulus/Keluar}
        {--batas=60 : jumlah baris siswa belum cocok yang ditampilkan}';

    protected $description = 'Periksa (hanya baca) siswa database lama yang belum cocok dengan siswa EduWeb, dan nilai apa yang akan terlewat bila dipindah.';

    public function handle(): int
    {
        if ((string) config('database.connections.lama.database') === '') {
            $this->error('LAMA_DB_DATABASE belum diisi di .env.');

            return self::FAILURE;
        }
        $lama = DB::connection('lama');

        $baru = Siswa::with('kelas')->get();
        $perNisn = $baru->keyBy(fn ($s) => trim((string) $s->nisn));
        $perNama = $baru->groupBy(fn ($s) => $this->normal($s->nama));

        $jumlahRapor = $lama->table('tbl_nilai_rapor')->select('id_siswa', DB::raw('count(*) n'))->groupBy('id_siswa')->pluck('n', 'id_siswa');
        $jumlahCbt = $lama->table('tbl_nilai')->select('id_siswa', DB::raw('count(*) n'))->groupBy('id_siswa')->pluck('n', 'id_siswa');
        $jumlahUs = $lama->table('tbl_nilai_us')->select('id_siswa', DB::raw('count(*) n'))->groupBy('id_siswa')->pluck('n', 'id_siswa');

        $query = $lama->table('tbl_siswa')->orderBy('kelas')->orderBy('nama_siswa');
        if (! $this->option('semua')) {
            $query->where('status', 'Aktif');
        }
        $lamaSiswa = $query->get();

        $ringkas = [];
        $belum = [];
        $tanpaKelas = 0;
        foreach ($lamaSiswa as $s) {
            $nisn = trim((string) $s->nisn);
            $cocok = $nisn !== '' ? $perNisn->get($nisn) : null;
            $tingkat = (string) $s->tingkat_kelas;
            $ringkas[$tingkat] ??= ['total' => 0, 'cocok' => 0, 'tanpa_kelas' => 0, 'belum' => 0];
            $ringkas[$tingkat]['total']++;

            if ($cocok) {
                $ringkas[$tingkat]['cocok']++;
                if ($cocok->kelas->isEmpty()) {
                    $ringkas[$tingkat]['tanpa_kelas']++;
                    $tanpaKelas++;
                }

                continue;
            }

            $ringkas[$tingkat]['belum']++;
            $mirip = $perNama->get($this->normal($s->nama_siswa));
            $belum[] = [
                $s->nama_siswa,
                $nisn === '' ? '(kosong)' : $nisn,
                $s->kelas.' / '.$s->status,
                ($jumlahRapor[$s->id_siswa] ?? 0).' / '.($jumlahCbt[$s->id_siswa] ?? 0).' / '.($jumlahUs[$s->id_siswa] ?? 0),
                $mirip ? 'nama sama di EduWeb, NISN: '.($mirip->first()->nisn ?: '(kosong)') : 'tidak ada di EduWeb',
            ];
        }

        $this->info('Siswa database lama dibandingkan dengan '.$baru->count().' siswa di aplikasi baru (dicocokkan lewat NISN).');
        $this->table(['Tingkat lama', 'Total', 'Cocok', 'Cocok tapi belum punya kelas', 'Belum cocok'],
            collect($ringkas)->map(fn ($r, $t) => [$t, $r['total'], $r['cocok'], $r['tanpa_kelas'], $r['belum']])->values()->all());

        if ($tanpaKelas > 0) {
            $this->warn("{$tanpaKelas} siswa cocok tetapi belum punya kelas di EduWeb: rapor mereka tidak akan tersimpan sebelum kelasnya diisi.");
        }

        if ($belum === []) {
            $this->info('Semua siswa lama sudah cocok.');
        } else {
            $this->tampilkanBelum($belum);
        }

        $this->periksaMapel($lama, $jumlahRapor);

        return self::SUCCESS;
    }

    private function tampilkanBelum(array $belum): void
    {

        $batas = max(1, (int) $this->option('batas'));
        $this->warn(count($belum).' siswa lama belum cocok. Kolom "Nilai" = rapor / CBT / US (jumlah baris yang akan terlewat).');
        $this->table(['Nama', 'NISN lama', 'Kelas / status', 'Nilai', 'Keterangan'], array_slice($belum, 0, $batas));
        if (count($belum) > $batas) {
            $this->line('… dan '.(count($belum) - $batas).' lainnya (naikkan --batas untuk melihat semua).');
        }
    }

    /** Mapel lama yang namanya tidak ada di mapel EduWeb: nilainya tidak akan ikut pindah. */
    private function periksaMapel($lama, $jumlahRapor): void
    {
        $namaBaru = Mapel::pluck('nama')->map(fn ($n) => mb_strtolower(trim($n)))->all();
        $perMapel = $lama->table('tbl_nilai_rapor')->select('id_mapel', DB::raw('count(*) n'))->groupBy('id_mapel')->pluck('n', 'id_mapel');
        $perMapelUs = $lama->table('tbl_nilai_us')->select('id_mapel', DB::raw('count(*) n'))->groupBy('id_mapel')->pluck('n', 'id_mapel');
        $tak = [];
        foreach ($lama->table('tbl_mapel')->orderBy('nama_mapel')->get() as $m) {
            $rapor = (int) ($perMapel[$m->id_mapel] ?? 0);
            $us = (int) ($perMapelUs[$m->id_mapel] ?? 0);
            if (($rapor + $us) === 0 || in_array(mb_strtolower(trim($m->nama_mapel)), $namaBaru, true)) {
                continue;
            }
            $tak[] = [$m->id_mapel, $m->nama_mapel, $rapor, $us];
        }
        if ($tak === []) {
            $this->info('Semua mapel lama yang punya nilai sudah cocok dengan mapel EduWeb.');

            return;
        }
        $this->warn(count($tak).' mapel lama punya nilai tetapi namanya tidak ada di EduWeb (nilainya terlewat):');
        $this->table(['ID lama', 'Nama mapel lama', 'Baris rapor', 'Baris US'], $tak);
        $this->line('Mapel EduWeb yang ada: '.Mapel::orderBy('nama')->pluck('nama')->implode(' | '));
    }

    private function normal(?string $nama): string
    {
        return preg_replace('/\s+/', ' ', mb_strtoupper(trim((string) $nama)));
    }
}
