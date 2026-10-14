<?php

namespace App\Otomatis;

use RuntimeException;

/** Galat yang pesannya aman ditampilkan kepada pengguna (tanpa kunci atau detail internal). */
class KesalahanOtomatis extends RuntimeException {}
