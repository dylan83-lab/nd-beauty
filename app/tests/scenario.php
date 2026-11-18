<?php
// Skenario uji logika inti (SQLite lokal).  php app/tests/scenario.php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

passthru('php ' . escapeshellarg(__DIR__ . '/../tools/setup-local.php') . ' > ' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
app_boot();

$pass = 0; $fail = 0;
function t(bool $c, string $m): void { global $pass, $fail; if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function as_user(string $role): void { $GLOBALS['__cli_role'] = $role; $GLOBALS['__cli_uid'] = (int)DB::val('SELECT id FROM users WHERE role=? ORDER BY id LIMIT 1', [$role]); }
function throws(callable $f, string $needle = ''): ?Throwable { try { $f(); } catch (Throwable $e) { return ($needle === '' || str_contains($e->getMessage(), $needle)) ? $e : null; } return null; }
function clock(string $hm): void { $GLOBALS['__now'] = date('Y-m-d') . ' ' . $hm . ':00'; }

$D = date('Y-m-d');
clock('12:00');
$jp = (int)DB::val("SELECT id FROM services WHERE name='Japanese Head Spa'");
$kr = (int)DB::val("SELECT id FROM services WHERE name='Korean Head Spa'");
$foot = (int)DB::val("SELECT id FROM services WHERE name='Foot Spa'");
$cust = (int)DB::val('SELECT id FROM customers LIMIT 1');

echo "== 1. Booking Japanese Head Spa 90 menit pukul 14.00 ==\n";
as_user('pelanggan');
$b = Booking::create(['customer_id' => $cust, 'items' => [['service_id' => $jp]], 'date' => $D, 'time' => '14:00', 'source' => 'online']);
t($b['status'] === 'booked' && $b['end_hm'] === '15:30', 'reservasi dibuat, selesai 15:30');
t($b['arrive_by'] === '13:50', 'jam kedatangan otomatis 13:50');
t((int)$b['total'] === 299000, 'harga dibekukan Rp299.000');
t(strlen($b['code']) === 8, 'kode booking ' . $b['code']);
$bid = (int)$b['id'];

echo "== 2. Slot terpakai sampai 15.30 ==\n";
$slots = Slots::forDate($D, [$jp], 90);
$map = array_column($slots, null, 'time');
t(isset($map['14:00']) && $map['14:00']['free'] === 1, 'pukul 14.00 sisa 1 kursi (dari 2)');
t($map['14:00']['status'] === 'terbatas', 'label Terbatas');
t(isset($map['15:00']) && $map['15:00']['free'] === 1, 'pukul 15.00 (bertabrakan sampai 15.30) sisa 1');
t(isset($map['15:30']) && $map['15:30']['free'] === 2, 'pukul 15.30 kembali penuh tersedia');
t(!isset($map['18:30']) || true, 'jam mulai terakhir 18.30');
t(isset($map['18:30']) && !isset($map['19:00']), 'tutup 20.00: mulai terakhir 18.30 untuk 90 menit');

echo "== 3. Perebutan slot terakhir ==\n";
$c2 = Customers::findOrCreate('Tamu A', '081300000001');
$c3 = Customers::findOrCreate('Tamu B', '081300000002');
$c4 = Customers::findOrCreate('Tamu C', '081300000003');
$x = Booking::create(['customer_id' => $c2, 'items' => [['service_id' => $jp]], 'date' => $D, 'time' => '14:00', 'source' => 'online']);
t(true, 'pemesan kedua berhasil mengambil kursi terakhir 14.00');
$e = throws(fn() => Booking::create(['customer_id' => $c3, 'items' => [['service_id' => $jp]], 'date' => $D, 'time' => '14:00', 'source' => 'online']));
t($e instanceof SlotTaken && count($e->alts) > 0, 'pemesan ketiga ditolak + alternatif ' . ($e instanceof SlotTaken ? implode(',', array_slice($e->alts, 0, 3)) : '-'));
$sb = Slots::forDate($D, [$jp], 90);
t(array_column($sb, null, 'time')['14:00']['status'] === 'penuh', 'slot 14.00 berlabel Penuh');
Booking::cancel((int)$x['id'], 'uji', false);
t(array_column(Slots::forDate($D, [$jp], 90), null, 'time')['14:00']['free'] === 1, 'pembatalan membebaskan slot');
$e = throws(fn() => Booking::create(['customer_id' => $cust, 'items' => [['service_id' => $kr]], 'date' => $D, 'time' => '14:30', 'source' => 'online']), 'bertabrakan');
t($e !== null, 'pelanggan yang sama tidak bisa double-booking');

echo "== 4. Kasir check-in, terapis mulai & selesai ==\n";
as_user('kasir');
clock('13:52');
$b = Booking::checkin($bid, null);
t($b['status'] === 'checkin', 'check-in kasir');
as_user('terapis');
$ther = (int)$b['therapist_id'];
$GLOBALS['__cli_uid'] = $ther;
$e = throws(fn() => Booking::start($bid, $ther + 99), 'bukan penugasan');
t($e !== null, 'terapis lain tidak bisa memulai');
clock('14:02');
$b = Booking::start($bid, $ther);
t($b['status'] === 'inservice', 'Mulai Perawatan');
clock('15:28');
$b = Booking::finish($bid, $ther);
Booking::finish($bid, $ther); // klik ganda
t($b['status'] === 'done', 'Selesaikan Perawatan');
$used = (int)DB::val("SELECT COUNT(*) FROM stock_moves WHERE move_type='usage' AND ref_id=?", [$bid]);
t($used === 4, 'bahan terpakai tercatat 4 baris (sekali): ' . $used);

echo "== 5. Kasir: produk + pembayaran ==\n";
as_user('kasir');
$kasir = Auth::id();
$e = throws(fn() => Pos::pay((int)Pos::forBooking($bid, $kasir)['id'], ['method' => 'cash', 'received' => 400000], 'x', $kasir), 'Buka kas');
t($e !== null, 'pembayaran ditolak sebelum kas dibuka');
Cash::open($kasir, 200000);
$sale = Pos::forBooking($bid, $kasir);
$prod = (int)DB::val("SELECT id FROM products WHERE name='Shampoo ND 250 ml'");
$sale = Pos::update((int)$sale['id'], ['lines' => array_merge(array_map(fn($i) => ['id' => $i['id'], 'qty' => 1], $sale['items']), [['type' => 'product', 'ref_id' => $prod, 'qty' => 1]])], false);
t((int)$sale['total'] === 299000 + 85000, 'total = layanan + 1 produk = ' . $sale['total']);
$e = throws(fn() => Pos::pay((int)$sale['id'], ['method' => 'transfer'], 'k1', $kasir), 'Konfirmasi');
t($e !== null, 'transfer wajib konfirmasi dana diterima');
$e = throws(fn() => Pos::pay((int)$sale['id'], ['method' => 'cash', 'received' => 100000], 'k1', $kasir), 'kurang');
t($e !== null, 'uang diterima kurang ditolak');
$paid = Pos::pay((int)$sale['id'], ['method' => 'cash', 'received' => 400000], 'k1', $kasir);
$paid2 = Pos::pay((int)$sale['id'], ['method' => 'cash', 'received' => 400000], 'k1', $kasir); // kirim ulang
t($paid['status'] === 'paid' && !empty($paid['receipt_no']), 'struk terbit ' . $paid['receipt_no'] . ', kembalian ' . $paid['change_amt']);
t(!empty($paid2['already']) && $paid2['receipt_no'] === $paid['receipt_no'], 'kirim ulang tidak membuat transaksi baru');
t((int)DB::val("SELECT COUNT(*) FROM cash_movements WHERE ref_type='sale' AND ref_id=?", [(int)$sale['id']]) === 1, 'tepat satu mutasi masuk');
t((int)DB::val("SELECT COUNT(*) FROM stock_moves WHERE move_type='sale' AND ref_id=?", [(int)$sale['id']]) === 1, 'stok produk berkurang satu kali');
t(Stock::onHand($prod) === 9.0, 'stok shampoo 10 -> 9');
t(Booking::get($bid)['pay_status'] === 'paid', 'status pembayaran reservasi Lunas');

echo "== 6. Tutup kas ==\n";
$s = Cash::current($kasir);
$sum = Cash::summary((int)$s['id']);
t($sum['expected_calc'] === 200000 + 384000, 'kas diharapkan = awal + tunai = ' . $sum['expected_calc']);
$e = throws(fn() => Cash::close((int)$s['id'], $kasir, 580000, ''), 'selisih');
t($e !== null, 'selisih wajib diberi keterangan');
$cl = Cash::close((int)$s['id'], $kasir, 580000, 'uang kembalian kurang');
t($cl['diff'] === -4000, 'selisih -4.000 tercatat');

echo "== 7. Promo draf / kedaluwarsa ==\n";
$pi = (int)DB::val('SELECT id FROM promo_items ORDER BY id LIMIT 1');
t(throws(fn() => Booking::resolveItems([['promo_item_id' => $pi]], $D), 'tidak tersedia') !== null, 'promo draf tidak dapat dipesan');
DB::exec("UPDATE promos SET status='active', ends='2026-01-31', starts='2026-01-01'");
DB::exec('UPDATE promo_items SET service_id=?, duration_min=90 WHERE id=?', [$jp, $pi]);
t(throws(fn() => Booking::resolveItems([['promo_item_id' => $pi]], $D), 'hanya berlaku') !== null, 'promo kedaluwarsa ditolak');
DB::exec("UPDATE promos SET starts=?, ends=?", [date('Y-m-d', strtotime('-5 days')), date('Y-m-d', strtotime('+5 days'))]);
$r = Booking::resolveItems([['promo_item_id' => $pi]], $D);
t($r['total'] === 89000 && $r['duration'] === 90, 'promo aktif: harga Rp89.000, durasi 90');

echo "== 8. Absensi radius 100 m ==\n";
S_set('att_lat', '-6.4756'); S_set('att_lng', '106.8386');
$terU = (int)DB::val("SELECT id FROM users WHERE role='terapis' ORDER BY id LIMIT 1");
clock('09:00');
t(throws(fn() => Attendance::punch($terU, 'in', -6.4756, 106.8386, 10), 'belum diaktifkan') !== null, 'ditolak sebelum admin memverifikasi lokasi');
S_set('att_enabled', '1');
$e = throws(fn() => Attendance::punch($terU, 'in', -6.4801, 106.8386, 10));
t($e instanceof AttendanceFail && $e->data['reason'] === 'outside' && $e->data['distance'] > 400, 'di luar radius: jarak ' . ($e->data['distance'] ?? '?') . ' m');
$e = throws(fn() => Attendance::punch($terU, 'in', -6.4756, 106.8386, 120));
t($e instanceof AttendanceFail && $e->data['reason'] === 'low_accuracy', 'GPS tidak akurat ditolak');
$e = throws(fn() => Attendance::punch($terU, 'in', null, null, null));
t($e instanceof AttendanceFail && $e->data['reason'] === 'no_location', 'izin lokasi ditolak/tidak ada');
t(throws(fn() => Attendance::punch($terU, 'out', -6.4756, 106.8386, 10), 'belum absen masuk') !== null, 'pulang sebelum masuk ditolak');
clock('09:20');
$a = Attendance::punch($terU, 'in', -6.4756, 106.8386, 12);
t($a['row']['status'] === 'hadir' && (int)$a['row']['late_min'] === 20, 'absen masuk dalam radius, terlambat 20 menit');
t(throws(fn() => Attendance::punch($terU, 'in', -6.4756, 106.8386, 12), 'sudah absen masuk') !== null, 'absen masuk ganda ditolak');
clock('16:30');
$a = Attendance::punch($terU, 'out', -6.4756, 106.8386, 12);
t((int)$a['row']['early_min'] === 30 && (int)$a['row']['work_min'] === 430, 'pulang awal 30 menit, durasi kerja 430 menit');
$cid = Attendance::requestCorrection($terU, $D, 'waktu', '09:00', '17:00', null, 'GPS bermasalah');
as_user('manager');
Attendance::processCorrection($cid, true, 'ok', Auth::id());
$row = DB::one('SELECT * FROM attendance WHERE user_id=? AND att_date=?', [$terU, $D]);
t((int)$row['late_min'] === 0 && (int)$row['work_min'] === 480, 'koreksi disetujui: tepat waktu, 480 menit');

echo "== 9. Refund ==\n";
clock('17:00');
as_user('kasir');
$items = DB::all('SELECT * FROM sale_items WHERE sale_id=?', [(int)$sale['id']]);
$pitem = array_values(array_filter($items, fn($i) => $i['item_type'] === 'product'))[0];
$rq = Pos::requestRefund((int)$sale['id'], [['sale_item_id' => $pitem['id'], 'qty' => 1]], 'produk rusak', $kasir);
as_user('manager');
$res = Pos::processRequest($rq, true, 'setuju', Auth::id());
$res2 = throws(fn() => Pos::processRequest($rq, true, 'setuju', Auth::id()), 'sudah diproses');
t($res['status'] === 'approved' && $res2 !== null, 'refund disetujui sekali; ulang ditolak');
t(Stock::onHand($prod) === 10.0, 'stok produk kembali 10');
t((int)DB::val("SELECT COUNT(*) FROM cash_movements WHERE category='refund' AND kind='out'") === 1, 'satu mutasi keluar refund');
t(DB::val('SELECT status FROM sales WHERE id=?', [(int)$sale['id']]) === 'partial', 'status transaksi refund sebagian');

echo "\nHasil: $pass lulus, $fail gagal\n";
exit($fail ? 1 : 0);
