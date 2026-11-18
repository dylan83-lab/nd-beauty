<?php
declare(strict_types=1);

/** Sesi, login, CSRF, dan kewenangan per peran (diterapkan di server). */
final class Auth
{
    public const ROLES = ['admin' => 'Admin', 'manager' => 'Manager', 'kasir' => 'Kasir', 'terapis' => 'Terapis', 'pelanggan' => 'Pelanggan'];
    public const STAFF = ['admin', 'manager', 'kasir', 'terapis'];

    /** Matriks kewenangan: fitur => peran yang diizinkan. */
    public const PERMS = [
        'dashboard'        => ['admin', 'manager'],
        'booking_manage'   => ['admin', 'manager', 'kasir'],
        'queue'            => ['admin', 'manager', 'kasir'],
        'pos'              => ['admin', 'kasir'],
        'customers'        => ['admin', 'manager', 'kasir'],
        'member_manage'    => ['admin', 'manager'],
        'cash_session'     => ['admin', 'kasir'],
        'cash_mutation'    => ['admin', 'manager'],
        'cash_review'      => ['admin', 'manager'],
        'stock_view'       => ['admin', 'manager', 'kasir'],
        'stock_manage'     => ['admin', 'manager'],
        'schedule'         => ['admin', 'manager'],
        'attendance_self'  => ['admin', 'manager', 'kasir', 'terapis'],
        'attendance_all'   => ['admin', 'manager'],
        'corrections'      => ['admin', 'manager'],
        'refund_request'   => ['admin', 'manager', 'kasir'],
        'refund_approve'   => ['admin', 'manager'],
        'reports'          => ['admin', 'manager'],
        'settings'         => ['admin'],
        'therapist_work'   => ['terapis'],
    ];

    private static ?array $user = null;
    private static bool $loaded = false;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('ndsess');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }

    public static function user(): ?array
    {
        if (self::$loaded) return self::$user;
        self::$loaded = true;
        self::start();
        $id = (int)($_SESSION['uid'] ?? 0);
        if ($id > 0) {
            $u = DB::one('SELECT id,name,email,phone,role,position,active FROM users WHERE id=?', [$id]);
            self::$user = ($u && (int)$u['active'] === 1) ? $u : null;
            if (!self::$user) unset($_SESSION['uid']);
        }
        return self::$user;
    }

    public static function id(): ?int { return PHP_SAPI === 'cli' && isset($GLOBALS['__cli_uid']) ? (int)$GLOBALS['__cli_uid'] : (self::user()['id'] ?? null); }
    public static function role(): ?string { return PHP_SAPI === 'cli' && isset($GLOBALS['__cli_role']) ? $GLOBALS['__cli_role'] : (self::user()['role'] ?? null); }

    public static function can(string $perm): bool
    {
        $r = self::role();
        return $r !== null && in_array($r, self::PERMS[$perm] ?? [], true);
    }

    public static function isStaff(): bool { return in_array(self::role(), self::STAFF, true); }

    /** Untuk halaman: arahkan ke login atau tolak. */
    public static function needPage(string $perm): array
    {
        $u = self::user();
        if (!$u) { header('Location: ' . base_url('login?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''))); exit; }
        if (!self::can($perm)) { http_response_code(403); require APP_ROOT . '/src/views/403.php'; exit; }
        return $u;
    }

    /** Untuk API: 401/403 JSON. */
    public static function needApi(string $perm): array
    {
        $u = self::user();
        if (!$u) json_fail('Silakan masuk terlebih dahulu.', 401);
        if (!self::can($perm)) json_fail('Anda tidak memiliki akses untuk tindakan ini.', 403);
        return $u;
    }

    public static function login(string $ident, string $pass): array
    {
        $ident = trim($ident);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
        $since = date("Y-m-d H:i:s", now_ts() - 900);
        $fails = (int)DB::val('SELECT COUNT(*) FROM login_attempts WHERE ident=? AND at>=?', [mb_strtolower($ident), $since]);
        if ($fails >= 8) throw new UserError('Terlalu banyak percobaan. Coba lagi 15 menit lagi.');
        $ph = normalize_phone($ident);
        $u = DB::one('SELECT * FROM users WHERE (LOWER(email)=? OR phone=?) AND active=1', [mb_strtolower($ident), $ph ?? '-']);
        if (!$u || !password_verify($pass, $u['pass_hash'])) {
            DB::insert('login_attempts', ['ident' => mb_strtolower($ident), 'ip' => $ip, 'at' => now()]);
            throw new UserError('Email/nomor HP atau kata sandi salah.');
        }
        DB::exec('DELETE FROM login_attempts WHERE ident=?', [mb_strtolower($ident)]);
        self::start();
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        self::$loaded = false;
        self::$user = null;
        return self::user();
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
        self::$user = null;
        self::$loaded = true;
    }

    public static function csrf(): string
    {
        self::start();
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(): void
    {
        $t = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['_csrf'] ?? '');
        if (!is_string($t) || !hash_equals(self::csrf(), $t)) json_fail('Sesi kedaluwarsa. Muat ulang halaman.', 419);
    }

    public static function hash(string $pw): string { return password_hash($pw, PASSWORD_DEFAULT); }

    /** Customer record milik pengguna pelanggan yang sedang masuk. */
    public static function customerId(): ?int
    {
        $uid = self::id();
        if (!$uid) return null;
        $c = DB::val('SELECT id FROM customers WHERE user_id=?', [$uid]);
        return $c ? (int)$c : null;
    }
}
