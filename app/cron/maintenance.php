<?php
/**
 * Pemeliharaan terjadwal (cPanel → Cron Jobs, tiap 10 menit):
 *   perintah: /usr/local/bin/php /home/USERNAME/public_html/app/cron/maintenance.php   (lihat README)
 * - Menandai reservasi "Tidak Hadir" bila pelanggan tak check-in melewati masa tenggang (slot dibuka kembali).
 * - Membersihkan catatan percobaan login lama.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../src/bootstrap.php';
app_boot();

$grace = (int)S('noshow_grace', '30');
$cut = date('Y-m-d H:i:s', now_ts() - $grace * 60);
$n = 0;
foreach (DB::all("SELECT id FROM bookings WHERE status='booked' AND start_at < ?", [$cut]) as $b) if (Booking::markNoShow((int)$b['id'])) $n++;
DB::exec('DELETE FROM login_attempts WHERE at < ?', [date('Y-m-d H:i:s', now_ts() - 86400)]);
echo date('c') . " tidak hadir: $n\n";
