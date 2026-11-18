<?php
declare(strict_types=1);

/** Mutasi keuangan, saldo akun, dan buka/tutup kas. Penjualan lunas = tepat satu mutasi masuk; transfer antarakun bukan omzet. */
final class Cash
{
    public const CATS = [
        'saldo_awal' => 'Saldo awal', 'penjualan' => 'Penjualan', 'setoran_modal' => 'Setoran modal', 'pendapatan_lain' => 'Pendapatan lain',
        'pembelian_stok' => 'Pembelian stok', 'operasional' => 'Biaya operasional', 'gaji' => 'Gaji/komisi', 'refund' => 'Refund', 'prive' => 'Penarikan pemilik',
        'transfer' => 'Transfer antarakun', 'selisih_kas' => 'Selisih kas', 'lainnya' => 'Lainnya',
    ];

    public static function accounts(): array
    {
        $rows = DB::all('SELECT * FROM accounts ORDER BY id');
        foreach ($rows as &$a) $a['balance'] = self::balance((int)$a['id']);
        return $rows;
    }

    public static function balance(int $accountId): int
    {
        return (int)DB::val("SELECT COALESCE(SUM(CASE WHEN kind='in' THEN amount ELSE -amount END),0) FROM cash_movements WHERE account_id=?", [$accountId]);
    }

    public static function accountFor(string $method, ?int $accountId = null): int
    {
        if ($accountId) {
            $a = DB::one('SELECT id, type FROM accounts WHERE id=? AND active=1', [$accountId]);
            if ($a) return (int)$a['id'];
        }
        $type = ['cash' => 'cash', 'qris' => 'qris', 'transfer' => 'bank'][$method] ?? 'cash';
        $id = DB::val('SELECT id FROM accounts WHERE type=? AND active=1 ORDER BY id LIMIT 1', [$type]);
        if (!$id) throw new UserError('Akun kas untuk metode ini belum dibuat. Hubungi admin.');
        return (int)$id;
    }

    /** Tambah mutasi. Mengembalikan id, atau 0 bila uniq_key sudah pernah dicatat (idempoten). */
    public static function add(array $m): int
    {
        $amount = (int)($m['amount'] ?? 0);
        if ($amount <= 0) throw new UserError('Nominal harus lebih dari 0.');
        if (!in_array($m['kind'] ?? '', ['in', 'out'], true)) throw new UserError('Jenis mutasi tidak valid.');
        if (!DB::val('SELECT 1 FROM accounts WHERE id=?', [(int)$m['account_id']])) throw new UserError('Akun tidak ditemukan.');
        return DB::tryInsert('cash_movements', [
            'mv_date' => $m['mv_date'] ?? today(), 'account_id' => (int)$m['account_id'], 'kind' => $m['kind'], 'category' => $m['category'] ?? 'lainnya',
            'amount' => $amount, 'note' => mb_substr((string)($m['note'] ?? ''), 0, 250), 'ref_type' => $m['ref_type'] ?? '', 'ref_id' => (int)($m['ref_id'] ?? 0),
            'group_id' => $m['group_id'] ?? null, 'cash_session_id' => $m['cash_session_id'] ?? null, 'user_id' => Auth::id(), 'uniq_key' => $m['uniq_key'] ?? null, 'created_at' => now(),
        ]);
    }

    public static function transfer(int $from, int $to, int $amount, string $note, string $date): void
    {
        if ($from === $to) throw new UserError('Akun asal dan tujuan harus berbeda.');
        if (self::balance($from) < $amount) throw new UserError('Saldo akun asal tidak mencukupi.');
        DB::tx(function () use ($from, $to, $amount, $note, $date) {
            $g = 'T' . bin2hex(random_bytes(6));
            self::add(['account_id' => $from, 'kind' => 'out', 'category' => 'transfer', 'amount' => $amount, 'note' => $note, 'mv_date' => $date, 'group_id' => $g]);
            self::add(['account_id' => $to, 'kind' => 'in', 'category' => 'transfer', 'amount' => $amount, 'note' => $note, 'mv_date' => $date, 'group_id' => $g]);
            audit('cash.transfer', 'account', $from, "to=$to amount=$amount");
        });
    }

    public static function movements(string $from, string $to, ?int $accountId = null): array
    {
        $sql = 'SELECT m.*, a.name AS account_name, u.name AS user_name FROM cash_movements m JOIN accounts a ON a.id=m.account_id LEFT JOIN users u ON u.id=m.user_id WHERE m.mv_date BETWEEN ? AND ?';
        $p = [$from, $to];
        if ($accountId) { $sql .= ' AND m.account_id=?'; $p[] = $accountId; }
        return DB::all($sql . ' ORDER BY m.mv_date DESC, m.id DESC LIMIT 500', $p);
    }

    // ---------- Sesi kas kasir ----------
    public static function current(int $userId): ?array
    {
        return DB::one("SELECT * FROM cash_sessions WHERE user_id=? AND status='open' ORDER BY id DESC LIMIT 1", [$userId]);
    }

    public static function open(int $userId, int $openingCash): array
    {
        if ($openingCash < 0) throw new UserError('Kas awal tidak valid.');
        return DB::tx(function () use ($userId, $openingCash) {
            if (self::current($userId)) throw new UserError('Kas Anda sudah terbuka.');
            $id = DB::insert('cash_sessions', ['user_id' => $userId, 'opened_at' => now(), 'opening_cash' => $openingCash, 'status' => 'open']);
            audit('cash.open', 'cash_session', $id, 'kas awal ' . $openingCash);
            return DB::one('SELECT * FROM cash_sessions WHERE id=?', [$id]);
        });
    }

    public static function summary(int $sid): array
    {
        $s = DB::one('SELECT * FROM cash_sessions WHERE id=?', [$sid]);
        if (!$s) throw new UserError('Sesi kas tidak ditemukan.');
        $mv = DB::one("SELECT COALESCE(SUM(CASE WHEN m.kind='in' THEN m.amount ELSE 0 END),0) AS cin, COALESCE(SUM(CASE WHEN m.kind='out' THEN m.amount ELSE 0 END),0) AS cout
            FROM cash_movements m JOIN accounts a ON a.id=m.account_id WHERE m.cash_session_id=? AND a.type='cash' AND m.category<>'selisih_kas'", [$sid]);
        $by = ['cash' => 0, 'transfer' => 0, 'qris' => 0];
        foreach (DB::all("SELECT method, SUM(total) AS t, COUNT(*) AS n FROM sales WHERE cash_session_id=? AND paid_at IS NOT NULL GROUP BY method", [$sid]) as $r) $by[$r['method']] = (int)$r['t'];
        $cnt = (int)DB::val('SELECT COUNT(*) FROM sales WHERE cash_session_id=? AND paid_at IS NOT NULL', [$sid]);
        $refund = (int)DB::val("SELECT COALESCE(SUM(amount),0) FROM cash_movements WHERE cash_session_id=? AND category='refund'", [$sid]);
        $expected = (int)$s['opening_cash'] + (int)$mv['cin'] - (int)$mv['cout'];
        return $s + ['cash_in' => (int)$mv['cin'], 'cash_out' => (int)$mv['cout'], 'expected_calc' => $expected, 'by_method' => $by, 'sales_count' => $cnt, 'refund_total' => $refund];
    }

    public static function close(int $sid, int $userId, int $counted, string $note): array
    {
        return DB::tx(function () use ($sid, $userId, $counted, $note) {
            $s = DB::one('SELECT * FROM cash_sessions WHERE id=?' . DB::forUpdate(), [$sid]);
            if (!$s || (int)$s['user_id'] !== $userId) throw new UserError('Sesi kas tidak ditemukan.');
            if ($s['status'] !== 'open') throw new UserError('Kas sudah ditutup.');
            if ($counted < 0) throw new UserError('Uang fisik tidak valid.');
            $sum = self::summary($sid);
            $diff = $counted - $sum['expected_calc'];
            if ($diff !== 0 && trim($note) === '') throw new UserError('Ada selisih ' . rp(abs($diff)) . '. Isi keterangan selisih.');
            DB::update('cash_sessions', ['closed_at' => now(), 'expected_cash' => $sum['expected_calc'], 'counted_cash' => $counted, 'diff' => $diff, 'diff_note' => mb_substr($note, 0, 250), 'status' => 'closed'], 'id=?', [$sid]);
            if ($diff !== 0) {
                self::add(['account_id' => self::accountFor('cash'), 'kind' => $diff > 0 ? 'in' : 'out', 'category' => 'selisih_kas', 'amount' => abs($diff), 'note' => 'Selisih tutup kas #' . $sid . ': ' . $note, 'cash_session_id' => $sid, 'uniq_key' => 'cs:close:' . $sid]);
            }
            audit('cash.close', 'cash_session', $sid, "expected={$sum['expected_calc']} counted=$counted diff=$diff");
            rev_bump();
            return self::summary($sid);
        });
    }

    public static function review(int $sid, int $userId, string $note): void
    {
        DB::update('cash_sessions', ['reviewed_by' => $userId, 'review_note' => mb_substr($note, 0, 250)], 'id=?', [$sid]);
        audit('cash.review', 'cash_session', $sid, $note);
    }

    public static function sessions(string $from, string $to): array
    {
        return DB::all("SELECT cs.*, u.name AS user_name, r.name AS reviewer FROM cash_sessions cs JOIN users u ON u.id=cs.user_id LEFT JOIN users r ON r.id=cs.reviewed_by
            WHERE SUBSTR(cs.opened_at,1,10) BETWEEN ? AND ? ORDER BY cs.id DESC", [$from, $to]);
    }
}
