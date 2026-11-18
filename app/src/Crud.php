<?php
declare(strict_types=1);

/** Definisi & penyimpanan generik untuk menu Pengaturan (admin). Validasi dilakukan di server. */
final class Crud
{
    public static function defs(): array
    {
        $cats = ['Head Spa' => 'Head Spa', 'Foot Spa' => 'Foot Spa', 'Kuku' => 'Kuku', 'Nail Art' => 'Nail Art', 'Paket' => 'Paket', 'Treatment' => 'Treatment', 'Lainnya' => 'Lainnya'];
        return [
            'services' => ['title' => 'Layanan & Tarif', 'table' => 'services', 'order' => 'sort, id', 'list' => ['name', 'category', 'price', 'duration_min', 'active'],
                'fields' => [
                    ['name', 'Nama layanan', 'text', true], ['category', 'Kategori', 'select', true, $cats], ['description', 'Deskripsi', 'textarea'],
                    ['price', 'Tarif reguler (Rp)', 'number', true], ['price_from', 'Tampilkan sebagai "Mulai dari"', 'check'],
                    ['duration_min', 'Durasi (menit) — wajib diisi agar bisa dibooking', 'number'],
                    ['resource_type', 'Kursi/ruang yang dipakai', 'select', true, ['headspa' => 'Kursi Head Spa', 'general' => 'Meja Perawatan']],
                    ['photo', 'URL foto (opsional)', 'text'], ['sort', 'Urutan tampil', 'number'], ['active', 'Aktif (tampil di katalog)', 'check'],
                ]],
            'products' => ['title' => 'Produk & Bahan', 'table' => 'products', 'order' => 'kind, name', 'list' => ['name', 'kind', 'unit', 'price', 'min_stock', 'active'],
                'fields' => [
                    ['name', 'Nama', 'text', true], ['kind', 'Jenis', 'select', true, ['jual' => 'Produk dijual', 'bahan' => 'Bahan treatment (dipakai)']],
                    ['unit', 'Satuan', 'text', true], ['price', 'Harga jual (Rp)', 'number'], ['cost', 'Harga beli (Rp)', 'number'],
                    ['min_stock', 'Stok minimum (peringatan)', 'number'], ['active', 'Aktif', 'check'],
                ]],
            'plans' => ['title' => 'Membership', 'table' => 'membership_plans', 'order' => 'id', 'list' => ['name', 'discount_pct', 'points_per_10k', 'price', 'duration_days', 'combine_promo', 'active'],
                'fields' => [
                    ['name', 'Nama paket', 'text', true], ['discount_pct', 'Diskon (%) pada layanan & produk', 'number'], ['points_per_10k', 'Poin per Rp10.000 belanja', 'number'],
                    ['price', 'Harga membership (Rp)', 'number'], ['duration_days', 'Masa berlaku (hari)', 'number', true],
                    ['combine_promo', 'Boleh digabung dengan harga promo', 'check'], ['benefits', 'Keterangan manfaat (tampil ke pelanggan)', 'text'], ['active', 'Aktif', 'check'],
                ]],
            'shifts' => ['title' => 'Shift Kerja', 'table' => 'shifts', 'order' => 'start_time', 'list' => ['name', 'start_time', 'end_time', 'break_start', 'break_end', 'late_tol', 'active'],
                'fields' => [
                    ['name', 'Nama shift', 'text', true], ['start_time', 'Jam mulai', 'time', true], ['end_time', 'Jam selesai', 'time', true],
                    ['break_start', 'Istirahat mulai', 'time'], ['break_end', 'Istirahat selesai', 'time'],
                    ['late_tol', 'Toleransi terlambat (menit)', 'number'], ['early_tol', 'Toleransi pulang awal (menit)', 'number'], ['active', 'Aktif', 'check'],
                ]],
            'resources' => ['title' => 'Kursi & Ruang', 'table' => 'resources', 'order' => 'type, id', 'list' => ['name', 'type', 'active'],
                'fields' => [['name', 'Nama', 'text', true], ['type', 'Jenis', 'select', true, ['headspa' => 'Kursi Head Spa', 'general' => 'Meja Perawatan']], ['active', 'Aktif', 'check']]],
            'accounts' => ['title' => 'Akun Kas & Bank', 'table' => 'accounts', 'order' => 'id', 'list' => ['name', 'type', 'note', 'active'],
                'fields' => [['name', 'Nama akun', 'text', true], ['type', 'Jenis', 'select', true, ['cash' => 'Kas tunai', 'bank' => 'Bank / transfer', 'qris' => 'QRIS']], ['note', 'Keterangan (no. rekening, dll.)', 'text'], ['active', 'Aktif', 'check']]],
            'holidays' => ['title' => 'Hari Libur', 'table' => 'holidays', 'pk' => 'hdate', 'order' => 'hdate DESC', 'list' => ['hdate', 'note'],
                'fields' => [['hdate', 'Tanggal', 'date', true], ['note', 'Keterangan', 'text']], 'deletable' => true],
            'users' => ['title' => 'Pegawai & Hak Akses', 'table' => 'users', 'order' => "CASE role WHEN 'admin' THEN 1 WHEN 'manager' THEN 2 WHEN 'kasir' THEN 3 WHEN 'terapis' THEN 4 ELSE 5 END, name", 'list' => ['name', 'role', 'email', 'phone', 'active'],
                'where' => "role<>'pelanggan'",
                'fields' => [
                    ['name', 'Nama', 'text', true], ['role', 'Peran / hak akses', 'select', true, ['admin' => 'Admin', 'manager' => 'Manager', 'kasir' => 'Kasir', 'terapis' => 'Terapis']],
                    ['email', 'Email (untuk masuk)', 'text'], ['phone', 'No. HP (untuk masuk)', 'text'], ['position', 'Jabatan', 'text'],
                    ['password', 'Kata sandi (kosongkan bila tidak diubah)', 'password'], ['skills', 'Layanan yang dikuasai (terapis)', 'multi'], ['active', 'Aktif', 'check'],
                ]],
        ];
    }

    public static function list(string $e): array
    {
        $d = self::def($e);
        $cols = $e === 'users' ? 'id,name,email,phone,role,position,active' : '*';
        $sql = "SELECT $cols FROM {$d['table']}" . (isset($d['where']) ? ' WHERE ' . $d['where'] : '') . ' ORDER BY ' . $d['order'];
        $rows = DB::all($sql);
        if ($e === 'users') {
            $sk = [];
            foreach (DB::all('SELECT user_id, service_id FROM therapist_services') as $r) $sk[(int)$r['user_id']][] = (int)$r['service_id'];
            foreach ($rows as &$r) $r['skills'] = $sk[(int)$r['id']] ?? [];
        }
        return $rows;
    }

    public static function def(string $e): array
    {
        $d = self::defs()[$e] ?? null;
        if (!$d) throw new UserError('Data tidak dikenal.');
        return $d;
    }

    public static function save(string $e, array $in): string
    {
        $d = self::def($e);
        $pk = $d['pk'] ?? 'id';
        $row = [];
        foreach ($d['fields'] as $f) {
            [$name, $label, $type] = $f;
            $req = !empty($f[3]);
            if ($type === 'password' || $type === 'multi') continue;
            $v = $in[$name] ?? null;
            if ($type === 'check') { $row[$name] = !empty($v) && $v !== '0' ? 1 : 0; continue; }
            $v = is_string($v) ? trim($v) : $v;
            if (($v === null || $v === '') && !in_array($type, ['time'], true)) {
                if ($req) throw new UserError($label . ' wajib diisi.');
                $v = in_array($type, ['number'], true) ? 0 : ($name === 'email' || $name === 'phone' ? null : '');
            }
            if ($type === 'number') { if (!is_numeric($v)) throw new UserError($label . ' harus berupa angka.'); $v = $name === 'min_stock' ? (float)$v : (int)$v; if ($v < 0) throw new UserError($label . ' tidak boleh negatif.'); }
            if ($type === 'time') { if ($v === null || $v === '') { if ($req) throw new UserError($label . ' wajib diisi.'); $v = null; } elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$v)) throw new UserError($label . ' harus berformat JJ:MM.'); }
            if ($type === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) throw new UserError($label . ' tidak valid.');
            if ($type === 'select' && !isset($f[4][$v])) throw new UserError($label . ' tidak valid.');
            $row[$name] = $v;
        }
        if ($e === 'users') return self::saveUser($in, $row);
        if ($e === 'shifts' && !empty($row['break_start']) !== !empty($row['break_end'])) throw new UserError('Isi kedua jam istirahat, atau kosongkan keduanya.');
        if ($e === 'shifts' && $row['end_time'] <= $row['start_time']) throw new UserError('Jam selesai harus setelah jam mulai.');
        $id = $in[$pk] ?? $in['id'] ?? null;
        try {
            if ($pk === 'hdate') {
                DB::exec('DELETE FROM holidays WHERE hdate=?', [$row['hdate']]);
                DB::insert('holidays', $row);
                audit('crud.save', $e, 0, json_encode($row, JSON_UNESCAPED_UNICODE));
                return (string)$row['hdate'];
            }
            if ($id) { DB::update($d['table'], $row, "$pk=?", [$id]); $rid = (string)$id; }
            else $rid = (string)DB::insert($d['table'], $row);
        } catch (PDOException $ex) {
            throw new UserError('Data gagal disimpan (duplikat atau tidak valid).');
        }
        audit('crud.save', $e, (int)$rid, json_encode($row, JSON_UNESCAPED_UNICODE));
        rev_bump();
        return $rid;
    }

    private static function saveUser(array $in, array $row): string
    {
        $id = (int)($in['id'] ?? 0);
        if (!empty($row['email'])) { $row['email'] = mb_strtolower($row['email']); if (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) throw new UserError('Format email tidak valid.'); }
        $row['phone'] = !empty($row['phone']) ? normalize_phone($row['phone']) : null;
        if (empty($row['email']) && empty($row['phone'])) throw new UserError('Isi email atau nomor HP agar pegawai bisa masuk.');
        $pw = (string)($in['password'] ?? '');
        if (!$id && strlen($pw) < 6) throw new UserError('Kata sandi awal minimal 6 karakter.');
        if ($pw !== '' && strlen($pw) < 6) throw new UserError('Kata sandi minimal 6 karakter.');
        if ($id === (int)Auth::id() && ($row['role'] !== 'admin' || !$row['active'])) throw new UserError('Anda tidak dapat menurunkan peran atau menonaktifkan akun Anda sendiri.');
        try {
            DB::tx(function () use (&$id, $row, $pw, $in) {
                if ($id) {
                    DB::update('users', $row, "id=? AND role<>'pelanggan'", [$id]);
                    if ($pw !== '') DB::update('users', ['pass_hash' => Auth::hash($pw)], 'id=?', [$id]);
                } else {
                    $id = DB::insert('users', $row + ['pass_hash' => Auth::hash($pw), 'created_at' => now()]);
                }
                DB::exec('DELETE FROM therapist_services WHERE user_id=?', [$id]);
                if ($row['role'] === 'terapis') foreach ((array)($in['skills'] ?? []) as $sid) DB::tryInsert('therapist_services', ['user_id' => $id, 'service_id' => (int)$sid]);
                if (!DB::val("SELECT 1 FROM users WHERE role='admin' AND active=1")) throw new UserError('Harus ada minimal satu admin aktif.');
            });
        } catch (PDOException $ex) {
            throw new UserError('Email atau nomor HP sudah dipakai pengguna lain.');
        }
        audit('crud.user', 'user', $id, $row['role']);
        rev_bump();
        return (string)$id;
    }

    public static function delete(string $e, string $id): void
    {
        $d = self::def($e);
        if (empty($d['deletable'])) throw new UserError('Data ini tidak dapat dihapus. Nonaktifkan saja.');
        DB::exec("DELETE FROM {$d['table']} WHERE " . ($d['pk'] ?? 'id') . '=?', [$id]);
        audit('crud.delete', $e, 0, $id);
    }
}
