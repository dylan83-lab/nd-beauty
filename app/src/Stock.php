<?php
declare(strict_types=1);

/** Stok: produk jual dan bahan treatment. Saldo = jumlah kartu stok (stock_moves). Setiap pergerakan otomatis memakai kunci unik agar tidak ganda. */
final class Stock
{
    public const TYPES = ['opening' => 'Stok awal', 'purchase' => 'Pembelian', 'sale' => 'Penjualan', 'usage' => 'Pemakaian', 'adjust' => 'Penyesuaian', 'return' => 'Retur/Refund'];

    public static function onHand(int $pid): float
    {
        return (float)(DB::val('SELECT COALESCE(SUM(qty),0) FROM stock_moves WHERE product_id=?', [$pid]) ?? 0);
    }

    /** Catat pergerakan. Mengembalikan false bila kunci unik sudah ada (permintaan ulang). */
    public static function move(int $pid, string $type, float $qty, string $note = '', string $refType = '', int $refId = 0, ?string $uniq = null, int $unitCost = 0): bool
    {
        $id = DB::tryInsert('stock_moves', [
            'product_id' => $pid, 'move_type' => $type, 'qty' => $qty, 'unit_cost' => $unitCost, 'note' => mb_substr($note, 0, 160),
            'ref_type' => $refType, 'ref_id' => $refId, 'uniq_key' => $uniq, 'user_id' => Auth::id(), 'created_at' => now(),
        ]);
        return $id > 0;
    }

    public static function list(): array
    {
        $rows = DB::all('SELECT p.*, COALESCE((SELECT SUM(qty) FROM stock_moves m WHERE m.product_id=p.id),0) AS on_hand FROM products p ORDER BY p.kind, p.name');
        foreach ($rows as &$r) {
            $r['on_hand'] = (float)$r['on_hand'];
            $r['low'] = (int)$r['active'] === 1 && $r['on_hand'] <= (float)$r['min_stock'];
        }
        return $rows;
    }

    public static function low(): array { return array_values(array_filter(self::list(), fn($r) => $r['low'])); }

    public static function card(int $pid, int $limit = 100): array
    {
        return DB::all('SELECT m.*, u.name AS user_name FROM stock_moves m LEFT JOIN users u ON u.id=m.user_id WHERE m.product_id=? ORDER BY m.id DESC LIMIT ' . (int)$limit, [$pid]);
    }

    public static function purchase(int $pid, float $qty, int $unitCost, ?int $accountId, string $note): void
    {
        if ($qty <= 0) throw new UserError('Jumlah pembelian harus lebih dari 0.');
        DB::tx(function () use ($pid, $qty, $unitCost, $accountId, $note) {
            self::move($pid, 'purchase', $qty, $note ?: 'Pembelian stok', 'purchase', 0, null, $unitCost);
            if ($accountId && $unitCost > 0) {
                $name = (string)DB::val('SELECT name FROM products WHERE id=?', [$pid]);
                Cash::add(['account_id' => $accountId, 'kind' => 'out', 'category' => 'pembelian_stok', 'amount' => (int)round($qty * $unitCost), 'note' => 'Beli ' . $name . ($note ? ' — ' . $note : '')]);
            }
            audit('stock.purchase', 'product', $pid, "qty=$qty cost=$unitCost");
        });
    }

    public static function adjust(int $pid, float $newOnHand, string $reason): void
    {
        if (trim($reason) === '') throw new UserError('Alasan penyesuaian wajib diisi.');
        DB::tx(function () use ($pid, $newOnHand, $reason) {
            $delta = $newOnHand - self::onHand($pid);
            if (abs($delta) < 0.0001) return;
            self::move($pid, 'adjust', $delta, $reason, 'adjust');
            audit('stock.adjust', 'product', $pid, "to=$newOnHand $reason");
        });
    }

    /** Bahan default per layanan pada reservasi, dijumlahkan per bahan. */
    public static function defaultMaterials(int $bookingId): array
    {
        $svc = [];
        foreach (DB::all('SELECT svc_ids FROM booking_items WHERE booking_id=?', [$bookingId]) as $it) $svc = array_merge($svc, array_filter(array_map('intval', explode(',', $it['svc_ids']))));
        $out = [];
        foreach ($svc as $sid) {
            foreach (DB::all('SELECT m.product_id, m.qty, p.name, p.unit FROM service_materials m JOIN products p ON p.id=m.product_id WHERE m.service_id=?', [$sid]) as $m) {
                $k = (int)$m['product_id'];
                $out[$k] = ['product_id' => $k, 'name' => $m['name'], 'unit' => $m['unit'], 'qty' => ($out[$k]['qty'] ?? 0) + (float)$m['qty']];
            }
        }
        return array_values($out);
    }

    /** Dipanggil saat terapis menyelesaikan perawatan; pemakaian bahan dicatat tepat satu kali per bahan. */
    public static function useForBooking(int $bookingId, int $userId, ?array $materials): void
    {
        $list = $materials ?? self::defaultMaterials($bookingId);
        foreach ($list as $m) {
            $pid = (int)($m['product_id'] ?? 0);
            $qty = (float)($m['qty'] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;
            if (!DB::val('SELECT 1 FROM products WHERE id=?', [$pid])) continue;
            self::move($pid, 'usage', -$qty, 'Pemakaian reservasi #' . $bookingId, 'booking', $bookingId, 'use:' . $bookingId . ':' . $pid);
        }
    }
}
