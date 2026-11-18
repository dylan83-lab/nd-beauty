<?php
declare(strict_types=1);

final class SlotTaken extends UserError
{
    public array $alts = [];
}

/** Reservasi dari pemesanan sampai selesai. Satu reservasi dipakai di booking, check-in, terapis, dan kasir. */
final class Booking
{
    public const STATUS = ['booked' => 'Dipesan', 'checkin' => 'Check-in', 'inservice' => 'Dalam Perawatan', 'done' => 'Selesai', 'cancelled' => 'Dibatalkan', 'noshow' => 'Tidak Hadir'];
    public const PAY = ['unpaid' => 'Belum Bayar', 'paid' => 'Lunas', 'partial' => 'Refund Sebagian', 'refunded' => 'Dikembalikan'];

    /** Ubah pilihan layanan/promo menjadi baris dengan harga & durasi yang dibekukan saat pemesanan. */
    public static function resolveItems(array $items, string $date): array
    {
        if (!$items) throw new UserError('Pilih minimal satu layanan.');
        if (count($items) > 5) throw new UserError('Maksimal 5 layanan per reservasi.');
        $lines = [];
        $svcAll = [];
        foreach ($items as $it) {
            if (!empty($it['promo_item_id'])) {
                $pi = DB::one('SELECT pi.*, p.status AS pstatus, p.starts, p.ends, p.name AS pname FROM promo_items pi JOIN promos p ON p.id=pi.promo_id WHERE pi.id=?', [(int)$it['promo_item_id']]);
                if (!$pi || $pi['pstatus'] !== 'active') throw new UserError('Promo tidak tersedia.');
                if ($date < $pi['starts'] || $date > $pi['ends'] || today() > $pi['ends']) throw new UserError('Promo ' . $pi['pname'] . ' hanya berlaku ' . tgl_id($pi['starts'], false) . ' – ' . tgl_id($pi['ends'], false) . '.');
                $dur = (int)$pi['duration_min'];
                $ids = (int)$pi['is_package'] === 1 ? array_values(array_filter(array_map('intval', explode(',', (string)$pi['includes'])))) : ($pi['service_id'] ? [(int)$pi['service_id']] : []);
                if ($dur <= 0 || !$ids) throw new UserError('Promo ini belum siap dipesan.');
                $lines[] = ['service_id' => (int)$pi['is_package'] === 1 ? null : (int)$pi['service_id'], 'promo_item_id' => (int)$pi['id'], 'name' => $pi['label'] . ' (Promo)', 'price' => (int)$pi['promo_price'], 'duration' => $dur, 'svc_ids' => $ids];
                $svcAll = array_merge($svcAll, $ids);
            } else {
                $s = DB::one('SELECT * FROM services WHERE id=? AND active=1', [(int)($it['service_id'] ?? 0)]);
                if (!$s) throw new UserError('Layanan tidak ditemukan.');
                if ((int)$s['duration_min'] <= 0) throw new UserError($s['name'] . ' belum bisa dipesan online (durasi belum ditetapkan). Hubungi kami via WhatsApp.');
                $lines[] = ['service_id' => (int)$s['id'], 'promo_item_id' => null, 'name' => $s['name'], 'price' => (int)$s['price'], 'duration' => (int)$s['duration_min'], 'svc_ids' => [(int)$s['id']]];
                $svcAll[] = (int)$s['id'];
            }
        }
        $svcAll = array_values(array_unique($svcAll));
        return [
            'lines' => $lines, 'svc_ids' => $svcAll,
            'duration' => array_sum(array_column($lines, 'duration')),
            'total' => array_sum(array_column($lines, 'price')),
            'res_type' => Slots::resourceType($svcAll),
        ];
    }

    private static function newCode(): string
    {
        $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $c = 'ND';
            for ($i = 0; $i < 6; $i++) $c .= $a[random_int(0, strlen($a) - 1)];
        } while (DB::val('SELECT 1 FROM bookings WHERE code=?', [$c]));
        return $c;
    }

    private static function pickTherapist(array $ctx, array $freeIds): int
    {
        $load = array_fill_keys($freeIds, 0);
        foreach ($ctx['bookings'] as $b) if (isset($load[(int)$b['therapist_id']])) $load[(int)$b['therapist_id']] += diff_min($b['start_at'], $b['end_at']);
        asort($load);
        return (int)array_key_first($load);
    }

    private static function alternatives(string $date, array $r): array
    {
        $out = [];
        foreach (Slots::forDate($date, $r['svc_ids'], $r['duration']) as $s) if ($s['free'] > 0) { $out[] = $s['time']; if (count($out) >= 6) break; }
        return $out;
    }

    /**
     * Buat reservasi. Pemeriksaan ketersediaan diulang di dalam transaksi yang dikunci per-hari,
     * sehingga hanya satu pemesan yang menang saat berebut slot terakhir.
     */
    public static function create(array $p): array
    {
        $date = (string)($p['date'] ?? '');
        $time = (string)($p['time'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) throw new UserError('Tanggal atau jam tidak valid.');
        $source = $p['source'] ?? 'online';
        $staff = $source !== 'online';
        $r = self::resolveItems($p['items'] ?? [], $date);
        $start = $date . ' ' . $time . ':00';
        $end = add_min($start, $r['duration']);
        if ($date < today()) throw new UserError('Tanggal sudah lewat.');
        if ($start < date('Y-m-d H:i:s', now_ts() - 60)) throw new UserError('Jam tersebut sudah lewat. Pilih jam lain.');
        if (!Slots::isOpenDay($date)) throw new UserError('Kami tutup pada tanggal tersebut.');
        $open = $date . ' ' . S('open_time', '09:00') . ':00';
        $close = $date . ' ' . S('close_time', '20:00') . ':00';
        if ($start < $open || $end > $close) throw new UserError('Perawatan harus selesai sebelum jam tutup (' . S('close_time') . ' WIB).');
        $cid = (int)($p['customer_id'] ?? 0);
        if (!$cid) throw new UserError('Pelanggan belum dipilih.');
        $tid = !empty($p['therapist_id']) ? (int)$p['therapist_id'] : null;
        if ($tid && !$staff && S('therapist_choose', '1') !== '1') $tid = null;

        $out = DB::tx(function () use ($p, $date, $start, $end, $r, $cid, $tid, $source) {
            DB::lockDay($date);
            $dup = DB::val("SELECT code FROM bookings WHERE customer_id=? AND status IN " . Slots::ACTIVE . " AND start_at < ? AND ? < end_at", [$cid, $end, $start]);
            if ($dup) throw new UserError('Pelanggan sudah memiliki reservasi (' . $dup . ') yang bertabrakan dengan jam ini.');
            $ctx = Slots::context($date);
            $f = Slots::free($ctx, $start, $end, $r['svc_ids'], $r['res_type'], $tid);
            if ($f['free'] <= 0) {
                $e = new SlotTaken('Maaf, jam ' . hm($start) . ' baru saja terisi.');
                $e->alts = self::alternatives($date, $r);
                throw $e;
            }
            $therapist = $tid ?: self::pickTherapist($ctx, $f['th']);
            $id = DB::insert('bookings', [
                'code' => self::newCode(), 'customer_id' => $cid, 'source' => $source, 'book_date' => $date,
                'start_at' => $start, 'end_at' => $end, 'therapist_id' => $therapist, 'therapist_chosen' => $tid ? 1 : 0,
                'resource_id' => $f['res'][0], 'status' => 'booked', 'pay_status' => 'unpaid', 'total' => $r['total'],
                'notes' => mb_substr(trim((string)($p['notes'] ?? '')), 0, 250), 'created_by' => $p['created_by'] ?? null, 'created_at' => now(),
            ]);
            foreach ($r['lines'] as $l) {
                DB::insert('booking_items', ['booking_id' => $id, 'service_id' => $l['service_id'], 'promo_item_id' => $l['promo_item_id'], 'name' => $l['name'], 'price' => $l['price'], 'duration_min' => $l['duration'], 'svc_ids' => implode(',', $l['svc_ids'])]);
            }
            audit('booking.create', 'booking', $id, $source . ' ' . $start);
            rev_bump();
            return $id;
        });
        return self::get($out);
    }

    public static function get(int $id): ?array
    {
        $b = DB::one('SELECT b.*, c.name AS customer_name, c.phone AS customer_phone, c.user_id AS customer_user, t.name AS therapist_name, r.name AS resource_name
            FROM bookings b JOIN customers c ON c.id=b.customer_id LEFT JOIN users t ON t.id=b.therapist_id LEFT JOIN resources r ON r.id=b.resource_id WHERE b.id=?', [$id]);
        if (!$b) return null;
        $b['items'] = DB::all('SELECT * FROM booking_items WHERE booking_id=? ORDER BY id', [$id]);
        return self::decorate($b);
    }

    public static function getByCode(string $code): ?array
    {
        $id = DB::val('SELECT id FROM bookings WHERE code=?', [strtoupper(trim($code))]);
        return $id ? self::get((int)$id) : null;
    }

    private static function decorate(array $b): array
    {
        $b['status_label'] = self::STATUS[$b['status']] ?? $b['status'];
        $b['pay_label'] = self::PAY[$b['pay_status']] ?? $b['pay_status'];
        $b['arrive_by'] = date('H:i', strtotime($b['start_at']) - (int)S('arrive_before', '10') * 60);
        $b['start_hm'] = hm($b['start_at']);
        $b['end_hm'] = hm($b['end_at']);
        $b['duration'] = diff_min($b['start_at'], $b['end_at']);
        return $b;
    }

    public static function cancel(int $id, string $reason, bool $byCustomer): array
    {
        return DB::tx(function () use ($id, $reason, $byCustomer) {
            $b = DB::one('SELECT * FROM bookings WHERE id=?' . DB::forUpdate(), [$id]);
            if (!$b) throw new UserError('Reservasi tidak ditemukan.');
            if ($b['pay_status'] !== 'unpaid') throw new UserError('Reservasi sudah dibayar. Gunakan refund/koreksi.');
            $ok = $byCustomer ? ['booked'] : ['booked', 'checkin'];
            if (!in_array($b['status'], $ok, true)) throw new UserError('Reservasi dengan status ini tidak dapat dibatalkan.');
            if ($byCustomer) {
                $minH = (int)S('cancel_min_hours', '2');
                if (strtotime($b['start_at']) - now_ts() < $minH * 3600) throw new UserError('Pembatalan online maksimal ' . $minH . ' jam sebelum jadwal. Hubungi kami via WhatsApp.');
            }
            DB::update('bookings', ['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 160), 'cancelled_at' => now()], 'id=?', [$id]);
            DB::exec("UPDATE sales SET status='void' WHERE booking_id=? AND status='open'", [$id]);
            audit('booking.cancel', 'booking', $id, $reason);
            rev_bump();
            return self::get($id);
        });
    }

    public static function reschedule(int $id, string $date, string $time, bool $byCustomer): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) throw new UserError('Tanggal atau jam tidak valid.');
        return DB::tx(function () use ($id, $date, $time, $byCustomer) {
            $b = DB::one('SELECT * FROM bookings WHERE id=?' . DB::forUpdate(), [$id]);
            if (!$b) throw new UserError('Reservasi tidak ditemukan.');
            if ($b['status'] !== 'booked') throw new UserError('Hanya reservasi berstatus Dipesan yang dapat dijadwalkan ulang.');
            if ($byCustomer && strtotime($b['start_at']) - now_ts() < (int)S('cancel_min_hours', '2') * 3600) throw new UserError('Penjadwalan ulang online maksimal ' . S('cancel_min_hours') . ' jam sebelum jadwal. Hubungi kami via WhatsApp.');
            $dur = diff_min($b['start_at'], $b['end_at']);
            $start = $date . ' ' . $time . ':00';
            $end = add_min($start, $dur);
            if ($start < date('Y-m-d H:i:s', now_ts() - 60)) throw new UserError('Jam tersebut sudah lewat.');
            if (!Slots::isOpenDay($date)) throw new UserError('Kami tutup pada tanggal tersebut.');
            if ($start < $date . ' ' . S('open_time') . ':00' || $end > $date . ' ' . S('close_time') . ':00') throw new UserError('Di luar jam operasional.');
            $svc = [];
            foreach (DB::all('SELECT svc_ids FROM booking_items WHERE booking_id=?', [$id]) as $it) $svc = array_merge($svc, array_filter(array_map('intval', explode(',', $it['svc_ids']))));
            $svc = array_values(array_unique($svc));
            DB::lockDay($date);
            $dup = DB::val("SELECT code FROM bookings WHERE customer_id=? AND id<>? AND status IN " . Slots::ACTIVE . " AND start_at < ? AND ? < end_at", [$b['customer_id'], $id, $end, $start]);
            if ($dup) throw new UserError('Pelanggan sudah memiliki reservasi (' . $dup . ') pada jam tersebut.');
            $ctx = Slots::context($date, $id);
            $want = $b['therapist_chosen'] ? (int)$b['therapist_id'] : null;
            $f = Slots::free($ctx, $start, $end, $svc, Slots::resourceType($svc), $want);
            if ($f['free'] <= 0) {
                $e = new SlotTaken('Jam ' . $time . ' tidak tersedia.');
                $e->alts = array_values(array_map(fn($s) => $s['time'], array_filter(Slots::forDate($date, $svc, $dur, $want, $id), fn($s) => $s['free'] > 0)));
                throw $e;
            }
            $tid = $want ?: (in_array((int)$b['therapist_id'], $f['th'], true) ? (int)$b['therapist_id'] : self::pickTherapist($ctx, $f['th']));
            DB::update('bookings', ['book_date' => $date, 'start_at' => $start, 'end_at' => $end, 'therapist_id' => $tid, 'resource_id' => $f['res'][0]], 'id=?', [$id]);
            audit('booking.reschedule', 'booking', $id, $b['start_at'] . ' -> ' . $start);
            rev_bump();
            return self::get($id);
        });
    }

    public static function checkin(int $id, ?int $therapistId): array
    {
        return DB::tx(function () use ($id, $therapistId) {
            $b = DB::one('SELECT * FROM bookings WHERE id=?' . DB::forUpdate(), [$id]);
            if (!$b) throw new UserError('Reservasi tidak ditemukan.');
            if ($b['status'] !== 'booked') throw new UserError('Check-in hanya untuk reservasi berstatus Dipesan.');
            if ($b['book_date'] !== today()) throw new UserError('Check-in hanya dapat dilakukan pada hari kunjungan (' . tgl_id($b['book_date'], false) . ').');
            $upd = ['status' => 'checkin', 'checkin_at' => now()];
            if ($therapistId && $therapistId !== (int)$b['therapist_id']) {
                $upd['therapist_id'] = self::assertTherapistFree($id, $therapistId, $b);
                $upd['therapist_chosen'] = 1;
            }
            DB::update('bookings', $upd, 'id=?', [$id]);
            audit('booking.checkin', 'booking', $id, '');
            rev_bump();
            return self::get($id);
        });
    }

    public static function assignTherapist(int $id, int $therapistId): array
    {
        return DB::tx(function () use ($id, $therapistId) {
            $b = DB::one('SELECT * FROM bookings WHERE id=?' . DB::forUpdate(), [$id]);
            if (!$b || !in_array($b['status'], ['booked', 'checkin'], true)) throw new UserError('Terapis hanya dapat diganti sebelum perawatan dimulai.');
            $tid = self::assertTherapistFree($id, $therapistId, $b);
            DB::update('bookings', ['therapist_id' => $tid, 'therapist_chosen' => 1], 'id=?', [$id]);
            audit('booking.therapist', 'booking', $id, (string)$tid);
            rev_bump();
            return self::get($id);
        });
    }

    private static function assertTherapistFree(int $bookingId, int $tid, array $b): int
    {
        $u = DB::one("SELECT id FROM users WHERE id=? AND role='terapis' AND active=1", [$tid]);
        if (!$u) throw new UserError('Terapis tidak ditemukan.');
        $svc = [];
        foreach (DB::all('SELECT svc_ids FROM booking_items WHERE booking_id=?', [$bookingId]) as $it) $svc = array_merge($svc, array_filter(array_map('intval', explode(',', $it['svc_ids']))));
        foreach (array_unique($svc) as $sid) if (!DB::val('SELECT 1 FROM therapist_services WHERE user_id=? AND service_id=?', [$tid, $sid])) throw new UserError('Terapis tersebut belum dapat melayani layanan ini.');
        $busy = DB::val('SELECT code FROM bookings WHERE therapist_id=? AND id<>? AND status IN ' . Slots::ACTIVE . ' AND start_at < ? AND ? < end_at', [$tid, $bookingId, $b['end_at'], $b['start_at']]);
        if ($busy) throw new UserError('Terapis sedang menangani reservasi lain (' . $busy . ') pada jam tersebut.');
        return $tid;
    }

    public static function start(int $id, int $userId): array
    {
        return DB::tx(function () use ($id, $userId) {
            $b = DB::one('SELECT * FROM bookings WHERE id=?' . DB::forUpdate(), [$id]);
            if (!$b || (int)$b['therapist_id'] !== $userId) throw new UserError('Pelanggan ini bukan penugasan Anda.');
            if ($b['status'] === 'inservice') return self::get($id); // idempoten
            if ($b['status'] !== 'checkin') throw new UserError('Pelanggan belum check-in di kasir.');
            if (DB::val("SELECT 1 FROM bookings WHERE therapist_id=? AND status='inservice' AND id<>?", [$userId, $id])) throw new UserError('Selesaikan perawatan yang sedang berjalan terlebih dahulu.');
            DB::update('bookings', ['status' => 'inservice', 'started_at' => now()], 'id=?', [$id]);
            audit('booking.start', 'booking', $id, '');
            rev_bump();
            return self::get($id);
        });
    }

    public static function finish(int $id, int $userId, ?array $materials = null): array
    {
        return DB::tx(function () use ($id, $userId, $materials) {
            $b = DB::one('SELECT * FROM bookings WHERE id=?' . DB::forUpdate(), [$id]);
            if (!$b || (int)$b['therapist_id'] !== $userId) throw new UserError('Pelanggan ini bukan penugasan Anda.');
            if ($b['status'] === 'done') return self::get($id); // idempoten
            if ($b['status'] !== 'inservice') throw new UserError('Perawatan belum dimulai.');
            DB::update('bookings', ['status' => 'done', 'finished_at' => now()], 'id=?', [$id]);
            Stock::useForBooking($id, $userId, $materials);
            audit('booking.finish', 'booking', $id, '');
            rev_bump();
            return self::get($id);
        });
    }

    public static function markNoShow(int $id, bool $force = false): bool
    {
        return DB::tx(function () use ($id, $force) {
            $b = DB::one('SELECT * FROM bookings WHERE id=?' . DB::forUpdate(), [$id]);
            if (!$b || $b['status'] !== 'booked') return false;
            if (!$force && now_ts() < strtotime($b['start_at']) + (int)S('noshow_grace', '30') * 60) return false;
            DB::update('bookings', ['status' => 'noshow', 'cancelled_at' => now(), 'cancel_reason' => 'Tidak hadir'], 'id=?', [$id]);
            audit('booking.noshow', 'booking', $id, '');
            rev_bump();
            return true;
        });
    }

    /** Daftar untuk staf (antrean/kalender). */
    public static function listDay(string $date): array
    {
        $rows = DB::all('SELECT b.*, c.name AS customer_name, c.phone AS customer_phone, t.name AS therapist_name, r.name AS resource_name,
              (SELECT id FROM sales s WHERE s.booking_id=b.id) AS sale_id, (SELECT status FROM sales s WHERE s.booking_id=b.id) AS sale_status
            FROM bookings b JOIN customers c ON c.id=b.customer_id LEFT JOIN users t ON t.id=b.therapist_id LEFT JOIN resources r ON r.id=b.resource_id
            WHERE b.book_date=? ORDER BY b.start_at, b.id', [$date]);
        return self::attachItems($rows);
    }

    public static function attachItems(array $rows): array
    {
        if (!$rows) return [];
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $items = [];
        foreach (DB::all("SELECT * FROM booking_items WHERE booking_id IN ($ids) ORDER BY id") as $it) $items[(int)$it['booking_id']][] = $it;
        foreach ($rows as &$r) {
            $r['items'] = $items[(int)$r['id']] ?? [];
            $r['services'] = implode(' + ', array_column($r['items'], 'name'));
            $r = self::decorate($r);
        }
        return $rows;
    }

    public static function forCustomer(int $cid): array
    {
        $rows = DB::all('SELECT b.*, c.name AS customer_name, t.name AS therapist_name FROM bookings b JOIN customers c ON c.id=b.customer_id LEFT JOIN users t ON t.id=b.therapist_id
            WHERE b.customer_id=? ORDER BY b.start_at DESC', [$cid]);
        return self::attachItems($rows);
    }

    /** Untuk terapis: hanya penugasan miliknya, tanpa kontak pelanggan. */
    public static function forTherapist(int $uid, string $from, string $to): array
    {
        $rows = DB::all("SELECT b.id, b.code, b.book_date, b.start_at, b.end_at, b.status, b.notes, b.therapist_id, c.name AS customer_name, r.name AS resource_name
            FROM bookings b JOIN customers c ON c.id=b.customer_id LEFT JOIN resources r ON r.id=b.resource_id
            WHERE b.therapist_id=? AND b.book_date BETWEEN ? AND ? AND b.status IN ('booked','checkin','inservice','done') ORDER BY b.start_at", [$uid, $from, $to]);
        return self::attachItems($rows);
    }
}
