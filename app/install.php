<?php
/**
 * Installer web sekali pakai (cPanel).
 * Keamanan: hanya berjalan bila berkas  app/storage/.allow-install  dibuat pemilik lewat File Manager,
 * dan berkas itu dihapus otomatis setelah instalasi berhasil.
 */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

$allow = APP_ROOT . '/storage/.allow-install';
$done = app_config() !== null;
$msg = ''; $ok = false;

function page(string $body): void
{
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pemasangan ND Japanese Head Spa</title>
<style>body{font-family:system-ui,sans-serif;background:#fff8f3;color:#33191f;margin:0;padding:20px}main{max-width:560px;margin:auto;background:#fff;border:1px solid #efd9df;border-radius:16px;padding:24px}
h1{color:#7a1230;font-size:1.5rem;margin:0 0 6px}label{display:block;font-size:.85rem;margin:12px 0 4px;color:#7d6169}input{width:100%;box-sizing:border-box;padding:10px;border:1.5px solid #efd9df;border-radius:10px;font:inherit}
button{margin-top:18px;background:#7a1230;color:#fff;border:0;border-radius:999px;padding:12px 22px;font:inherit;cursor:pointer}.e{background:#fde7e5;color:#b3261e;padding:10px;border-radius:10px;margin:10px 0}.o{background:#e3f4ec;color:#2e7d5b;padding:10px;border-radius:10px;margin:10px 0}code{background:#fdeef2;padding:2px 6px;border-radius:6px}</style></head><body><main>' . $body . '</main></body></html>';
    exit;
}

if ($done) {
    try { app_boot(); $installed = Setup::isInstalled(); } catch (Throwable $e) { $installed = false; }
    if ($installed) page('<h1>Sudah terpasang</h1><p>Aplikasi sudah terpasang. Untuk keamanan, halaman ini dikunci. Buka <a href="' . e(base_url('login')) . '">halaman masuk</a>.</p>');
}
if (!is_file($allow)) {
    page('<h1>Pemasangan dikunci</h1><p>Untuk memulai pemasangan, buat berkas kosong bernama <code>.allow-install</code> di folder <code>app/storage/</code> lewat cPanel → File Manager, lalu muat ulang halaman ini.</p><p>Berkas itu dihapus otomatis setelah pemasangan selesai.</p>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = fn(string $k) => trim((string)($_POST[$k] ?? ''));
    try {
        if (strlen($f('admin_pass')) < 8) throw new RuntimeException('Kata sandi admin minimal 8 karakter.');
        if (!filter_var($f('admin_email'), FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email admin tidak valid.');
        $cfg = ['driver' => 'mysql', 'host' => $f('db_host') ?: 'localhost', 'name' => $f('db_name'), 'user' => $f('db_user'), 'pass' => (string)($_POST['db_pass'] ?? '')];
        try { DB::connect($cfg); } catch (Throwable $e) { throw new RuntimeException('Tidak dapat terhubung ke database: ' . $e->getMessage()); }
        if (Setup::isInstalled()) throw new RuntimeException('Database ini sudah berisi data aplikasi. Gunakan database kosong.');
        Setup::createTables();
        Setup::seed($f('admin_pass'), !empty($_POST['demo']), mb_strtolower($f('admin_email')));
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
        file_put_contents(APP_ROOT . '/config.local.php', "<?php\nreturn " . var_export($cfg + ['base_path' => $dir], true) . ";\n");
        @chmod(APP_ROOT . '/config.local.php', 0640);
        @unlink($allow);
        $ok = true;
    } catch (Throwable $e) { $msg = $e->getMessage(); }
}

if ($ok) page('<h1>Pemasangan berhasil 🌸</h1><div class="o">Aplikasi siap dipakai. Berkas pemasangan telah dikunci.</div><p>Masuk sebagai admin dengan email dan kata sandi yang Anda isi, lalu lengkapi: <b>Pengaturan → Lokasi Absensi</b> (verifikasi titik), durasi layanan, nama terapis, dan jadwal.</p><p><a href="' . e(base_url('login')) . '">Buka halaman masuk →</a></p>');

page('<h1>Pemasangan ND Japanese Head Spa</h1><p>Buat dulu database MySQL dan pengguna di cPanel → <b>MySQL® Databases</b> (beri semua hak), lalu isi di bawah.</p>' . ($msg ? '<div class="e">' . e($msg) . '</div>' : '') . '
<form method="post"><label>Host database</label><input name="db_host" value="localhost"><label>Nama database</label><input name="db_name" required placeholder="akun_ndbeauty"><label>Pengguna database</label><input name="db_user" required placeholder="akun_ndapp"><label>Kata sandi database</label><input name="db_pass" type="password">
<hr><label>Nama admin: akun admin pemilik</label><label>Email admin</label><input name="admin_email" type="email" required><label>Kata sandi admin (min. 8 karakter)</label><input name="admin_pass" type="password" required minlength="8">
<label style="display:flex;gap:8px;align-items:center;margin-top:14px"><input type="checkbox" name="demo" value="1" checked style="width:auto"> Isi akun contoh (manager, kasir, 3 terapis, 1 pelanggan; kata sandi <code>ND-Demo2026</code>)</label>
<button>Pasang Aplikasi</button></form>');
