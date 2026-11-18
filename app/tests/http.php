<?php
// Uji kewenangan lewat HTTP.  Jalankan server dev dulu:  php -S localhost:8080 -t <repo> app/tools/dev-router.php
declare(strict_types=1);
$BASE = $argv[1] ?? 'http://localhost:8080/app';
$pass = 0; $fail = 0;
function t(bool $c, string $m): void { global $pass, $fail; if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }

class Client {
    public string $jar; public ?string $csrf = null;
    function __construct(public string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'ck'); }
    function req(string $method, string $path, ?array $data = null, bool $csrf = true): array {
        $ch = curl_init($this->base . $path);
        $h = ['Accept: application/json'];
        if ($method === 'POST') { $h[] = 'Content-Type: application/json'; if ($csrf && $this->csrf) $h[] = 'X-CSRF: ' . $this->csrf; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data ?? [])); }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => false]);
        $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return [$code, json_decode((string)$body, true) ?? ['_raw' => $body]];
    }
    function page(string $path): array { return $this->req('GET', $path); }
    function csrfFromPage(): void { $ch = curl_init($this->base . '/login'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar]); $h = curl_exec($ch); curl_close($ch); preg_match('/name="csrf" content="([a-f0-9]+)"/', $h, $m); $this->csrf = $m[1] ?? null; }
    function login(string $ident, string $pw = 'ND-Demo2026'): array { $this->csrfFromPage(); return $this->req('POST', '/api/auth.login', ['ident' => $ident, 'password' => $pw]); }
}

echo "== Tanpa login ==\n";
$g = new Client($BASE);
[$c] = $g->req('GET', '/api/queue'); t($c === 401, 'antrean ditolak 401');
[$c] = $g->req('GET', '/api/dash'); t($c === 401, 'dashboard ditolak 401');
[$c, $j] = $g->req('GET', '/api/slots'); t($c === 200, 'katalog/slot publik boleh dilihat');
[$c] = $g->req('GET', '/api/report'); t($c === 401, 'laporan ditolak');

echo "== CSRF & login ==\n";
$p = new Client($BASE); $p->csrfFromPage();
[$c] = $p->req('POST', '/api/auth.login', ['ident' => 'pelanggan@ndbeauty.id', 'password' => 'ND-Demo2026'], false); t($c === 419, 'POST tanpa token CSRF ditolak (419)');
[$c, $j] = $p->login('pelanggan@ndbeauty.id', 'salah'); t($c === 422, 'kata sandi salah ditolak');
[$c, $j] = $p->login('081200000099'); t($c === 200 && $j['user']['role'] === 'pelanggan', 'pelanggan masuk dengan nomor HP');

echo "== Pelanggan ==\n";
foreach (['queue', 'dash', 'cash.status', 'stock.list', 'sched.get', 'crud.list?e=users', 'cust.search', 'pos.catalog', 'att.list', 'corr.list'] as $ep) { [$c] = $p->req('GET', '/api/' . $ep); t($c === 403, "pelanggan tidak boleh /$ep (403)"); }
[$c, $j] = $p->req('GET', '/api/booking.mine'); t($c === 200 && is_array($j['bookings']), 'pelanggan melihat reservasinya sendiri');

// siapkan reservasi milik pelanggan lain (via CLI/DB) untuk uji isolasi
require __DIR__ . '/../src/bootstrap.php'; app_boot();
$other = Customers::findOrCreate('Orang Lain', '081399999999');
$jp = (int)DB::val("SELECT id FROM services WHERE name='Japanese Head Spa'");
$GLOBALS['__cli_uid'] = 1; $GLOBALS['__cli_role'] = 'admin';
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$ob = Booking::create(['customer_id' => $other, 'items' => [['service_id' => $jp]], 'date' => $tomorrow, 'time' => '10:00', 'source' => 'kasir']);
[$c] = $p->req('POST', '/api/booking.cancel', ['id' => $ob['id']]); t($c === 404, 'pelanggan tidak bisa membatalkan reservasi orang lain');
[$c, $j] = $p->req('GET', '/api/booking.mine'); t(!in_array($ob['code'], array_column($j['bookings'], 'code'), true), 'reservasi orang lain tidak muncul di daftar');
[$c, $j] = $p->req('GET', '/api/slots?' . http_build_query(['booking' => $ob['id'], 'date' => $tomorrow])); t($c === 404, 'pelanggan tidak bisa mengintip slot jadwal-ulang milik orang lain');
$pubSlots = $p->req('GET', '/api/slots?' . http_build_query(['items' => json_encode([['service_id' => $jp]]), 'date' => $tomorrow]))[1];
t(!str_contains(json_encode($pubSlots), 'Orang Lain'), 'slot publik tidak membocorkan identitas pemesan lain');
[$c, $j] = $p->req('POST', '/api/booking.create', ['items' => [['service_id' => $jp]], 'date' => $tomorrow, 'time' => '14:00']);
t($c === 200 && $j['booking']['arrive_by'] === '13:50', 'pelanggan booking 14.00 → tiba 13.50 (' . ($j['booking']['code'] ?? $j['error'] ?? '') . ')');
$mine = $j['booking'] ?? null;

echo "== Terapis ==\n";
$t = new Client($BASE); [$c, $j] = $t->login('terapis1@ndbeauty.id'); t($c === 200, 'terapis masuk');
foreach (['queue', 'dash', 'cash.status', 'cust.search', 'pos.catalog', 'stock.list', 'report?type=penjualan', 'crud.list?e=users'] as $ep) { [$c] = $t->req('GET', '/api/' . $ep); t($c === 403, "terapis tidak boleh /$ep"); }
[$c, $j] = $t->req('GET', '/api/work.list'); t($c === 200, 'terapis melihat penugasannya');
$all = json_encode($j);
t(!str_contains($all, 'phone') && !str_contains($all, '0813'), 'rincian penugasan terapis tanpa kontak pelanggan');
foreach ($j['bookings'] as $b) t(true, 'penugasan: ' . $b['customer'] . ' ' . $b['start']);
$foreign = (int)DB::val('SELECT id FROM bookings WHERE therapist_id<>? AND status=\'booked\' LIMIT 1', [(int)DB::val("SELECT id FROM users WHERE email='terapis1@ndbeauty.id'")]);
if ($foreign) { [$c, $j] = $t->req('GET', '/api/work.materials?id=' . $foreign); t($c === 422, 'terapis tidak bisa membuka penugasan terapis lain'); [$c] = $t->req('POST', '/api/work.start', ['id' => $foreign]); t($c === 422, 'terapis tidak bisa memulai pelanggan terapis lain'); }

echo "== Kasir ==\n";
$k = new Client($BASE); $k->login('kasir@ndbeauty.id');
[$c] = $k->req('GET', '/api/queue'); t($c === 200, 'kasir melihat antrean');
[$c] = $k->req('GET', '/api/pos.catalog'); t($c === 200, 'kasir membuka katalog kasir');
foreach (['dash', 'crud.list?e=users', 'report?type=penjualan', 'sched.get', 'cash.accounts', 'cash.sessions'] as $ep) { [$c] = $k->req('GET', '/api/' . $ep); t($c === 403, "kasir tidak boleh /$ep"); }
[$c] = $k->req('POST', '/api/settings.save', ['s' => ['biz_name' => 'x']]); t($c === 403, 'kasir tidak boleh mengubah pengaturan');
[$c] = $k->req('POST', '/api/pos.refund_now', ['sale_id' => 1, 'lines' => [], 'reason' => 'x']); t($c === 403, 'kasir tidak boleh refund langsung (butuh manager)');
[$c, $j] = $k->req('GET', '/api/queue?date=' . $tomorrow); t($c === 200 && in_array($ob['code'], array_column($j['bookings'], 'code'), true), 'kasir melihat reservasi semua pelanggan');

echo "== Manager ==\n";
$m = new Client($BASE); $m->login('manager@ndbeauty.id');
foreach (['dash', 'cash.accounts', 'stock.list', 'sched.get', 'corr.list', 'att.list'] as $ep) { [$c] = $m->req('GET', '/api/' . $ep); t($c === 200, "manager boleh /$ep"); }
[$c] = $m->req('GET', '/api/report?type=penjualan'); t($c === 200, 'manager boleh laporan');
[$c] = $m->req('GET', '/api/crud.list?e=users'); t($c === 403, 'manager tidak boleh kelola pengguna');
[$c] = $m->req('GET', '/api/pos.catalog'); t($c === 403, 'manager tidak membuka layar kasir');

echo "== Admin & ekspor ==\n";
$a = new Client($BASE); $a->login('admin@ndbeauty.id', 'ND-Demo2026');
[$c, $j] = $a->req('GET', '/api/crud.list?e=users'); t($c === 200 && count($j['rows']) >= 6, 'admin kelola pengguna');
t(!str_contains(json_encode($j), 'pass_hash'), 'hash kata sandi tidak dikirim ke klien');
[$c, $j] = $a->req('GET', '/api/promo.list'); t($c === 200 && $j['promos'][0]['status'] === 'draft', 'promo launching tersimpan sebagai draf');
[$c, $j] = $a->req('POST', '/api/promo.status', ['id' => $j['promos'][0]['id'], 'status' => 'active']); t($c === 422, 'promo draf tidak bisa diaktifkan sebelum varian/durasi ditetapkan');
foreach (['xlsx' => 'PK', 'pdf' => '%PDF'] as $fmt => $magic) {
    $ch = curl_init($BASE . '/api/report?type=penjualan&format=' . $fmt . '&from=2026-01-01&to=2026-12-31');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $a->jar]); $b = curl_exec($ch); curl_close($ch);
    t(str_starts_with((string)$b, $magic) && strlen((string)$b) > 300, "ekspor $fmt valid (" . strlen((string)$b) . ' byte)');
}
[$c] = $a->req('GET', '/api/att.config'); // metode salah
t($c === 405, 'metode HTTP salah ditolak (405)');
[$c, $j] = $a->req('POST', '/api/crud.save', ['e' => 'users', 'data' => ['id' => 1, 'name' => 'Admin ND', 'role' => 'kasir', 'email' => 'admin@ndbeauty.id', 'active' => 1]]); t($c === 422, 'admin tidak bisa menurunkan perannya sendiri');

echo "\nHasil: $pass lulus, $fail gagal\n";
exit($fail ? 1 : 0);
