<?php

namespace App\Services;

use RuntimeException;

/** Galat aturan jadwal; pesannya aman ditampilkan kepada admin. */
class KesalahanJadwal extends RuntimeException {}
