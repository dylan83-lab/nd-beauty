<?php
// Pengembangan lokal: buat database SQLite + data awal.  php app/tools/setup-local.php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$dir = APP_ROOT . '/storage';
if (!is_dir($dir)) mkdir($dir, 0775, true);
$file = $dir . '/dev.sqlite';
foreach ([$file, $file . '-wal', $file . '-shm'] as $f) if (is_file($f)) unlink($f);

$cfg = ['driver' => 'sqlite', 'sqlite_path' => $file, 'base_path' => '/app', 'fake_now' => getenv('ND_FAKE_NOW') ?: null];
file_put_contents(APP_ROOT . '/config.local.php', "<?php\nreturn " . var_export($cfg, true) . ";\n");
DB::connect($cfg);
Setup::createTables();
Setup::seed('ND-Demo2026', true);
echo "OK: {$file}\n";
