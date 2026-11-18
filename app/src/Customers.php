<?php
declare(strict_types=1);

/** Data pelanggan, akun pelanggan, membership, dan poin. */
final class Customers
{
    public static function findOrCreate(string $name, ?string $phone, ?string $email = null): int
    {
        $name = trim($name);
        $phone = normalize_phone($phone);
        $email = $email !== null && trim($email) !== '' ? mb_strtolower(trim($email)) : null;
        if ($name === '') throw new UserError('Nama pelanggan wajib diisi.');
        if ($phone) {
            $id = DB::val('SELECT id FROM customers WHERE phone=?', [$phone]);
            if ($id) return (int)$id;
        }
        return DB::insert('customers', ['name' => $name, 'phone' => $phone, 'email' => $email, 'points' => 0, 'created_at' => now()]);
    }

    /** Pendaftaran akun pelanggan. Jika nomor HP sudah ada sebagai pelanggan datang langsung, akun ditautkan ke data lama. */
    public static function register(string $name, string $phone, ?string $email, string $password): int
    {
        $name = trim($name);
        $ph = normalize_phone($phone);
        $email = $email !== null && trim($email) !== '' ? mb_strtolower(trim($email)) : null;
        if ($name === '' || !$ph || strlen($ph) < 9) throw new UserError('Nama dan nomor HP yang valid wajib diisi.');
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new UserError('Format email tidak valid.');
        if (strlen($password) < 6) throw new UserError('Kata sandi minimal 6 karakter.');
        return DB::tx(function () use ($name, $ph, $email, $password) {
            if (DB::val('SELECT 1 FROM users WHERE phone=?', [$ph]) || ($email && DB::val('SELECT 1 FROM users WHERE LOWER(email)=?', [$email]))) throw new UserError('Nomor HP atau email sudah terdaftar. Silakan masuk.');
            $uid = DB::insert('users', ['name' => $name, 'email' => $email, 'phone' => $ph, 'pass_hash' => Auth::hash($password), 'role' => 'pelanggan', 'position' => '', 'active' => 1, 'created_at' => now()]);
            $c = DB::one('SELECT id, user_id FROM customers WHERE phone=?', [$ph]);
            if ($c && !$c['user_id']) {
                DB::update('customers', ['user_id' => $uid], 'id=?', [$c['id']]);
            } elseif (!$c) {
                DB::insert('customers', ['user_id' => $uid, 'name' => $name, 'phone' => $ph, 'email' => $email, 'points' => 0, 'created_at' => now()]);
            }
            return $uid;
        });
    }

    public static function memberInfo(array $c): array
    {
        $plan = null;
        $active = false;
        if (!empty($c['plan_id'])) {
            $plan = DB::one('SELECT * FROM membership_plans WHERE id=?', [(int)$c['plan_id']]);
            $active = $plan && !empty($c['member_until']) && $c['member_until'] >= today();
        }
        return ['active' => $active, 'plan' => $plan, 'until' => $c['member_until'] ?? null, 'points' => (int)($c['points'] ?? 0)];
    }

    public static function get(int $id): ?array
    {
        $c = DB::one('SELECT * FROM customers WHERE id=?', [$id]);
        if (!$c) return null;
        $c['member'] = self::memberInfo($c);
        return $c;
    }

    public static function search(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') return DB::all('SELECT id, name, phone, plan_id, member_until, points FROM customers ORDER BY id DESC LIMIT ' . (int)$limit);
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        $ph = normalize_phone($q);
        return DB::all("SELECT id, name, phone, plan_id, member_until, points FROM customers WHERE name LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!' OR phone=? ORDER BY name LIMIT " . (int)$limit, [$like, $like, $ph ?? '-']);
    }

    public static function activate(int $cid, int $planId): void
    {
        $plan = DB::one('SELECT * FROM membership_plans WHERE id=? AND active=1', [$planId]);
        if (!$plan) throw new UserError('Paket membership tidak ditemukan.');
        $c = DB::one('SELECT * FROM customers WHERE id=?', [$cid]);
        $base = (!empty($c['member_until']) && $c['member_until'] >= today() && (int)$c['plan_id'] === $planId) ? $c['member_until'] : today();
        DB::update('customers', ['plan_id' => $planId, 'member_until' => date('Y-m-d', strtotime($base . ' +' . (int)$plan['duration_days'] . ' days'))], 'id=?', [$cid]);
        audit('member.activate', 'customer', $cid, $plan['name']);
    }

    public static function syncPoints(int $cid): int
    {
        $p = (int)DB::val('SELECT COALESCE(SUM(points),0) FROM loyalty_moves WHERE customer_id=?', [$cid]);
        DB::update('customers', ['points' => $p], 'id=?', [$cid]);
        return $p;
    }

    public static function loyalty(int $cid, int $points, string $note, string $uniq): void
    {
        if ($points === 0) return;
        if (DB::tryInsert('loyalty_moves', ['customer_id' => $cid, 'points' => $points, 'note' => $note, 'uniq_key' => $uniq, 'created_at' => now()])) self::syncPoints($cid);
    }

    public static function history(int $cid): array
    {
        return [
            'bookings' => Booking::forCustomer($cid),
            'sales' => DB::all("SELECT id, receipt_no, total, method, paid_at, status FROM sales WHERE customer_id=? AND paid_at IS NOT NULL ORDER BY id DESC LIMIT 50", [$cid]),
            'points' => DB::all('SELECT points, note, created_at FROM loyalty_moves WHERE customer_id=? ORDER BY id DESC LIMIT 50', [$cid]),
        ];
    }
}
