<?php
declare(strict_types=1);

/** Pembuatan tabel + data awal (katalog dari poster, pengaturan awal, akun contoh). */
final class Setup
{
    public static function schemaSql(string $driver): array
    {
        $sql = file_get_contents(APP_ROOT . '/sql/schema.sql');
        $sql = preg_replace('/^--.*$/m', '', $sql);
        $pk = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $sql = str_replace('{PK}', $pk, $sql);
        $out = [];
        foreach (explode(';', $sql) as $st) {
            $st = trim($st);
            if ($st === '') continue;
            if ($driver === 'mysql' && str_starts_with($st, 'CREATE TABLE')) $st .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $out[] = $st;
        }
        return $out;
    }

    public static function createTables(): void
    {
        foreach (self::schemaSql(DB::$driver) as $st) DB::$pdo->exec($st);
    }

    public static function isInstalled(): bool
    {
        try { return (int)DB::val('SELECT COUNT(*) FROM users') > 0; } catch (Throwable $e) { return false; }
    }

    public static function seed(string $adminPass, bool $demo = true, string $adminEmail = 'admin@ndbeauty.id'): void
    {
        DB::tx(function () use ($adminPass, $demo, $adminEmail) {
            self::seedSettings();
            self::seedCore($adminPass, $demo, $adminEmail);
            self::seedCatalog();
            self::seedPromo();
            self::seedInventory();
            DB::tryInsert('revs', ['id' => 1, 'n' => 1]);
        });
    }

    private static function seedSettings(): void
    {
        $s = [
            'biz_name' => 'ND Japanese Head Spa',
            'biz_tagline' => 'Relax • Revitalize • Rebalance',
            'biz_address' => 'Perumahan Surya Praja Permai Blok C7 No. 13, Jl. Sukahati, Cibinong, Kabupaten Bogor, Jawa Barat 16913',
            'biz_wa' => '085196741600',
            'biz_maps_lat' => '-6.4756', 'biz_maps_lng' => '106.8386',
            'open_time' => '09:00', 'close_time' => '20:00', 'open_days' => '1,2,3,4,5,6,7',
            'slot_step' => '30', 'arrive_before' => '10',
            'cancel_min_hours' => '2', 'noshow_grace' => '30', 'max_advance_days' => '60',
            'therapist_choose' => '1',
            'att_enabled' => '0', 'att_lat' => '', 'att_lng' => '', 'att_radius' => '100', 'att_acc_max' => '50',
            'att_verified_by' => '', 'att_verified_at' => '',
            'point_value' => '100', 'manual_disc_max_pct' => '10',
            'receipt_footer' => 'Terima kasih. Relax • Revitalize • Rebalance',
            'howto' => "1. Pilih layanan atau paket.\n2. Pilih tanggal dan jam yang tersedia.\n3. Masuk/daftar akun lalu Konfirmasi Booking.\n4. Datang paling lambat 10 menit sebelum jadwal.\n5. Check-in di kasir, nikmati perawatan, lalu bayar di kasir.",
        ];
        foreach ($s as $k => $v) DB::tryInsert('settings', ['k' => $k, 'v' => $v]);
    }

    private static function user(string $name, string $email, ?string $phone, string $role, string $pass, string $pos = ''): int
    {
        return DB::insert('users', ['name' => $name, 'email' => $email, 'phone' => $phone, 'pass_hash' => Auth::hash($pass), 'role' => $role, 'position' => $pos, 'active' => 1, 'created_at' => now()]);
    }

    private static function seedCore(string $adminPass, bool $demo, string $adminEmail): void
    {
        $admin = self::user('Admin ND', $adminEmail, '085196741600', 'admin', $adminPass, 'Pemilik / Admin');

        DB::insert('shifts', ['name' => 'Pagi', 'start_time' => '09:00', 'end_time' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00', 'late_tol' => 10, 'early_tol' => 10, 'active' => 1]);
        DB::insert('shifts', ['name' => 'Sore', 'start_time' => '12:00', 'end_time' => '20:00', 'break_start' => '16:00', 'break_end' => '17:00', 'late_tol' => 10, 'early_tol' => 10, 'active' => 1]);
        foreach ([['Kursi Head Spa 1', 'headspa'], ['Kursi Head Spa 2', 'headspa'], ['Meja Perawatan 1', 'general'], ['Meja Perawatan 2', 'general'], ['Meja Perawatan 3', 'general']] as [$n, $t]) {
            DB::insert('resources', ['name' => $n, 'type' => $t, 'active' => 1]);
        }
        foreach ([['Kas Tunai', 'cash'], ['Rekening Bank', 'bank'], ['QRIS', 'qris']] as [$n, $t]) {
            DB::insert('accounts', ['name' => $n, 'type' => $t, 'active' => 1]);
        }
        DB::insert('membership_plans', ['name' => 'Member Silver (contoh)', 'discount_pct' => 5, 'points_per_10k' => 1, 'price' => 0, 'duration_days' => 365, 'combine_promo' => 0, 'benefits' => 'Diskon 5% layanan + 1 poin per Rp10.000. Tidak digabung dengan promo.', 'active' => 1]);

        if (!$demo) return;
        $pw = 'ND-' . 'Demo2026';
        $m = self::user('Manager ND', 'manager@ndbeauty.id', '081200000002', 'manager', $pw, 'Manager');
        $k = self::user('Kasir ND', 'kasir@ndbeauty.id', '081200000003', 'kasir', $pw, 'Kasir');
        $t1 = self::user('Sari', 'terapis1@ndbeauty.id', '081200000011', 'terapis', $pw, 'Terapis');
        $t2 = self::user('Dewi', 'terapis2@ndbeauty.id', '081200000012', 'terapis', $pw, 'Terapis');
        $t3 = self::user('Rina', 'terapis3@ndbeauty.id', '081200000013', 'terapis', $pw, 'Terapis');
        $c = self::user('Pelanggan Demo', 'pelanggan@ndbeauty.id', '081200000099', 'pelanggan', $pw);
        DB::insert('customers', ['user_id' => $c, 'name' => 'Pelanggan Demo', 'phone' => '081200000099', 'email' => 'pelanggan@ndbeauty.id', 'points' => 0, 'created_at' => now()]);
        for ($d = 1; $d <= 7; $d++) {
            DB::insert('therapist_pattern', ['user_id' => $t1, 'weekday' => $d, 'shift_id' => 1]);
            DB::insert('therapist_pattern', ['user_id' => $t2, 'weekday' => $d, 'shift_id' => 2]);
            DB::insert('therapist_pattern', ['user_id' => $t3, 'weekday' => $d, 'shift_id' => in_array($d, [1, 3, 5, 7], true) ? 1 : 2]);
            if ($d <= 6) {
                DB::insert('therapist_pattern', ['user_id' => $k, 'weekday' => $d, 'shift_id' => 1]);
                DB::insert('therapist_pattern', ['user_id' => $m, 'weekday' => $d, 'shift_id' => 1]);
            }
        }
    }

    private static function seedCatalog(): void
    {
        $svc = [
            // kategori, nama, deskripsi, harga, mulai?, durasi, sumber daya
            ['Head Spa', 'Japanese Head Spa', 'Terapi relaksasi holistik ala Jepang untuk kesehatan kulit kepala dan kesegaran rambut.', 299000, 0, 90, 'headspa'],
            ['Head Spa', 'Korean Head Spa', 'Perawatan scalp & hair treatment ala Korea dengan teknologi modern untuk detox, nutrisi, dan revitalisasi rambut.', 199000, 0, 60, 'headspa'],
            ['Foot Spa', 'Foot Spa', 'Relaksasi kaki, melancarkan sirkulasi, dan mengurangi kelelahan.', 99000, 0, 0, 'general'],
            ['Kuku', 'Pedicure', 'Perawatan dan merapikan kuku kaki agar sehat, bersih, dan cantik.', 79000, 0, 0, 'general'],
            ['Kuku', 'Manicure', 'Perawatan dan merapikan kuku tangan agar sehat, bersih, dan cantik.', 79000, 0, 0, 'general'],
            ['Paket', 'Paket Meni + Pedi', 'Manicure + Pedicure.', 129000, 0, 0, 'general'],
            ['Paket', 'Paket Foot Spa + Meni + Pedi', 'Foot Spa + Manicure + Pedicure.', 249000, 0, 0, 'general'],
            ['Nail Art', 'Nail Art Polos Tangan', 'Nail art polos untuk kuku tangan.', 29000, 0, 0, 'general'],
            ['Nail Art', 'Nail Art Polos Kaki', 'Nail art polos untuk kuku kaki.', 39000, 0, 0, 'general'],
            ['Nail Art', 'Nail Art Motif', 'Cat eye, ombre, French nail, marbel, 3D. Harga mulai dari.', 99000, 1, 0, 'general'],
            ['Nail Art', 'Remove Nail Art', 'Pelepasan nail art.', 39000, 0, 0, 'general'],
            ['Nail Art', 'Nail Art for Kids', 'Nail art khusus anak.', 59000, 0, 0, 'general'],
            ['Nail Art', 'Nail Extension (10 kuku)', 'Nail extension untuk 10 kuku.', 49000, 0, 0, 'general'],
            ['Treatment', 'Treatment Kutu Rambut', 'Perawatan intensif untuk membersihkan kutu dan telur kutu, serta menjaga kesehatan kulit kepala. Harga mulai dari.', 399000, 1, 0, 'headspa'],
        ];
        $ids = [];
        foreach ($svc as $i => [$cat, $name, $desc, $price, $from, $dur, $res]) {
            $ids[$name] = DB::insert('services', ['category' => $cat, 'name' => $name, 'description' => $desc, 'price' => $price, 'price_from' => $from, 'duration_min' => $dur, 'resource_type' => $res, 'sort' => $i + 1, 'active' => 1]);
        }
        $jp = [
            ['Konsultasi & Scalp Analyzer', 'Analisis kondisi kulit kepala & rambut'],
            ['Dry Massage', 'Relaksasi awal pada kepala, leher & bahu'],
            ['Scalp Cleansing & Eksfoliasi', 'Membersihkan kotoran, minyak berlebih & sel kulit mati'],
            ['Hair Wash dengan Tsubaki', 'Keramas lembut dengan shampoo & kondisioner premium Tsubaki'],
            ['Hair Mask (Dove)', 'Nutrisi intensif untuk kelembutan rambut'],
            ['Japanese Scalp Massage', 'Pijat teknik Jepang (Shiatsu) untuk melancarkan sirkulasi darah dan mengurangi stres'],
            ['Hair Tonic & Serum (Pantene)', 'Menutrisi akar rambut dan menguatkan rambut'],
            ['Hair Oil (Martha Tilaar) & Vitamin Rambut (Pantene)', 'Menjaga kelembapan, kilau, dan kesehatan rambut'],
            ['Blow Dry', 'Pengeringan rambut & styling natural'],
        ];
        $kr = [
            ['Konsultasi & Scalp Analyzer', 'Analisis mendalam kondisi kulit kepala & rambut'],
            ['Scalp Steaming', 'Uap hangat untuk membuka pori-pori dan melembutkan kulit kepala'],
            ['Deep Scalp Cleansing', 'Pembersihan menyeluruh dari kotoran, minyak, dan residu styling'],
            ['Ampoule / Scalp Essence', 'Essence/ampoule khas Korea (anti rontok, hair growth, atau oil control)'],
            ['Hair Mask (Dove)', 'Nutrisi intensif untuk perbaikan dan kekuatan rambut'],
            ['Korean Scalp Massage', 'Pijat relaksasi teknik Korea (akupresur) untuk menstimulasi pertumbuhan rambut'],
            ['Hair Tonic & Serum (Pantene)', 'Menutrisi, menyehatkan, dan memperkuat akar rambut'],
            ['Hair Oil (Martha Tilaar) & Vitamin Rambut (Pantene)', 'Menjaga kelembapan, kilau, dan elastisitas rambut'],
            ['Blow Dry & Styling', 'Pengeringan rambut & styling sesuai kebutuhan'],
        ];
        foreach ([['Japanese Head Spa', $jp], ['Korean Head Spa', $kr]] as [$n, $steps]) {
            foreach ($steps as $i => [$t, $d]) DB::insert('service_steps', ['service_id' => $ids[$n], 'step_no' => $i + 1, 'title' => $t, 'detail' => $d]);
        }
        DB::update('services', ['meta' => json_encode([
            'filosofi' => 'Holistik & relaksasi menyeluruh', 'teknik' => 'Shiatsu Jepang (ritmis, menenangkan)',
            'fokus' => 'Relaksasi, melancarkan sirkulasi, dan keseimbangan tubuh', 'kesan' => 'Rileks, ringan, bebas stres',
            'manfaat' => ['Mengurangi stres & kelelahan', 'Melancarkan sirkulasi darah di kulit kepala', 'Menyehatkan kulit kepala & akar rambut', 'Mengurangi kerontokan', 'Membuat rambut lebih lembut, halus, dan berkilau'],
        ], JSON_UNESCAPED_UNICODE)], 'id=?', [$ids['Japanese Head Spa']]);
        DB::update('services', ['meta' => json_encode([
            'filosofi' => 'Teknologi & perawatan scalp intensif', 'teknik' => 'Akupresur Korea (stimulasi & revitalisasi)',
            'fokus' => 'Detox kulit kepala, nutrisi intensif, stimulasi pertumbuhan rambut', 'kesan' => 'Kulit kepala bersih maksimal, rambut lebih kuat dan bervolume',
            'manfaat' => ['Membersihkan kulit kepala secara mendalam (detox)', 'Menyeimbangkan produksi minyak (oil control)', 'Menutrisi dan memperkuat akar rambut', 'Merangsang pertumbuhan rambut', 'Rambut terasa lebih bervolume, sehat, dan segar lebih lama'],
        ], JSON_UNESCAPED_UNICODE)], 'id=?', [$ids['Korean Head Spa']]);

        // Terapis demo menguasai semua layanan.
        foreach (DB::all("SELECT id FROM users WHERE role='terapis'") as $t) {
            foreach ($ids as $sid) DB::tryInsert('therapist_services', ['user_id' => $t['id'], 'service_id' => $sid]);
        }
    }

    /** Promo Launching: disimpan sebagai DRAF. Admin wajib menetapkan varian layanan, isi paket, dan durasi sebelum diaktifkan. */
    private static function seedPromo(): void
    {
        $pid = DB::insert('promos', ['name' => 'Promo Launching', 'starts' => '2026-11-01', 'ends' => '2026-11-30', 'status' => 'draft', 'note' => 'Draf dari poster launching. Tentukan varian, isi paket, dan durasi sebelum diaktifkan.']);
        $items = [
            ['Japanese Head Spa', 'Perawatan relaksasi kepala, kulit kepala & rambut ala Jepang', 89000, 100000, 0],
            ['Foot Spa', 'Perawatan relaksasi kaki, melancarkan sirkulasi darah', 65000, 75000, 0],
            ['Meni Pedi', 'Perawatan kuku tangan & kaki, bersih, sehat, dan rapi', 49000, 55000, 0],
            ['Nail Art', 'Kreasi nail art cantik & trendy sesuai kepribadian Anda', 59000, 70000, 0],
            ['Treatment Kutu Rambut', 'Perawatan khusus mengatasi kutu rambut & telurnya secara aman', 99000, 115000, 0],
            ['Relax Package', 'Japanese Head Spa + Foot Spa', 149000, 175000, 1],
            ['Beauty Package', 'Meni Pedi + Nail Art', 109000, 125000, 1],
            ['Complete Care', 'Japanese Head Spa + Meni Pedi + Nail Art', 189000, 225000, 1],
        ];
        foreach ($items as [$l, $i, $pp, $np, $pk]) {
            DB::insert('promo_items', ['promo_id' => $pid, 'label' => $l, 'info' => $i, 'promo_price' => $pp, 'normal_price' => $np, 'service_id' => null, 'includes' => '', 'duration_min' => 0, 'is_package' => $pk]);
        }
    }

    private static function seedInventory(): void
    {
        $prod = [
            ['Shampoo ND 250 ml', 'jual', 'pcs', 85000, 45000, 3, 10],
            ['Hair Serum ND 50 ml', 'jual', 'pcs', 120000, 65000, 3, 8],
            ['Shampoo Tsubaki (pemakaian)', 'bahan', 'ml', 0, 0, 500, 5000],
            ['Hair Mask Dove (pemakaian)', 'bahan', 'gr', 0, 0, 300, 3000],
            ['Hair Tonic Pantene (pemakaian)', 'bahan', 'ml', 0, 0, 200, 2000],
            ['Hair Oil Martha Tilaar (pemakaian)', 'bahan', 'ml', 0, 0, 150, 1500],
            ['Ampoule Scalp Essence', 'bahan', 'pcs', 0, 0, 10, 50],
        ];
        $pid = [];
        foreach ($prod as [$n, $k, $u, $pr, $c, $min, $open]) {
            $id = DB::insert('products', ['name' => $n, 'kind' => $k, 'unit' => $u, 'price' => $pr, 'cost' => $c, 'min_stock' => $min, 'active' => 1]);
            $pid[$n] = $id;
            DB::insert('stock_moves', ['product_id' => $id, 'move_type' => 'opening', 'qty' => $open, 'unit_cost' => $c, 'note' => 'Stok awal', 'ref_type' => 'opening', 'ref_id' => $id, 'uniq_key' => 'open:' . $id, 'created_at' => now()]);
        }
        $jp = (int)DB::val("SELECT id FROM services WHERE name='Japanese Head Spa'");
        $kr = (int)DB::val("SELECT id FROM services WHERE name='Korean Head Spa'");
        foreach ([[$jp, 'Shampoo Tsubaki (pemakaian)', 30], [$jp, 'Hair Mask Dove (pemakaian)', 20], [$jp, 'Hair Tonic Pantene (pemakaian)', 10], [$jp, 'Hair Oil Martha Tilaar (pemakaian)', 5],
                  [$kr, 'Ampoule Scalp Essence', 1], [$kr, 'Hair Mask Dove (pemakaian)', 20], [$kr, 'Hair Tonic Pantene (pemakaian)', 10], [$kr, 'Hair Oil Martha Tilaar (pemakaian)', 5]] as [$s, $pn, $q]) {
            DB::insert('service_materials', ['service_id' => $s, 'product_id' => $pid[$pn], 'qty' => $q]);
        }
    }
}
