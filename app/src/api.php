<?php
declare(strict_types=1);

/** Dispatcher API JSON. Setiap rute menyatakan kewenangan yang dibutuhkan; pemeriksaan dilakukan di server. */

function api_dispatch(string $name): never
{
    $routes = api_routes();
    if (!isset($routes[$name])) json_fail('Endpoint tidak ditemukan.', 404);
    [$method, $perm, $fn] = $routes[$name];
    if ($_SERVER['REQUEST_METHOD'] !== $method) json_fail('Metode tidak diizinkan.', 405);
    try {
        if ($perm === 'customer') { $u = Auth::user(); if (!$u) json_fail('Silakan masuk terlebih dahulu.', 401); if ($u['role'] !== 'pelanggan') json_fail('Khusus akun pelanggan.', 403); }
        elseif ($perm !== null && $perm !== 'user' && $perm !== 'any') Auth::needApi($perm);
        elseif ($perm === 'any' && !Auth::user()) json_fail('Silakan masuk terlebih dahulu.', 401);
        if ($method === 'POST') Auth::checkCsrf();
        $res = $fn(body(), $_GET);
        json_out(['ok' => true] + (is_array($res) ? $res : []));
    } catch (SlotTaken $e) {
        json_fail($e->getMessage(), 409, ['alts' => $e->alts, 'slot_taken' => true]);
    } catch (AttendanceFail $e) {
        json_fail($e->getMessage(), 422, ['att' => $e->data]);
    } catch (UserError $e) {
        json_fail($e->getMessage(), 422);
    } catch (Throwable $e) {
        @file_put_contents(APP_ROOT . '/storage/error.log', '[' . now() . '] ' . $name . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND);
        json_fail('Terjadi kesalahan di server. Coba lagi.', 500);
    }
}

function own_booking(int $id): array
{
    $b = Booking::get($id);
    if (!$b || (int)$b['customer_user'] !== (int)Auth::id()) json_fail('Reservasi tidak ditemukan.', 404);
    return $b;
}

/** Ringkasan reservasi yang aman untuk pelanggan. */
function pub_booking(array $b): array
{
    return [
        'id' => (int)$b['id'], 'code' => $b['code'], 'date' => $b['book_date'], 'date_label' => tgl_id($b['book_date']), 'start' => $b['start_hm'], 'end' => $b['end_hm'], 'arrive_by' => $b['arrive_by'],
        'status' => $b['status'], 'status_label' => $b['status_label'], 'pay_status' => $b['pay_status'], 'pay_label' => $b['pay_label'], 'total' => (int)$b['total'],
        'services' => implode(' + ', array_column($b['items'], 'name')), 'therapist' => $b['therapist_name'] ?? null, 'items' => array_map(fn($i) => ['name' => $i['name'], 'price' => (int)$i['price']], $b['items']),
        'can_change' => $b['status'] === 'booked' && strtotime($b['start_at']) - now_ts() >= (int)S('cancel_min_hours', '2') * 3600,
    ];
}

function staff_booking_row(array $b): array
{
    return [
        'id' => (int)$b['id'], 'code' => $b['code'], 'date' => $b['book_date'], 'start' => $b['start_hm'], 'end' => $b['end_hm'], 'arrive_by' => $b['arrive_by'],
        'status' => $b['status'], 'status_label' => $b['status_label'], 'pay_status' => $b['pay_status'], 'pay_label' => $b['pay_label'], 'total' => (int)$b['total'],
        'customer_id' => (int)$b['customer_id'], 'customer' => $b['customer_name'], 'phone' => $b['customer_phone'] ?? '', 'services' => $b['services'] ?? implode(' + ', array_column($b['items'], 'name')),
        'therapist_id' => $b['therapist_id'] ? (int)$b['therapist_id'] : null, 'therapist' => $b['therapist_name'] ?? null, 'resource' => $b['resource_name'] ?? null,
        'source' => $b['source'], 'notes' => $b['notes'], 'sale_id' => isset($b['sale_id']) && $b['sale_id'] ? (int)$b['sale_id'] : null, 'sale_status' => $b['sale_status'] ?? null,
    ];
}

function items_from(array $in): array
{
    $items = $in['items'] ?? null;
    if (is_string($items)) $items = json_decode($items, true);
    return is_array($items) ? $items : [];
}

function api_routes(): array
{
    $R = [];

    // ---------------- Publik ----------------
    $R['slots'] = ['GET', null, function ($in, $q) {
        $items = items_from($q);
        $date = (string)($q['date'] ?? today());
        $staff = !empty($q['staff']) && Auth::can('booking_manage');
        if (!empty($q['booking'])) { // jadwal ulang: pakai layanan & durasi reservasi itu sendiri
            $b = Booking::get((int)$q['booking']);
            if (!$b || !(Auth::can('booking_manage') || (int)$b['customer_user'] === (int)Auth::id())) json_fail('Reservasi tidak ditemukan.', 404);
            $svc = [];
            foreach ($b['items'] as $it) $svc = array_merge($svc, array_filter(array_map('intval', explode(',', $it['svc_ids']))));
            $open = Slots::isOpenDay($date);
            return ['duration' => (int)$b['duration'], 'total' => (int)$b['total'], 'closed' => !$open, 'closed_note' => $open ? '' : 'Tutup pada hari ini',
                'slots' => Slots::forDate($date, array_values(array_unique($svc)), (int)$b['duration'], $b['therapist_chosen'] ? (int)$b['therapist_id'] : null, (int)$b['id'], $staff)];
        }
        $exclude = null;
        if (!empty($q['exclude'])) {
            $b = Booking::get((int)$q['exclude']);
            if ($b && (Auth::can('booking_manage') || ((int)$b['customer_user'] === (int)Auth::id()))) $exclude = (int)$b['id'];
        }
        if (!$items) return ['slots' => [], 'duration' => 0, 'total' => 0];
        $r = Booking::resolveItems($items, $date);
        $tid = !empty($q['therapist']) ? (int)$q['therapist'] : null;
        $open = Slots::isOpenDay($date);
        $holiday = DB::val('SELECT note FROM holidays WHERE hdate=?', [$date]);
        return [
            'duration' => $r['duration'], 'total' => $r['total'], 'closed' => !$open, 'closed_note' => $holiday ?: ($open ? '' : 'Tutup pada hari ini'),
            'arrive_before' => (int)S('arrive_before', '10'), 'close' => S('close_time'),
            'slots' => Slots::forDate($date, $r['svc_ids'], $r['duration'], $tid, $exclude, $staff),
        ];
    }];

    $R['auth.login'] = ['POST', null, function ($in) {
        $u = Auth::login((string)($in['ident'] ?? ''), (string)($in['password'] ?? ''));
        return ['user' => ['name' => $u['name'], 'role' => $u['role']], 'redirect' => in_array($u['role'], Auth::STAFF, true) ? base_url('s/') : base_url('')];
    }];
    $R['auth.register'] = ['POST', null, function ($in) {
        Customers::register((string)($in['name'] ?? ''), (string)($in['phone'] ?? ''), $in['email'] ?? null, (string)($in['password'] ?? ''));
        $u = Auth::login((string)$in['phone'], (string)$in['password']);
        return ['user' => ['name' => $u['name'], 'role' => $u['role']]];
    }];
    $R['auth.logout'] = ['POST', null, function () { Auth::logout(); return []; }];
    $R['poll'] = ['GET', 'any', function () {
        $o = ['rev' => rev_get()];
        if (Auth::can('corrections')) $o['pending'] = (int)DB::val("SELECT COUNT(*) FROM att_corrections WHERE status='pending'") + (int)DB::val("SELECT COUNT(*) FROM refund_requests WHERE status='pending'");
        return $o;
    }];

    // ---------------- Pelanggan ----------------
    $R['booking.create'] = ['POST', 'customer', function ($in) {
        $cid = Auth::customerId();
        if (!$cid) throw new UserError('Data pelanggan tidak ditemukan.');
        $b = Booking::create(['customer_id' => $cid, 'items' => items_from($in), 'date' => (string)($in['date'] ?? ''), 'time' => (string)($in['time'] ?? ''), 'therapist_id' => $in['therapist_id'] ?? null, 'notes' => (string)($in['notes'] ?? ''), 'source' => 'online', 'created_by' => Auth::id()]);
        return ['booking' => pub_booking($b)];
    }];
    $R['booking.mine'] = ['GET', 'customer', function () {
        $cid = Auth::customerId();
        return ['bookings' => array_map('pub_booking', Booking::forCustomer((int)$cid))];
    }];
    $R['booking.cancel'] = ['POST', 'customer', function ($in) {
        $b = own_booking((int)($in['id'] ?? 0));
        return ['booking' => pub_booking(Booking::cancel((int)$b['id'], (string)($in['reason'] ?? 'Dibatalkan pelanggan'), true))];
    }];
    $R['booking.reschedule'] = ['POST', 'customer', function ($in) {
        $b = own_booking((int)($in['id'] ?? 0));
        return ['booking' => pub_booking(Booking::reschedule((int)$b['id'], (string)($in['date'] ?? ''), (string)($in['time'] ?? ''), true))];
    }];
    $R['me.update'] = ['POST', 'customer', function ($in) {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new UserError('Nama wajib diisi.');
        $email = trim((string)($in['email'] ?? '')) ?: null;
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new UserError('Format email tidak valid.');
        try { DB::update('users', ['name' => $name, 'email' => $email ? mb_strtolower($email) : null], 'id=?', [Auth::id()]); }
        catch (PDOException $e) { throw new UserError('Email sudah dipakai akun lain.'); }
        DB::update('customers', ['name' => $name, 'email' => $email], 'user_id=?', [Auth::id()]);
        return [];
    }];
    $R['me.password'] = ['POST', 'any', function ($in) {
        $u = DB::one('SELECT pass_hash FROM users WHERE id=?', [Auth::id()]);
        if (!password_verify((string)($in['old'] ?? ''), $u['pass_hash'])) throw new UserError('Kata sandi lama salah.');
        if (strlen((string)($in['new'] ?? '')) < 6) throw new UserError('Kata sandi baru minimal 6 karakter.');
        DB::update('users', ['pass_hash' => Auth::hash((string)$in['new'])], 'id=?', [Auth::id()]);
        return [];
    }];

    // ---------------- Antrean & reservasi (staf) ----------------
    $R['queue'] = ['GET', 'booking_manage', function ($in, $q) {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($q['date'] ?? '')) ? $q['date'] : today();
        $ther = DB::all("SELECT id, name FROM users WHERE role='terapis' AND active=1 ORDER BY id");
        foreach ($ther as &$t) { $sh = Slots::shiftFor((int)$t['id'], $date); $t['shift'] = $sh ? ['name' => $sh['name'], 'start' => $sh['start_time'], 'end' => $sh['end_time'], 'bs' => $sh['break_start'], 'be' => $sh['break_end']] : null; }
        return ['date' => $date, 'date_label' => tgl_id($date), 'rev' => rev_get(), 'bookings' => array_map('staff_booking_row', Booking::listDay($date)), 'therapists' => $ther, 'open' => S('open_time'), 'close' => S('close_time')];
    }];
    $R['book.catalog'] = ['GET', 'booking_manage', function () {
        require_once APP_ROOT . '/src/views/data.php';
        $items = [];
        foreach (DB::all("SELECT id, name, category, price, price_from, duration_min FROM services WHERE active=1 AND duration_min>0 ORDER BY sort, id") as $s) $items[] = ['key' => 's' . $s['id'], 'name' => $s['name'], 'price' => (int)$s['price'], 'dur' => (int)$s['duration_min'], 'cat' => $s['category']];
        foreach (promo_ready() as $p) foreach ($p['items'] as $it) $items[] = ['key' => 'p' . $it['id'], 'name' => $it['label'] . ' (Promo)', 'price' => (int)$it['promo_price'], 'dur' => (int)$it['duration_min'], 'cat' => $p['name']];
        return ['items' => $items, 'therapists' => DB::all("SELECT id, name FROM users WHERE role='terapis' AND active=1 ORDER BY id")];
    }];
    $R['sbooking.create'] =['POST', 'booking_manage', function ($in) {
        $cid = (int)($in['customer_id'] ?? 0);
        if (!$cid) $cid = Customers::findOrCreate((string)($in['name'] ?? ''), $in['phone'] ?? null, $in['email'] ?? null);
        $src = ($in['source'] ?? 'kasir') === 'walkin' ? 'walkin' : 'kasir';
        $b = Booking::create(['customer_id' => $cid, 'items' => items_from($in), 'date' => (string)($in['date'] ?? ''), 'time' => (string)($in['time'] ?? ''), 'therapist_id' => $in['therapist_id'] ?? null, 'notes' => (string)($in['notes'] ?? ''), 'source' => $src, 'created_by' => Auth::id()]);
        return ['booking' => staff_booking_row($b)];
    }];
    $R['booking.checkin'] = ['POST', 'booking_manage', fn($in) => ['booking' => staff_booking_row(Booking::checkin((int)$in['id'], !empty($in['therapist_id']) ? (int)$in['therapist_id'] : null))]];
    $R['booking.assign'] = ['POST', 'booking_manage', fn($in) => ['booking' => staff_booking_row(Booking::assignTherapist((int)$in['id'], (int)$in['therapist_id']))]];
    $R['booking.noshow'] = ['POST', 'booking_manage', function ($in) { if (!Booking::markNoShow((int)$in['id'], true)) throw new UserError('Hanya reservasi berstatus Dipesan yang dapat ditandai Tidak Hadir.'); return []; }];
    $R['booking.scancel'] = ['POST', 'booking_manage', fn($in) => ['booking' => staff_booking_row(Booking::cancel((int)$in['id'], trim((string)($in['reason'] ?? '')) ?: 'Dibatalkan petugas', false))]];
    $R['booking.sreschedule'] = ['POST', 'booking_manage', fn($in) => ['booking' => staff_booking_row(Booking::reschedule((int)$in['id'], (string)$in['date'], (string)$in['time'], false))]];

    // ---------------- Terapis ----------------
    $R['work.list'] = ['GET', 'therapist_work', function () {
        $uid = (int)Auth::id();
        $rows = Booking::forTherapist($uid, today(), date('Y-m-d', strtotime(today() . ' +7 days')));
        $out = array_map(fn($b) => [
            'id' => (int)$b['id'], 'code' => $b['code'], 'date' => $b['book_date'], 'date_label' => tgl_id($b['book_date']), 'start' => $b['start_hm'], 'end' => $b['end_hm'], 'status' => $b['status'], 'status_label' => $b['status_label'],
            'customer' => $b['customer_name'], 'notes' => $b['notes'], 'room' => $b['resource_name'], 'services' => $b['services'], 'items' => array_column($b['items'], 'name'),
        ], $rows);
        $sh = Slots::shiftFor($uid, today());
        return ['rev' => rev_get(), 'today' => today(), 'shift' => $sh ? ['name' => $sh['name'], 'start' => $sh['start_time'], 'end' => $sh['end_time']] : null, 'bookings' => $out];
    }];
    $R['work.materials'] = ['GET', 'therapist_work', function ($in, $q) {
        $b = Booking::get((int)($q['id'] ?? 0));
        if (!$b || (int)$b['therapist_id'] !== (int)Auth::id()) throw new UserError('Pelanggan ini bukan penugasan Anda.');
        return ['materials' => Stock::defaultMaterials((int)$b['id'])];
    }];
    $R['work.start'] = ['POST', 'therapist_work', fn($in) => ['status' => Booking::start((int)$in['id'], (int)Auth::id())['status']]];
    $R['work.finish'] = ['POST', 'therapist_work', fn($in) => ['status' => Booking::finish((int)$in['id'], (int)Auth::id(), isset($in['materials']) && is_array($in['materials']) ? $in['materials'] : null)['status']]];

    // ---------------- Absensi ----------------
    $R['att.today'] = ['GET', 'attendance_self', function () {
        $t = Attendance::today((int)Auth::id());
        $c = $t['config'];
        return ['row' => $t['row'], 'shift' => $t['shift'], 'enabled' => $c['enabled'], 'radius' => $c['radius'], 'acc_max' => $c['acc_max'], 'now' => $c['now']];
    }];
    $R['att.punch'] = ['POST', 'attendance_self', function ($in) {
        $type = ($in['type'] ?? '') === 'out' ? 'out' : 'in';
        $r = Attendance::punch((int)Auth::id(), $type, isset($in['lat']) ? (float)$in['lat'] : null, isset($in['lng']) ? (float)$in['lng'] : null, isset($in['acc']) ? (float)$in['acc'] : null);
        return ['row' => $r['row'], 'distance' => $r['distance'], 'accuracy' => $r['accuracy'], 'time' => $r['time'], 'shift' => $r['shift']['name'] ?? null, 'type' => $type];
    }];
    $R['att.mine'] = ['GET', 'attendance_self', function () {
        $uid = (int)Auth::id();
        return ['rows' => DB::all('SELECT a.*, s.name AS shift_name FROM attendance a LEFT JOIN shifts s ON s.id=a.shift_id WHERE a.user_id=? ORDER BY a.att_date DESC LIMIT 20', [$uid]), 'corrections' => Attendance::corrections(null, $uid)];
    }];
    $R['att.correction'] = ['POST', 'attendance_self', function ($in) {
        $id = Attendance::requestCorrection((int)Auth::id(), (string)($in['date'] ?? ''), (string)($in['ctype'] ?? 'waktu'), $in['req_in'] ?? null, $in['req_out'] ?? null, $in['req_status'] ?? null, (string)($in['reason'] ?? ''));
        return ['id' => $id];
    }];
    $R['att.list'] = ['GET', 'attendance_all', function ($in, $q) {
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($q['date'] ?? '')) ? $q['date'] : today();
        $rows = [];
        foreach (DB::all("SELECT id, name, role FROM users WHERE role<>'pelanggan' AND active=1 ORDER BY name") as $u) {
            $a = DB::one('SELECT a.*, s.name AS shift_name FROM attendance a LEFT JOIN shifts s ON s.id=a.shift_id WHERE a.user_id=? AND a.att_date=?', [$u['id'], $d]);
            $sh = Slots::shiftFor((int)$u['id'], $d);
            $rows[] = ['user_id' => (int)$u['id'], 'name' => $u['name'], 'role' => Auth::ROLES[$u['role']], 'shift' => $sh ? $sh['name'] . ' ' . $sh['start_time'] . '–' . $sh['end_time'] : '-', 'status' => $a['status'] ?? ($sh ? ($d < today() ? 'alpa' : '') : ''),
                'in' => hm($a['in_at'] ?? null), 'out' => hm($a['out_at'] ?? null), 'late' => (int)($a['late_min'] ?? 0), 'early' => (int)($a['early_min'] ?? 0), 'work' => (int)($a['work_min'] ?? 0), 'dist' => $a['in_dist'] ?? null];
        }
        return ['date' => $d, 'rows' => $rows];
    }];
    $R['att.setstatus'] = ['POST', 'attendance_all', function ($in) { Attendance::setStatusDirect((int)$in['user_id'], (string)$in['date'], (string)$in['status'], (string)($in['note'] ?? ''), (int)Auth::id()); return []; }];
    $R['corr.list'] = ['GET', 'corrections', function ($in, $q) { return ['rows' => Attendance::corrections(($q['status'] ?? '') ?: null)]; }];
    $R['corr.process'] = ['POST', 'corrections', function ($in) { Attendance::processCorrection((int)$in['id'], !empty($in['approve']), (string)($in['note'] ?? ''), (int)Auth::id()); return []; }];

    // ---------------- Pelanggan (staf) & membership ----------------
    $R['cust.search'] = ['GET', 'customers', function ($in, $q) {
        $rows = Customers::search((string)($q['q'] ?? ''), 20);
        foreach ($rows as &$r) { $m = Customers::memberInfo($r); $r['member'] = $m['active'] ? $m['plan']['name'] : null; }
        return ['customers' => $rows];
    }];
    $R['cust.get'] = ['GET', 'customers', function ($in, $q) {
        $c = Customers::get((int)($q['id'] ?? 0));
        if (!$c) throw new UserError('Pelanggan tidak ditemukan.');
        $h = Customers::history((int)$c['id']);
        return ['customer' => $c, 'bookings' => array_map('staff_booking_row', $h['bookings']), 'sales' => $h['sales'], 'points' => $h['points'], 'plans' => DB::all('SELECT id, name FROM membership_plans WHERE active=1')];
    }];
    $R['cust.save'] = ['POST', 'customers', function ($in) {
        $id = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new UserError('Nama wajib diisi.');
        $phone = normalize_phone($in['phone'] ?? null);
        $email = trim((string)($in['email'] ?? '')) ?: null;
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new UserError('Format email tidak valid.');
        try {
            if ($id) DB::update('customers', ['name' => $name, 'phone' => $phone, 'email' => $email, 'notes' => mb_substr((string)($in['notes'] ?? ''), 0, 250)], 'id=?', [$id]);
            else $id = DB::insert('customers', ['name' => $name, 'phone' => $phone, 'email' => $email, 'notes' => mb_substr((string)($in['notes'] ?? ''), 0, 250), 'points' => 0, 'created_at' => now()]);
        } catch (PDOException $e) { throw new UserError('Nomor HP sudah terdaftar atas pelanggan lain.'); }
        audit('customer.save', 'customer', $id, $name);
        return ['id' => $id];
    }];
    $R['cust.member'] = ['POST', 'member_manage', function ($in) {
        if (!empty($in['remove'])) DB::update('customers', ['plan_id' => null, 'member_until' => null], 'id=?', [(int)$in['id']]);
        else Customers::activate((int)$in['id'], (int)$in['plan_id']);
        rev_bump();
        return [];
    }];

    // ---------------- Kasir ----------------
    $R['pos.catalog'] = ['GET', 'pos', function () {
        return [
            'services' => DB::all('SELECT id, name, category, price, price_from, duration_min FROM services WHERE active=1 ORDER BY sort, id'),
            'products' => array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'price' => (int)$p['price'], 'stock' => $p['on_hand']], array_values(array_filter(Stock::list(), fn($p) => $p['kind'] === 'jual' && (int)$p['active'] === 1))),
            'plans' => DB::all('SELECT id, name, price, discount_pct FROM membership_plans WHERE active=1'),
            'accounts' => DB::all("SELECT id, name, type FROM accounts WHERE active=1 ORDER BY id"),
            'manual_max_pct' => (int)S('manual_disc_max_pct', '10'), 'point_value' => (int)S('point_value', '100'),
        ];
    }];
    $R['pos.queue'] = ['GET', 'pos', function () {
        $rows = Booking::listDay(today());
        $rows = array_values(array_filter($rows, fn($b) => in_array($b['status'], ['booked', 'checkin', 'inservice', 'done'], true) && $b['pay_status'] === 'unpaid'));
        return ['rev' => rev_get(), 'bookings' => array_map('staff_booking_row', $rows)];
    }];
    $R['pos.open'] = ['POST', 'pos', function ($in) {
        $uid = (int)Auth::id();
        $s = !empty($in['booking_id']) ? Pos::forBooking((int)$in['booking_id'], $uid) : Pos::newWalkin($uid, !empty($in['customer_id']) ? (int)$in['customer_id'] : null);
        return ['sale' => $s];
    }];
    $R['pos.get'] = ['GET', 'pos', fn($in, $q) => ['sale' => Pos::view((int)($q['id'] ?? 0))]];
    $R['pos.update'] = ['POST', 'pos', fn($in) => ['sale' => Pos::update((int)$in['id'], $in, in_array(Auth::role(), ['admin', 'manager'], true))]];
    $R['pos.pay'] = ['POST', 'pos', fn($in) => ['sale' => Pos::pay((int)$in['id'], $in, (string)($in['idem_key'] ?? ''), (int)Auth::id())]];
    $R['pos.receipts'] = ['GET', 'refund_request', function ($in, $q) {
        $term = trim((string)($q['q'] ?? ''));
        $like = '%' . $term . '%';
        $rows = DB::all("SELECT s.id, s.receipt_no, s.total, s.status, s.paid_at, c.name AS customer_name FROM sales s LEFT JOIN customers c ON c.id=s.customer_id
            WHERE s.paid_at IS NOT NULL AND (s.receipt_no LIKE ? OR c.name LIKE ?) ORDER BY s.id DESC LIMIT 15", [$like, $like]);
        return ['sales' => $rows];
    }];
    $R['pos.refund_request'] = ['POST', 'refund_request', function ($in) {
        $id = Pos::requestRefund((int)$in['sale_id'], (array)($in['lines'] ?? []), (string)($in['reason'] ?? ''), (int)Auth::id());
        return ['id' => $id];
    }];
    $R['pos.refund_now'] = ['POST', 'refund_approve', function ($in) {
        return ['id' => Pos::refundNow((int)$in['sale_id'], (array)($in['lines'] ?? []), (string)($in['reason'] ?? ''), (int)Auth::id(), (int)Auth::id())];
    }];
    $R['pos.refund_list'] = ['GET', 'refund_request', function ($in, $q) {
        $st = ($q['status'] ?? 'pending');
        $rows = DB::all('SELECT r.*, s.receipt_no, u.name AS requester FROM refund_requests r JOIN sales s ON s.id=r.sale_id JOIN users u ON u.id=r.requested_by WHERE r.status=? ORDER BY r.id DESC LIMIT 100', [$st]);
        foreach ($rows as &$r) {
            $r['lines'] = [];
            foreach (json_decode($r['items_json'], true) ?: [] as $l) {
                $it = DB::one('SELECT name FROM sale_items WHERE id=?', [(int)$l['sale_item_id']]);
                $r['lines'][] = ($it['name'] ?? '?') . ' ×' . (int)$l['qty'];
            }
        }
        return ['rows' => $rows];
    }];
    $R['pos.refund_process'] = ['POST', 'refund_approve', fn($in) => Pos::processRequest((int)$in['id'], !empty($in['approve']), (string)($in['note'] ?? ''), (int)Auth::id())];

    // ---------------- Kas ----------------
    $R['cash.status'] = ['GET', 'cash_session', function () {
        $s = Cash::current((int)Auth::id());
        return ['session' => $s ? Cash::summary((int)$s['id']) : null];
    }];
    $R['cash.open'] = ['POST', 'cash_session', fn($in) => ['session' => Cash::open((int)Auth::id(), (int)($in['opening_cash'] ?? 0))]];
    $R['cash.close'] = ['POST', 'cash_session', function ($in) {
        $s = Cash::current((int)Auth::id());
        if (!$s) throw new UserError('Kas belum dibuka.');
        return ['summary' => Cash::close((int)$s['id'], (int)Auth::id(), (int)($in['counted'] ?? 0), (string)($in['note'] ?? ''))];
    }];
    $R['cash.accounts'] = ['GET', 'cash_mutation', fn() => ['accounts' => Cash::accounts(), 'cats' => Cash::CATS]];
    $R['cash.list'] = ['GET', 'cash_mutation', function ($in, $q) {
        $f = (string)($q['from'] ?? today()); $t = (string)($q['to'] ?? today());
        return ['rows' => Cash::movements($f, $t, !empty($q['account']) ? (int)$q['account'] : null)];
    }];
    $R['cash.move'] = ['POST', 'cash_mutation', function ($in) {
        $cat = (string)($in['category'] ?? 'lainnya');
        if (!isset(Cash::CATS[$cat]) || in_array($cat, ['transfer', 'penjualan', 'refund', 'selisih_kas'], true)) throw new UserError('Kategori tidak valid untuk input manual.');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['date'] ?? '')) ? $in['date'] : today();
        $id = Cash::add(['account_id' => (int)$in['account_id'], 'kind' => ($in['kind'] ?? '') === 'out' ? 'out' : 'in', 'category' => $cat, 'amount' => (int)$in['amount'], 'note' => (string)($in['note'] ?? ''), 'mv_date' => $date]);
        audit('cash.move', 'cash_movement', $id, $cat . ' ' . $in['amount']);
        rev_bump();
        return ['id' => $id];
    }];
    $R['cash.transfer'] = ['POST', 'cash_mutation', function ($in) {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['date'] ?? '')) ? $in['date'] : today();
        Cash::transfer((int)$in['from'], (int)$in['to'], (int)$in['amount'], (string)($in['note'] ?? ''), $date);
        rev_bump();
        return [];
    }];
    $R['cash.sessions'] = ['GET', 'cash_review', function ($in, $q) { return ['rows' => Cash::sessions((string)($q['from'] ?? date('Y-m-d', strtotime('-30 days'))), (string)($q['to'] ?? today()))]; }];
    $R['cash.review'] = ['POST', 'cash_review', function ($in) { Cash::review((int)$in['id'], (int)Auth::id(), (string)($in['note'] ?? '')); return []; }];

    // ---------------- Stok ----------------
    $R['stock.list'] = ['GET', 'stock_view', fn() => ['rows' => Stock::list(), 'accounts' => DB::all('SELECT id, name FROM accounts WHERE active=1')]];
    $R['stock.card'] = ['GET', 'stock_view', fn($in, $q) => ['rows' => Stock::card((int)$q['id'])]];
    $R['stock.purchase'] = ['POST', 'stock_manage', function ($in) { Stock::purchase((int)$in['product_id'], (float)$in['qty'], (int)($in['unit_cost'] ?? 0), !empty($in['account_id']) ? (int)$in['account_id'] : null, (string)($in['note'] ?? '')); rev_bump(); return []; }];
    $R['stock.adjust'] = ['POST', 'stock_manage', function ($in) { Stock::adjust((int)$in['product_id'], (float)$in['on_hand'], (string)($in['reason'] ?? '')); rev_bump(); return []; }];

    // ---------------- Jadwal ----------------
    $R['sched.get'] = ['GET', 'schedule', function ($in, $q) {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($q['from'] ?? '')) ? $q['from'] : date('Y-m-d', strtotime('monday this week', now_ts()));
        $days = [];
        for ($i = 0; $i < 7; $i++) $days[] = date('Y-m-d', strtotime($from . " +$i days"));
        $users = DB::all("SELECT id, name, role FROM users WHERE role IN ('terapis','kasir','manager') AND active=1 ORDER BY CASE role WHEN 'terapis' THEN 1 WHEN 'kasir' THEN 2 ELSE 3 END, name");
        $grid = [];
        foreach ($users as $u) foreach ($days as $d) { $sh = Slots::shiftFor((int)$u['id'], $d); $grid[$u['id']][$d] = $sh ? (int)$sh['id'] : 0; }
        $pat = [];
        foreach (DB::all('SELECT * FROM therapist_pattern') as $p) $pat[(int)$p['user_id']][(int)$p['weekday']] = (int)$p['shift_id'];
        return ['from' => $from, 'days' => $days, 'users' => $users, 'grid' => $grid, 'pattern' => $pat, 'shifts' => DB::all('SELECT id, name, start_time, end_time FROM shifts WHERE active=1 ORDER BY start_time'), 'holidays' => DB::all('SELECT hdate FROM holidays')];
    }];
    $R['sched.set'] = ['POST', 'schedule', function ($in) {
        $uid = (int)$in['user_id']; $d = (string)$in['date']; $sid = (int)$in['shift_id'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) throw new UserError('Tanggal tidak valid.');
        if (!DB::val("SELECT 1 FROM users WHERE id=? AND role<>'pelanggan'", [$uid])) throw new UserError('Pegawai tidak ditemukan.');
        if ($sid > 0 && !DB::val('SELECT 1 FROM shifts WHERE id=?', [$sid])) throw new UserError('Shift tidak ditemukan.');
        DB::exec('DELETE FROM schedules WHERE user_id=? AND sched_date=?', [$uid, $d]);
        DB::insert('schedules', ['user_id' => $uid, 'sched_date' => $d, 'shift_id' => $sid]);
        audit('schedule.set', 'user', $uid, "$d shift=$sid");
        rev_bump();
        return [];
    }];
    $R['sched.pattern'] = ['POST', 'schedule', function ($in) {
        $uid = (int)$in['user_id']; $wd = (int)$in['weekday']; $sid = (int)$in['shift_id'];
        if ($wd < 1 || $wd > 7) throw new UserError('Hari tidak valid.');
        DB::exec('DELETE FROM therapist_pattern WHERE user_id=? AND weekday=?', [$uid, $wd]);
        if ($sid > 0) DB::insert('therapist_pattern', ['user_id' => $uid, 'weekday' => $wd, 'shift_id' => $sid]);
        audit('schedule.pattern', 'user', $uid, "wd=$wd shift=$sid");
        rev_bump();
        return [];
    }];

    // ---------------- Dashboard & laporan ----------------
    $R['dash'] = ['GET', 'dashboard', fn() => ['d' => Reports::dashboard()]];
    $R['report'] = ['GET', 'reports', function ($in, $q) {
        $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($q['from'] ?? '')) ? $q['from'] : date('Y-m-01');
        $t = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($q['to'] ?? '')) ? $q['to'] : today();
        if ($t < $f) throw new UserError('Tanggal akhir sebelum tanggal awal.');
        $rep = Reports::build((string)($q['type'] ?? 'penjualan'), $f, $t);
        $fmt = $q['format'] ?? 'json';
        if ($fmt === 'xlsx') Export::xlsx($rep);
        if ($fmt === 'pdf') Export::pdf($rep);
        return ['report' => $rep];
    }];

    // ---------------- Pengaturan (admin) ----------------
    $R['crud.list'] = ['GET', 'settings', function ($in, $q) {
        $e = (string)($q['e'] ?? '');
        $d = Crud::def($e);
        return ['def' => ['title' => $d['title'], 'list' => $d['list'], 'fields' => $d['fields'], 'deletable' => !empty($d['deletable']), 'pk' => $d['pk'] ?? 'id'], 'rows' => Crud::list($e),
            'services' => $e === 'users' ? DB::all('SELECT id, name FROM services WHERE active=1 ORDER BY sort, id') : []];
    }];
    $R['crud.save'] = ['POST', 'settings', fn($in) => ['id' => Crud::save((string)$in['e'], (array)($in['data'] ?? []))]];
    $R['crud.delete'] = ['POST', 'settings', function ($in) { Crud::delete((string)$in['e'], (string)$in['id']); return []; }];
    $R['settings.get'] = ['GET', 'settings', function () {
        $o = [];
        foreach (DB::all('SELECT k, v FROM settings') as $r) $o[$r['k']] = $r['v'];
        return ['s' => $o];
    }];
    $R['settings.save'] = ['POST', 'settings', function ($in) {
        $allowed = ['biz_name', 'biz_tagline', 'biz_address', 'biz_wa', 'biz_maps_lat', 'biz_maps_lng', 'open_time', 'close_time', 'open_days', 'slot_step', 'arrive_before', 'cancel_min_hours', 'noshow_grace', 'max_advance_days', 'therapist_choose', 'point_value', 'manual_disc_max_pct', 'receipt_footer', 'howto'];
        $d = (array)($in['s'] ?? []);
        foreach ($allowed as $k) if (array_key_exists($k, $d)) S_set($k, trim((string)$d[$k]));
        foreach (['open_time', 'close_time'] as $k) if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', S($k) ?? '')) throw new UserError('Format jam harus JJ:MM.');
        if (S('close_time') <= S('open_time')) throw new UserError('Jam tutup harus setelah jam buka.');
        if (!in_array((int)S('slot_step'), [10, 15, 20, 30, 60], true)) throw new UserError('Langkah slot harus 10, 15, 20, 30, atau 60 menit.');
        audit('settings.save', 'settings', 0, implode(',', array_keys($d)));
        rev_bump();
        return [];
    }];
    $R['att.config'] = ['POST', 'settings', function ($in) {
        $lat = (float)($in['lat'] ?? 0); $lng = (float)($in['lng'] ?? 0);
        if (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0 && $lng == 0)) throw new UserError('Pilih titik lokasi kerja pada peta.');
        $radius = (int)($in['radius'] ?? 100); $acc = (int)($in['acc_max'] ?? 50);
        if ($radius < 20 || $radius > 1000) throw new UserError('Radius harus antara 20 dan 1000 meter.');
        S_set('att_lat', (string)$lat); S_set('att_lng', (string)$lng); S_set('att_radius', (string)$radius); S_set('att_acc_max', (string)max(10, $acc));
        $verify = !empty($in['verified']);
        S_set('att_enabled', $verify ? '1' : '0');
        S_set('att_verified_by', $verify ? (string)Auth::user()['name'] : ''); S_set('att_verified_at', $verify ? now() : '');
        audit('attendance.config', 'settings', 0, "lat=$lat lng=$lng r=$radius verified=" . ($verify ? 1 : 0));
        return [];
    }];

    // Layanan: langkah treatment & bahan.
    $R['svc.detail'] = ['GET', 'settings', function ($in, $q) {
        $id = (int)$q['id'];
        return ['steps' => DB::all('SELECT title, detail FROM service_steps WHERE service_id=? ORDER BY step_no', [$id]),
            'materials' => DB::all('SELECT m.product_id, m.qty FROM service_materials m WHERE m.service_id=?', [$id]),
            'products' => DB::all("SELECT id, name, unit FROM products WHERE kind='bahan' AND active=1 ORDER BY name"),
            'meta' => json_decode((string)DB::val('SELECT meta FROM services WHERE id=?', [$id]) ?: 'null', true)];
    }];
    $R['svc.detail_save'] = ['POST', 'settings', function ($in) {
        $id = (int)$in['id'];
        DB::tx(function () use ($id, $in) {
            DB::exec('DELETE FROM service_steps WHERE service_id=?', [$id]);
            foreach (array_values((array)($in['steps'] ?? [])) as $i => $s) if (trim((string)($s['title'] ?? '')) !== '') DB::insert('service_steps', ['service_id' => $id, 'step_no' => $i + 1, 'title' => trim($s['title']), 'detail' => trim((string)($s['detail'] ?? ''))]);
            DB::exec('DELETE FROM service_materials WHERE service_id=?', [$id]);
            foreach ((array)($in['materials'] ?? []) as $m) if ((int)($m['product_id'] ?? 0) > 0 && (float)($m['qty'] ?? 0) > 0) DB::tryInsert('service_materials', ['service_id' => $id, 'product_id' => (int)$m['product_id'], 'qty' => (float)$m['qty']]);
            if (isset($in['meta'])) DB::update('services', ['meta' => json_encode($in['meta'], JSON_UNESCAPED_UNICODE)], 'id=?', [$id]);
            audit('service.detail', 'service', $id, '');
        });
        return [];
    }];

    // Promo.
    $R['promo.list'] = ['GET', 'settings', function () {
        $ps = DB::all('SELECT * FROM promos ORDER BY id DESC');
        foreach ($ps as &$p) $p['items'] = DB::all('SELECT * FROM promo_items WHERE promo_id=? ORDER BY id', [$p['id']]);
        return ['promos' => $ps, 'services' => DB::all('SELECT id, name, price, duration_min FROM services WHERE active=1 ORDER BY sort, id')];
    }];
    $R['promo.save'] = ['POST', 'settings', function ($in) {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['starts'] ?? '')) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['ends'] ?? '')) || $in['ends'] < $in['starts']) throw new UserError('Nama dan periode promo (awal ≤ akhir) wajib diisi.');
        $row = ['name' => $name, 'starts' => $in['starts'], 'ends' => $in['ends'], 'note' => mb_substr((string)($in['note'] ?? ''), 0, 250)];
        if (!empty($in['id'])) { DB::update('promos', $row, 'id=?', [(int)$in['id']]); $id = (int)$in['id']; } else $id = DB::insert('promos', $row + ['status' => 'draft']);
        audit('promo.save', 'promo', $id, $name);
        rev_bump();
        return ['id' => $id];
    }];
    $R['promo.item_save'] = ['POST', 'settings', function ($in) {
        $pkg = !empty($in['is_package']) ? 1 : 0;
        $inc = $pkg ? implode(',', array_values(array_filter(array_map('intval', (array)($in['includes'] ?? []))))) : '';
        $sid = !$pkg && !empty($in['service_id']) ? (int)$in['service_id'] : null;
        if ($sid && !DB::val('SELECT 1 FROM services WHERE id=?', [$sid])) throw new UserError('Layanan tidak ditemukan.');
        $row = ['label' => trim((string)($in['label'] ?? '')), 'info' => mb_substr((string)($in['info'] ?? ''), 0, 250), 'promo_price' => max(0, (int)($in['promo_price'] ?? 0)), 'normal_price' => max(0, (int)($in['normal_price'] ?? 0)),
            'service_id' => $sid, 'includes' => $inc, 'duration_min' => max(0, (int)($in['duration_min'] ?? 0)), 'is_package' => $pkg];
        if ($row['label'] === '' || $row['promo_price'] <= 0) throw new UserError('Nama dan harga promo wajib diisi.');
        if (!empty($in['id'])) { DB::update('promo_items', $row, 'id=?', [(int)$in['id']]); $id = (int)$in['id']; } else $id = DB::insert('promo_items', $row + ['promo_id' => (int)$in['promo_id']]);
        audit('promo.item', 'promo_item', $id, $row['label']);
        rev_bump();
        return ['id' => $id];
    }];
    $R['promo.item_delete'] = ['POST', 'settings', function ($in) { DB::exec('DELETE FROM promo_items WHERE id=?', [(int)$in['id']]); rev_bump(); return []; }];
    $R['promo.status'] = ['POST', 'settings', function ($in) {
        $id = (int)$in['id'];
        $st = (string)($in['status'] ?? '');
        if (!in_array($st, ['draft', 'active', 'ended'], true)) throw new UserError('Status tidak valid.');
        if ($st === 'active') {
            $ready = 0;
            foreach (DB::all('SELECT * FROM promo_items WHERE promo_id=?', [$id]) as $it) {
                if ((int)$it['duration_min'] > 0 && ((int)$it['is_package'] ? $it['includes'] !== '' : $it['service_id'] !== null)) $ready++;
            }
            if ($ready === 0) throw new UserError('Belum ada item promo yang lengkap. Tetapkan layanan/isi paket dan durasi minimal untuk satu item, lalu aktifkan.');
        }
        DB::update('promos', ['status' => $st], 'id=?', [$id]);
        audit('promo.status', 'promo', $id, $st);
        rev_bump();
        return [];
    }];

    return $R;
}
