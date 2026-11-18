<?php
declare(strict_types=1);

/** Dashboard harian dan laporan (HTML / Excel / PDF). Angka diambil langsung dari transaksi aktual. */
final class Reports
{
    public const TYPES = [
        'penjualan' => 'Penjualan Layanan & Produk', 'metode' => 'Metode Pembayaran', 'kas' => 'Mutasi & Saldo Akun',
        'reservasi' => 'Reservasi & Pembatalan', 'stok' => 'Stok', 'member' => 'Membership', 'absensi' => 'Absensi Pegawai',
    ];

    public static function dashboard(): array
    {
        $d = today();
        $t0 = $d . ' 00:00:00'; $t1 = $d . ' 23:59:59';
        $gross = (int)DB::val('SELECT COALESCE(SUM(total),0) FROM sales WHERE paid_at BETWEEN ? AND ?', [$t0, $t1]);
        $ref = (int)DB::val('SELECT COALESCE(SUM(amount),0) FROM refunds WHERE created_at BETWEEN ? AND ?', [$t0, $t1]);
        $cnt = (int)DB::val('SELECT COUNT(*) FROM sales WHERE paid_at BETWEEN ? AND ?', [$t0, $t1]);
        $in = (int)DB::val("SELECT COALESCE(SUM(amount),0) FROM cash_movements WHERE mv_date=? AND kind='in' AND category NOT IN ('transfer','saldo_awal')", [$d]);
        $out = (int)DB::val("SELECT COALESCE(SUM(amount),0) FROM cash_movements WHERE mv_date=? AND kind='out' AND category<>'transfer'", [$d]);
        $bk = DB::all("SELECT status, COUNT(*) AS n FROM bookings WHERE book_date=? GROUP BY status", [$d]);
        $byStatus = [];
        foreach ($bk as $r) $byStatus[$r['status']] = (int)$r['n'];
        $serving = DB::all("SELECT b.id, b.code, b.start_at, b.end_at, c.name AS customer_name, t.name AS therapist_name FROM bookings b JOIN customers c ON c.id=b.customer_id LEFT JOIN users t ON t.id=b.therapist_id WHERE b.status='inservice' AND b.book_date=? ORDER BY b.started_at", [$d]);
        $busyIds = array_map(fn($r) => (int)DB::val('SELECT therapist_id FROM bookings WHERE id=?', [$r['id']]), $serving);
        $avail = [];
        $nowT = substr(now(), 11, 5);
        foreach (DB::all("SELECT id, name FROM users WHERE role='terapis' AND active=1 ORDER BY id") as $t) {
            $sh = Slots::shiftFor((int)$t['id'], $d);
            if (!$sh || $nowT < $sh['start_time'] || $nowT >= $sh['end_time']) continue;
            if (in_array((int)$t['id'], $busyIds, true)) continue;
            $avail[] = $t['name'];
        }
        $up = DB::all("SELECT b.id, b.code, b.start_at, b.status, c.name AS customer_name, t.name AS therapist_name FROM bookings b JOIN customers c ON c.id=b.customer_id LEFT JOIN users t ON t.id=b.therapist_id
            WHERE b.book_date=? AND b.status IN ('booked','checkin') ORDER BY b.start_at LIMIT 8", [$d]);
        $recent = DB::all("SELECT s.id, s.receipt_no, s.total, s.method, s.paid_at, c.name AS customer_name FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.paid_at IS NOT NULL ORDER BY s.paid_at DESC, s.id DESC LIMIT 6");
        $byMethod = ['cash' => 0, 'transfer' => 0, 'qris' => 0];
        foreach (DB::all('SELECT method, SUM(total) AS t FROM sales WHERE paid_at BETWEEN ? AND ? GROUP BY method', [$t0, $t1]) as $r) if ($r['method']) $byMethod[$r['method']] = (int)$r['t'];
        $week = [];
        for ($i = 6; $i >= 0; $i--) {
            $dd = date('Y-m-d', strtotime($d . " -$i days"));
            $week[] = ['d' => $dd, 'v' => (int)DB::val('SELECT COALESCE(SUM(total),0) FROM sales WHERE paid_at BETWEEN ? AND ?', [$dd . ' 00:00:00', $dd . ' 23:59:59'])];
        }
        $sessions = DB::all("SELECT cs.id, cs.diff, u.name AS user_name FROM cash_sessions cs JOIN users u ON u.id=cs.user_id WHERE cs.status='closed' AND cs.diff<>0 AND cs.reviewed_by IS NULL ORDER BY cs.id DESC LIMIT 5");
        return [
            'sales_net' => $gross - $ref, 'sales_gross' => $gross, 'refund' => $ref, 'paid_count' => $cnt, 'cash_in' => $in, 'cash_out' => $out,
            'bookings_today' => array_sum($byStatus) - ($byStatus['cancelled'] ?? 0) - ($byStatus['noshow'] ?? 0), 'by_status' => $byStatus,
            'serving' => $serving, 'therapists_available' => $avail, 'upcoming' => $up, 'recent' => $recent, 'low_stock' => Stock::low(),
            'by_method' => $byMethod, 'week' => $week, 'cash_diffs' => $sessions,
            'pending_corrections' => (int)DB::val("SELECT COUNT(*) FROM att_corrections WHERE status='pending'"),
            'pending_refunds' => (int)DB::val("SELECT COUNT(*) FROM refund_requests WHERE status='pending'"),
            'accounts' => Cash::accounts(), 'rev' => rev_get(),
        ];
    }

    public static function build(string $type, string $from, string $to): array
    {
        if (!isset(self::TYPES[$type])) throw new UserError('Jenis laporan tidak dikenal.');
        $f = ['penjualan' => 'sales', 'metode' => 'methods', 'kas' => 'cash', 'reservasi' => 'bookings', 'stok' => 'stock', 'member' => 'members', 'absensi' => 'attendance'][$type];
        $r = self::$f($from, $to);
        return $r + ['type' => $type, 'title' => self::TYPES[$type], 'from' => $from, 'to' => $to];
    }

    private static function sales(string $from, string $to): array
    {
        $rows = DB::all("SELECT s.paid_at, s.receipt_no, c.name AS cust, i.item_type, i.name, i.qty, i.price*i.qty AS gross, i.disc, i.net, i.refunded_amt
            FROM sale_items i JOIN sales s ON s.id=i.sale_id LEFT JOIN customers c ON c.id=s.customer_id
            WHERE s.paid_at BETWEEN ? AND ? ORDER BY s.paid_at, i.id", [$from . ' 00:00:00', $to . ' 23:59:59']);
        $out = []; $g = $d = $n = $r = 0; $svc = $prd = 0;
        foreach ($rows as $x) {
            $out[] = [substr($x['paid_at'], 0, 16), $x['receipt_no'], $x['cust'] ?? 'Umum', ['service' => 'Layanan', 'product' => 'Produk', 'member' => 'Membership'][$x['item_type']] ?? $x['item_type'], $x['name'], (int)$x['qty'], (int)$x['gross'], (int)$x['disc'], (int)$x['net'], (int)$x['refunded_amt']];
            $g += (int)$x['gross']; $d += (int)$x['disc']; $n += (int)$x['net']; $r += (int)$x['refunded_amt'];
            if ($x['item_type'] === 'product') $prd += (int)$x['net'] - (int)$x['refunded_amt']; else $svc += (int)$x['net'] - (int)$x['refunded_amt'];
        }
        return ['cols' => ['Waktu', 'No. Struk', 'Pelanggan', 'Jenis', 'Item', 'Qty', 'Bruto', 'Potongan', 'Neto', 'Refund'], 'money' => [6, 7, 8, 9],
            'rows' => $out, 'total' => ['TOTAL', '', '', '', '', '', $g, $d, $n, $r],
            'summary' => ['Penjualan neto (setelah refund)' => rp($n - $r), 'Layanan' => rp($svc), 'Produk' => rp($prd), 'Total potongan' => rp($d), 'Total refund' => rp($r)]];
    }

    private static function methods(string $from, string $to): array
    {
        $rows = DB::all("SELECT method, COUNT(*) AS n, SUM(total) AS t FROM sales WHERE paid_at BETWEEN ? AND ? GROUP BY method", [$from . ' 00:00:00', $to . ' 23:59:59']);
        $out = []; $tn = $tt = 0;
        foreach ($rows as $x) { $out[] = [Pos::METHODS[$x['method']] ?? (string)$x['method'], (int)$x['n'], (int)$x['t']]; $tn += (int)$x['n']; $tt += (int)$x['t']; }
        $daily = DB::all("SELECT SUBSTR(paid_at,1,10) AS d, method, SUM(total) AS t FROM sales WHERE paid_at BETWEEN ? AND ? GROUP BY SUBSTR(paid_at,1,10), method ORDER BY d", [$from . ' 00:00:00', $to . ' 23:59:59']);
        return ['cols' => ['Metode', 'Jumlah Transaksi', 'Total'], 'money' => [2], 'rows' => $out, 'total' => ['TOTAL', $tn, $tt],
            'summary' => ['Total diterima' => rp($tt), 'Transaksi' => (string)$tn], 'daily' => $daily];
    }

    private static function cash(string $from, string $to): array
    {
        $rows = DB::all("SELECT m.mv_date, a.name AS acc, m.kind, m.category, m.amount, m.note, u.name AS usr FROM cash_movements m JOIN accounts a ON a.id=m.account_id LEFT JOIN users u ON u.id=m.user_id
            WHERE m.mv_date BETWEEN ? AND ? ORDER BY m.mv_date, m.id", [$from, $to]);
        $out = []; $in = $outT = $sales = $modal = 0;
        foreach ($rows as $x) {
            $in_ = $x['kind'] === 'in';
            $out[] = [$x['mv_date'], $x['acc'], Cash::CATS[$x['category']] ?? $x['category'], $x['note'], $in_ ? (int)$x['amount'] : 0, $in_ ? 0 : (int)$x['amount'], $x['usr'] ?? ''];
            if ($x['category'] === 'transfer') continue;
            if ($in_) { $in += (int)$x['amount']; if ($x['category'] === 'penjualan') $sales += (int)$x['amount']; if ($x['category'] === 'setoran_modal') $modal += (int)$x['amount']; }
            else $outT += (int)$x['amount'];
        }
        $sum = ['Penjualan (omzet) diterima' => rp($sales), 'Setoran modal' => rp($modal), 'Total uang masuk (tanpa transfer)' => rp($in), 'Total uang keluar (tanpa transfer)' => rp($outT), 'Transfer antarakun' => 'Tidak dihitung sebagai omzet/uang masuk'];
        foreach (Cash::accounts() as $a) $sum['Saldo ' . $a['name'] . ' (sekarang)'] = rp($a['balance']);
        return ['cols' => ['Tanggal', 'Akun', 'Kategori', 'Keterangan', 'Masuk', 'Keluar', 'Petugas'], 'money' => [4, 5], 'rows' => $out, 'total' => ['TOTAL', '', '', '', $in, $outT, ''], 'summary' => $sum];
    }

    private static function bookings(string $from, string $to): array
    {
        $rows = Booking::attachItems(DB::all('SELECT b.*, c.name AS customer_name, t.name AS therapist_name FROM bookings b JOIN customers c ON c.id=b.customer_id LEFT JOIN users t ON t.id=b.therapist_id WHERE b.book_date BETWEEN ? AND ? ORDER BY b.start_at', [$from, $to]));
        $out = []; $cnt = [];
        foreach ($rows as $b) {
            $out[] = [$b['book_date'], $b['start_hm'] . '–' . $b['end_hm'], $b['code'], $b['customer_name'], $b['services'], $b['therapist_name'] ?? '-', $b['source'], $b['status_label'], $b['pay_label'], (int)$b['total'], $b['cancel_reason']];
            $cnt[$b['status_label']] = ($cnt[$b['status_label']] ?? 0) + 1;
        }
        $sum = ['Total reservasi' => (string)count($rows)];
        foreach ($cnt as $k => $v) $sum[$k] = (string)$v;
        return ['cols' => ['Tanggal', 'Jam', 'Kode', 'Pelanggan', 'Layanan', 'Terapis', 'Sumber', 'Status', 'Pembayaran', 'Total', 'Keterangan'], 'money' => [9], 'rows' => $out, 'summary' => $sum];
    }

    private static function stock(string $from, string $to): array
    {
        $out = [];
        foreach (DB::all('SELECT * FROM products ORDER BY kind, name') as $p) {
            $id = (int)$p['id'];
            $open = (float)DB::val('SELECT COALESCE(SUM(qty),0) FROM stock_moves WHERE product_id=? AND created_at < ?', [$id, $from . ' 00:00:00']);
            $m = fn(string $cond) => (float)DB::val("SELECT COALESCE(SUM(qty),0) FROM stock_moves WHERE product_id=? AND created_at BETWEEN ? AND ? AND $cond", [$id, $from . ' 00:00:00', $to . ' 23:59:59']);
            $purchase = $m("move_type IN ('purchase','opening')"); $sale = -$m("move_type='sale'"); $use = -$m("move_type='usage'"); $adj = $m("move_type IN ('adjust','return')");
            $now = Stock::onHand($id);
            $out[] = [$p['name'], $p['kind'] === 'jual' ? 'Dijual' : 'Bahan', $p['unit'], $open, $purchase, $sale, $use, $adj, $now, (float)$p['min_stock'], $now <= (float)$p['min_stock'] ? 'MENIPIS' : 'Aman'];
        }
        return ['cols' => ['Produk', 'Jenis', 'Satuan', 'Awal', 'Masuk', 'Terjual', 'Dipakai', 'Penyesuaian/Retur', 'Saldo Kini', 'Minimum', 'Status'], 'rows' => $out, 'summary' => ['Produk menipis' => (string)count(array_filter($out, fn($r) => $r[10] === 'MENIPIS'))]];
    }

    private static function members(string $from, string $to): array
    {
        $out = [];
        foreach (DB::all('SELECT c.*, p.name AS plan_name FROM customers c LEFT JOIN membership_plans p ON p.id=c.plan_id WHERE c.plan_id IS NOT NULL ORDER BY c.member_until DESC') as $c) {
            $spend = (int)DB::val('SELECT COALESCE(SUM(total),0) FROM sales WHERE customer_id=? AND paid_at BETWEEN ? AND ?', [$c['id'], $from . ' 00:00:00', $to . ' 23:59:59']);
            $out[] = [$c['name'], $c['phone'] ?? '', $c['plan_name'], $c['member_until'], $c['member_until'] >= today() ? 'Aktif' : 'Kedaluwarsa', (int)$c['points'], $spend];
        }
        return ['cols' => ['Nama', 'HP', 'Paket', 'Berlaku s/d', 'Status', 'Poin', 'Belanja (periode)'], 'money' => [6], 'rows' => $out, 'summary' => ['Jumlah member' => (string)count($out)]];
    }

    private static function attendance(string $from, string $to): array
    {
        $out = []; $tot = [];
        $users = DB::all("SELECT id, name FROM users WHERE role<>'pelanggan' AND active=1 ORDER BY name");
        $limit = min($to, date('Y-m-d', strtotime(today() . ' -1 day')));
        foreach ($users as $u) {
            $att = [];
            foreach (DB::all('SELECT a.*, s.name AS shift_name FROM attendance a LEFT JOIN shifts s ON s.id=a.shift_id WHERE a.user_id=? AND a.att_date BETWEEN ? AND ?', [$u['id'], $from, $to]) as $a) $att[$a['att_date']] = $a;
            for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
                $d = date('Y-m-d', $t);
                $a = $att[$d] ?? null;
                $sh = Slots::shiftFor((int)$u['id'], $d);
                if (!$a && (!$sh || $d > $limit)) continue;
                $status = $a['status'] ?? 'alpa';
                $out[] = [$d, $u['name'], $a['shift_name'] ?? ($sh['name'] ?? '-'), Attendance::STATUS[$status] ?? $status, hm($a['in_at'] ?? null), hm($a['out_at'] ?? null), (int)($a['late_min'] ?? 0), (int)($a['early_min'] ?? 0), (int)($a['work_min'] ?? 0), (int)($a['in_dist'] ?? 0) ?: ''];
                $k = $u['name'];
                $tot[$k] = ($tot[$k] ?? ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'cuti' => 0, 'alpa' => 0, 'late' => 0]);
                $tot[$k][$status]++; $tot[$k]['late'] += (int)($a['late_min'] ?? 0);
            }
        }
        $sum = [];
        foreach ($tot as $k => $v) $sum[$k] = "Hadir {$v['hadir']} · Izin {$v['izin']} · Sakit {$v['sakit']} · Cuti {$v['cuti']} · Tanpa ket. {$v['alpa']} · Telat {$v['late']} mnt";
        return ['cols' => ['Tanggal', 'Pegawai', 'Shift', 'Status', 'Masuk', 'Pulang', 'Telat (mnt)', 'Pulang Awal (mnt)', 'Durasi Kerja (mnt)', 'Jarak Masuk (m)'], 'rows' => $out, 'summary' => $sum];
    }
}
