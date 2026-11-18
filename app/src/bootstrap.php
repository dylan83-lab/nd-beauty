<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
date_default_timezone_set('Asia/Jakarta');
mb_internal_encoding('UTF-8');

spl_autoload_register(function (string $c): void {
    $f = __DIR__ . '/' . $c . '.php';
    if (is_file($f)) require $f;
});

require __DIR__ . '/helpers.php';

function app_config(): ?array
{
    static $cfg = false;
    if ($cfg === false) {
        $path = getenv('ND_CONFIG') ?: APP_ROOT . '/config.local.php';
        $cfg = is_file($path) ? (require $path) : null;
    }
    return $cfg;
}

function app_boot(bool $needDb = true): void
{
    $cfg = app_config();
    if ($cfg === null) {
        if (PHP_SAPI === 'cli') throw new RuntimeException('config.local.php belum ada. Jalankan installer.');
        header('Location: ' . base_url('install.php'));
        exit;
    }
    // Jam simulasi untuk pengujian lokal saja (config.local.php: 'fake_now' => '13:00'). Jangan dipakai di produksi.
    if (!empty($cfg['fake_now']) && !isset($GLOBALS['__now'])) $GLOBALS['__now'] = date('Y-m-d') . ' ' . $cfg['fake_now'] . ':00';
    if ($needDb) DB::connect($cfg);
}
