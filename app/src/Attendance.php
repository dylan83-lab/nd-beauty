<?php
declare(strict_types=1);

final class AttendanceFail extends UserError
{
    public array $data = [];
}

/** Absensi pegawai berbasis lokasi (radius dari titik kerja, waktu server), serta koreksi dengan persetujuan manager. */
final class Attendance
{
    public const STATUS = ['hadir' => 'Hadir', 'izin' => 'Izin', 'sakit' => 'Sakit', 'cuti' => 'Cuti', 'alpa' => 'Tanpa Keterangan'];

    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
        $dp = $p2 - $p1; $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }

    public static function config(): array
    {
        return [
            'enabled' => S('att_enabled', '0') === '1',
            'lat' => S('att_lat') !== '' ? (float)S('att_lat') : null,
            'lng' => S('att_lng') !== '' ? (float)S('att_lng') : null,
            'radius' => (int)S('att_radius', '100'),
            'acc_max' => (int)S('att_acc_max', '50'),
        ];
    }

    public static function today(int $uid): array
    {
        $row = DB::one('SELECT * FROM attendance WHERE user_id=? AND att_date=?', [$uid, today()]);
        $shift = Slots::shiftFor($uid, today());
        return ['row' => $row, 'shift' => $shift, 'config' => self::config() + ['now' => now()]];
    }

    private static function calcLate(?array $shift, string $inAt): int
    {
        if (!$shift) return 0;
        $m = diff_min(substr($inAt, 0, 10) . ' ' . $shift['start_time'] . ':00', $inAt);
        return $m > (int)$shift['late_tol'] ? $m : 0;
    }

    private static function calcEarly(?array $shift, string $outAt): int
    {
        if (!$shift) return 0;
        $m = diff_min($outAt, substr($outAt, 0, 10) . ' ' . $shift['end_time'] . ':00');
        return $m > (int)$shift['early_tol'] ? $m : 0;
    }

    /** Absen masuk/pulang. Lokasi hanya diambil saat tombol ditekan; waktu = waktu server. */
    public static function punch(int $uid, string $type, ?float $lat, ?float $lng, ?float $acc): array
    {
        $cfg = self::config();
        if (!$cfg['enabled'] || $cfg['lat'] === null || $cfg['lng'] === null) throw new UserError('Absensi belum diaktifkan. Admin perlu memverifikasi titik lokasi kerja terlebih dahulu.');
        $date = today();
        $row = DB::one('SELECT * FROM attendance WHERE user_id=? AND att_date=?', [$uid, $date]);
        if ($type === 'in') {
            if ($row && $row['in_at']) throw new UserError('Anda sudah absen masuk hari ini pukul ' . hm($row['in_at']) . '.');
            if ($row && in_array($row['status'], ['izin', 'sakit', 'cuti'], true)) throw new UserError('Hari ini tercatat ' . self::STATUS[$row['status']] . '. Ajukan koreksi bila keliru.');
        } else {
            if (!$row || !$row['in_at']) throw new UserError('Anda belum absen masuk, jadi belum dapat absen pulang. Ajukan koreksi bila lupa absen masuk.');
            if ($row['out_at']) throw new UserError('Anda sudah absen pulang pukul ' . hm($row['out_at']) . '.');
        }
        if ($lat === null || $lng === null || !is_finite($lat) || !is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) {
            $e = new AttendanceFail('Lokasi tidak tersedia. Izinkan akses lokasi pada browser/HP Anda, lalu coba lagi.');
            $e->data = ['reason' => 'no_location'];
            throw $e;
        }
        $dist = (int)round(self::distance($lat, $lng, $cfg['lat'], $cfg['lng']));
        $accI = $acc !== null ? (int)round($acc) : null;
        if ($accI === null || $accI > $cfg['acc_max']) {
            $e = new AttendanceFail('Akurasi GPS kurang baik (±' . ($accI ?? '?') . ' m, batas ' . $cfg['acc_max'] . ' m). Pindah ke area terbuka lalu coba lagi, atau ajukan koreksi.');
            $e->data = ['reason' => 'low_accuracy', 'accuracy' => $accI, 'max' => $cfg['acc_max'], 'distance' => $dist];
            throw $e;
        }
        if ($dist > $cfg['radius']) {
            $e = new AttendanceFail('Anda berada ' . $dist . ' m dari lokasi kerja (batas ' . $cfg['radius'] . ' m). Absen hanya bisa dilakukan di lokasi.');
            $e->data = ['reason' => 'outside', 'distance' => $dist, 'radius' => $cfg['radius'], 'accuracy' => $accI];
            throw $e;
        }
        return DB::tx(function () use ($uid, $type, $date, $lat, $lng, $dist, $accI) {
            $shift = Slots::shiftFor($uid, $date);
            $t = now();
            if ($type === 'in') {
                $id = DB::tryInsert('attendance', [
                    'user_id' => $uid, 'att_date' => $date, 'shift_id' => $shift['id'] ?? null, 'status' => 'hadir', 'in_at' => $t,
                    'in_lat' => $lat, 'in_lng' => $lng, 'in_dist' => $dist, 'in_acc' => $accI, 'late_min' => self::calcLate($shift, $t),
                ]);
                if (!$id) {
                    $row = DB::one('SELECT * FROM attendance WHERE user_id=? AND att_date=?', [$uid, $date]);
                    if ($row && !$row['in_at'] && $row['status'] === 'hadir') {
                        DB::update('attendance', ['in_at' => $t, 'in_lat' => $lat, 'in_lng' => $lng, 'in_dist' => $dist, 'in_acc' => $accI, 'late_min' => self::calcLate($shift, $t), 'shift_id' => $shift['id'] ?? null], 'id=?', [$row['id']]);
                    } else throw new UserError('Anda sudah absen masuk hari ini.');
                }
            } else {
                $row = DB::one('SELECT * FROM attendance WHERE user_id=? AND att_date=?' . DB::forUpdate(), [$uid, $date]);
                if ($row['out_at']) throw new UserError('Anda sudah absen pulang.');
                DB::update('attendance', ['out_at' => $t, 'out_lat' => $lat, 'out_lng' => $lng, 'out_dist' => $dist, 'out_acc' => $accI,
                    'early_min' => self::calcEarly($shift, $t), 'work_min' => diff_min($row['in_at'], $t)], 'id=? AND out_at IS NULL', [$row['id']]);
            }
            audit('attendance.' . $type, 'user', $uid, "dist=$dist acc=$accI");
            $res = DB::one('SELECT * FROM attendance WHERE user_id=? AND att_date=?', [$uid, $date]);
            return ['row' => $res, 'shift' => $shift, 'distance' => $dist, 'accuracy' => $accI, 'time' => $t];
        });
    }

    // ---------- Koreksi ----------
    public static function requestCorrection(int $uid, string $date, string $ctype, ?string $reqIn, ?string $reqOut, ?string $reqStatus, string $reason): int
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > today()) throw new UserError('Tanggal tidak valid (tidak boleh di masa depan).');
        if ($date < date('Y-m-d', strtotime('-45 days'))) throw new UserError('Koreksi maksimal 45 hari ke belakang.');
        if (!in_array($ctype, ['waktu', 'status'], true)) throw new UserError('Jenis koreksi tidak valid.');
        if (trim($reason) === '') throw new UserError('Alasan wajib diisi.');
        $reqIn = $reqIn ? $date . ' ' . substr($reqIn, 0, 5) . ':00' : null;
        $reqOut = $reqOut ? $date . ' ' . substr($reqOut, 0, 5) . ':00' : null;
        if ($ctype === 'waktu') {
            if (!$reqIn && !$reqOut) throw new UserError('Isi jam masuk dan/atau jam pulang yang benar.');
            if ($reqIn && $reqOut && $reqOut <= $reqIn) throw new UserError('Jam pulang harus setelah jam masuk.');
        } else {
            if (!isset(self::STATUS[$reqStatus ?? ''])) throw new UserError('Pilih status.');
        }
        if (DB::val("SELECT 1 FROM att_corrections WHERE user_id=? AND att_date=? AND status='pending'", [$uid, $date])) throw new UserError('Sudah ada pengajuan koreksi menunggu persetujuan untuk tanggal ini.');
        $orig = DB::one('SELECT * FROM attendance WHERE user_id=? AND att_date=?', [$uid, $date]);
        $id = DB::insert('att_corrections', [
            'user_id' => $uid, 'att_date' => $date, 'ctype' => $ctype, 'req_in' => $reqIn, 'req_out' => $reqOut, 'req_status' => $ctype === 'status' ? $reqStatus : null,
            'reason' => mb_substr($reason, 0, 250), 'status' => 'pending', 'original_json' => json_encode($orig ?: null), 'created_at' => now(),
        ]);
        audit('attendance.correction', 'att_correction', $id, $date . ' ' . $ctype);
        rev_bump();
        return $id;
    }

    public static function processCorrection(int $id, bool $approve, string $note, int $byUser): void
    {
        DB::tx(function () use ($id, $approve, $note, $byUser) {
            $c = DB::one('SELECT * FROM att_corrections WHERE id=?' . DB::forUpdate(), [$id]);
            if (!$c) throw new UserError('Pengajuan tidak ditemukan.');
            if ($c['status'] !== 'pending') throw new UserError('Pengajuan sudah diproses.');
            if ($approve && (int)$c['user_id'] === $byUser && Auth::role() !== 'admin') throw new UserError('Pengajuan sendiri harus disetujui manager/admin lain.');
            $result = null;
            if ($approve) $result = self::apply($c);
            DB::update('att_corrections', ['status' => $approve ? 'approved' : 'rejected', 'processed_by' => $byUser, 'processed_at' => now(), 'process_note' => mb_substr($note, 0, 250), 'result_json' => json_encode($result)], 'id=?', [$id]);
            audit('attendance.correction.' . ($approve ? 'approve' : 'reject'), 'att_correction', $id, $note);
            rev_bump();
        });
    }

    private static function apply(array $c): ?array
    {
        $uid = (int)$c['user_id'];
        $date = $c['att_date'];
        $row = DB::one('SELECT * FROM attendance WHERE user_id=? AND att_date=?', [$uid, $date]);
        $shift = Slots::shiftFor($uid, $date);
        if ($c['ctype'] === 'status') {
            $set = ['status' => $c['req_status'], 'note' => 'Koreksi #' . $c['id']];
            if ($c['req_status'] !== 'hadir') $set += ['in_at' => null, 'out_at' => null, 'late_min' => 0, 'early_min' => 0, 'work_min' => 0];
            if ($row) DB::update('attendance', $set, 'id=?', [$row['id']]);
            else DB::insert('attendance', ['user_id' => $uid, 'att_date' => $date, 'shift_id' => $shift['id'] ?? null] + $set + ['in_at' => null]);
        } else {
            $in = $c['req_in'] ?: ($row['in_at'] ?? null);
            $out = $c['req_out'] ?: ($row['out_at'] ?? null);
            $set = ['status' => 'hadir', 'in_at' => $in, 'out_at' => $out, 'late_min' => $in ? self::calcLate($shift, $in) : 0, 'early_min' => $out ? self::calcEarly($shift, $out) : 0, 'work_min' => ($in && $out) ? diff_min($in, $out) : 0, 'note' => 'Koreksi #' . $c['id']];
            if ($row) DB::update('attendance', $set, 'id=?', [$row['id']]);
            else DB::insert('attendance', ['user_id' => $uid, 'att_date' => $date, 'shift_id' => $shift['id'] ?? null] + $set);
        }
        return DB::one('SELECT * FROM attendance WHERE user_id=? AND att_date=?', [$uid, $date]);
    }

    public static function corrections(?string $status = null, ?int $uid = null): array
    {
        $sql = 'SELECT c.*, u.name AS user_name, p.name AS processor FROM att_corrections c JOIN users u ON u.id=c.user_id LEFT JOIN users p ON p.id=c.processed_by WHERE 1=1';
        $p = [];
        if ($status) { $sql .= ' AND c.status=?'; $p[] = $status; }
        if ($uid) { $sql .= ' AND c.user_id=?'; $p[] = $uid; }
        return DB::all($sql . ' ORDER BY c.id DESC LIMIT 200', $p);
    }

    /** Manager/admin mencatat izin/sakit/cuti langsung (tercatat sebagai koreksi yang disetujui). */
    public static function setStatusDirect(int $uid, string $date, string $status, string $note, int $byUser): void
    {
        if (!isset(self::STATUS[$status])) throw new UserError('Status tidak valid.');
        DB::tx(function () use ($uid, $date, $status, $note, $byUser) {
            $orig = DB::one('SELECT * FROM attendance WHERE user_id=? AND att_date=?', [$uid, $date]);
            $cid = DB::insert('att_corrections', ['user_id' => $uid, 'att_date' => $date, 'ctype' => 'status', 'req_status' => $status, 'reason' => $note !== '' ? $note : 'Dicatat langsung oleh atasan', 'status' => 'pending', 'original_json' => json_encode($orig ?: null), 'created_at' => now()]);
            $c = DB::one('SELECT * FROM att_corrections WHERE id=?', [$cid]);
            $res = self::apply($c);
            DB::update('att_corrections', ['status' => 'approved', 'processed_by' => $byUser, 'processed_at' => now(), 'process_note' => 'Dicatat langsung', 'result_json' => json_encode($res)], 'id=?', [$cid]);
            audit('attendance.direct', 'user', $uid, "$date $status");
        });
    }
}
