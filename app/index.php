<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
app_boot();
Auth::start();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim(base_url(''), '/');
$p = trim(substr($uri, strlen($base)), '/');
if (str_starts_with($p, 'index.php')) $p = trim(substr($p, 9), '/');

if (str_starts_with($p, 'api/')) {
    require __DIR__ . '/src/api.php';
    api_dispatch(substr($p, 4));
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
require __DIR__ . '/src/views/layout.php';

$pages = [
    '' => 'c_home', 'layanan' => 'c_layanan', 'bandingkan' => 'c_bandingkan', 'booking' => 'c_booking', 'reservasi' => 'c_reservasi',
    'akun' => 'c_akun', 'login' => 'c_login', 'daftar' => 'c_login', 'keluar' => 'logout',
    's' => 's_home', 's/antrean' => 's_antrean', 's/kalender' => 's_kalender', 's/kasir' => 's_kasir', 's/pelanggan' => 's_pelanggan',
    's/kas' => 's_kas', 's/stok' => 's_stok', 's/jadwal' => 's_jadwal', 's/absensi' => 's_absensi', 's/persetujuan' => 's_persetujuan',
    's/laporan' => 's_laporan', 's/pengaturan' => 's_pengaturan', 's/struk' => 's_struk',
];
if ($p === 'logout' || $p === 'keluar') { if ($_SERVER['REQUEST_METHOD'] === 'POST') { Auth::checkCsrf(); } Auth::logout(); header('Location: ' . base_url('')); exit; }

$key = $p;
$arg = null;
if (!isset($pages[$key]) && preg_match('#^(reservasi|s/struk)/([A-Za-z0-9\-]+)$#', $p, $m)) { $key = $m[1]; $arg = $m[2]; }
if (!isset($pages[$key])) { http_response_code(404); require __DIR__ . '/src/views/404.php'; exit; }
$GLOBALS['route_arg'] = $arg;
require __DIR__ . '/src/pages/' . $pages[$key] . '.php';
