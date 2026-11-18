<?php
declare(strict_types=1);

/** Kasir: penjualan dari reservasi, produk, potongan/member/poin, pembayaran idempoten, struk, refund. */
final class Pos
{
    public const METHODS = ['cash' => 'Tunai', 'transfer' => 'Transfer', 'qris' => 'QRIS'];

    // ---------- Penjualan ----------
    public static function forBooking(int $bookingId, int $cashierId): array
    {
        return DB::tx(function () use ($bookingId, $cashierId) {
            $b = DB::one('SELECT * FROM bookings WHERE id=?' . DB::forUpdate(), [$bookingId]);
            if (!$b) throw new UserError('Reservasi tidak ditemukan.');
            if (in_array($b['status'], ['cancelled', 'noshow'], true)) throw new UserError('Reservasi ini sudah dibatalkan / tidak hadir.');
            $s = DB::one('SELECT id FROM sales WHERE booking_id=?', [$bookingId]);
            if ($s) return self::view((int)$s['id']);
            $sid = DB::insert('sales', ['booking_id' => $bookingId, 'customer_id' => $b['customer_id'], 'cashier_id' => $cashierId, 'status' => 'open', 'created_at' => now()]);
            foreach (DB::all('SELECT * FROM booking_items WHERE booking_id=? ORDER BY id', [$bookingId]) as $it) {
                DB::insert('sale_items', ['sale_id' => $sid, 'item_type' => 'service', 'ref_id' => $it['service_id'], 'promo_item_id' => $it['promo_item_id'], 'name' => $it['name'], 'price' => $it['price'], 'qty' => 1, 'locked' => 1]);
            }
            self::recalc($sid);
            audit('sale.open', 'sale', $sid, 'booking ' . $b['code']);
            return self::view($sid);
        });
    }

    public static function newWalkin(int $cashierId, ?int $customerId): array
    {
        $sid = DB::insert('sales', ['booking_id' => null, 'customer_id' => $customerId, 'cashier_id' => $cashierId, 'status' => 'open', 'created_at' => now()]);
        self::recalc($sid);
        return self::view($sid);
    }

    /** Hitung ulang potongan & total dan simpan. Kembalikan hasil hitung. */
    public static function recalc(int $saleId, ?int $manualAmount = null, ?int $pointsUsed = null): array
    {
        $s = DB::one('SELECT * FROM sales WHERE id=?', [$saleId]);
        $items = DB::all('SELECT * FROM sale_items WHERE sale_id=? ORDER BY id', [$saleId]);
        $cust = $s['customer_id'] ? Customers::get((int)$s['customer_id']) : null;
        $manual = $manualAmount ?? (int)$s['disc_manual'];
        $pts = $pointsUsed ?? (int)$s['points_used'];
        $pct = 0;
        $combine = false;
        if ($cust && $cust['member']['active']) { $pct = (int)$cust['member']['plan']['discount_pct']; $combine = (int)$cust['member']['plan']['combine_promo'] === 1; }

        $sub = 0; $discMember = 0; $lines = [];
        foreach ($items as $it) {
            $line = (int)$it['price'] * (int)$it['qty'];
            $dm = 0;
            if ($pct > 0 && $it['item_type'] !== 'member' && ($it['promo_item_id'] === null || $combine)) $dm = (int)floor($line * $pct / 100);
            $sub += $line; $discMember += $dm;
            $lines[] = ['id' => (int)$it['id'], 'line' => $line, 'dm' => $dm];
        }
        $afterMember = $sub - $discMember;
        $manual = max(0, min($manual, $afterMember));
        $afterManual = $afterMember - $manual;
        $pv = max(1, (int)S('point_value', '100'));
        $pts = max(0, min($pts, $cust ? (int)$cust['points'] : 0, (int)ceil($afterManual / $pv)));
        $discPoints = min($pts * $pv, $afterManual);
        $total = $afterManual - $discPoints;

        // Alokasi potongan manual + poin ke baris, sebanding dengan nilai setelah diskon member.
        $extra = $manual + $discPoints;
        $baseSum = $afterMember;
        $given = 0;
        $n = count($lines);
        foreach ($lines as $i => &$l) {
            $after = $l['line'] - $l['dm'];
            $share = ($baseSum > 0) ? ($i === $n - 1 ? $extra - $given : (int)round($extra * $after / $baseSum)) : 0;
            $share = max(0, min($share, $after));
            $given += $share;
            $l['disc'] = $l['dm'] + $share;
            $l['net'] = $l['line'] - $l['disc'];
        }
        unset($l);
        foreach ($lines as $l) DB::update('sale_items', ['disc' => $l['disc'], 'net' => $l['net']], 'id=?', [$l['id']]);
        DB::update('sales', ['subtotal' => $sub, 'disc_member' => $discMember, 'disc_manual' => $manual, 'points_used' => $pts, 'disc_points' => $discPoints, 'total' => $total], 'id=? AND paid_at IS NULL', [$saleId]);
        return ['subtotal' => $sub, 'disc_member' => $discMember, 'disc_manual' => $manual, 'points_used' => $pts, 'disc_points' => $discPoints, 'total' => $total];
    }

    public static function view(int $saleId): array
    {
        $s = DB::one('SELECT s.*, c.name AS customer_name, c.phone AS customer_phone, u.name AS cashier_name FROM sales s LEFT JOIN customers c ON c.id=s.customer_id LEFT JOIN users u ON u.id=s.cashier_id WHERE s.id=?', [$saleId]);
        if (!$s) throw new UserError('Transaksi tidak ditemukan.');
        $s['items'] = DB::all('SELECT * FROM sale_items WHERE sale_id=? ORDER BY id', [$saleId]);
        $s['customer'] = $s['customer_id'] ? Customers::get((int)$s['customer_id']) : null;
        $s['booking'] = $s['booking_id'] ? Booking::get((int)$s['booking_id']) : null;
        $s['method_label'] = self::METHODS[$s['method'] ?? ''] ?? '';
        return $s;
    }

    /** Ubah isi transaksi terbuka: baris tambahan (produk/layanan/member), harga variabel, pelanggan, potongan, poin. */
    public static function update(int $saleId, array $d, bool $isManager): array
    {
        return DB::tx(function () use ($saleId, $d, $isManager) {
            $s = DB::one('SELECT * FROM sales WHERE id=?' . DB::forUpdate(), [$saleId]);
            if (!$s) throw new UserError('Transaksi tidak ditemukan.');
            if ($s['status'] !== 'open') throw new UserError('Transaksi sudah ' . ($s['status'] === 'paid' ? 'lunas' : 'ditutup') . ' dan tidak dapat diubah.');
            if (array_key_exists('customer_id', $d) && !$s['booking_id']) {
                DB::update('sales', ['customer_id' => $d['customer_id'] ? (int)$d['customer_id'] : null], 'id=?', [$saleId]);
            }
            if (isset($d['lines']) && is_array($d['lines'])) {
                $keep = [];
                foreach ($d['lines'] as $ln) {
                    $qty = max(1, min(99, (int)($ln['qty'] ?? 1)));
                    if (!empty($ln['id'])) {
                        $it = DB::one('SELECT * FROM sale_items WHERE id=? AND sale_id=?', [(int)$ln['id'], $saleId]);
                        if (!$it) continue;
                        $keep[] = (int)$it['id'];
                        $upd = ['qty' => $it['locked'] ? 1 : $qty];
                        if ($it['item_type'] === 'service' && isset($ln['price']) && DB::val('SELECT price_from FROM services WHERE id=?', [(int)$it['ref_id']])) {
                            $np = max(0, (int)$ln['price']);
                            if ($np !== (int)$it['price']) { $upd['price'] = $np; audit('sale.price', 'sale', $saleId, $it['name'] . ' ' . $it['price'] . '->' . $np); }
                        }
                        DB::update('sale_items', $upd, 'id=?', [$it['id']]);
                    } else {
                        $type = $ln['type'] ?? '';
                        if ($type === 'product') {
                            $p = DB::one("SELECT * FROM products WHERE id=? AND kind='jual' AND active=1", [(int)($ln['ref_id'] ?? 0)]);
                            if (!$p) throw new UserError('Produk tidak ditemukan.');
                            $keep[] = DB::insert('sale_items', ['sale_id' => $saleId, 'item_type' => 'product', 'ref_id' => $p['id'], 'name' => $p['name'], 'price' => $p['price'], 'qty' => $qty, 'locked' => 0]);
                        } elseif ($type === 'service') {
                            $sv = DB::one('SELECT * FROM services WHERE id=? AND active=1', [(int)($ln['ref_id'] ?? 0)]);
                            if (!$sv) throw new UserError('Layanan tidak ditemukan.');
                            $price = (int)$sv['price_from'] && isset($ln['price']) ? max(0, (int)$ln['price']) : (int)$sv['price'];
                            $keep[] = DB::insert('sale_items', ['sale_id' => $saleId, 'item_type' => 'service', 'ref_id' => $sv['id'], 'name' => $sv['name'], 'price' => $price, 'qty' => $qty, 'locked' => 0]);
                        } elseif ($type === 'member') {
                            $pl = DB::one('SELECT * FROM membership_plans WHERE id=? AND active=1', [(int)($ln['ref_id'] ?? 0)]);
                            if (!$pl) throw new UserError('Paket membership tidak ditemukan.');
                            if (!DB::val('SELECT customer_id FROM sales WHERE id=?', [$saleId])) throw new UserError('Pilih pelanggan sebelum menambah membership.');
                            $keep[] = DB::insert('sale_items', ['sale_id' => $saleId, 'item_type' => 'member', 'ref_id' => $pl['id'], 'name' => 'Membership ' . $pl['name'], 'price' => $pl['price'], 'qty' => 1, 'locked' => 0]);
                        }
                    }
                }
                // Hapus baris non-terkunci yang tidak lagi dikirim.
                foreach (DB::all('SELECT id, locked FROM sale_items WHERE sale_id=?', [$saleId]) as $it) {
                    if (!(int)$it['locked'] && !in_array((int)$it['id'], $keep, true)) DB::exec('DELETE FROM sale_items WHERE id=?', [$it['id']]);
                }
            }
            $manual = null;
            if (array_key_exists('disc_manual', $d)) {
                $manual = max(0, (int)$d['disc_manual']);
                $reason = trim((string)($d['manual_reason'] ?? ''));
                if ($manual > 0) {
                    $base = (int)DB::val('SELECT COALESCE(SUM(price*qty),0) FROM sale_items WHERE sale_id=?', [$saleId]);
                    $cap = (int)floor($base * (int)S('manual_disc_max_pct', '10') / 100);
                    if (!$isManager && $manual > $cap) throw new UserError('Potongan manual melebihi batas kasir (' . S('manual_disc_max_pct') . '% = ' . rp($cap) . '). Minta persetujuan manager.');
                    if ($reason === '') throw new UserError('Isi alasan potongan manual.');
                }
                DB::update('sales', ['manual_reason' => mb_substr($reason, 0, 120)], 'id=?', [$saleId]);
                audit('sale.discount', 'sale', $saleId, $manual . ' ' . $reason);
            }
            self::recalc($saleId, $manual, array_key_exists('points_used', $d) ? max(0, (int)$d['points_used']) : null);
            rev_bump();
            return self::view($saleId);
        });
    }

    // ---------- Pembayaran ----------
    /** Pembayaran lunas. Idempoten: pengiriman ulang tidak menggandakan mutasi, stok, atau poin. */
    public static function pay(int $saleId, array $pay, string $idemKey, int $userId): array
    {
        return DB::tx(function () use ($saleId, $pay, $idemKey, $userId) {
            $s = DB::one('SELECT * FROM sales WHERE id=?' . DB::forUpdate(), [$saleId]);
            if (!$s) throw new UserError('Transaksi tidak ditemukan.');
            if ($s['paid_at'] !== null) { $v = self::view($saleId); $v['already'] = true; return $v; }
            if ($s['status'] !== 'open') throw new UserError('Transaksi sudah ditutup.');
            $items = DB::all('SELECT * FROM sale_items WHERE sale_id=?', [$saleId]);
            if (!$items) throw new UserError('Transaksi kosong.');
            if ($s['booking_id']) {
                $bk = DB::one('SELECT status FROM bookings WHERE id=?' . DB::forUpdate(), [$s['booking_id']]);
                if (!$bk || $bk['status'] !== 'done') throw new UserError('Perawatan belum selesai. Pembayaran dapat diproses setelah terapis menekan Selesaikan Perawatan.');
            }
            $sess = Cash::current($userId);
            if (!$sess) throw new UserError('Buka kas terlebih dahulu sebelum menerima pembayaran.');
            $method = (string)($pay['method'] ?? '');
            if (!isset(self::METHODS[$method])) throw new UserError('Pilih metode pembayaran.');

            $calc = self::recalc($saleId);
            $total = $calc['total'];
            $received = null; $change = null;
            if ($total > 0) {
                if ($method === 'cash') {
                    $received = (int)($pay['received'] ?? 0);
                    if ($received < $total) throw new UserError('Uang diterima kurang dari total ' . rp($total) . '.');
                    $change = $received - $total;
                } elseif (empty($pay['confirmed'])) {
                    throw new UserError('Konfirmasi bahwa dana ' . self::METHODS[$method] . ' sudah diterima.');
                }
            }
            $acct = Cash::accountFor($method, isset($pay['account_id']) ? (int)$pay['account_id'] : null);

            // Stok produk: kurangi tepat sekali.
            $fresh = DB::all('SELECT * FROM sale_items WHERE sale_id=?', [$saleId]);
            foreach ($fresh as $it) {
                if ($it['item_type'] !== 'product') continue;
                if (Stock::onHand((int)$it['ref_id']) < (int)$it['qty']) throw new UserError('Stok ' . $it['name'] . ' tidak cukup.');
            }
            foreach ($fresh as $it) {
                if ($it['item_type'] === 'product') Stock::move((int)$it['ref_id'], 'sale', -(int)$it['qty'], 'Penjualan', 'sale', $saleId, 'sale:' . $saleId . ':' . $it['id']);
            }

            // Nomor struk berurutan per hari.
            $day = date('Y-m-d');
            DB::lockDay($day);
            $n = (int)DB::val("SELECT COUNT(*) FROM sales WHERE paid_at >= ? AND paid_at <= ?", [$day . ' 00:00:00', $day . ' 23:59:59']) + 1;
            do { $rno = 'INV-' . date('Ymd') . '-' . sprintf('%03d', $n++); } while (DB::val('SELECT 1 FROM sales WHERE receipt_no=?', [$rno]));

            // Poin & membership.
            $earned = 0;
            if ($s['customer_id']) {
                $cust = Customers::get((int)$s['customer_id']);
                if ($calc['points_used'] > 0) Customers::loyalty((int)$s['customer_id'], -$calc['points_used'], 'Tukar poin ' . $rno, 'redeem:' . $saleId);
                if ($cust['member']['active']) {
                    $earned = (int)floor($total / 10000) * (int)$cust['member']['plan']['points_per_10k'];
                    if ($earned > 0) Customers::loyalty((int)$s['customer_id'], $earned, 'Belanja ' . $rno, 'earn:' . $saleId);
                }
                foreach ($fresh as $it) if ($it['item_type'] === 'member') Customers::activate((int)$s['customer_id'], (int)$it['ref_id']);
            }

            if ($total > 0) {
                Cash::add(['account_id' => $acct, 'kind' => 'in', 'category' => 'penjualan', 'amount' => $total, 'note' => 'Penjualan ' . $rno, 'ref_type' => 'sale', 'ref_id' => $saleId, 'cash_session_id' => (int)$sess['id'], 'uniq_key' => 'sale:' . $saleId]);
            }
            DB::update('sales', [
                'status' => 'paid', 'receipt_no' => $rno, 'method' => $method, 'account_id' => $acct, 'cash_received' => $received, 'change_amt' => $change,
                'pay_ref' => mb_substr((string)($pay['reference'] ?? ''), 0, 80), 'points_earned' => $earned, 'idem_key' => $idemKey !== '' ? $idemKey : null,
                'cash_session_id' => (int)$sess['id'], 'cashier_id' => $userId, 'paid_at' => now(),
            ], 'id=?', [$saleId]);
            if ($s['booking_id']) DB::update('bookings', ['pay_status' => 'paid'], 'id=?', [$s['booking_id']]);
            audit('sale.pay', 'sale', $saleId, $rno . ' ' . $method . ' ' . $total);
            rev_bump();
            return self::view($saleId);
        });
    }

    public static function receiptByNo(string $no): ?array
    {
        $id = DB::val('SELECT id FROM sales WHERE receipt_no=?', [$no]);
        return $id ? self::view((int)$id) : null;
    }

    // ---------- Refund / koreksi ----------
    private static function calcRefund(int $saleId, array $lines): array
    {
        $out = [];
        $amount = 0;
        foreach ($lines as $l) {
            $it = DB::one('SELECT * FROM sale_items WHERE id=? AND sale_id=?', [(int)($l['sale_item_id'] ?? 0), $saleId]);
            $q = (int)($l['qty'] ?? 0);
            if (!$it || $q <= 0) continue;
            if ($q > (int)$it['qty'] - (int)$it['refunded_qty']) throw new UserError('Jumlah refund ' . $it['name'] . ' melebihi yang dapat dikembalikan.');
            $amt = ($q + (int)$it['refunded_qty'] === (int)$it['qty']) ? (int)$it['net'] - (int)$it['refunded_amt'] : (int)round((int)$it['net'] * $q / (int)$it['qty']);
            $out[] = ['item' => $it, 'qty' => $q, 'amount' => $amt];
            $amount += $amt;
        }
        if (!$out) throw new UserError('Pilih barang/layanan yang dikembalikan.');
        return [$out, $amount];
    }

    public static function requestRefund(int $saleId, array $lines, string $reason, int $userId): int
    {
        $reason = trim($reason);
        if ($reason === '') throw new UserError('Alasan wajib diisi.');
        $s = DB::one('SELECT * FROM sales WHERE id=?', [$saleId]);
        if (!$s || $s['paid_at'] === null || !in_array($s['status'], ['paid', 'partial'], true)) throw new UserError('Hanya transaksi lunas yang dapat direfund.');
        [, $amount] = self::calcRefund($saleId, $lines);
        $id = DB::insert('refund_requests', ['sale_id' => $saleId, 'items_json' => json_encode($lines), 'reason' => mb_substr($reason, 0, 250), 'status' => 'pending', 'requested_by' => $userId, 'amount' => $amount, 'created_at' => now()]);
        audit('refund.request', 'sale', $saleId, $reason);
        rev_bump();
        return $id;
    }

    public static function processRequest(int $reqId, bool $approve, string $note, int $userId): array
    {
        return DB::tx(function () use ($reqId, $approve, $note, $userId) {
            $r = DB::one('SELECT * FROM refund_requests WHERE id=?' . DB::forUpdate(), [$reqId]);
            if (!$r) throw new UserError('Permintaan tidak ditemukan.');
            if ($r['status'] !== 'pending') throw new UserError('Permintaan sudah diproses.');
            if (!$approve) {
                DB::update('refund_requests', ['status' => 'rejected', 'processed_by' => $userId, 'processed_at' => now(), 'process_note' => mb_substr($note, 0, 250)], 'id=?', [$reqId]);
                audit('refund.reject', 'sale', (int)$r['sale_id'], $note);
                rev_bump();
                return ['status' => 'rejected'];
            }
            $rid = self::refundNow((int)$r['sale_id'], json_decode($r['items_json'], true) ?: [], $r['reason'], $userId, $userId, $reqId);
            DB::update('refund_requests', ['status' => 'approved', 'processed_by' => $userId, 'processed_at' => now(), 'process_note' => mb_substr($note, 0, 250)], 'id=?', [$reqId]);
            return ['status' => 'approved', 'refund_id' => $rid];
        });
    }

    /** Refund langsung (manager/admin) — atau dieksekusi saat permintaan disetujui. */
    public static function refundNow(int $saleId, array $lines, string $reason, int $approvedBy, int $createdBy, ?int $reqId = null): int
    {
        if (trim($reason) === '') throw new UserError('Alasan wajib diisi.');
        return DB::tx(function () use ($saleId, $lines, $reason, $approvedBy, $createdBy, $reqId) {
            $s = DB::one('SELECT * FROM sales WHERE id=?' . DB::forUpdate(), [$saleId]);
            if (!$s || $s['paid_at'] === null || !in_array($s['status'], ['paid', 'partial'], true)) throw new UserError('Hanya transaksi lunas yang dapat direfund.');
            [$rows, $amount] = self::calcRefund($saleId, $lines);

            $ptsRev = 0; $ptsRet = 0;
            if ($s['customer_id'] && (int)$s['total'] > 0) {
                $ratio = $amount / (int)$s['total'];
                $doneRev = (int)DB::val('SELECT COALESCE(SUM(pts_reversed),0) FROM refunds WHERE sale_id=?', [$saleId]);
                $doneRet = (int)DB::val('SELECT COALESCE(SUM(pts_returned),0) FROM refunds WHERE sale_id=?', [$saleId]);
                $ptsRev = min((int)$s['points_earned'] - $doneRev, (int)round((int)$s['points_earned'] * $ratio));
                $ptsRet = min((int)$s['points_used'] - $doneRet, (int)round((int)$s['points_used'] * $ratio));
            }
            $rid = DB::insert('refunds', ['sale_id' => $saleId, 'amount' => $amount, 'reason' => mb_substr($reason, 0, 250), 'request_id' => $reqId, 'approved_by' => $approvedBy, 'created_by' => $createdBy, 'pts_reversed' => max(0, $ptsRev), 'pts_returned' => max(0, $ptsRet), 'created_at' => now()]);
            foreach ($rows as $r) {
                $it = $r['item'];
                DB::insert('refund_items', ['refund_id' => $rid, 'sale_item_id' => $it['id'], 'qty' => $r['qty'], 'amount' => $r['amount']]);
                DB::exec('UPDATE sale_items SET refunded_qty=refunded_qty+?, refunded_amt=refunded_amt+? WHERE id=?', [$r['qty'], $r['amount'], $it['id']]);
                if ($it['item_type'] === 'product') Stock::move((int)$it['ref_id'], 'return', (float)$r['qty'], 'Refund ' . $s['receipt_no'], 'refund', $rid, 'ret:' . $rid . ':' . $it['id']);
            }
            if ($amount > 0 && $s['account_id']) {
                $sess = Cash::current($createdBy);
                Cash::add(['account_id' => (int)$s['account_id'], 'kind' => 'out', 'category' => 'refund', 'amount' => $amount, 'note' => 'Refund ' . $s['receipt_no'] . ': ' . $reason, 'ref_type' => 'refund', 'ref_id' => $rid, 'cash_session_id' => $sess ? (int)$sess['id'] : null, 'uniq_key' => 'refund:' . $rid]);
            }
            if ($s['customer_id']) {
                if ($ptsRev > 0) Customers::loyalty((int)$s['customer_id'], -$ptsRev, 'Refund ' . $s['receipt_no'], 'refund-earn:' . $rid);
                if ($ptsRet > 0) Customers::loyalty((int)$s['customer_id'], $ptsRet, 'Poin dikembalikan ' . $s['receipt_no'], 'refund-used:' . $rid);
            }
            $left = (int)DB::val('SELECT COALESCE(SUM(qty - refunded_qty),0) FROM sale_items WHERE sale_id=?', [$saleId]);
            $st = $left === 0 ? 'refunded' : 'partial';
            DB::update('sales', ['status' => $st], 'id=?', [$saleId]);
            if ($s['booking_id']) DB::update('bookings', ['pay_status' => $st], 'id=?', [$s['booking_id']]);
            audit('refund.done', 'sale', $saleId, rp($amount) . ' ' . $reason);
            rev_bump();
            return $rid;
        });
    }
}
