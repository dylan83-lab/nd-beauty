<?php
declare(strict_types=1);

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function base_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $cfg = function_exists('app_config') ? app_config() : null;
        if (!empty($cfg['base_path'])) {
            $base = rtrim($cfg['base_path'], '/');
        } else {
            $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/app/index.php'));
            $base = rtrim($dir === '/' || $dir === '.' ? '' : $dir, '/');
        }
    }
    return $base . '/' . ltrim($path, '/');
}

/** Jam sistem (WIB). Dapat disimulasikan untuk pengujian lewat $GLOBALS['__now']. */
function now_ts(): int { return isset($GLOBALS['__now']) ? strtotime($GLOBALS['__now']) : \time(); }
function now(): string { return date('Y-m-d H:i:s', now_ts()); }
function today(): string { return date('Y-m-d', now_ts()); }

function rp($n): string { return 'Rp' . number_format((int)$n, 0, ',', '.'); }

function hm(?string $dt): string { return $dt ? substr($dt, 11, 5) : ''; }

function add_min(string $dt, int $min): string { return date('Y-m-d H:i:s', strtotime($dt) + $min * 60); }

function diff_min(string $a, string $b): int { return (int)round((strtotime($b) - strtotime($a)) / 60); }

const HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
const BULAN = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

function tgl_id(string $d, bool $withDay = true): string
{
    $t = strtotime($d);
    return ($withDay ? HARI[(int)date('w', $t)] . ', ' : '') . (int)date('j', $t) . ' ' . BULAN[(int)date('n', $t)] . ' ' . date('Y', $t);
}

function normalize_phone(?string $p): ?string
{
    if ($p === null) return null;
    $d = preg_replace('/\D+/', '', $p);
    if ($d === '') return null;
    if (str_starts_with($d, '62')) $d = '0' . substr($d, 2);
    if (!str_starts_with($d, '0')) $d = '0' . $d;
    return $d;
}

function wa_link(string $phone, string $text = ''): string
{
    $p = preg_replace('/\D+/', '', $phone);
    if (str_starts_with($p, '0')) $p = '62' . substr($p, 1);
    return 'https://wa.me/' . $p . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_fail(string $msg, int $code = 422, array $extra = []): never
{
    json_out(array_merge(['ok' => false, 'error' => $msg], $extra), $code);
}

function body(): array
{
    static $b = null;
    if ($b === null) {
        $raw = file_get_contents('php://input');
        $j = $raw !== '' && $raw !== false ? json_decode($raw, true) : null;
        $b = is_array($j) ? $j : $_POST;
    }
    return $b;
}

function S(string $key, ?string $default = null): ?string
{
    if (!isset($GLOBALS['__S'])) {
        $GLOBALS['__S'] = [];
        foreach (DB::all('SELECT k, v FROM settings') as $r) $GLOBALS['__S'][$r['k']] = $r['v'];
    }
    return $GLOBALS['__S'][$key] ?? $default;
}

function S_set(string $key, string $val): void
{
    unset($GLOBALS['__S']);
    $n = DB::exec('UPDATE settings SET v=? WHERE k=?', [$val, $key]);
    if ($n === 0 && !DB::val('SELECT 1 FROM settings WHERE k=?', [$key])) DB::insert('settings', ['k' => $key, 'v' => $val]);
}

function audit(string $action, string $entity = '', int $id = 0, string $detail = ''): void
{
    DB::insert('audit_log', [
        'user_id' => Auth::id(), 'action' => $action, 'entity' => $entity, 'entity_id' => $id,
        'detail' => mb_substr($detail, 0, 500), 'created_at' => now(),
    ]);
}

/** Naikkan penanda perubahan agar layar kasir/terapis menyegarkan data otomatis. */
function rev_bump(): void
{
    if (DB::exec('UPDATE revs SET n=n+1 WHERE id=1') === 0) DB::tryInsert('revs', ['id' => 1, 'n' => 1]);
}

function rev_get(): int { return (int)(DB::val('SELECT n FROM revs WHERE id=1') ?? 0); }

class UserError extends RuntimeException {}

/** Ikon SVG sederhana (garis). */
function icon(string $n, int $s = 20): string
{
    static $p = [
        'home' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'sparkle' => '<path d="M12 3l2 6 6 2-6 2-2 6-2-6-6-2 6-2z"/>',
        'cal' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5 7-5s7 1.500 7 5"/><path d="M16 4.500a3.500 3.500 0 010 7M18 15c2.500.6 4 2 4 5"/>',
        'cart' => '<circle cx="9" cy="20" r="1.500"/><circle cx="18" cy="20" r="1.500"/><path d="M2 3h3l2.500 12h11L21 7H6"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M16 13h3M3 10h18M6 6l10-3v3"/>',
        'box' => '<path d="M3 7l9-4 9 4v10l-9 4-9-4z"/><path d="M3 7l9 4 9-4M12 11v10"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'cog' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.900 4.900l2.100 2.100M17 17l2.100 2.100M4.900 19.100L7 17M17 7l2.100-2.100"/>',
        'pin' => '<path d="M12 21s7-6 7-12a7 7 0 10-14 0c0 6 7 12 7 12z"/><circle cx="12" cy="9" r="2.500"/>',
        'check' => '<path d="M4 12l5 5L20 6"/>',
        'x' => '<path d="M5 5l14 14M19 5L5 19"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'logout' => '<path d="M9 4H5v16h4M16 8l4 4-4 4M20 12H9"/>',
        'phone' => '<path d="M5 3h4l2 5-2.500 1.500a11 11 0 006 6L16 13l5 2v4a2 2 0 01-2 2A16 16 0 013 5a2 2 0 012-2z"/>',
        'bell' => '<path d="M6 16V11a6 6 0 1112 0v5l2 2H4zM10 21h4"/>',
        'moon' => '<path d="M20 14A8 8 0 0110 4a8 8 0 1010 10z"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'scale' => '<path d="M12 3v18M5 7h14M5 7l-3 7a3.500 3.500 0 006 0zM19 7l-3 7a3.500 3.500 0 006 0z"/>',
        'doc' => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13h7M9 17h7"/>',
        'gift' => '<rect x="3" y="8" width="18" height="4"/><path d="M5 12v9h14v-9M12 8v13M12 8c-3 0-5-1-4-3s4 0 4 3zM12 8c3 0 5-1 4-3s-4 0-4 3z"/>',
        'refresh' => '<path d="M20 11a8 8 0 00-14-4M4 4v4h4M4 13a8 8 0 0014 4M20 20v-4h-4"/>',
    ];
    return '<svg class="ic" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}
