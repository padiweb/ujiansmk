@include('errors.galat', ['judul' => 'Akses ditolak', 'pesan' => trim((string) ($exception->getMessage() ?? '')) !== '' ? $exception->getMessage() : 'Anda tidak memiliki akses ke halaman ini.'])
