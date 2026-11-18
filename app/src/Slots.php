<?php
declare(strict_types=1);

/** Mesin ketersediaan: jam operasional, hari libur, shift & keahlian terapis, istirahat, reservasi aktif, kapasitas kursi/ruang. */
final class Slots
{
    public const ACTIVE = "('booked','checkin','inservice')";

    public static function isOpenDay(string $date): bool
    {
        $wd = (string)date('N', strtotime($date));
        if (!in_array($wd, explode(',', S('open_days', '1,2,3,4,5,6,7')), true)) return false;
        return !DB::val('SELECT 1 FROM holidays WHERE hdate=?', [$date]);
    }

    public static function shiftFor(int $uid, string $date): ?array
    {
        $ov = DB::one('SELECT shift_id FROM schedules WHERE user_id=? AND sched_date=?', [$uid, $date]);
        if ($ov) $sid = (int)$ov['shift_id'];
        else {
            $p = DB::one('SELECT shift_id FROM therapist_pattern WHERE user_id=? AND weekday=?', [$uid, (int)date('N', strtotime($date))]);
            $sid = $p ? (int)$p['shift_id'] : 0;
        }
        return $sid > 0 ? DB::one('SELECT * FROM shifts WHERE id=?', [$sid]) : null;
    }

    public static function context(string $date, ?int $exclude = null): array
    {
        $shifts = [];
        foreach (DB::all('SELECT * FROM shifts') as $s) $shifts[(int)$s['id']] = $s;
        $ov = [];
        foreach (DB::all('SELECT user_id, shift_id FROM schedules WHERE sched_date=?', [$date]) as $r) $ov[(int)$r['user_id']] = (int)$r['shift_id'];
        $pat = [];
        foreach (DB::all('SELECT user_id, shift_id FROM therapist_pattern WHERE weekday=?', [(int)date('N', strtotime($date))]) as $r) $pat[(int)$r['user_id']] = (int)$r['shift_id'];
        $skills = [];
        foreach (DB::all('SELECT user_id, service_id FROM therapist_services') as $r) $skills[(int)$r['user_id']][(int)$r['service_id']] = true;

        $ther = [];
        foreach (DB::all("SELECT id, name FROM users WHERE role='terapis' AND active=1 ORDER BY id") as $t) {
            $id = (int)$t['id'];
            $sid = $ov[$id] ?? ($pat[$id] ?? 0);
            $ther[$id] = ['id' => $id, 'name' => $t['name'], 'shift' => $sid > 0 ? ($shifts[$sid] ?? null) : null];
        }
        $bk = DB::all('SELECT id, therapist_id, resource_id, start_at, end_at FROM bookings WHERE book_date=? AND status IN ' . self::ACTIVE, [$date]);
        if ($exclude) $bk = array_values(array_filter($bk, fn($b) => (int)$b['id'] !== $exclude));
        return [
            'date' => $date, 'ther' => $ther, 'skills' => $skills, 'bookings' => $bk,
            'resources' => DB::all('SELECT id, type FROM resources WHERE active=1 ORDER BY id'),
        ];
    }

    public static function resourceType(array $svcIds): string
    {
        if (!$svcIds) return 'general';
        $in = implode(',', array_map('intval', $svcIds));
        foreach (DB::all("SELECT resource_type FROM services WHERE id IN ($in)") as $r) if ($r['resource_type'] === 'headspa') return 'headspa';
        return 'general';
    }

    private static function overlap(string $a1, string $a2, string $b1, string $b2): bool { return $a1 < $b2 && $b1 < $a2; }

    /** Terapis & sumber daya yang bebas pada rentang waktu; juga kapasitas total untuk menentukan label status. */
    public static function free(array $ctx, string $start, string $end, array $svcIds, string $resType, ?int $therapistId = null): array
    {
        $date = $ctx['date'];
        $free = [];
        $eligible = 0;
        foreach ($ctx['ther'] as $t) {
            if ($therapistId && $t['id'] !== $therapistId) continue;
            $sh = $t['shift'];
            if (!$sh) continue;
            if ($start < $date . ' ' . $sh['start_time'] . ':00' || $end > $date . ' ' . $sh['end_time'] . ':00') continue;
            if ($sh['break_start'] && $sh['break_end'] && self::overlap($start, $end, $date . ' ' . $sh['break_start'] . ':00', $date . ' ' . $sh['break_end'] . ':00')) continue;
            foreach ($svcIds as $sid) if (empty($ctx['skills'][$t['id']][(int)$sid])) continue 2;
            $eligible++;
            $busy = false;
            foreach ($ctx['bookings'] as $b) {
                if ((int)$b['therapist_id'] === $t['id'] && self::overlap($start, $end, $b['start_at'], $b['end_at'])) { $busy = true; break; }
            }
            if (!$busy) $free[] = $t['id'];
        }
        $resFree = [];
        $resTotal = 0;
        foreach ($ctx['resources'] as $r) {
            if ($r['type'] !== $resType) continue;
            $resTotal++;
            $used = false;
            foreach ($ctx['bookings'] as $b) {
                if ((int)$b['resource_id'] === (int)$r['id'] && self::overlap($start, $end, $b['start_at'], $b['end_at'])) { $used = true; break; }
            }
            if (!$used) $resFree[] = (int)$r['id'];
        }
        $n = min(count($free), count($resFree));
        $cap = max(1, min($eligible ?: 1, $resTotal ?: 1));
        return ['th' => $free, 'res' => $resFree, 'free' => $n, 'cap' => $cap];
    }

    public static function label(int $free, int $cap): string
    {
        if ($free <= 0) return 'penuh';
        return ($free * 2 > $cap) ? 'tersedia' : 'terbatas';
    }

    /**
     * Daftar jam mulai untuk satu tanggal. $staff=true: langkah 15 menit + opsi "Sekarang" (untuk pelanggan datang langsung).
     * Pelanggan tidak menerima identitas pemesan lain — hanya jumlah dan label.
     */
    public static function forDate(string $date, array $svcIds, int $duration, ?int $therapistId = null, ?int $exclude = null, bool $staff = false): array
    {
        if ($duration <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [];
        if ($date < today() || !self::isOpenDay($date)) return [];
        if (!$staff && $date > date('Y-m-d', strtotime('+' . (int)S('max_advance_days', '60') . ' days'))) return [];
        $open = S('open_time', '09:00');
        $close = S('close_time', '20:00');
        $step = $staff ? 15 : max(5, (int)S('slot_step', '30'));
        $arrive = (int)S('arrive_before', '10');
        $resType = self::resourceType($svcIds);
        $ctx = self::context($date, $exclude);
        $lastStart = strtotime($date . ' ' . $close . ':00') - $duration * 60;
        $out = [];
        $times = [];
        for ($t = strtotime($date . ' ' . $open . ':00'); $t <= $lastStart; $t += $step * 60) $times[] = $t;
        if ($staff && $date === today()) {
            $n = (int)ceil(now_ts() / 300) * 300;
            if ($n >= strtotime($date . ' ' . $open . ':00') && $n <= $lastStart) { $times[] = $n; sort($times); $times = array_values(array_unique($times)); }
        }
        $nowTs = now_ts();
        foreach ($times as $t) {
            if ($t < $nowTs) continue;
            $start = date('Y-m-d H:i:s', $t);
            $end = add_min($start, $duration);
            $f = self::free($ctx, $start, $end, $svcIds, $resType, $therapistId);
            $out[] = [
                'time' => date('H:i', $t), 'end' => date('H:i', strtotime($end)),
                'arrive_by' => date('H:i', $t - $arrive * 60),
                'status' => self::label($f['free'], $f['cap']), 'free' => $f['free'],
                'now' => $staff && $date === today() && $t === (int)ceil($nowTs / 300) * 300,
            ];
        }
        return $out;
    }
}
